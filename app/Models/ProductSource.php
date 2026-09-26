<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'store_id',
        'url',
        'collector_type',
        'expected_model',
        'expected_voltage',
        'active',
        'priority',
    ];

    protected $casts = [
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PriceObservation::class);
    }

    public function latestObservation(): HasOne
    {
        return $this->hasOne(PriceObservation::class)->latestOfMany('collected_at');
    }

    public function collectionRuns(): HasMany
    {
        return $this->hasMany(CollectionRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(CollectionRun::class)->latestOfMany('started_at');
    }
}
