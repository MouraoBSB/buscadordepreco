<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Shopping\OfferPageVerifier;
use App\Services\Shopping\SerperShoppingClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProbeOffersCommand extends Command
{
    /**
     * Nome e assinatura do comando.
     *
     * @var string
     */
    protected $signature = 'pricewatch:probe
        {query? : Termo de busca livre (ex: "galaxy s25 256gb")}
        {--products : Usa os produtos ativos cadastrados em vez de um termo livre}
        {--limit=8 : Máximo de ofertas verificadas ao vivo por busca (cada uma gasta 1 crédito)}';

    /**
     * Descrição do comando.
     *
     * @var string
     */
    protected $description = 'Fase 0: busca ofertas reais no Google Shopping (Serper) e confere cada link ao vivo na loja';

    /**
     * Executa o comando.
     */
    public function handle(SerperShoppingClient $serper, OfferPageVerifier $verifier): int
    {
        if (! $serper->isConfigured()) {
            $this->error('SERPER_API_KEY não configurada no .env.');

            return self::FAILURE;
        }

        $queries = $this->resolveQueries();

        if ($queries === []) {
            $this->error('Informe um termo de busca ou use --products.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $summary = [];

        foreach ($queries as $query) {
            $summary[$query] = $this->probeQuery($query, $limit, $serper, $verifier);
        }

        $this->newLine();
        $this->info('Resumo');
        $this->table(
            ['Busca', 'Ofertas no Shopping', 'Verificadas', 'Confirmadas', 'Preço diferente', 'Bloqueadas', 'Quebradas/indisp.'],
            collect($summary)->map(fn (array $counts, string $query): array => [
                Str::limit($query, 40),
                $counts['listed'],
                $counts['checked'],
                $counts[OfferPageVerifier::Confirmed] ?? 0,
                $counts[OfferPageVerifier::PriceDiffers] ?? 0,
                $counts[OfferPageVerifier::Blocked] ?? 0,
                ($counts[OfferPageVerifier::Broken] ?? 0) + ($counts[OfferPageVerifier::OutOfStock] ?? 0),
            ])->values()->all(),
        );
        $this->line("Créditos Serper usados: {$serper->creditsUsed}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveQueries(): array
    {
        if ($this->option('products')) {
            return Product::query()
                ->where('active', true)
                ->get()
                ->map(function (Product $product): string {
                    $query = $product->name;
                    $modelCode = (string) $product->model_code;

                    if (preg_match('/\d/', $modelCode) && ! Str::contains($query, $modelCode, ignoreCase: true)) {
                        $query .= ' '.$modelCode;
                    }

                    return $query;
                })
                ->all();
        }

        $query = trim((string) $this->argument('query'));

        return $query === '' ? [] : [$query];
    }

    /**
     * @return array<string, int>
     */
    protected function probeQuery(string $query, int $limit, SerperShoppingClient $serper, OfferPageVerifier $verifier): array
    {
        $this->newLine();
        $this->info("Busca: \"{$query}\"");

        $offers = $serper->searchOffers($query);
        $counts = ['listed' => count($offers), 'checked' => 0];

        if ($offers === []) {
            $this->warn('  Nenhuma oferta no Google Shopping.');

            return $counts;
        }

        $rows = [];

        foreach (array_slice($offers, 0, $limit) as $offer) {
            $url = $serper->findStoreUrl($offer);
            $verification = $url !== null
                ? $verifier->verify($url, $offer->price)
                : ['status' => 'sem_link', 'page_price' => null, 'http_status' => null, 'in_stock' => null];

            $counts['checked']++;
            $counts[$verification['status']] = ($counts[$verification['status']] ?? 0) + 1;

            $rows[] = [
                Str::limit($offer->storeName, 18),
                Str::limit($offer->title, 45),
                $this->formatPrice($offer->price),
                $this->formatPrice($verification['page_price']),
                $verification['status'].($verification['http_status'] && $verification['http_status'] !== 200 ? " ({$verification['http_status']})" : ''),
                $url ?? '-',
            ];
        }

        $this->table(['Loja', 'Título', 'Preço Google', 'Preço na página', 'Status', 'Link'], $rows);

        return $counts;
    }

    protected function formatPrice(?float $price): string
    {
        return $price === null ? '-' : 'R$ '.number_format($price, 2, ',', '.');
    }
}
