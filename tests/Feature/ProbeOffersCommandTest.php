<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Shopping\OfferPageVerifier;
use App\Services\Shopping\SerperShoppingClient;
use App\Services\Shopping\ShoppingOffer;
use App\Services\Shopping\ShoppingOfferMatcher;
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

    public function test_matcher_keeps_only_the_monitored_product_and_flags_outlier_prices(): void
    {
        $product = new Product(['name' => 'Samsung Galaxy S25 256GB']);

        $offers = [
            'certa' => new ShoppingOffer('Samsung Galaxy S25 5G 256GB', 'KaBuM!', 3899.00),
            'ram' => new ShoppingOffer('Celular Samsung Galaxy S25 5G 12GB RAM', 'Amazon.com.br', 3999.00),
            'ultra' => new ShoppingOffer('Smartphone Samsung Galaxy S25 Ultra 256GB', 'Magalu', 6999.00),
            'mais' => new ShoppingOffer('Samsung Galaxy S25+ 5G 256GB', 'Magalu', 4999.00),
            'usado' => new ShoppingOffer('Celular Samsung Galaxy S25 256GB', 'Amazon.com.br - Seminovos', 3675.00),
            'armazenamento' => new ShoppingOffer('Samsung Galaxy S25 512GB', 'Casas Bahia', 4299.00),
            'como_novo' => new ShoppingOffer('Galaxy S25 256GB Azul Marinho - Sou Como Novo', 'Trocafy', 3133.71),
            'kit' => new ShoppingOffer('Smartphone Samsung Galaxy S25 256GB - 5G + Smartwatch Galaxy Watch8', 'Magalu', 5228.99),
            'barato' => new ShoppingOffer('Samsung Galaxy S25 256GB Azul', 'Loja Desconhecida', 1500.00),
            'sem_preco' => new ShoppingOffer('Samsung Galaxy S25 256GB', 'Fast Shop', null),
        ];

        $results = array_combine(array_keys($offers), app(ShoppingOfferMatcher::class)->match($product, array_values($offers)));

        $this->assertSame(ShoppingOfferMatcher::Accepted, $results['certa']['status']);
        $this->assertSame(ShoppingOfferMatcher::Accepted, $results['ram']['status']);
        $this->assertSame(ShoppingOfferMatcher::Suspicious, $results['barato']['status']);

        foreach (['ultra', 'mais', 'usado', 'armazenamento', 'sem_preco', 'como_novo', 'kit'] as $key) {
            $this->assertSame(ShoppingOfferMatcher::Rejected, $results[$key]['status'], "Oferta '{$key}' deveria ser recusada.");
        }

        $this->assertStringContainsString('ultra', $results['ultra']['reason']);
        $this->assertStringContainsString('Armazenamento', $results['armazenamento']['reason']);
    }

    public function test_matcher_does_not_mistake_capacity_for_product_version(): void
    {
        $product = new Product([
            'name' => 'Lavadora de Roupas Panasonic 18 kg Titânio Top Load',
            'brand' => 'Panasonic',
            'capacity_kg' => 18,
        ]);

        [$result] = app(ShoppingOfferMatcher::class)->match($product, [
            new ShoppingOffer('Máquina de Lavar Panasonic Antibacteria 18kg Titânio', 'Extra', 2799.00),
        ]);

        $this->assertSame(ShoppingOfferMatcher::Accepted, $result['status'], (string) $result['reason']);
    }

    public function test_washer_rejects_washer_dryer_and_two_offers_are_not_judged_as_outliers(): void
    {
        $product = new Product([
            'name' => 'Lavadora de Roupas Samsung 17 kg Ecobubble Top Load',
            'brand' => 'Samsung',
            'model_code' => 'WA17CG6746BVBZ',
            'capacity_kg' => 17,
        ]);

        [$washerDryer, $washer] = app(ShoppingOfferMatcher::class)->match($product, [
            new ShoppingOffer('Lavadora e Secadora Samsung Bespoke AI WH17D Laundy 17kg', 'shop.samsung.com/br', 19599.00),
            new ShoppingOffer('Lavadora Samsung Smart Digital Inverter WA17CG Black 17kg', 'Sipolatti', 3499.00),
        ]);

        $this->assertSame(ShoppingOfferMatcher::Rejected, $washerDryer['status']);
        $this->assertSame(ShoppingOfferMatcher::Accepted, $washer['status'], (string) $washer['reason']);

        [$first, $second] = app(ShoppingOfferMatcher::class)->match($product, [
            new ShoppingOffer('Lavadora Samsung WA17CG 17kg', 'Loja A', 3499.00),
            new ShoppingOffer('Lavadora Samsung WA17CG 17kg', 'Loja B', 1800.00),
        ]);

        $this->assertSame(ShoppingOfferMatcher::Accepted, $first['status']);
        $this->assertSame(ShoppingOfferMatcher::Accepted, $second['status']);
    }

    public function test_falls_back_to_shopping_search_link_when_serper_omits_it(): void
    {
        Http::fake([
            'google.serper.dev/shopping' => Http::response([
                'shopping' => [
                    ['title' => 'Samsung Galaxy S25 5G 256GB', 'source' => 'Horizon Play', 'price' => 'R$ 4.250,29', 'productId' => '11165022278430168481'],
                ],
                'credits' => 2,
            ]),
        ]);

        [$offer] = app(SerperShoppingClient::class)->searchOffers('galaxy s25');

        $this->assertSame(
            'https://www.google.com/search?q=Samsung+Galaxy+S25+5G+256GB+Horizon+Play&udm=28&gl=br&hl=pt-BR',
            $offer->googleLink,
        );
    }

    public function test_probe_shows_cheapest_matching_offer_with_google_link_using_a_single_search(): void
    {
        Http::fake([
            'google.serper.dev/shopping' => Http::response([
                'shopping' => [
                    ['title' => 'Samsung Galaxy S25 Ultra 256GB', 'source' => 'Magalu', 'price' => 'R$ 2.625,65 agora', 'link' => 'https://www.google.com/search?ibp=oshop&prds=ultra'],
                    ['title' => 'Samsung Galaxy S25 5G 256GB', 'source' => 'Amazon.com.br', 'price' => 'R$ 3.999,00 agora', 'link' => 'https://www.google.com/search?ibp=oshop&prds=amazon'],
                    ['title' => 'Samsung Galaxy S25 5G 256GB', 'source' => 'KaBuM!', 'price' => 'R$ 3.899,00 agora', 'link' => 'https://www.google.com/search?ibp=oshop&prds=kabum'],
                ],
                'credits' => 2,
            ]),
        ]);

        $this->artisan('pricewatch:probe', ['query' => 'samsung galaxy s25 256gb'])
            ->expectsOutputToContain('https://www.google.com/search?ibp=oshop&prds=kabum')
            ->expectsOutputToContain('1 ofertas recusadas')
            ->expectsOutputToContain('R$ 3.899,00')
            ->expectsOutputToContain('Créditos Serper usados: 2')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_probe_with_verify_confirms_offer_on_the_store_page(): void
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

        $this->artisan('pricewatch:probe', ['query' => 'samsung galaxy s25 256gb', '--verify' => true])
            ->expectsOutputToContain('confirmado R$ 3.899,00')
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
