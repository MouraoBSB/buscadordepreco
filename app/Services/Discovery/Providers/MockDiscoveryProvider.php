<?php

namespace App\Services\Discovery\Providers;

use App\Models\Product;
use App\Services\Discovery\Contracts\DiscoveryProviderInterface;
use App\Services\Discovery\DTOs\RawCandidateDto;

class MockDiscoveryProvider implements DiscoveryProviderInterface
{
    public function __construct(protected string $name = 'mock') {}

    /**
     * @var array<RawCandidateDto>
     */
    protected array $mockCandidates = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param  array<RawCandidateDto>  $candidates
     */
    public function setMockCandidates(array $candidates): self
    {
        $this->mockCandidates = $candidates;

        return $this;
    }

    public function search(Product $product, array $queries): array
    {
        return $this->mockCandidates;
    }
}
