<?php

namespace Tests\Unit;

use App\Services\Profiling\ProductEnrichmentService;
use PHPUnit\Framework\TestCase;

class ProductEnrichmentServiceTest extends TestCase
{
    protected ProductEnrichmentService $profiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiler = new ProductEnrichmentService();
    }

    public function test_profiles_tv_in_natural_language(): void
    {
        $dto = $this->profiler->profileFromText('LG OLED C6 55 polegadas', 4500.00);

        $this->assertEquals('LG', $dto->brand);
        $this->assertEquals('Televisores & Áudio', $dto->category);
        $this->assertEquals('55"', $dto->hardConstraints['screen_size'] ?? null);
        $this->assertContains('55"', $dto->requiredTerms);
        // Excludes conflicting screen sizes
        $this->assertContains('43"', $dto->forbiddenTerms);
        $this->assertContains('65"', $dto->forbiddenTerms);
        // User did not ask for parts or used, so they are protected
        $this->assertContains('peça', $dto->forbiddenTerms);
        $this->assertContains('usado', $dto->forbiddenTerms);
    }

    public function test_profiles_washing_machine_with_hard_constraints(): void
    {
        $dto = $this->profiler->profileFromText('Midea MA512W165/GK-05 16,5 kg 220 V sem agitador', 2200.00);

        $this->assertEquals('Midea', $dto->brand);
        $this->assertEquals('MA512W165/GK-05', $dto->modelCode);
        $this->assertEquals('220V', $dto->voltage);
        $this->assertEquals(16.5, $dto->capacityKg);
        $this->assertFalse($dto->hardConstraints['has_agitator']);
        $this->assertContains('220V', $dto->requiredTerms);
        // Voltage opposites forbidden
        $this->assertContains('110V', $dto->forbiddenTerms);
        $this->assertContains('127V', $dto->forbiddenTerms);
        // Agitator forbidden
        $this->assertContains('com agitador', $dto->forbiddenTerms);
    }

    public function test_context_aware_rules_permits_parts_when_explicitly_requested(): void
    {
        $dto = $this->profiler->profileFromText('Placa de potência lavadora Midea MA512W165 220V');

        // Since the user is searching for a part ("Placa"), "placa" must NOT be in forbidden terms!
        $this->assertNotContains('placa', $dto->forbiddenTerms);
        $this->assertNotContains('peça', $dto->forbiddenTerms);
    }

    public function test_context_aware_rules_permits_used_when_explicitly_requested(): void
    {
        $dto = $this->profiler->profileFromText('iPhone 14 Pro 128GB usado');

        // Since the user is searching for used ("usado"), "usado" must NOT be in forbidden terms!
        $this->assertNotContains('usado', $dto->forbiddenTerms);
    }
}
