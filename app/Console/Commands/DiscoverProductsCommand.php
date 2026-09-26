<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Discovery\ProductDiscoveryService;
use Illuminate\Console\Command;

class DiscoverProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricewatch:discover {--product= : Specific Product ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Descobre automaticamente novas fontes e ofertas para produtos ativos';

    /**
     * Execute the console command.
     */
    public function handle(ProductDiscoveryService $discoveryService): int
    {
        $productId = $this->option('product');
        $query = Product::query()->where('active', true);

        if ($productId) {
            $query->where('id', $productId);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->info('Nenhum produto ativo encontrado para descoberta.');

            return self::SUCCESS;
        }

        $this->info("Iniciando descoberta de ofertas para {$products->count()} produto(s)...");

        $totalFound = 0;
        $totalApproved = 0;
        $totalPending = 0;
        $totalRejected = 0;

        foreach ($products as $product) {
            $this->line("Pesquisando ofertas para: [{$product->id}] {$product->name}...");
            $summary = $discoveryService->discoverForProduct($product, triggerType: 'scheduled');

            $totalFound += $summary['candidates_found'];
            $totalApproved += $summary['candidates_auto_approved'];
            $totalPending += $summary['candidates_pending'];
            $totalRejected += $summary['candidates_rejected'];

            $this->info(" -> Encontradas: {$summary['candidates_found']} | Auto-aprovadas: {$summary['candidates_auto_approved']} | Pendentes: {$summary['candidates_pending']} | Rejeitadas: {$summary['candidates_rejected']}");

            // Throttle to respect quota and prevent bursting
            usleep(rand(1000000, 2000000));
        }

        $this->newLine();
        $this->info("Descoberta concluída: {$totalFound} analisadas, {$totalApproved} auto-aprovadas, {$totalPending} pendentes de revisão, {$totalRejected} rejeitadas.");

        return self::SUCCESS;
    }
}
