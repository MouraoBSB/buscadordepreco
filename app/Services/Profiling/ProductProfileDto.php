<?php

namespace App\Services\Profiling;

class ProductProfileDto
{
    public function __construct(
        public string $name,
        public ?string $commercialName = null,
        public ?string $brand = null,
        public ?string $category = null,
        public ?string $modelCode = null,
        public ?float $capacityKg = null,
        public ?string $voltage = null,
        public ?float $targetPrice = null,
        public array $hardConstraints = [],
        public array $inferredAttributes = [],
        public array $requiredTerms = [],
        public array $forbiddenTerms = [],
        public bool $strictModel = false,
        public ?string $imageUrl = null,
        public array $suggestedQueries = []
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'commercial_name' => $this->commercialName,
            'brand' => $this->brand,
            'category' => $this->category,
            'model_code' => $this->modelCode,
            'capacity_kg' => $this->capacityKg,
            'voltage' => $this->voltage,
            'target_price' => $this->targetPrice,
            'hard_constraints' => $this->hardConstraints,
            'inferred_attributes' => $this->inferredAttributes,
            'required_terms' => $this->requiredTerms,
            'forbidden_terms' => $this->forbiddenTerms,
            'strict_model' => $this->strictModel,
            'image_url' => $this->imageUrl,
            'suggested_queries' => $this->suggestedQueries,
        ];
    }
}
