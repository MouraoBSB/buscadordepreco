<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Shopping\ShoppingPriceRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShoppingPricesCommand extends Command
{
    /**
     * Nome e assinatura do comando.
     *
     * @var string
     */
    protected $signature = 'pricewatch:shopping {--product= : ID de um produto específico}';

    /**
     * Descrição do comando.
     *
     * @var string
     */
    protected $description = 'Busca os produtos ativos no Google Shopping, grava as ofertas no histórico e avalia alertas';

    /**
     * Executa o comando.
     */
    public function handle(ShoppingPriceRecorder $recorder): int
    {
        if (! $recorder->isConfigured()) {
            $this->error('SERPER_API_KEY não configurada no .env.');

            return self::FAILURE;
        }

        $products = Product::query()
            ->where('active', true)
            ->when($this->option('product'), fn ($query, $productId) => $query->whereKey($productId))
            ->get();

        if ($products->isEmpty()) {
            $this->info('Nenhum produto ativo encontrado.');

            return self::SUCCESS;
        }

        $rows = [];
        $failures = 0;

        foreach ($products as $product) {
            try {
                $summary = $recorder->record($product);
                $best = $summary['best'];

                $rows[] = [
                    Str::limit($summary['query'], 40),
                    $summary['listed'],
                    $summary['accepted'],
                    $summary['suspicious'],
                    $summary['recorded'],
                    $best ? 'R$ '.number_format((float) $best->regular_price, 2, ',', '.') : '-',
                    $best ? Str::limit((string) $best->seller, 18) : '-',
                ];
            } catch (\Throwable $e) {
                $failures++;
                Log::error('Falha na busca do Google Shopping', [
                    'product_id' => $product->id,
                    'produto' => $product->name,
                    'erro' => $e->getMessage(),
                ]);
                $rows[] = [Str::limit($product->name, 40), '-', '-', '-', '-', 'FALHA', Str::limit($e->getMessage(), 40)];
            }
        }

        $this->table(['Produto', 'No Shopping', 'Aceitas', 'Suspeitas', 'Gravadas', 'Melhor preço', 'Loja'], $rows);
        $this->line("Créditos Serper usados: {$recorder->creditsUsed()}");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
