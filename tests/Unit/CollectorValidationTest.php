<?php

namespace Tests\Unit;

use App\Models\ProductSource;
use App\Services\Collector\Collectors\GenericJsonLdCollector;
use PHPUnit\Framework\TestCase;

class CollectorValidationTest extends TestCase
{
    protected GenericJsonLdCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new GenericJsonLdCollector;
    }

    public function test_rejects_127v_when_220v_is_required(): void
    {
        $reflection = new \ReflectionClass($this->collector);
        $method = $reflection->getMethod('validateModelAndVoltage');
        $method->setAccessible(true);

        $source = new ProductSource([
            'expected_model' => 'MA512W165/GK-05',
            'expected_voltage' => '220V',
        ]);

        $mismatch = $method->invoke($this->collector, $source, 'Lavadora Midea 16.5kg MA512W165/GK-05 127V Titânio');

        $this->assertNotNull($mismatch);
        $this->assertStringContainsString('127V/110V', $mismatch);
    }

    public function test_accepts_220v_when_expected_model_matches(): void
    {
        $reflection = new \ReflectionClass($this->collector);
        $method = $reflection->getMethod('validateModelAndVoltage');
        $method->setAccessible(true);

        $source = new ProductSource([
            'expected_model' => 'MA512W165/GK-05',
            'expected_voltage' => '220V',
        ]);

        $mismatch = $method->invoke($this->collector, $source, 'Lavadora de Roupas Midea 16,5 kg MA512W165/GK-05 220V');

        $this->assertNull($mismatch);
    }

    public function test_rejects_mismatch_model(): void
    {
        $reflection = new \ReflectionClass($this->collector);
        $method = $reflection->getMethod('validateModelAndVoltage');
        $method->setAccessible(true);

        $source = new ProductSource([
            'expected_model' => 'NA-F180P7',
            'expected_voltage' => '220V',
        ]);

        $mismatch = $method->invoke($this->collector, $source, 'Lavadora Panasonic 12kg NA-F120B1 220V');

        $this->assertNotNull($mismatch);
        $this->assertStringContainsString('NA-F180P7', $mismatch);
    }

    public function test_price_parser_handles_brazilian_currency(): void
    {
        $reflection = new \ReflectionClass($this->collector);
        $method = $reflection->getMethod('parsePrice');
        $method->setAccessible(true);

        $this->assertEquals(2499.90, $method->invoke($this->collector, 'R$ 2.499,90'));
        $this->assertEquals(2200.00, $method->invoke($this->collector, '2.200,00'));
        $this->assertEquals(1850.50, $method->invoke($this->collector, 1850.50));
        $this->assertNull($method->invoke($this->collector, '0,00')); // Outlier guard (below 500)
        $this->assertNull($method->invoke($this->collector, '50.000,00')); // Outlier guard (above 15000)
    }
}
