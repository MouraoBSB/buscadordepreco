<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DiscoveryCandidate;
use App\Models\Product;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Alerts\Channels\GoWaChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CandidateAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_triggers_alert_when_detected_price_is_below_target(): void
    {
        $mockGoWa = Mockery::mock(GoWaChannel::class);
        $mockGoWa->shouldReceive('sendMessage')
            ->once()
            ->andReturn(['ok' => true, 'data' => ['id' => 'msg-123']]);
        $this->app->instance(GoWaChannel::class, $mockGoWa);

        $product = Product::create([
            'name' => 'Lavadora Midea 16,5kg',
            'commercial_name' => 'Midea Top Load 16,5kg Titanium',
            'target_price' => 2200.00,
            'active' => true,
        ]);

        $rule = AlertRule::create([
            'product_id' => $product->id,
            'type' => 'target_price',
            'threshold' => 2200.00,
            'active' => true,
        ]);

        $candidate = DiscoveryCandidate::create([
            'product_id' => $product->id,
            'url' => 'https://www.casasbahia.com.br/maquina-de-lavar-midea/p/15647890',
            'url_hash' => hash('sha256', 'https://www.casasbahia.com.br/maquina-de-lavar-midea/p/15647890'),
            'raw_title' => 'Máquina de Lavar Midea 16.5kg Titanium',
            'discovered_store_name' => 'Casas Bahia',
            'discovered_store_domain' => 'casasbahia.com.br',
            'detected_price' => 1994.00,
            'confidence_score' => 95,
            'status' => 'auto_approved',
        ]);

        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateCandidate($candidate);

        $this->assertDatabaseHas('alerts', [
            'product_id' => $product->id,
            'rule_id' => $rule->id,
            'status' => 'sent',
        ]);

        $alert = Alert::where('product_id', $product->id)->first();
        $this->assertNotNull($alert);
        $this->assertEquals(1994.00, $alert->payload['price']);
        $this->assertEquals('Casas Bahia', $alert->payload['store']);
        $this->assertEquals('discovery_candidate', $alert->payload['source_type']);
    }

    public function test_check_candidate_alerts_command(): void
    {
        $mockGoWa = Mockery::mock(GoWaChannel::class);
        $mockGoWa->shouldReceive('sendMessage')
            ->once()
            ->andReturn(['ok' => true]);
        $this->app->instance(GoWaChannel::class, $mockGoWa);

        $product = Product::create([
            'name' => 'Lavadora Midea 16,5kg',
            'commercial_name' => 'Midea Top Load 16,5kg Titanium',
            'target_price' => 2200.00,
            'active' => true,
        ]);

        AlertRule::create([
            'product_id' => $product->id,
            'type' => 'target_price',
            'threshold' => 2200.00,
            'active' => true,
        ]);

        $candidate = DiscoveryCandidate::create([
            'product_id' => $product->id,
            'url' => 'https://www.casasbahia.com.br/maquina-de-lavar-midea/p/15647890',
            'url_hash' => hash('sha256', 'https://www.casasbahia.com.br/maquina-de-lavar-midea/p/15647890'),
            'raw_title' => 'Máquina de Lavar Midea 16.5kg Titanium',
            'discovered_store_name' => 'Casas Bahia',
            'discovered_store_domain' => 'casasbahia.com.br',
            'detected_price' => 1994.00,
            'confidence_score' => 95,
            'status' => 'auto_approved',
        ]);

        $this->artisan('pricewatch:check-candidate-alerts', ['--product' => $product->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('alerts', [
            'product_id' => $product->id,
            'status' => 'sent',
        ]);

        // Also asserts product_source was ensured
        $candidate->refresh();
        $this->assertNotNull($candidate->product_source_id);
    }
}
