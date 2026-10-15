<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Services\Shopping;

use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\Store;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Discovery\CandidateValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Busca o produto no Google Shopping, grava as ofertas aceitas no histórico e avalia alertas.
 */
class ShoppingPriceRecorder
{
    // Marketplaces conhecidos em que a maioria das ofertas é de vendedores terceiros
    protected const array ThirdPartyMarketplaces = ['shopee.com.br'];

    // Por quantos dias o endereço encontrado (ou a falta dele) vale para a mesma oferta
    protected const int DirectLinkCacheDays = 7;

    public function __construct(
        protected SerperShoppingClient $serper,
        protected ShoppingOfferMatcher $matcher,
        protected AlertEvaluator $alertEvaluator,
        protected CandidateValidator $validator,
    ) {}

    public function isConfigured(): bool
    {
        return $this->serper->isConfigured();
    }

    public function creditsUsed(): int
    {
        return $this->serper->creditsUsed;
    }

    public function searchQueryFor(Product $product): string
    {
        $query = $product->name;
        $modelCode = (string) $product->model_code;

        if (preg_match('/\d/', $modelCode) && ! Str::contains($query, $modelCode, ignoreCase: true)) {
            $query .= ' '.$modelCode;
        }

        return $query;
    }

    /**
     * @return array{query: string, listed: int, accepted: int, suspicious: int, rejected: int, recorded: int, best: PriceObservation|null}
     */
    public function record(Product $product): array
    {
        $query = $this->searchQueryFor($product);
        $results = $this->matcher->match($product, $this->serper->searchOffers($query));
        $collectedAt = Carbon::now();

        $statusCount = fn (string $status): int => count(array_filter($results, fn (array $result): bool => $result['status'] === $status));

        // Uma observação por loja por rodada: a oferta mais barata daquela loja
        $cheapestPerStore = collect($results)
            ->reject(fn (array $result): bool => $result['status'] === ShoppingOfferMatcher::Rejected)
            ->sortBy(fn (array $result): float => $result['offer']->price)
            ->unique(fn (array $result): string => mb_strtolower($result['offer']->storeName, 'UTF-8'))
            ->values();

        $observations = DB::transaction(fn (): array => $cheapestPerStore
            ->map(fn (array $result): PriceObservation => $this->storeObservation($product, $result, $query, $collectedAt))
            ->all());

        // Alerta usa a melhor oferta de loja confiável; sem nenhuma, a melhor de qualquer loja
        $candidates = collect($observations)
            ->reject(fn (PriceObservation $observation): bool => $observation->is_suspicious)
            ->sortBy(fn (PriceObservation $observation): float => (float) $observation->regular_price);
        $best = $candidates->first(fn (PriceObservation $observation): bool => (bool) $observation->source->store?->isTrusted())
            ?? $candidates->first();

        // Endereço real da loja só para o que aparece no card e no alerta: a melhor oferta e a mais barata, se diferente
        $cheapest = $candidates->first();
        foreach (array_filter([$best, $cheapest !== $best ? $cheapest : null]) as $observation) {
            $this->resolveDirectLink($product, $observation);
        }

        if ($best !== null) {
            try {
                $this->alertEvaluator->evaluate($best);
            } catch (\Throwable $e) {
                Log::error('Falha ao avaliar alerta da oferta do Google Shopping', [
                    'product_id' => $product->id,
                    'observation_id' => $best->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return [
            'query' => $query,
            'listed' => count($results),
            'accepted' => $statusCount(ShoppingOfferMatcher::Accepted),
            'suspicious' => $statusCount(ShoppingOfferMatcher::Suspicious),
            'rejected' => $statusCount(ShoppingOfferMatcher::Rejected),
            'recorded' => count($observations),
            'best' => $best,
        ];
    }

    /**
     * @param  array{offer: ShoppingOffer, status: string, reason: string|null}  $result
     */
    protected function storeObservation(Product $product, array $result, string $query, Carbon $collectedAt): PriceObservation
    {
        $offer = $result['offer'];
        $store = $this->resolveStore($offer->storeName);

        $source = ProductSource::firstOrCreate(
            [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'collector_type' => ProductSource::CollectorGoogleShopping,
            ],
            [
                'url' => $offer->storeSearchLink(),
                'expected_model' => $product->model_code,
                'expected_voltage' => $product->voltage ?: 'Bivolt',
                'active' => true,
                'status' => 'active',
                'priority' => 5,
            ],
        );

        $title = Str::limit($offer->title, 495);
        $cachedLookup = $this->cachedDirectLookup($source, $title);

        $source->update([
            'url' => $cachedLookup['direct_url'] ?? $offer->storeSearchLink(),
            'last_checked_at' => $collectedAt,
        ]);

        $isSuspicious = $result['status'] === ShoppingOfferMatcher::Suspicious;

        return PriceObservation::create([
            'product_source_id' => $source->id,
            'regular_price' => $offer->price,
            'in_stock' => true,
            'seller' => $offer->storeName,
            'raw_title' => $title,
            'is_mismatch' => false,
            'is_suspicious' => $isSuspicious,
            'sanity_check_reason' => $isSuspicious ? $result['reason'] : null,
            'collected_at' => $collectedAt,
            'metadata' => [
                'provider' => ProductSource::CollectorGoogleShopping,
                'query' => $query,
                'google_product_id' => $offer->googleProductId,
                'google_link' => $offer->googleLink,
                'store_link' => $offer->storeSearchLink(),
                'direct_url' => $cachedLookup['direct_url'] ?? null,
                'direct_lookup_at' => $cachedLookup['direct_lookup_at'] ?? null,
            ],
        ])->setRelation('source', $source->setRelation('store', $store));
    }

    /**
     * Resultado de uma busca de endereço feita há menos de DirectLinkCacheDays para a mesma oferta (mesma fonte e título).
     *
     * @return array{direct_url: string|null, direct_lookup_at: string}|null
     */
    protected function cachedDirectLookup(ProductSource $source, string $title): ?array
    {
        $previous = PriceObservation::query()
            ->where('product_source_id', $source->id)
            ->where('raw_title', $title)
            ->whereNotNull('metadata->direct_lookup_at')
            ->latest('id')
            ->first();

        $lookedUpAt = $previous?->metadata['direct_lookup_at'] ?? null;

        if ($lookedUpAt === null || Carbon::parse($lookedUpAt)->lt(Carbon::now()->subDays(self::DirectLinkCacheDays))) {
            return null;
        }

        return ['direct_url' => $previous->metadata['direct_url'] ?? null, 'direct_lookup_at' => $lookedUpAt];
    }

    /**
     * Busca a página real do produto na loja (1 crédito) e aceita só se o título do resultado for do produto certo.
     */
    protected function resolveDirectLink(Product $product, PriceObservation $observation): void
    {
        if (($observation->metadata['direct_lookup_at'] ?? null) !== null) {
            return;
        }

        $directUrl = null;

        try {
            $page = $this->serper->findStorePage(new ShoppingOffer((string) $observation->raw_title, (string) $observation->seller, (float) $observation->regular_price));

            if ($page !== null && $this->validator->validateListing($product, $page['title'])->isValid) {
                $directUrl = $page['url'];
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao buscar o endereço da oferta na loja', [
                'observation_id' => $observation->id,
                'loja' => $observation->seller,
                'erro' => $e->getMessage(),
            ]);

            return;
        }

        $observation->update(['metadata' => array_merge($observation->metadata ?? [], [
            'direct_url' => $directUrl,
            'direct_lookup_at' => Carbon::now()->toIso8601String(),
        ])]);

        if ($directUrl !== null) {
            $observation->source->update(['url' => $directUrl]);
        }
    }

    /**
     * Loja conhecida pelo domínio (grande varejo, confiável); loja nova do Google Shopping é identificada pelo nome.
     */
    protected function resolveStore(string $storeName): Store
    {
        $domain = $this->serper->domainForStore($storeName);

        if ($domain !== null) {
            $trustLevel = in_array($domain, self::ThirdPartyMarketplaces, true) ? 'marketplace_3p' : 'marketplace_1p';
            $store = Store::firstOrCreate(
                ['domain' => $domain],
                ['name' => $storeName, 'active' => true, 'trust_level' => $trustLevel],
            );

            // Loja conhecida criada antes como "não verificada" (descoberta automática) é promovida
            if ($store->trust_level === 'standard') {
                $store->update(['trust_level' => $trustLevel]);
            }

            return $store;
        }

        return Store::firstOrCreate(
            ['name' => $storeName, 'domain' => ''],
            ['active' => true, 'trust_level' => 'standard'],
        );
    }
}
