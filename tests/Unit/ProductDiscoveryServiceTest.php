<?php

namespace Tests\Unit;

use App\Models\DiscoveryCandidate;
use App\Models\Product;
use App\Models\ProductSource;
use App\Services\Discovery\CandidateScorer;
use App\Services\Discovery\CandidateValidator;
use App\Services\Discovery\DTOs\RawCandidateDto;
use App\Services\Discovery\ProductDiscoveryService;
use App\Services\Discovery\Providers\MockDiscoveryProvider;
use App\Services\Profiling\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovers_and_auto_approves_matching_candidate(): void
    {
        $product = Product::create([
            'name' => 'Lavadora Midea 16,5 kg 220 V MA512W165/GK-05',
            'commercial_name' => 'Lavadora 16,5 kg',
            'brand' => 'Midea',
            'model_code' => 'MA512W165/GK-05',
            'voltage' => '220V',
            'capacity_kg' => 16.5,
            'target_price' => 2000.00,
            'hard_constraints' => [
                'voltage' => '220V',
                'has_agitator' => false,
            ],
            'forbidden_terms' => ['peça', 'placa', '110v', '127v'],
            'strict_model' => true,
        ]);

        $mockCandidates = [
            // Candidate 1: Exact match -> should auto approve (score >= 85)
            new RawCandidateDto(
                url: 'https://www.magazineluiza.com.br/lavadora-midea-165kg-ma512w165-220v/p/12345/ed/lava/',
                title: 'Lavadora de Roupas Midea 16,5kg MA512W165/GK-05 220V Automática',
                snippet: 'Lavadora Midea 16,5 kg sem agitador central modelo MA512W165/GK-05 220V',
                provider: 'mock'
            ),
            // Candidate 2: Incompatible voltage 127V -> rejected by validator
            new RawCandidateDto(
                url: 'https://www.casasbahia.com.br/lavadora-midea-165kg-127v/p/99999',
                title: 'Lavadora Midea 16,5kg MA512W165 127V',
                snippet: 'Tensão 127 volts modelo MA512W165',
                provider: 'mock'
            ),
            // Candidate 3: Forbidden term 'peça' -> rejected by validator
            new RawCandidateDto(
                url: 'https://www.mercadolivre.com.br/peca-placa-lavadora-midea-ma512w165/p/8888',
                title: 'Placa Lavadora Midea MA512W165 Peça Original',
                snippet: 'Peça de reposição placa de potência',
                provider: 'mock'
            ),
        ];

        $mockProvider = new MockDiscoveryProvider;
        $mockProvider->setMockCandidates($mockCandidates);

        $validator = new CandidateValidator;
        $scorer = new CandidateScorer;
        $profiler = new ProductEnrichmentService;

        $service = new ProductDiscoveryService($validator, $scorer, $profiler);
        $service->setProviders([$mockProvider]);

        $result = $service->discoverForProduct($product, ['lavadora midea 16,5kg']);

        $this->assertEquals(3, $result['candidates_found']);
        $this->assertEquals(1, $result['candidates_auto_approved']);
        $this->assertEquals(2, $result['candidates_rejected']);

        // Assert DiscoveryCandidate created
        $candidate1 = DiscoveryCandidate::where('product_id', $product->id)
            ->where('status', 'auto_approved')
            ->first();

        $this->assertNotNull($candidate1);
        $this->assertGreaterThanOrEqual(85, $candidate1->confidence_score);
        $this->assertNotNull($candidate1->product_source_id);

        // Assert ProductSource automatically generated for auto_approved
        $source = ProductSource::find($candidate1->product_source_id);
        $this->assertNotNull($source);
        $this->assertEquals($product->id, $source->product_id);
        $this->assertTrue($source->active);
        $this->assertEquals('magazineluiza.com.br', $source->store->domain);
    }
}
