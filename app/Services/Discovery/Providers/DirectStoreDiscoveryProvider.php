<?php

namespace App\Services\Discovery\Providers;

use App\Models\Product;
use App\Services\Discovery\Contracts\DiscoveryProviderInterface;
use App\Services\Discovery\DTOs\RawCandidateDto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DirectStoreDiscoveryProvider implements DiscoveryProviderInterface
{
    /**
     * Map of brand/retail stores supporting public catalog APIs (VTEX).
     */
    protected array $vtexStores = [
        'panasonic' => [
            'domain' => 'loja.panasonic.com.br',
            'endpoint' => 'https://loja.panasonic.com.br/api/catalog_system/pub/products/search',
        ],
        'midea' => [
            'domain' => 'mideastore.com.br',
            'endpoint' => 'https://www.mideastore.com.br/api/catalog_system/pub/products/search',
        ],
    ];

    public function getName(): string
    {
        return 'direct_store';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function search(Product $product, array $queries): array
    {
        $candidates = [];
        $brandLower = mb_strtolower($product->brand ?? '', 'UTF-8');

        // Identify relevant store connectors
        $targetStores = [];
        foreach ($this->vtexStores as $brandKey => $storeConfig) {
            if (empty($brandLower) || str_contains($brandLower, $brandKey)) {
                $targetStores[] = $storeConfig;
            }
        }

        if (empty($targetStores)) {
            return [];
        }

        $query = $product->model_code ?: ($queries[0] ?? $product->name);

        foreach ($targetStores as $store) {
            try {
                $response = Http::timeout(8)
                    ->get($store['endpoint'], [
                        'ft' => $query,
                        '_from' => 0,
                        '_to' => 4,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $items = $response->json();
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $url = $item['link'] ?? '';
                    $title = $item['productName'] ?? '';
                    $description = $item['description'] ?? '';

                    if (empty($url) || empty($title)) {
                        continue;
                    }

                    $candidates[] = new RawCandidateDto(
                        url: $url,
                        title: $title,
                        snippet: strip_tags($description),
                        provider: 'direct_store',
                        rawPayload: [
                            'productId' => $item['productId'] ?? null,
                            'brand' => $item['brand'] ?? null,
                            'store' => $store['domain'],
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("DirectStore search exception for {$store['domain']}: {$e->getMessage()}");
            }
        }

        return $candidates;
    }
}
