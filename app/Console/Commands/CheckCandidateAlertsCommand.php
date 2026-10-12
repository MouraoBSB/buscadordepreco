<?php

namespace App\Console\Commands;

use App\Models\DiscoveryCandidate;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Discovery\ProductDiscoveryService;
use Illuminate\Console\Command;

class CheckCandidateAlertsCommand extends Command
{
    protected $signature = 'pricewatch:check-candidate-alerts {--product= : ID do produto para filtrar}';

    protected $description = 'Avalia regras de alerta para candidatos da busca auto-aprovados ou aprovados e garante fontes ativas';

    public function handle(AlertEvaluator $evaluator, ProductDiscoveryService $discoveryService): int
    {
        $productId = $this->option('product');

        $query = DiscoveryCandidate::query()
            ->with(['product.alertRules'])
            ->whereIn('status', ['auto_approved', 'approved'])
            ->whereNotNull('detected_price')
            ->where('detected_price', '>', 0);

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $candidates = $query->get();

        $this->info("Analisando {$candidates->count()} candidato(s) com preço detectado...");

        $evaluated = 0;
        $sourcesEnsured = 0;

        foreach ($candidates as $candidate) {
            $product = $candidate->product;
            if (! $product) {
                continue;
            }

            // Ensure source exists if missing
            if (! $candidate->product_source_id) {
                $discoveryService->ensureSourceForCandidate($product, $candidate);
                $sourcesEnsured++;
            }

            $priceFormatted = number_format((float) $candidate->detected_price, 2, ',', '.');
            $this->line("Candidato #{$candidate->id} ({$candidate->discovered_store_name}): R$ {$priceFormatted} para '{$product->name}'");

            $evaluator->evaluateCandidate($candidate);
            $evaluated++;
        }

        $this->newLine();
        $this->info("Concluído: {$evaluated} candidato(s) avaliados, {$sourcesEnsured} fonte(s) vinculadas.");

        return self::SUCCESS;
    }
}
