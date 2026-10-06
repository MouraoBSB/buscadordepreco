<?php

namespace App\Console\Commands;

use App\Models\CollectionRun;
use App\Models\DiscoveryCandidate;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\ProductSource;
use App\Services\Discovery\CandidateScorer;
use App\Services\Discovery\CandidateValidator;
use App\Services\Discovery\DTOs\RawCandidateDto;
use App\Services\Profiling\ProductEnrichmentService;
use Illuminate\Console\Command;

class RecalculateCandidatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricewatch:rescore {--product= : ID específico do produto para recalcular}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcula o perfil, validação e pontuação de candidatos existentes e limpa fontes inválidas';

    /**
     * Execute the console command.
     */
    public function handle(
        CandidateValidator $validator,
        CandidateScorer $scorer,
        ProductEnrichmentService $profiler
    ): int {
        $productId = $this->option('product');
        $query = Product::query();

        if ($productId) {
            $query->where('id', $productId);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->warn('Nenhum produto encontrado.');

            return self::SUCCESS;
        }

        foreach ($products as $product) {
            $this->info("Recalculando Produto #{$product->id}: {$product->name}...");

            // 1. Re-profile product
            $profile = $profiler->profileFromText($product->name, $product->target_price ? (float) $product->target_price : null);
            $product->update([
                'brand' => $profile->brand,
                'model_code' => $profile->modelCode,
                'commercial_name' => $profile->commercialName,
                'hard_constraints' => $profile->hardConstraints,
                'forbidden_terms' => $profile->forbiddenTerms,
                'required_terms' => $profile->requiredTerms,
                'strict_model' => ! empty($profile->modelCode),
            ]);

            $this->line(' -> Marca: '.var_export($product->brand, true).' | Modelo: '.var_export($product->model_code, true).' | Restrições: '.json_encode($product->hard_constraints));

            $candidates = DiscoveryCandidate::where('product_id', $product->id)->get();
            $approved = 0;
            $pending = 0;
            $rejected = 0;
            $sourcesRemoved = 0;

            foreach ($candidates as $candidate) {
                $dto = new RawCandidateDto(
                    url: $candidate->url,
                    title: $candidate->raw_title,
                    snippet: $candidate->metadata['snippet'] ?? '',
                    provider: $candidate->discovery_provider ?? 'direct_store',
                    rawPayload: $candidate->metadata['raw_payload'] ?? []
                );

                $validation = $validator->validate($product, $dto);
                $scoring = $scorer->score($product, $dto, $validation);

                $oldStatus = $candidate->status;
                $newStatus = $scoring['status'];

                // If candidate is now rejected or pending, remove invalid product_source if one was generated
                if ($newStatus !== 'auto_approved' && $candidate->product_source_id) {
                    $sourceId = $candidate->product_source_id;
                    PriceObservation::where('product_source_id', $sourceId)->delete();
                    CollectionRun::where('product_source_id', $sourceId)->delete();
                    ProductSource::where('id', $sourceId)->delete();
                    $candidate->product_source_id = null;
                    $sourcesRemoved++;
                }

                $candidate->update([
                    'status' => $newStatus,
                    'confidence_score' => $scoring['score'],
                    'scoring_breakdown' => $scoring['breakdown'],
                    'rejection_reason' => $scoring['rejection_reason'],
                    'detected_model' => ! empty($validation->matches['model_code_matched']) ? $product->model_code : null,
                ]);

                if ($newStatus === 'auto_approved') {
                    $approved++;
                } elseif ($newStatus === 'pending_review') {
                    $pending++;
                } else {
                    $rejected++;
                }
            }

            $this->info(" -> Candidatos: {$approved} auto-aprovados, {$pending} pendentes, {$rejected} rejeitados. Fontes inválidas removidas: {$sourcesRemoved}.");
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
