<?php

namespace App\Services\Discovery;

use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryRun;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\Store;
use App\Services\Discovery\Contracts\DiscoveryProviderInterface;
use App\Services\Discovery\DTOs\RawCandidateDto;
use App\Services\Discovery\Providers\DirectStoreDiscoveryProvider;
use App\Services\Discovery\Providers\TavilyDiscoveryProvider;
use App\Services\Profiling\ProductEnrichmentService;
use Illuminate\Support\Facades\Log;

class ProductDiscoveryService
{
    /**
     * @var array<DiscoveryProviderInterface>
     */
    protected array $providers = [];

    public function __construct(
        protected CandidateValidator $validator,
        protected CandidateScorer $scorer,
        protected ProductEnrichmentService $profiler
    ) {
        $this->registerProvider(new DirectStoreDiscoveryProvider);
        $this->registerProvider(new TavilyDiscoveryProvider);
    }

    public function registerProvider(DiscoveryProviderInterface $provider): self
    {
        $this->providers[$provider->getName()] = $provider;

        return $this;
    }

    /**
     * Set explicit list of providers (useful for testing with MockDiscoveryProvider).
     *
     * @param  array<DiscoveryProviderInterface>  $providers
     */
    public function setProviders(array $providers): self
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }

        return $this;
    }

    /**
     * Execute discovery run for a specific product.
     *
     * @param  array<string>|null  $queries
     */
    public function discoverForProduct(Product $product, ?array $queries = null, string $triggerType = 'manual'): array
    {
        $startTime = microtime(true);

        // 1. Generate or prepare queries
        if (empty($queries)) {
            $profile = $this->profiler->profileFromText($product->name, $product->target_price ? (float) $product->target_price : null);
            $queries = $profile->suggestedQueries;
        }

        // 2. Create DiscoveryRun audit record
        $run = DiscoveryRun::create([
            'product_id' => $product->id,
            'provider' => 'multi',
            'trigger_type' => $triggerType,
            'status' => 'running',
            'queries_executed' => $queries,
            'candidates_found' => 0,
            'candidates_auto_approved' => 0,
            'candidates_pending' => 0,
            'candidates_rejected' => 0,
            'started_at' => now(),
        ]);

        $candidatesFound = 0;
        $candidatesAutoApproved = 0;
        $candidatesPending = 0;
        $candidatesRejected = 0;

        // Existing source hashes and candidate hashes for deduplication
        $existingSourceUrls = ProductSource::where('product_id', $product->id)->pluck('url')->toArray();
        $seenHashes = [];

        try {
            foreach ($this->providers as $provider) {
                if (! $provider->isAvailable()) {
                    continue;
                }

                /** @var array<RawCandidateDto> $rawCandidates */
                $rawCandidates = $provider->search($product, $queries);

                foreach ($rawCandidates as $rawCandidate) {
                    $urlHash = $rawCandidate->urlHash;
                    if (isset($seenHashes[$urlHash])) {
                        continue;
                    }
                    $seenHashes[$urlHash] = true;
                    $candidatesFound++;

                    // Check if URL is already monitored in product sources
                    if (in_array($rawCandidate->url, $existingSourceUrls, true)) {
                        continue;
                    }

                    // Check if already exists in candidates table
                    $existingCandidate = DiscoveryCandidate::where('product_id', $product->id)
                        ->where('url_hash', $urlHash)
                        ->first();

                    if ($existingCandidate && in_array($existingCandidate->status, ['approved', 'auto_approved'], true)) {
                        continue;
                    }

                    // Validate Candidate
                    $validation = $this->validator->validate($product, $rawCandidate);

                    // Score Candidate
                    $scoring = $this->scorer->score($product, $rawCandidate, $validation);

                    // Find or create Store
                    $domain = $rawCandidate->getDomain();
                    $cleanDomain = preg_replace('/^www\./', '', $domain);
                    $store = null;
                    if (! empty($cleanDomain)) {
                        $store = Store::firstOrCreate(
                            ['domain' => $cleanDomain],
                            [
                                'name' => ucfirst(explode('.', $cleanDomain)[0]),
                                'active' => true,
                                'trust_level' => 'standard',
                            ]
                        );
                    }

                    // Save / update DiscoveryCandidate
                    $candidateModel = DiscoveryCandidate::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'url_hash' => $urlHash,
                        ],
                        [
                            'store_id' => $store?->id,
                            'discovered_store_name' => $store?->name ?? $cleanDomain,
                            'discovered_store_domain' => $cleanDomain,
                            'url' => $rawCandidate->url,
                            'raw_title' => $rawCandidate->title,
                            'detected_model' => $validation->matches['model_code_matched'] ?? false ? $product->model_code : null,
                            'confidence_score' => $scoring['score'],
                            'scoring_breakdown' => $scoring['breakdown'],
                            'status' => $scoring['status'],
                            'rejection_reason' => $scoring['rejection_reason'],
                            'discovery_provider' => $provider->getName(),
                            'metadata' => [
                                'snippet' => $rawCandidate->snippet,
                                'raw_payload' => $rawCandidate->rawPayload,
                            ],
                            'discovered_at' => now(),
                        ]
                    );

                    // Update counts
                    if ($scoring['status'] === 'auto_approved') {
                        $candidatesAutoApproved++;

                        // Automatically create ProductSource for auto-approved candidates
                        $source = ProductSource::firstOrCreate(
                            [
                                'product_id' => $product->id,
                                'url' => $rawCandidate->url,
                            ],
                            [
                                'store_id' => $store?->id,
                                'collector_type' => 'generic_jsonld',
                                'expected_model' => $product->model_code,
                                'expected_voltage' => $product->voltage,
                                'active' => true,
                                'status' => 'active',
                                'priority' => 10,
                                'discovery_candidate_id' => $candidateModel->id,
                            ]
                        );

                        $candidateModel->update([
                            'product_source_id' => $source->id,
                        ]);
                    } elseif ($scoring['status'] === 'pending_review') {
                        $candidatesPending++;
                    } else {
                        $candidatesRejected++;
                    }
                }
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => 'completed',
                'candidates_found' => $candidatesFound,
                'candidates_auto_approved' => $candidatesAutoApproved,
                'candidates_pending' => $candidatesPending,
                'candidates_rejected' => $candidatesRejected,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Discovery run failed for product {$product->id}: {$e->getMessage()}");

            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'finished_at' => now(),
            ]);
        }

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'candidates_found' => $candidatesFound,
            'candidates_auto_approved' => $candidatesAutoApproved,
            'candidates_pending' => $candidatesPending,
            'candidates_rejected' => $candidatesRejected,
            'duration_ms' => $run->duration_ms,
        ];
    }
}
