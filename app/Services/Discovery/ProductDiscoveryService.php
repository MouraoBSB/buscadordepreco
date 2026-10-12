<?php

namespace App\Services\Discovery;

use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryRun;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\Store;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Collector\Pipeline\CollectionPipeline;
use App\Services\Discovery\Contracts\DiscoveryProviderInterface;
use App\Services\Discovery\DTOs\RawCandidateDto;
use App\Services\Discovery\Providers\DirectStoreDiscoveryProvider;
use App\Services\Discovery\Providers\TavilyDiscoveryProvider;
use App\Services\Profiling\ProductEnrichmentService;
use Illuminate\Support\Facades\Log;

class ProductDiscoveryService
{
    /**
     * @var array<string, DiscoveryProviderInterface>
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
            $input = $product->name;
            if ($product->model_code && ! str_contains($input, $product->model_code)) {
                $input .= ' '.$product->model_code;
            }
            if ($product->voltage && ! str_contains($input, $product->voltage)) {
                $input .= ' '.$product->voltage;
            }
            $profile = $this->profiler->profileFromText($input, $product->target_price ? (float) $product->target_price : null);
            $queries = $profile->suggestedQueries;
        }

        // 2. Create DiscoveryRun audit record
        $run = DiscoveryRun::create([
            'product_id' => $product->id,
            'provider' => 'hybrid',
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
        $providerStats = [];

        try {
            foreach ($this->providers as $providerName => $provider) {
                if (! $provider->isAvailable()) {
                    $providerStats[$providerName] = [
                        'status' => 'skipped',
                        'reason' => 'Provedor indisponível ou limite de cota atingido (circuit breaker ativo).',
                        'duration_ms' => 0,
                        'candidates_found' => 0,
                    ];

                    continue;
                }

                $providerStart = microtime(true);
                $rawCandidates = [];

                // Isolated execution per provider (Resilience - failure in one does not abort the run)
                try {
                    /** @var array<RawCandidateDto> $rawCandidates */
                    $rawCandidates = $provider->search($product, $queries);
                    $providerDuration = (int) round((microtime(true) - $providerStart) * 1000);

                    $providerStats[$providerName] = [
                        'status' => 'success',
                        'candidates_found' => count($rawCandidates),
                        'duration_ms' => $providerDuration,
                    ];
                } catch (\Throwable $providerEx) {
                    $providerDuration = (int) round((microtime(true) - $providerStart) * 1000);
                    Log::error("Discovery provider [{$providerName}] failed for product {$product->id}: {$providerEx->getMessage()}");

                    $providerStats[$providerName] = [
                        'status' => 'failed',
                        'error' => $providerEx->getMessage(),
                        'duration_ms' => $providerDuration,
                        'candidates_found' => 0,
                    ];

                    continue;
                }

                foreach ($rawCandidates as $rawCandidate) {
                    $urlHash = $rawCandidate->getUrlHash();

                    // Deduplication without losing provenance (User Adjustment #1)
                    if (isset($seenHashes[$urlHash])) {
                        $existingCandidate = DiscoveryCandidate::where('product_id', $product->id)
                            ->where('url_hash', $urlHash)
                            ->first();

                        if ($existingCandidate) {
                            $meta = $existingCandidate->metadata ?? [];
                            $providersList = $meta['providers'] ?? [$existingCandidate->discovery_provider];

                            if (! in_array($providerName, $providersList, true)) {
                                $providersList[] = $providerName;
                                $meta['providers'] = array_values(array_unique($providersList));

                                $scoring = $existingCandidate->scoring_breakdown ?? [];
                                $updates = [
                                    'metadata' => $meta,
                                    'discovery_provider' => implode(', ', $providersList),
                                ];

                                // Multi-provider corroboration bonus (+5 pts)
                                if (! isset($scoring['multi_provider_corroboration'])) {
                                    $scoring['multi_provider_corroboration'] = 5;
                                    $newScore = min(100, (int) $existingCandidate->confidence_score + 5);
                                    $updates['confidence_score'] = $newScore;
                                    $updates['scoring_breakdown'] = $scoring;

                                    if ($newScore >= 85 && $existingCandidate->status === 'pending_review') {
                                        $updates['status'] = 'auto_approved';
                                    }
                                }

                                $existingCandidate->update($updates);

                                if ($existingCandidate->status === 'auto_approved') {
                                    $this->ensureSourceForCandidate($product, $existingCandidate);
                                    if ($existingCandidate->detected_price && (float) $existingCandidate->detected_price > 0) {
                                        try {
                                            app(AlertEvaluator::class)->evaluateCandidate($existingCandidate);
                                        } catch (\Throwable $e) {
                                            Log::warning("Candidate alert evaluation failed for existing candidate {$existingCandidate->id}: {$e->getMessage()}");
                                        }
                                    }
                                }
                            }
                        }

                        continue;
                    }

                    $seenHashes[$urlHash] = true;
                    $candidatesFound++;

                    // Check if URL is already monitored in product sources
                    if (in_array($rawCandidate->url, $existingSourceUrls, true)) {
                        continue;
                    }

                    // Check if already exists in candidates table from previous runs
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

                    // Extract price if available
                    $detectedPrice = $rawCandidate->price;
                    if (! $detectedPrice) {
                        $textToScan = ($rawCandidate->snippet ?? '').' '.$rawCandidate->title;
                        if (preg_match('/R\$\s*([\d\.]+(?:,\d{2})?)/iu', $textToScan, $mPrice)) {
                            $cleanPrice = str_replace('.', '', $mPrice[1]);
                            $cleanPrice = str_replace(',', '.', $cleanPrice);
                            $detectedPrice = (float) $cleanPrice;
                        }
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
                            'detected_price' => $detectedPrice,
                            'confidence_score' => $scoring['score'],
                            'scoring_breakdown' => $scoring['breakdown'],
                            'status' => $scoring['status'],
                            'rejection_reason' => $scoring['rejection_reason'],
                            'discovery_provider' => $providerName,
                            'metadata' => [
                                'providers' => [$providerName],
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
                        $this->ensureSourceForCandidate($product, $candidateModel, $store);

                        // Evaluate alerts for auto-approved candidate if price detected
                        if ($candidateModel->detected_price && (float) $candidateModel->detected_price > 0) {
                            try {
                                app(AlertEvaluator::class)->evaluateCandidate($candidateModel);
                            } catch (\Throwable $e) {
                                Log::warning("Candidate alert evaluation failed for candidate {$candidateModel->id}: {$e->getMessage()}");
                            }
                        }
                    } elseif ($scoring['status'] === 'pending_review') {
                        $candidatesPending++;
                    } else {
                        $candidatesRejected++;
                    }
                }

                // Controlled sequential pacing (User Adjustment #2)
                usleep(rand(400000, 700000)); // 400ms - 700ms jitter between providers
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => 'completed',
                'provider_stats' => $providerStats,
                'candidates_found' => $candidatesFound,
                'candidates_auto_approved' => $candidatesAutoApproved,
                'candidates_pending' => $candidatesPending,
                'candidates_rejected' => $candidatesRejected,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Discovery run critical error for product {$product->id}: {$e->getMessage()}");

            $run->update([
                'status' => 'failed',
                'provider_stats' => $providerStats,
                'error_message' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'finished_at' => now(),
            ]);
        }

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'provider_stats' => $providerStats,
            'candidates_found' => $candidatesFound,
            'candidates_auto_approved' => $candidatesAutoApproved,
            'candidates_pending' => $candidatesPending,
            'candidates_rejected' => $candidatesRejected,
            'duration_ms' => $run->duration_ms,
        ];
    }

    /**
     * Ensure a ProductSource exists for an auto-approved or approved candidate.
     */
    public function ensureSourceForCandidate(Product $product, DiscoveryCandidate $candidate, ?Store $store = null): ?ProductSource
    {
        if ($candidate->product_source_id) {
            $existing = ProductSource::find($candidate->product_source_id);
            if ($existing) {
                return $existing;
            }
        }

        if (! $store) {
            $domain = $candidate->discovered_store_domain;
            if (empty($domain) && ! empty($candidate->url)) {
                $parsedHost = parse_url($candidate->url, PHP_URL_HOST);
                if ($parsedHost) {
                    $domain = $parsedHost;
                }
            }

            if (! empty($domain)) {
                $cleanDomain = preg_replace('/^www\./', '', $domain);
                $storeName = $candidate->discovered_store_name;
                if (empty($storeName) || $storeName === $domain) {
                    $storeName = ucfirst(explode('.', $cleanDomain)[0]);
                }

                $store = Store::firstOrCreate(
                    ['domain' => $cleanDomain],
                    [
                        'name' => $storeName,
                        'active' => true,
                        'trust_level' => 'standard',
                    ]
                );

                if (empty($candidate->discovered_store_domain)) {
                    $candidate->update(['discovered_store_domain' => $cleanDomain]);
                }
            }
        }

        if (! $store) {
            $store = Store::firstOrCreate(
                ['domain' => 'loja-online.com'],
                [
                    'name' => 'Loja Online',
                    'active' => true,
                    'trust_level' => 'standard',
                ]
            );
        }

        $source = ProductSource::firstOrCreate(
            [
                'product_id' => $product->id,
                'url' => $candidate->url,
            ],
            [
                'store_id' => $store?->id,
                'collector_type' => 'generic_jsonld',
                'expected_model' => $product->model_code,
                'expected_voltage' => $product->voltage ?: 'Bivolt',
                'active' => true,
                'status' => 'active',
                'priority' => 10,
                'discovery_candidate_id' => $candidate->id,
            ]
        );

        if ($candidate->product_source_id !== $source->id) {
            $candidate->update(['product_source_id' => $source->id]);
        }

        if ($source->wasRecentlyCreated) {
            dispatch(function () use ($source) {
                try {
                    app(CollectionPipeline::class)->run($source);
                } catch (\Throwable $e) {
                    Log::warning("Immediate collection failed for auto-approved source {$source->id}: {$e->getMessage()}");
                }
            })->afterResponse();
        }

        return $source;
    }
}
