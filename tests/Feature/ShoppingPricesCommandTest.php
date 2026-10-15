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
        ]);
    }

    public function test_records_cheapest_offer_per_store_and_registers_alert_without_whatsapp(): void
    {
        Store::create(['name' => 'Magazine Luiza', 'domain' => 'magazineluiza.com.br', 'active' => true]);

        $this->artisan('pricewatch:shopping')
            ->expectsOutputToContain('Créditos Serper usados: 2')
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
}
