<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Services\Shopping;

use Illuminate\Support\Facades\Http;

/**
 * Abre ao vivo a página do produto na loja e lê preço e disponibilidade da própria página.
 */
class OfferPageVerifier
{
    public const string Confirmed = 'confirmado';

    public const string PriceDiffers = 'preco_diferente';

    public const string OutOfStock = 'indisponivel';

    public const string Blocked = 'bloqueado';

    public const string Broken = 'quebrado';

    public const string NoPriceOnPage = 'sem_preco';

    protected const float PriceTolerance = 0.03;

    /**
     * @return array{status: string, http_status: int|null, page_price: float|null, in_stock: bool|null}
     */
    public function verify(string $url, ?float $expectedPrice): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9',
            ])->timeout(20)->get($url);
        } catch (\Throwable) {
            return $this->result(self::Blocked, null);
        }

        $httpStatus = $response->status();

        if (in_array($httpStatus, [404, 410], true)) {
            return $this->result(self::Broken, $httpStatus);
        }

        if (! $response->successful()) {
            return $this->result(self::Blocked, $httpStatus);
        }

        ['price' => $pagePrice, 'in_stock' => $inStock] = $this->extractOffer($response->body());

        if ($inStock === false) {
            return $this->result(self::OutOfStock, $httpStatus, $pagePrice, false);
        }

        if ($pagePrice === null) {
            return $this->result(self::NoPriceOnPage, $httpStatus, null, $inStock);
        }

        $matchesExpected = $expectedPrice === null
            || abs($pagePrice - $expectedPrice) <= $expectedPrice * self::PriceTolerance;

        return $this->result($matchesExpected ? self::Confirmed : self::PriceDiffers, $httpStatus, $pagePrice, $inStock);
    }

    /**
     * @return array{price: float|null, in_stock: bool|null}
     */
    public function extractOffer(string $html): array
    {
        foreach ($this->jsonLdProducts($html) as $product) {
            $offers = $product['offers'] ?? null;
            if (! is_array($offers)) {
                continue;
            }

            $offer = array_is_list($offers) ? ($offers[0] ?? []) : $offers;
            $price = $offer['price'] ?? $offer['lowPrice'] ?? null;

            if (is_numeric($price) && (float) $price > 0) {
                $availability = strtolower((string) ($offer['availability'] ?? ''));

                return [
                    'price' => (float) $price,
                    'in_stock' => $availability === '' ? null : ! preg_match('/(outofstock|discontinued|soldout)/', $availability),
                ];
            }
        }

        if (preg_match('/<meta\b[^>]*(?:property=[\'"]product:price:amount[\'"]|itemprop=[\'"]price[\'"])[^>]*content=[\'"]([\d\.,]+)[\'"]/i', $html, $match)) {
            $metaPrice = is_numeric($match[1]) ? (float) $match[1] : ShoppingOffer::parseBrazilianPrice($match[1]);

            return ['price' => $metaPrice > 0 ? $metaPrice : null, 'in_stock' => null];
        }

        return ['price' => null, 'in_stock' => null];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function jsonLdProducts(string $html): array
    {
        if (! preg_match_all('/<script\b[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }

        $products = [];

        foreach ($matches[1] as $content) {
            $data = json_decode(trim($content), true);
            if (! is_array($data)) {
                continue;
            }

            $nodes = array_is_list($data) ? $data : ($data['@graph'] ?? [$data]);

            foreach ($nodes as $node) {
                $types = (array) ($node['@type'] ?? []);
                if (in_array('Product', $types, true)) {
                    $products[] = $node;
                }
            }
        }

        return $products;
    }

    /**
     * @return array{status: string, http_status: int|null, page_price: float|null, in_stock: bool|null}
     */
    protected function result(string $status, ?int $httpStatus, ?float $pagePrice = null, ?bool $inStock = null): array
    {
        return [
            'status' => $status,
            'http_status' => $httpStatus,
            'page_price' => $pagePrice,
            'in_stock' => $inStock,
        ];
    }
}
