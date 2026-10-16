<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\ApiUsageLog;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\Store;
use App\Services\Alerts\Channels\GoWaChannel;
use App\Services\Collector\Pipeline\CollectionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShoppingPricesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    /**
     * Resultados orgânicos devolvidos pela busca do endereço na loja (Serper /search).
     *
     * @var array<int, array{link: string, title: string}>
     */
    protected array $organicResults = [];

    /**
     * Preço exibido em cada página de loja simulada (URL => preço); páginas ausentes respondem 404.
     *
     * @var array<string, float>
     */
    protected array $storePagePrices = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.serper.key' => 'test-key', 'services.gowa.enabled' => false]);

        $this->product = Product::create([
            'name' => 'Samsung Galaxy S25 256GB',
            'image_url' => 'https://exemplo.test/s25.png',
            'target_price' => 4000,
            'active' => true,
        ]);

        AlertRule::create([
            'product_id' => $this->product->id,
            'type' => 'target_price',
            'threshold' => 4000,
            'active' => true,
        ]);

        Http::fake([
            'google.serper.dev/shopping' => Http::response([
                'shopping' => [
                    ['title' => 'Samsung Galaxy S25 5G 256GB', 'source' => 'KaBuM!', 'price' => 'R$ 3.899,00 agora', 'link' => 'https://www.google.com/search?ibp=oshop&prds=kabum'],
                    ['title' => 'Smartphone Samsung Galaxy S25 256GB Azul', 'source' => 'Magalu', 'price' => 'R$ 4.100,00', 'link' => 'https://www.google.com/search?ibp=oshop&prds=magalu-caro'],
                    ['title' => 'Smartphone Samsung Galaxy S25 256GB Prata', 'source' => 'Magalu', 'price' => 'R$ 3.999,00', 'link' => 'https://www.google.com/search?ibp=oshop&prds=magalu'],
                    ['title' => 'Samsung Galaxy S25 Ultra 256GB', 'source' => 'Magalu', 'price' => 'R$ 2.625,65'],
                    ['title' => 'Samsung Galaxy S25 256GB', 'source' => 'Loja Relâmpago', 'price' => 'R$ 1.500,00'],
                    ['title' => 'Celular Samsung Galaxy S25 5G 256GB', 'source' => 'Outlet do Celular', 'price' => 'R$ 3.600,00', 'link' => 'https://www.google.com/search?ibp=oshop&prds=outlet'],
                ],
                'credits' => 2,
            ]),
            'google.serper.dev/search' => fn () => Http::response(['organic' => $this->organicResults, 'credits' => 1]),
            '*' => fn ($request) => isset($this->storePagePrices[$request->url()])
                ? Http::response($this->productPageHtml($this->storePagePrices[$request->url()]))
                : Http::response('', 404),
        ]);
    }

    public function test_records_cheapest_offer_per_store_and_registers_alert_without_whatsapp(): void
    {
        Store::create(['name' => 'Magazine Luiza', 'domain' => 'magazineluiza.com.br', 'active' => true]);

        // 2 créditos da busca + 1 por endereço procurado (melhor oferta confiável e a mais barata)
        $this->artisan('pricewatch:shopping')
            ->expectsOutputToContain('Créditos Serper usados: 4')
            ->assertSuccessful();

        $sources = ProductSource::with('store')->where('product_id', $this->product->id)->get();
        $this->assertCount(4, $sources);
        $this->assertTrue($sources->every(fn (ProductSource $source): bool => $source->isGoogleShopping()));
        $this->assertSame(1, Store::where('domain', 'magazineluiza.com.br')->count(), 'Magalu deve reaproveitar a loja existente.');

        $magaluObservation = PriceObservation::whereHas('source.store', fn ($query) => $query->where('domain', 'magazineluiza.com.br'))->sole();
        $this->assertEquals(3999.00, (float) $magaluObservation->regular_price);
        $this->assertStringContainsString('q=Smartphone+Samsung+Galaxy+S25+256GB+Prata+Magalu', $magaluObservation->source->url);
        $this->assertSame('https://www.google.com/search?ibp=oshop&prds=magalu', $magaluObservation->metadata['google_link']);

        $suspicious = PriceObservation::where('seller', 'Loja Relâmpago')->sole();
        $this->assertTrue($suspicious->is_suspicious);

        $alert = Alert::sole();
        $this->assertSame('skipped', $alert->status);
        // Outlet do Celular (R$ 3.600) é mais barata, mas não é loja confiável: o alerta usa a KaBuM
        $this->assertEquals(3899.00, $alert->payload['price']);
        $this->assertTrue($alert->payload['trusted_store']);
        $this->assertStringContainsString('KaBuM%21', $alert->payload['url']);
        $this->assertSame('https://www.google.com/search?ibp=oshop&prds=kabum', $alert->payload['compare_url']);
        $this->assertStringContainsString('Comparar lojas no Google', $alert->payload['message']);
        $this->assertStringNotContainsString('Loja pouco conhecida', $alert->payload['message']);
        $this->assertSame('standard', Store::where('name', 'Outlet do Celular')->value('trust_level'));
        $this->assertStringContainsString('GOWA_ENABLED', $alert->error_message);

        $this->assertSame(2, (int) ApiUsageLog::where('provider', 'serper')->where('purpose', 'shopping')->sum('credits_used'));
    }

    public function test_resolves_store_page_for_best_offer_and_reuses_it_on_next_run(): void
    {
        $this->organicResults = [
            ['link' => 'https://www.kabum.com.br/busca/galaxy-s25', 'title' => 'Busca Galaxy S25'],
            ['link' => 'https://www.kabum.com.br/produto/703060/samsung-galaxy-s25-5g-256gb?srsltid=abc', 'title' => 'Samsung Galaxy S25 5G 256GB Galaxy AI Prata'],
        ];

        $this->artisan('pricewatch:shopping')->assertSuccessful();
        $this->artisan('pricewatch:shopping')->assertSuccessful();

        $directUrl = 'https://www.kabum.com.br/produto/703060/samsung-galaxy-s25-5g-256gb';
        $kabum = ProductSource::whereHas('store', fn ($query) => $query->where('domain', 'kabum.com.br'))->sole();
        $this->assertSame($directUrl, $kabum->url);
        $this->assertSame($directUrl, Alert::sole()->payload['url']);

        // Outlet do Celular não tem domínio conhecido e o resultado é de outra loja: fica sem endereço direto
        $outlet = ProductSource::whereHas('store', fn ($query) => $query->where('name', 'Outlet do Celular'))->sole();
        $this->assertStringStartsWith('https://www.google.com/search?', $outlet->url);

        // 1ª rodada: KaBuM e Outlet; 2ª rodada reaproveita os dois resultados
        $this->assertSame(2, Http::recorded(fn ($request): bool => str_contains($request->url(), '/search'))->count());
    }

    public function test_discards_offer_whose_store_page_with_same_price_is_a_used_product(): void
    {
        // Caso real da KaBuM: o Google mostrou "Samsung Galaxy S25 5G 256GB" com o preço de um anúncio usado
        $this->organicResults = [
            ['link' => 'https://www.kabum.com.br/produto/703060/samsung-galaxy-s25-5g-256gb', 'title' => 'Samsung Galaxy S25 5G 256GB Galaxy AI Prata'],
            ['link' => 'https://www.kabum.com.br/produto/947644/usado-samsung-galaxy-s25-256gb', 'title' => 'Usado - Samsung Galaxy S25 256gb - Azul Claro - Aceitável'],
        ];
        $this->storePagePrices = [
            'https://www.kabum.com.br/produto/703060/samsung-galaxy-s25-5g-256gb' => 4500.00,
            'https://www.kabum.com.br/produto/947644/usado-samsung-galaxy-s25-256gb' => 3899.00,
        ];

        $this->artisan('pricewatch:shopping')->assertSuccessful();
        $this->artisan('pricewatch:shopping')->assertSuccessful();

        $kabumObservations = PriceObservation::where('seller', 'KaBuM!')->get();
        $this->assertCount(2, $kabumObservations);
        $this->assertTrue($kabumObservations->every(fn (PriceObservation $observation): bool => $observation->is_mismatch));
        $this->assertStringContainsString('Usado', $kabumObservations->first()->mismatch_reason);

        // O alerta passa para a próxima loja confiável (Magalu)
        $this->assertEquals(3999.00, Alert::sole()->payload['price']);

        // 1ª rodada: KaBuM, Magalu e Outlet; 2ª rodada reaproveita os três resultados
        $this->assertSame(3, Http::recorded(fn ($request): bool => str_contains($request->url(), '/search'))->count());
    }

    public function test_store_page_with_title_of_another_product_is_discarded(): void
    {
        $this->organicResults = [
            ['link' => 'https://www.kabum.com.br/produto/1/capinha-galaxy-s25', 'title' => 'Capinha Samsung Galaxy S25 256GB Transparente'],
        ];

        $this->artisan('pricewatch:shopping')->assertSuccessful();

        $kabum = ProductSource::whereHas('store', fn ($query) => $query->where('domain', 'kabum.com.br'))->sole();
        $this->assertStringStartsWith('https://www.google.com/search?', $kabum->url);
    }

    public function test_second_run_reuses_sources_and_does_not_repeat_the_alert(): void
    {
        $this->artisan('pricewatch:shopping')->assertSuccessful();
        $this->artisan('pricewatch:shopping')->assertSuccessful();

        $this->assertSame(4, ProductSource::count());
        $this->assertSame(8, PriceObservation::count());
        $this->assertSame(1, Alert::count());
    }

    public function test_http_collector_never_scrapes_google_shopping_sources(): void
    {
        $this->artisan('pricewatch:shopping')->assertSuccessful();
        $source = ProductSource::firstOrFail();
        $observationsBefore = PriceObservation::count();

        $result = app(CollectionPipeline::class)->run($source);

        $this->assertFalse($result->isSuccess);
        $this->assertSame('GOOGLE_SHOPPING_SOURCE', $result->errorCode);
        $this->assertSame($observationsBefore, PriceObservation::count());

        $this->artisan('pricewatch:collect')
            ->expectsOutputToContain('Nenhuma fonte ativa encontrada')
            ->assertSuccessful();
    }

    public function test_disabled_whatsapp_channel_skips_without_calling_gowa(): void
    {
        $result = app(GoWaChannel::class)->sendMessage('teste');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['skipped']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/send/message'));
    }

    protected function productPageHtml(float $price): string
    {
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'BRL', 'availability' => 'https://schema.org/InStock'],
        ]);

        return "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body></body></html>";
    }
}
