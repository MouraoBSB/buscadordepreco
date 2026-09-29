<?php

namespace App\Services\Discovery\Providers;

use App\Models\ApiUsageLog;
use App\Models\Product;
use App\Services\Discovery\Contracts\DiscoveryProviderInterface;
use App\Services\Discovery\DTOs\RawCandidateDto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TavilyDiscoveryProvider implements DiscoveryProviderInterface
{
    public function getName(): string
    {
        return 'tavily';
    }

    public function isAvailable(): bool
    {
        $key = config('services.tavily.key');
        if (empty($key)) {
            return false;
        }

        // Quota safeguard: Do not execute discovery if >= 95% of quota used
        $monthlyLimit = (int) config('services.tavily.monthly_limit', 1000);
        $used = ApiUsageLog::getMonthlyUsage('tavily');

        return $used < ($monthlyLimit * 0.95);
    }

    public function search(Product $product, array $queries): array
    {
        $apiKey = config('services.tavily.key');
        if (empty($apiKey) || ! $this->isAvailable()) {
            Log::warning('TavilyDiscoveryProvider skipped: unavailable or near quota limit.');

            return [];
        }

        $candidates = [];
        // Use up to 2 queries per discovery run to conserve quota
        $queriesToRun = array_slice($queries, 0, 2);

        foreach ($queriesToRun as $query) {
            $startTime = microtime(true);

            try {
                $searchQuery = $query.' comprar loja preco brasil';

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])
                    ->timeout(12)
                    ->post('https://api.tavily.com/search', [
                        'api_key' => $apiKey,
                        'query' => $searchQuery,
                        'search_depth' => 'basic',
                        'include_images' => true,
                        'max_results' => 8,
                        'include_domains' => [
                            'magazineluiza.com.br',
                            'mercadolivre.com.br',
                            'casasbahia.com.br',
                            'fastshop.com.br',
                            'carrefour.com.br',
                            'pontofrio.com.br',
                            'extra.com.br',
                            'amazon.com.br',
                            'mideastore.com.br',
                            'midea.com.br',
                            'panasonic.com.br',
                            'samsung.com',
                        ],
                    ]);

                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                $status = $response->status();
                $results = $response->successful() ? $response->json('results', []) : [];

                // If product currently lacks a photo, auto-enrich it from discovery images
                if (blank($product->getRawOriginal('image_url')) && ! empty($response->json('images'))) {
                    $images = $response->json('images');
                    $firstImg = is_string($images[0]) ? $images[0] : ($images[0]['url'] ?? null);
                    if ($firstImg) {
                        $product->update(['image_url' => $firstImg]);
                    }
                }

                // Audit usage log
                ApiUsageLog::logRequest(
                    provider: 'tavily',
                    purpose: 'discovery',
                    query: $query,
                    credits: 1,
                    status: $status,
                    durationMs: $durationMs,
                    metadata: ['results_count' => count($results)]
                );

                if (! $response->successful()) {
                    Log::error("Tavily search failed [{$status}]: ".$response->body());

                    continue;
                }

                foreach ($results as $res) {
                    $url = $res['url'] ?? '';
                    $title = $res['title'] ?? '';
                    $snippet = $res['content'] ?? '';

                    if (empty($url) || empty($title)) {
                        continue;
                    }

                    $candidates[] = new RawCandidateDto(
                        url: $url,
                        title: $title,
                        snippet: $snippet,
                        provider: 'tavily',
                        rawPayload: $res
                    );
                }
            } catch (\Throwable $e) {
                Log::error("Tavily exception during search: {$e->getMessage()}");
            }

            // Small jitter between queries
            usleep(rand(300000, 700000));
        }

        return $candidates;
    }
}
