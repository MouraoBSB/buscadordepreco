<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\Store;
use App\Services\Coupons\CouponCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CouponCalculatorService $service;

    protected Product $product;

    protected Store $store;

    protected ProductSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CouponCalculatorService;

        $this->product = Product::create([
            'name' => 'Lavadora Midea 16.5kg',
            'target_price' => 2500,
            'active' => true,
        ]);

        $this->store = Store::create([
            'name' => 'Mercado Livre',
            'domain' => 'mercadolivre.com.br',
        ]);

        $this->source = ProductSource::create([
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'url' => 'https://mercadolivre.com.br/item-1',
            'collector_type' => 'generic_jsonld',
            'active' => true,
        ]);
    }

    public function test_calculates_fixed_discount_on_pix_and_regular(): void
    {
        Coupon::create([
            'code' => 'MENOS100',
            'store_id' => $this->store->id,
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'applies_to_pix' => true,
            'active' => true,
        ]);

        $result = $this->service->calculateBestCoupon(
            source: $this->source,
            regularPrice: 2000.0,
            pixPrice: 1900.0
        );

        $this->assertEquals(1800.0, $result['coupon_price']);
        $this->assertEquals('MENOS100', $result['coupon_code']);
        $this->assertEquals(100.0, $result['coupon_discount']);
        $this->assertEquals('pix', $result['applied_on']);
    }

    public function test_respects_applies_to_pix_flag(): void
    {
        // Coupon that only applies to regular/card price, not to Pix
        Coupon::create([
            'code' => 'CARTAO150',
            'store_id' => $this->store->id,
            'discount_type' => 'fixed',
            'discount_value' => 150,
            'applies_to_pix' => false,
            'active' => true,
        ]);

        // Regular: 2000 - 150 = 1850
        // Pix: 1900
        // Base lowest before coupon is 1900. Final 1850 is lower than 1900!
        $result = $this->service->calculateBestCoupon(
            source: $this->source,
            regularPrice: 2000.0,
            pixPrice: 1900.0
        );

        $this->assertEquals(1850.0, $result['coupon_price']);
        $this->assertEquals('CARTAO150', $result['coupon_code']);
        $this->assertEquals('regular', $result['applied_on']);
    }

    public function test_calculates_percentage_discount_with_max_cap(): void
    {
        Coupon::create([
            'code' => 'DESC10',
            'store_id' => $this->store->id,
            'discount_type' => 'percentage',
            'discount_value' => 10, // 10% of 2000 is 200
            'max_discount' => 120, // capped at 120
            'applies_to_pix' => true,
            'active' => true,
        ]);

        $result = $this->service->calculateBestCoupon(
            source: $this->source,
            regularPrice: 2000.0,
            pixPrice: null
        );

        $this->assertEquals(1880.0, $result['coupon_price']);
        $this->assertEquals(120.0, $result['coupon_discount']);
        $this->assertEquals('DESC10', $result['coupon_code']);
    }

    public function test_rejects_coupon_below_min_order_value(): void
    {
        Coupon::create([
            'code' => 'ALTOVALOR',
            'store_id' => $this->store->id,
            'discount_type' => 'fixed',
            'discount_value' => 300,
            'min_order_value' => 3000, // requires min 3000
            'applies_to_pix' => true,
            'active' => true,
        ]);

        $result = $this->service->calculateBestCoupon(
            source: $this->source,
            regularPrice: 2500.0,
            pixPrice: 2400.0
        );

        $this->assertNull($result['coupon_price']);
        $this->assertNull($result['applied_coupon_id']);
    }

    public function test_records_auto_detected_coupon_and_applies_it(): void
    {
        $result = $this->service->calculateBestCoupon(
            source: $this->source,
            regularPrice: 2000.0,
            pixPrice: 1900.0,
            detectedCouponCode: 'MLOFF50',
            detectedCouponDiscount: 50.0,
            detectedDiscountType: 'fixed'
        );

        $this->assertEquals(1850.0, $result['coupon_price']);
        $this->assertEquals('MLOFF50', $result['coupon_code']);
        $this->assertEquals(50.0, $result['coupon_discount']);

        $this->assertDatabaseHas('coupons', [
            'code' => 'MLOFF50',
            'store_id' => $this->store->id,
            'discount_type' => 'fixed',
            'source_type' => 'auto_detected',
        ]);
    }

    public function test_ignores_expired_coupons(): void
    {
        Coupon::create([
            'code' => 'EXPIRADO',
            'store_id' => $this->store->id,
            'discount_type' => 'fixed',
            'discount_value' => 200,
            'expires_at' => now()->subDay(),
            'active' => true,
        ]);

        $result = $this->service->calculateBestCoupon(
            source: $this->source,
            regularPrice: 2000.0,
            pixPrice: 1900.0
        );

        $this->assertNull($result['coupon_price']);
    }
}
