<?php

namespace App\Services\Collector\Contracts;

use App\Models\ProductSource;
use App\Services\Collector\PriceResult;

interface PriceCollectorInterface
{
    /**
     * Collect price and availability for a given ProductSource.
     */
    public function collect(ProductSource $source): PriceResult;

    /**
     * Unique identifier for the collector type.
     */
    public function getIdentifier(): string;
}
