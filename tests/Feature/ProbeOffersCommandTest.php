<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace Tests\Feature;

use App\Services\Shopping\OfferPageVerifier;
use App\Services\Shopping\ShoppingOffer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeOffersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.serper.key' => 'test-key']);
    }

    public function test_parses_brazilian_prices_from_google_shopping(): void
    {
        $this->assertSame(3163.13, ShoppingOffer::parseBrazilianPrice("R$\u{00A0}3.163,13 agora"));
        $this->assertSame(3899.0, ShoppingOffer::parseBrazilianPrice('R$ 3899,00'));
        $this->assertSame(999.9, ShoppingOffer::parseBrazilianPrice('R$ 999,90'));
        $this->assertSame(12500.0, ShoppingOffer::parseBrazilianPrice('R$ 12.500'));
        $this->assertNull(ShoppingOffer::parseBrazilianPrice(null));
    }

    public function test_probe_confirms_offer_whose_live_page_price_matches_google(): void
    {
        Http::fake([
            'google.serper.dev/shopping' => Http::response([
                'shopping' => [
                    ['title' => 'Samsung Galaxy S25 5G 256GB', 'source' => 'KaBuM!', 'price' => 'R$ 3.899,00 agora'],
                ],
                'credits' => 2,
            ]),
            'google.serper.dev/search' => Http::response([
                'organic' => [
                    ['link' => 'https://www.kabum.com.br/busca/galaxy-s25'],
                    ['link' => 'https://www.kabum.com.br/produto/703055/galaxy-s25?srsltid=abc'],
                ],
                'credits' => 1,
            ]),
            'www.kabum.com.br/*' => Http::response($this->productPageHtml(3899.00, 'InStock')),
        ]);

        $this->artisan('pricewatch:probe', ['query' => 'galaxy s25'])
            ->expectsOutputToContain('confirmado | https://www.kabum.com.br/produto/703055/galaxy-s25 |')
            ->expectsOutputToContain('Créditos Serper usados: 3')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/search')
            && $request['q'] === 'Samsung Galaxy S25 5G 256GB site:kabum.com.br');
    }

    public function test_verifier_classifies_broken_blocked_out_of_stock_and_divergent_pages(): void
    {
        Http::fake([
            'loja.test/quebrado' => Http::response('', 404),
            'loja.test/bloqueado' => Http::response('', 403),
            'loja.test/esgotado' => Http::response($this->productPageHtml(3899.00, 'OutOfStock')),
            'loja.test/caro' => Http::response($this->productPageHtml(4500.00, 'InStock')),
        ]);

        $verifier = new OfferPageVerifier;

        $this->assertSame(OfferPageVerifier::Broken, $verifier->verify('https://loja.test/quebrado', 3899.00)['status']);
        $this->assertSame(OfferPageVerifier::Blocked, $verifier->verify('https://loja.test/bloqueado', 3899.00)['status']);
        $this->assertSame(OfferPageVerifier::OutOfStock, $verifier->verify('https://loja.test/esgotado', 3899.00)['status']);

        $divergent = $verifier->verify('https://loja.test/caro', 3899.00);
        $this->assertSame(OfferPageVerifier::PriceDiffers, $divergent['status']);
        $this->assertSame(4500.0, $divergent['page_price']);
    }

    public function test_probe_fails_without_serper_key(): void
    {
        config(['services.serper.key' => null]);

        $this->artisan('pricewatch:probe', ['query' => 'galaxy s25'])
            ->expectsOutputToContain('SERPER_API_KEY')
            ->assertFailed();
    }

    protected function productPageHtml(float $price, string $availability): string
    {
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'Samsung Galaxy S25',
            'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'BRL', 'availability' => "https://schema.org/{$availability}"],
        ]);

        return "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body></body></html>";
    }
}
