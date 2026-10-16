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
use Illuminate\Support\Collection;
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

    // Por quantos dias o resultado da busca do endereço na loja vale para a mesma oferta
    protected const int DirectLinkCacheDays = 7;

    // Quantas vezes tentar a próxima oferta quando a melhor se revela outro produto na página da loja
    protected const int MaxOfferReplacements = 3;

    // Páginas da loja abertas por oferta para achar a que tem o mesmo preço do Google
    protected const int MaxPagesToVerify = 6;

    public function __construct(
        protected SerperShoppingClient $serper,
        protected ShoppingOfferMatcher $matcher,
        protected AlertEvaluator $alertEvaluator,
        protected CandidateValidator $validator,
        protected OfferPageVerifier $pageVerifier,
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

        $observations = collect(DB::transaction(fn (): array => $cheapestPerStore
            ->map(fn (array $result): PriceObservation => $this->storeObservation($product, $result, $query, $collectedAt))
            ->all()));

        // Endereço real só para o que aparece no card e no alerta: a melhor oferta (loja confiável) e a mais barata
        $best = $this->resolveUntilValid($product, fn (): ?PriceObservation => $this->bestOffer($observations));
        $this->resolveUntilValid($product, fn (): ?PriceObservation => $this->validOffers($observations)->first());

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
            'recorded' => $observations->count(),
            'best' => $best,
        ];
    }

    /**
     * @param  Collection<int, PriceObservation>  $observations
     * @return Collection<int, PriceObservation>
     */
    protected function validOffers(Collection $observations): Collection
    {
        return $observations
            ->reject(fn (PriceObservation $observation): bool => $observation->is_suspicious || $observation->is_mismatch)
            ->sortBy(fn (PriceObservation $observation): float => (float) $observation->regular_price)
            ->values();
    }

    /**
     * Melhor oferta de loja confiável; sem nenhuma, a melhor de qualquer loja.
     *
     * @param  Collection<int, PriceObservation>  $observations
     */
    protected function bestOffer(Collection $observations): ?PriceObservation
    {
        $valid = $this->validOffers($observations);

        return $valid->first(fn (PriceObservation $observation): bool => (bool) $observation->source->store?->isTrusted())
            ?? $valid->first();
    }

    /**
     * Resolve o endereço da oferta escolhida; se a página da loja revelar outro produto, passa para a próxima.
     *
     * @param  callable(): ?PriceObservation  $pick
     */
    protected function resolveUntilValid(Product $product, callable $pick): ?PriceObservation
    {
        for ($attempt = 0; $attempt < self::MaxOfferReplacements; $attempt++) {
            $observation = $pick();

            if ($observation === null) {
                return null;
            }

            $this->resolveDirectLink($product, $observation);

            if (! $observation->is_mismatch) {
                return $observation;
            }
        }

        return $pick();
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
        $cached = $this->cachedDirectLookup($source, $title, $offer->price);

        $source->update([
            'url' => $cached['direct_url'] ?? $offer->storeSearchLink(),
            'last_checked_at' => $collectedAt,
        ]);

        $isSuspicious = $result['status'] === ShoppingOfferMatcher::Suspicious;
        $rejection = $cached['direct_rejection'] ?? null;

        return PriceObservation::create([
            'product_source_id' => $source->id,
            'regular_price' => $offer->price,
            'in_stock' => true,
            'seller' => $offer->storeName,
            'raw_title' => $title,
            'is_mismatch' => $rejection !== null,
            'mismatch_reason' => $rejection,
            'is_suspicious' => $isSuspicious,
            'sanity_check_reason' => $isSuspicious ? $result['reason'] : null,
            'collected_at' => $collectedAt,
            'metadata' => array_merge([
                'provider' => ProductSource::CollectorGoogleShopping,
                'query' => $query,
                'google_product_id' => $offer->googleProductId,
                'google_link' => $offer->googleLink,
                'store_link' => $offer->storeSearchLink(),
            ], $cached ?? []),
        ])->setRelation('source', $source->setRelation('store', $store));
    }

    /**
     * Resultado da busca de endereço feita há menos de DirectLinkCacheDays para a mesma oferta (fonte, título e preço).
     *
     * @return array{direct_url: string|null, direct_lookup_at: string, direct_verified: bool, direct_rejection: string|null, page_price: float|null}|null
     */
    protected function cachedDirectLookup(ProductSource $source, string $title, ?float $price): ?array
    {
        $previous = PriceObservation::query()
            ->where('product_source_id', $source->id)
            ->where('raw_title', $title)
            ->where('regular_price', $price)
            ->whereNotNull('metadata->direct_lookup_at')
            // Registros anteriores à checagem de preço na página (sem direct_verified) são buscados de novo
            ->whereNotNull('metadata->direct_verified')
            ->latest('id')
            ->first();

        $lookedUpAt = $previous?->metadata['direct_lookup_at'] ?? null;

        if ($lookedUpAt === null || Carbon::parse($lookedUpAt)->lt(Carbon::now()->subDays(self::DirectLinkCacheDays))) {
            return null;
        }

        return [
            'direct_url' => $previous->metadata['direct_url'] ?? null,
            'direct_lookup_at' => $lookedUpAt,
            'direct_verified' => (bool) ($previous->metadata['direct_verified'] ?? false),
            'direct_rejection' => $previous->metadata['direct_rejection'] ?? null,
            'page_price' => $previous->metadata['page_price'] ?? null,
        ];
    }

    /**
     * Procura a página da oferta no site da loja (1 crédito) e abre as páginas encontradas (sem custo).
     * A página com o mesmo preço do Google é o anúncio real: se for outro produto (ex.: usado), a oferta é descartada.
     */
    protected function resolveDirectLink(Product $product, PriceObservation $observation): void
    {
        if (($observation->metadata['direct_lookup_at'] ?? null) !== null) {
            return;
        }

        $price = (float) $observation->regular_price;

        try {
            $pages = $this->serper->findStorePages(new ShoppingOffer((string) $observation->raw_title, (string) $observation->seller, $price));
        } catch (\Throwable $e) {
            Log::warning('Falha ao buscar o endereço da oferta na loja', [
                'observation_id' => $observation->id,
                'loja' => $observation->seller,
                'erro' => $e->getMessage(),
            ]);

            return;
        }

        $chosen = null;
        $verified = false;
        $rejection = null;
        $pagePrices = [];

        foreach (array_slice($pages, 0, self::MaxPagesToVerify) as $page) {
            $check = $this->pageVerifier->verify($page['url'], $price);
            $pagePrices[$page['url']] = $check['page_price'];

            if ($check['status'] !== OfferPageVerifier::Confirmed) {
                continue;
            }

            $validation = $this->validator->validateListing($product, $page['title']);

            if (! $validation->isValid) {
                $rejection = "O anúncio da loja com este preço é outro produto: \"{$page['title']}\" ({$validation->rejectionReason})";
            } else {
                $chosen = $page;
                $verified = true;
            }

            break;
        }

        // Nenhuma página com o mesmo preço (ex.: loja que bloqueia a leitura): primeira página do produto certo, sem garantia de preço
        if ($chosen === null && $rejection === null) {
            $chosen = collect($pages)->first(fn (array $page): bool => $this->validator->validateListing($product, $page['title'])->isValid);
        }

        $observation->update([
            'is_mismatch' => $rejection !== null,
            'mismatch_reason' => $rejection,
            'metadata' => array_merge($observation->metadata ?? [], [
                'direct_url' => $chosen['url'] ?? null,
                'direct_lookup_at' => Carbon::now()->toIso8601String(),
                'direct_verified' => $verified,
                'direct_rejection' => $rejection,
                'page_price' => $chosen !== null ? ($pagePrices[$chosen['url']] ?? null) : null,
            ]),
        ]);

        if ($rejection !== null) {
            Log::info('Oferta descartada: a página da loja com o mesmo preço é de outro produto', [
                'observation_id' => $observation->id,
                'loja' => $observation->seller,
                'motivo' => $rejection,
            ]);
        }

        if ($chosen !== null) {
            $observation->source->update(['url' => $chosen['url']]);
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
