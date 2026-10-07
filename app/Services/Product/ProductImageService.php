<?php

namespace App\Services\Product;

use App\Models\ApiUsageLog;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductImageService
{
    /**
     * Search for a suitable product image URL via Tavily Image Search.
     */
    public function searchProductImage(Product|string $productOrQuery): ?string
    {
        $apiKey = config('services.tavily.key');
        if (empty($apiKey)) {
            return null;
        }

        $query = $productOrQuery instanceof Product
            ? trim(($productOrQuery->brand ? $productOrQuery->brand.' ' : '').($productOrQuery->commercial_name ?: $productOrQuery->name))
            : trim($productOrQuery);

        if (empty($query)) {
            return null;
        }

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
                ->timeout(10)
                ->post('https://api.tavily.com/search', [
                    'api_key' => $apiKey,
                    'query' => $query.' produto oficial foto',
                    'include_images' => true,
                    'max_results' => 3,
                ]);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $images = $response->json('images', []);

                ApiUsageLog::logRequest(
                    provider: 'tavily',
                    purpose: 'image_search',
                    query: $query,
                    credits: 1,
                    status: $response->status(),
                    durationMs: $durationMs,
                    metadata: ['images_count' => count($images)]
                );

                foreach ($images as $img) {
                    $url = is_string($img) ? $img : ($img['url'] ?? null);
                    if ($url && $this->isValidImageUrl($url)) {
                        return $url;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("ProductImageService failed to fetch image for '{$query}': ".$e->getMessage());
        }

        return null;
    }

    /**
     * Fetch and assign an image to a Product if it currently has no image.
     */
    public function fetchAndAssignImage(Product $product): ?string
    {
        if (! blank($product->image_url)) {
            return $product->image_url;
        }

        $imageUrl = $this->searchProductImage($product);
        if ($imageUrl) {
            $product->update(['image_url' => $imageUrl]);
            Log::info("ProductImageService: Assigned automatic image to product #{$product->id} ({$product->name}): {$imageUrl}");

            return $imageUrl;
        }

        return null;
    }

    /**
     * Validate image URL to avoid tracking pixels or non-image assets.
     */
    protected function isValidImageUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $cleanUrl = strtok($url, '?');
        $ext = strtolower(pathinfo($cleanUrl, PATHINFO_EXTENSION));

        // Accept common image extensions or URLs containing known image path indicators
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            return true;
        }

        if (str_contains($url, 'scene7.com') || str_contains($url, 'images') || str_contains($url, 'media') || str_contains($url, 'assets')) {
            return true;
        }

        return false;
    }
}
