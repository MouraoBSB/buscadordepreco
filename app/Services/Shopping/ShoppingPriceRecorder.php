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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Busca o produto no Google Shopping, grava as ofertas aceitas no histórico e avalia alertas.
 */
class ShoppingPriceRecorder
{
    public function __construct(
        protected SerperShoppingClient $serper,
        protected ShoppingOfferMatcher $matcher,
        protected AlertEvaluator $alertEvaluator,
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

        $best = collect($observations)
            ->reject(fn (PriceObservation $observation): bool => $observation->is_suspicious)
            ->sortBy(fn (PriceObservation $observation): float => (float) $observation->regular_price)
            ->first();

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
                'url' => $offer->googleLink ?? '',
                'expected_model' => $product->model_code,
                'expected_voltage' => $product->voltage ?: 'Bivolt',
                'active' => true,
                'status' => 'active',
                'priority' => 5,
            ],
        );

        $source->update([
            'url' => $offer->googleLink ?? $source->url,
            'last_checked_at' => $collectedAt,
        ]);

        $isSuspicious = $result['status'] === ShoppingOfferMatcher::Suspicious;

        return PriceObservation::create([
            'product_source_id' => $source->id,
            'regular_price' => $offer->price,
            'in_stock' => true,
            'seller' => $offer->storeName,
            'raw_title' => Str::limit($offer->title, 495),
            'is_mismatch' => false,
            'is_suspicious' => $isSuspicious,
            'sanity_check_reason' => $isSuspicious ? $result['reason'] : null,
            'collected_at' => $collectedAt,
            'metadata' => [
                'provider' => ProductSource::CollectorGoogleShopping,
                'query' => $query,
                'google_product_id' => $offer->googleProductId,
                'google_link' => $offer->googleLink,
            ],
        ]);
    }

    /**
     * Loja conhecida pelo domínio; loja nova do Google Shopping é identificada pelo nome.
     */
    protected function resolveStore(string $storeName): Store
    {
        $domain = $this->serper->domainForStore($storeName);

        if ($domain !== null) {
            return Store::firstOrCreate(
                ['domain' => $domain],
                ['name' => $storeName, 'active' => true, 'trust_level' => 'standard'],
            );
        }

        return Store::firstOrCreate(
            ['name' => $storeName, 'domain' => ''],
            ['active' => true, 'trust_level' => 'standard'],
        );
    }
}
