<?php

namespace App\Console\Commands;

use App\Models\ProductSource;
use App\Services\Collector\Pipeline\CollectionPipeline;
use Illuminate\Console\Command;

class CollectPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricewatch:collect {--source= : Specific ProductSource ID} {--product= : Specific Product ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executa a coleta de preços das fontes ativas';

    /**
     * Execute the console command.
     */
    public function handle(CollectionPipeline $pipeline): int
    {
        $sourceId = $this->option('source');
        $productId = $this->option('product');

        $query = ProductSource::query()->where('active', true);

        if ($sourceId) {
            $query->where('id', $sourceId);
        } elseif ($productId) {
            $query->where('product_id', $productId);
        }

        $sources = $query->with(['product', 'store'])->get();

        if ($sources->isEmpty()) {
            $this->info('Nenhuma fonte ativa encontrada para coleta.');

            return self::SUCCESS;
        }

        $this->info("Iniciando coleta para {$sources->count()} fonte(s)...");

        $successCount = 0;
        $failCount = 0;
        $mismatchCount = 0;

        foreach ($sources as $source) {
            $this->line("Coletando: [{$source->store->name}] {$source->product->name}...");

            $result = $pipeline->run($source);

            if ($result->isMismatch) {
                $mismatchCount++;
                $this->warn(" -> MISMATCH: {$result->mismatchReason}");
            } elseif ($result->isSuccess) {
                $successCount++;
                $priceStr = number_format($result->getEffectivePrice() ?? 0, 2, ',', '.');
                $this->info(" -> SUCESSO: R$ {$priceStr} (Em estoque: ".($result->inStock ? 'Sim' : 'Não').')');
            } else {
                $failCount++;
                $this->error(" -> FALHA: {$result->errorMessage}");
            }

            // Sleep with jitter to avoid aggressive requests
            usleep(rand(500000, 1500000)); // 0.5s - 1.5s
        }

        $this->newLine();
        $this->info("Coleta finalizada: {$successCount} sucesso(s), {$mismatchCount} mismatch(es), {$failCount} falha(s).");

        return self::SUCCESS;
    }
}
