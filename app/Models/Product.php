<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'commercial_name',
        'brand',
        'image_url',
        'model_code',
        'capacity_kg',
        'voltage',
        'target_price',
        'active',
        'metadata',
        'hard_constraints',
        'inferred_attributes',
        'required_terms',
        'forbidden_terms',
        'strict_model',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:1',
        'target_price' => 'decimal:2',
        'active' => 'boolean',
        'metadata' => 'array',
        'hard_constraints' => 'array',
        'inferred_attributes' => 'array',
        'required_terms' => 'array',
        'forbidden_terms' => 'array',
        'strict_model' => 'boolean',
    ];

    public function getImageUrlAttribute(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return asset(ltrim($value, '/'));
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function discoveryCandidates(): HasMany
    {
        return $this->hasMany(DiscoveryCandidate::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(DiscoveryCandidate::class);
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
