<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Shopping\OfferPageVerifier;
use App\Services\Shopping\SerperShoppingClient;
use App\Services\Shopping\ShoppingOffer;
use App\Services\Shopping\ShoppingOfferMatcher;
use App\Services\Shopping\ShoppingPriceRecorder;
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
        {--verify : Também abre a página da loja das ofertas aceitas (1 crédito extra por oferta; lojas com anti-bot falham)}
        {--limit=5 : Máximo de ofertas aceitas verificadas ao vivo com --verify}';

    /**
     * Descrição do comando.
     *
     * @var string
     */
    protected $description = 'Busca ofertas no Google Shopping (Serper), filtra as que são o produto certo e mostra o menor preço';

    /**
     * Executa o comando.
     */
    public function handle(SerperShoppingClient $serper, ShoppingOfferMatcher $matcher, OfferPageVerifier $verifier): int
    {
        if (! $serper->isConfigured()) {
            $this->error('SERPER_API_KEY não configurada no .env.');

            return self::FAILURE;
        }

        $products = $this->resolveProducts();

        if ($products === []) {
            $this->error('Informe um termo de busca ou use --products.');

            return self::FAILURE;
        }

        $summary = [];

        foreach ($products as $product) {
            $summary[] = $this->probeProduct($product, $serper, $matcher, $verifier);
        }

        $this->newLine();
        $this->info('Resumo');
        $this->table(['Busca', 'No Shopping', 'Aceitas', 'Suspeitas', 'Recusadas', 'Menor preço aceito', 'Loja'], $summary);
        $this->line("Créditos Serper usados: {$serper->creditsUsed}");

        return self::SUCCESS;
    }

    /**
     * Produtos ativos cadastrados ou um produto transitório (não salvo) montado a partir do termo livre.
     *
     * @return array<int, Product>
     */
    protected function resolveProducts(): array
    {
        if ($this->option('products')) {
            return Product::query()->where('active', true)->get()->all();
        }

        $query = trim((string) $this->argument('query'));

        return $query === '' ? [] : [new Product(['name' => $query, 'active' => true])];
    }

    /**
     * @return array<int, string|int>
     */
    protected function probeProduct(Product $product, SerperShoppingClient $serper, ShoppingOfferMatcher $matcher, OfferPageVerifier $verifier): array
    {
        $query = app(ShoppingPriceRecorder::class)->searchQueryFor($product);

        $this->newLine();
        $this->info("Busca: \"{$query}\"");

        $results = $matcher->match($product, $serper->searchOffers($query));

        $kept = collect($results)
            ->reject(fn (array $result): bool => $result['status'] === ShoppingOfferMatcher::Rejected)
            ->sortBy(fn (array $result): float => $result['offer']->price)
            ->values();
        $rejected = collect($results)->where('status', ShoppingOfferMatcher::Rejected)->values();

        if ($kept->isEmpty()) {
            $this->warn('  Nenhuma oferta do produto certo no Google Shopping.');
        } else {
            $this->renderKeptOffers($kept->all(), $serper, $verifier);
        }

        if ($rejected->isNotEmpty()) {
            if ($this->output->isVerbose()) {
                $this->table(['Loja recusada', 'Título', 'Preço', 'Motivo'], $rejected->map(fn (array $result): array => [
                    Str::limit($result['offer']->storeName, 18),
                    Str::limit($result['offer']->title, 45),
                    $this->formatPrice($result['offer']->price),
                    $result['reason'],
                ])->all());
            } else {
                $this->line("  {$rejected->count()} ofertas recusadas (use -v para ver os motivos).");
            }
        }

        $best = $kept->firstWhere('status', ShoppingOfferMatcher::Accepted);

        return [
            Str::limit($query, 40),
            count($results),
            $kept->where('status', ShoppingOfferMatcher::Accepted)->count(),
            $kept->where('status', ShoppingOfferMatcher::Suspicious)->count(),
            $rejected->count(),
            $best ? $this->formatPrice($best['offer']->price) : '-',
            $best ? Str::limit($best['offer']->storeName, 18) : '-',
        ];
    }

    /**
     * @param  array<int, array{offer: ShoppingOffer, status: string, reason: string|null}>  $kept
     */
    protected function renderKeptOffers(array $kept, SerperShoppingClient $serper, OfferPageVerifier $verifier): void
    {
        $verify = (bool) $this->option('verify');
        $verifyLimit = max(1, (int) $this->option('limit'));
        $verified = 0;
        $rows = [];

        foreach ($kept as $result) {
            $offer = $result['offer'];
            $row = [
                Str::limit($offer->storeName, 18),
                Str::limit($offer->title, 45),
                $this->formatPrice($offer->price),
                $result['status'] === ShoppingOfferMatcher::Suspicious ? "suspeita: {$result['reason']}" : 'aceita',
            ];

            if ($verify) {
                $row[] = $result['status'] === ShoppingOfferMatcher::Accepted && $verified++ < $verifyLimit
                    ? $this->verifyOnStore($offer, $serper, $verifier)
                    : '-';
            }

            $row[] = $offer->googleLink ?? '-';
            $rows[] = $row;
        }

        $headers = ['Loja', 'Título', 'Preço Google', 'Status'];
        if ($verify) {
            $headers[] = 'Na loja';
        }
        $headers[] = 'Link';

        $this->table($headers, $rows);
    }

    protected function verifyOnStore(ShoppingOffer $offer, SerperShoppingClient $serper, OfferPageVerifier $verifier): string
    {
        $url = $serper->findStoreUrl($offer);

        if ($url === null) {
            return 'sem_link';
        }

        $verification = $verifier->verify($url, $offer->price);
        $httpStatus = $verification['http_status'] && $verification['http_status'] !== 200 ? " ({$verification['http_status']})" : '';
        $pagePrice = $verification['page_price'] !== null ? ' '.$this->formatPrice($verification['page_price']) : '';

        return $verification['status'].$httpStatus.$pagePrice;
    }

    protected function formatPrice(?float $price): string
    {
        return $price === null ? '-' : 'R$ '.number_format($price, 2, ',', '.');
    }
}
