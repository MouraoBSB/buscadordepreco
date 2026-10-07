<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'store_id',
        'product_id',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'min_order_value',
        'applies_to_pix',
        'source_type',
        'active',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'applies_to_pix' => 'boolean',
        'active' => 'boolean',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PriceObservation::class, 'applied_coupon_id');
    }

    /**
     * Check if coupon is currently valid (active and not expired).
     */
    public function isValid(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate discount amount for a given base price and payment mode.
     */
    public function calculateDiscount(float $basePrice, bool $isPix = false): float
    {
        if (! $this->isValid()) {
            return 0.0;
        }

        if ($isPix && ! $this->applies_to_pix) {
            return 0.0;
        }

        if ($this->min_order_value !== null && $basePrice < (float) $this->min_order_value) {
            return 0.0;
        }

        $discount = 0.0;
        if ($this->discount_type === 'percentage') {
            $discount = $basePrice * ((float) $this->discount_value / 100);
        } else {
            $discount = (float) $this->discount_value;
        }

        if ($this->max_discount !== null && $discount > (float) $this->max_discount) {
            $discount = (float) $this->max_discount;
        }

        $discount = min($discount, $basePrice);

        return round($discount, 2);
    }
}
