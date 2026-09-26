<?php

namespace App\Services\Discovery\Contracts;

use App\Models\Product;
use App\Services\Discovery\DTOs\RawCandidateDto;

interface DiscoveryProviderInterface
{
    /**
     * Provider identifier name (e.g. tavily, direct_store, mock).
     */
    public function getName(): string;

    /**
     * Check if provider is enabled and ready to execute.
     */
    public function isAvailable(): bool;

    /**
     * Execute search for a product and return list of raw candidate DTOs.
     *
     * @param  array<string>  $queries
     * @return array<RawCandidateDto>
     */
    public function search(Product $product, array $queries): array;
}
