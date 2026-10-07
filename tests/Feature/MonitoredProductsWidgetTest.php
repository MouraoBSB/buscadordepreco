<?php

namespace Tests\Feature;

use App\Filament\Widgets\MonitoredProductsWidget;
use App\Models\DiscoveryCandidate;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoredProductsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_displays_lowest_in_stock_price(): void
    {
        $product = Product::create([
            'name' => 'Lavadora Teste',
            'commercial_name' => 'Lavadora 18kg',
            'target_price' => 2500,
            'active' => true,
        ]);

        $store = Store::create([
            'name' => 'Loja Teste',
            'domain' => 'lojateste.com',
        ]);

        $source = ProductSource::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'url' => 'https://lojateste.com/p1',
            'collector_type' => 'generic_jsonld',
            'active' => true,
        ]);

        PriceObservation::create([
            'product_source_id' => $source->id,
            'regular_price' => 2600,
            'pix_price' => 2400,
            'in_stock' => true,
            'is_mismatch' => false,
            'collected_at' => now(),
        ]);

        $widget = new MonitoredProductsWidget;
        $items = $widget->getProducts();

        $this->assertCount(1, $items);
        $this->assertEquals(2400.0, $items[0]['current_price']);
        $this->assertEquals('in_stock', $items[0]['price_type']);
        $this->assertEquals('Em estoque', $items[0]['price_badge']);
        $this->assertTrue($items[0]['is_below_target']);
    }

    public function test_widget_displays_lowest_historical_price_when_out_of_stock(): void
    {
        $product = Product::create([
            'name' => 'Geladeira Teste',
            'target_price' => 3000,
            'active' => true,
        ]);

        $store = Store::create([
            'name' => 'Loja Teste',
            'domain' => 'lojateste.com',
        ]);

        $source = ProductSource::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'url' => 'https://lojateste.com/p2',
            'collector_type' => 'generic_jsonld',
            'active' => true,
        ]);

        // Historical lower observation (out of stock later)
        PriceObservation::create([
            'product_source_id' => $source->id,
            'regular_price' => 2800,
            'in_stock' => false,
            'is_mismatch' => false,
            'collected_at' => now()->subDays(5),
        ]);

        // Recent higher observation
        PriceObservation::create([
            'product_source_id' => $source->id,
            'regular_price' => 3200,
            'in_stock' => false,
            'is_mismatch' => false,
            'collected_at' => now()->subDay(),
        ]);

        $widget = new MonitoredProductsWidget;
        $items = $widget->getProducts();

        $this->assertCount(1, $items);
        $this->assertEquals(2800.0, $items[0]['current_price']);
        $this->assertEquals('historical', $items[0]['price_type']);
        $this->assertEquals('Menor histórico', $items[0]['price_badge']);
        $this->assertStringContainsString('3.200,00', $items[0]['price_subtext']);
    }

    public function test_widget_displays_detected_price_when_no_observations(): void
    {
        $product = Product::create([
            'name' => 'Console Teste',
            'active' => true,
        ]);

        DiscoveryCandidate::create([
            'product_id' => $product->id,
            'url' => 'https://example.com/item',
            'url_hash' => hash('sha256', 'https://example.com/item'),
            'raw_title' => 'Console Teste Novo',
            'discovered_store_name' => 'Loja Exemplo',
            'detected_price' => 1999.90,
            'status' => 'auto_approved',
        ]);

        $widget = new MonitoredProductsWidget;
        $items = $widget->getProducts();

        $this->assertCount(1, $items);
        $this->assertEquals(1999.90, $items[0]['current_price']);
        $this->assertEquals('detected', $items[0]['price_type']);
        $this->assertEquals('Detectado na busca', $items[0]['price_badge']);
        $this->assertStringContainsString('Loja Exemplo', $items[0]['price_subtext']);
    }

    public function test_widget_displays_none_when_no_prices_exist(): void
    {
        $product = Product::create([
            'name' => 'Produto Sem Preço',
            'active' => true,
        ]);

        $widget = new MonitoredProductsWidget;
        $items = $widget->getProducts();

        $this->assertCount(1, $items);
        $this->assertNull($items[0]['current_price']);
        $this->assertEquals('none', $items[0]['price_type']);
        $this->assertNull($items[0]['price_badge']);
    }
}
