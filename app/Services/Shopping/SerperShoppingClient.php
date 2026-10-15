<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Services\Shopping;

use App\Models\ApiUsageLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Acesso ao Google Shopping via Serper.dev.
 *
 * Os resultados do Shopping trazem apenas links do Google; a URL direta da loja é obtida
 * com uma segunda busca web restrita ao site (1 crédito extra por oferta).
 */
class SerperShoppingClient
{
    protected const string BASE_URL = 'https://google.serper.dev';

    /**
     * Nomes de lojas do Google Shopping mapeados para seus domínios.
     *
     * @var array<string, string>
     */
    protected array $storeDomains = [
        'magalu' => 'magazineluiza.com.br',
        'magazine luiza' => 'magazineluiza.com.br',
        'kabum' => 'kabum.com.br',
        'amazon' => 'amazon.com.br',
        'mercado livre' => 'mercadolivre.com.br',
        'casas bahia' => 'casasbahia.com.br',
        'pontofrio' => 'pontofrio.com.br',
        'extra' => 'extra.com.br',
        'carrefour' => 'carrefour.com.br',
        'fast shop' => 'fastshop.com.br',
        'samsung' => 'samsung.com',
        'taqi' => 'taqi.com.br',
        'havan' => 'havan.com.br',
        'americanas' => 'americanas.com.br',
        'submarino' => 'submarino.com.br',
        'shopee' => 'shopee.com.br',
    ];

    public int $creditsUsed = 0;

    public function isConfigured(): bool
    {
        return filled(config('services.serper.key'));
    }

    /**
     * @return array<int, ShoppingOffer>
     */
    public function searchOffers(string $query): array
    {
        $results = $this->post('/shopping', ['q' => $query])['shopping'] ?? [];

        $offers = [];
        $seen = [];

        foreach ($results as $result) {
            $title = trim($result['title'] ?? '');
            $storeName = trim($result['source'] ?? '');

            if ($title === '' || $storeName === '') {
                continue;
            }

            $dedupeKey = mb_strtolower($storeName.'|'.$title, 'UTF-8');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $offers[] = new ShoppingOffer(
                title: $title,
                storeName: $storeName,
                price: ShoppingOffer::parseBrazilianPrice($result['price'] ?? null),
                googleProductId: $result['productId'] ?? null,
                imageUrl: $result['imageUrl'] ?? null,
                googleLink: $result['link'] ?? null,
            );
        }

        return $offers;
    }

    public function domainForStore(string $storeName): ?string
    {
        $normalized = mb_strtolower($storeName, 'UTF-8');

        foreach ($this->storeDomains as $needle => $domain) {
            if (str_contains($normalized, $needle)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Encontra a URL direta da página do produto no site da loja.
     */
    public function findStoreUrl(ShoppingOffer $offer): ?string
    {
        $domain = $this->domainForStore($offer->storeName);
        $query = $domain !== null
            ? "{$offer->title} site:{$domain}"
            : "{$offer->title} {$offer->storeName}";

        $organicResults = $this->post('/search', ['q' => $query, 'num' => 5])['organic'] ?? [];

        foreach ($organicResults as $result) {
            $link = $result['link'] ?? '';
            $host = (string) parse_url($link, PHP_URL_HOST);

            if ($link === '' || str_contains($host, 'google.')) {
                continue;
            }

            if ($domain !== null && ! str_contains($host, $domain)) {
                continue;
            }

            if ($this->isListingPage($link)) {
                continue;
            }

            return $this->stripTrackingParameters($link);
        }

        return null;
    }

    protected function isListingPage(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return (bool) preg_match('#(/s/|/s$|/busca|/search|/categoria|/departamento|/lista|/c/|/b/|/b$)#', $path);
    }

    protected function stripTrackingParameters(string $url): string
    {
        return preg_replace('/[?&]srsltid=[^&]*$/', '', $url) ?? $url;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SERPER_API_KEY não configurada no .env.');
        }

        $startedAt = microtime(true);

        $response = Http::withHeaders(['X-API-KEY' => config('services.serper.key')])
            ->acceptJson()
            ->timeout(20)
            ->post(self::BASE_URL.$endpoint, array_merge(['gl' => 'br', 'hl' => 'pt-br'], $payload));

        $credits = $response->failed() ? 0 : (int) $response->json('credits', 1);

        ApiUsageLog::logRequest(
            provider: 'serper',
            purpose: $endpoint === '/shopping' ? 'shopping' : 'store_url',
            query: mb_substr((string) ($payload['q'] ?? ''), 0, 500),
            credits: $credits,
            status: $response->status(),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );

        if ($response->failed()) {
            throw new RuntimeException("Serper {$endpoint} falhou com HTTP {$response->status()}.");
        }

        $this->creditsUsed += $credits;

        return $response->json() ?? [];
    }
}
