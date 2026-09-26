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
        'brand',
        'image_url',
        'model_code',
        'capacity_kg',
        'voltage',
        'target_price',
        'active',
        'metadata',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:1',
        'target_price' => 'decimal:2',
        'active' => 'boolean',
        'metadata' => 'array',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
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
