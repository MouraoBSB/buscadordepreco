<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppGroupMessageJob;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Services\Coupons\WhatsAppCouponExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppCouponExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected WhatsAppCouponExtractorService $service;

    protected Store $mercadolivre;

    protected Store $amazon;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WhatsAppCouponExtractorService;

        $this->mercadolivre = Store::create([
            'name' => 'Mercado Livre',
            'domain' => 'mercadolivre.com.br',
        ]);

        $this->amazon = Store::create([
            'name' => 'Amazon',
            'domain' => 'amazon.com.br',
        ]);

        $this->product = Product::create([
            'name' => 'Lavadora de Roupas Midea 16.5kg',
            'commercial_name' => 'Lavadora Midea 16.5kg',
            'model_code' => 'MA512W165',
            'target_price' => 2400,
            'active' => true,
        ]);
    }

    public function test_extracts_coupon_with_percentage_discount_and_store(): void
    {
        $message = '🔥 Galera, use o cupom VALE20 para 20% OFF no Mercado Livre! Corre que vai acabar: https://mercadolivre.com.br/item-xyz';

        $coupon = $this->service->extractAndStore($message, [
            'group_name' => 'Promos Brasil',
            'sender' => '556199999999@s.whatsapp.net',
        ]);

        $this->assertNotNull($coupon);
        $this->assertEquals('VALE20', $coupon->code);
        $this->assertEquals('percentage', $coupon->discount_type);
        $this->assertEquals(20.0, $coupon->discount_value);
        $this->assertEquals($this->mercadolivre->id, $coupon->store_id);
        $this->assertEquals('whatsapp_group', $coupon->source_type);
        $this->assertTrue($coupon->active);
    }

    public function test_extracts_fixed_discount_with_min_order_value(): void
    {
        $message = '🚨 CUPOM: ECONOMIZE100 dando R$ 100 de desconto nas compras acima de R$ 1000 na Amazon: https://amazon.com.br/dp/B08';

        $coupon = $this->service->extractAndStore($message, [
            'group_name' => 'Achados de Cupons',
        ]);

        $this->assertNotNull($coupon);
        $this->assertEquals('ECONOMIZE100', $coupon->code);
        $this->assertEquals('fixed', $coupon->discount_type);
        $this->assertEquals(100.0, $coupon->discount_value);
        $this->assertEquals(1000.0, $coupon->min_order_value);
        $this->assertEquals($this->amazon->id, $coupon->store_id);
    }

    public function test_ignores_messages_with_blacklisted_keywords_as_code(): void
    {
        $message = 'ATENÇÃO: Produto em super oferta! Clique aqui para comprar agora no link.';

        $coupon = $this->service->extractAndStore($message);

        $this->assertNull($coupon);
        $this->assertEquals(0, Coupon::count());
    }

    public function test_associates_targeted_product_when_model_matches(): void
    {
        $message = 'Imperdível! Lavadora Midea 16.5kg modelo MA512W165 com cupom MIDEA150 na Amazon https://amazon.com.br/p/1';

        $coupon = $this->service->extractAndStore($message);

        $this->assertNotNull($coupon);
        $this->assertEquals('MIDEA150', $coupon->code);
        $this->assertEquals($this->product->id, $coupon->product_id);
        $this->assertEquals($this->amazon->id, $coupon->store_id);
    }

    public function test_webhook_endpoint_authenticates_and_dispatches_job(): void
    {
        Queue::fake();

        config(['services.gowa.webhook_secret' => 'teste-secret-123']);

        $payload = [
            'event' => 'message',
            'payload' => [
                'id' => 'MSG123',
                'from' => '120363025287462828@g.us',
                'is_group' => true,
                'group_name' => 'Grupo Cupons VIP',
                'message' => [
                    'conversation' => 'Use o cupom DESC15 na Amazon',
                ],
            ],
        ];

        // 1. Unauthorized when secret is wrong or missing
        $responseUnauthorized = $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Webhook-Secret' => 'errado',
        ]);
        $responseUnauthorized->assertStatus(401);

        // 2. Authorized when secret matches
        $responseAuthorized = $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Webhook-Secret' => 'teste-secret-123',
        ]);

        $responseAuthorized->assertStatus(200);
        $responseAuthorized->assertJson(['status' => 'queued']);

        Queue::assertPushed(ProcessWhatsAppGroupMessageJob::class, function ($job) {
            return ($job->data['event'] ?? null) === 'message';
        });
    }
}
