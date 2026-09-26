<?php

namespace App\Services\Collector;

use App\Models\ProductSource;
use App\Services\Collector\Collectors\GenericJsonLdCollector;
use App\Services\Collector\Contracts\PriceCollectorInterface;
use InvalidArgumentException;

class CollectorManager
{
    /**
     * @var array<string, PriceCollectorInterface>
     */
    protected array $collectors = [];

    public function __construct()
    {
        $this->registerCollector(new GenericJsonLdCollector);
    }

    public function registerCollector(PriceCollectorInterface $collector): void
    {
        $this->collectors[$collector->getIdentifier()] = $collector;
    }

    public function getCollectorForSource(ProductSource $source): PriceCollectorInterface
    {
        $type = $source->collector_type ?: 'generic_jsonld';

        if (isset($this->collectors[$type])) {
            return $this->collectors[$type];
        }

        // Fallback to generic_jsonld
        if (isset($this->collectors['generic_jsonld'])) {
            return $this->collectors['generic_jsonld'];
        }

        throw new InvalidArgumentException("Nenhum coletor registrado para o tipo '{$type}'");
    }

    public function collect(ProductSource $source): PriceResult
    {
        $collector = $this->getCollectorForSource($source);

        return $collector->collect($source);
    }
}
