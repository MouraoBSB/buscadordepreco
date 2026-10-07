<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_source_id',
        'regular_price',
        'pix_price',
        'coupon_price',
        'applied_coupon_id',
        'coupon_code',
        'coupon_discount',
        'shipping_price',
        'installment_price',
        'installment_count',
        'in_stock',
        'seller',
        'seller_type',
        'raw_title',
        'is_mismatch',
        'mismatch_reason',
        'is_suspicious',
        'sanity_check_reason',
        'collected_at',
        'metadata',
    ];

    protected $casts = [
        'regular_price' => 'decimal:2',
        'pix_price' => 'decimal:2',
        'coupon_price' => 'decimal:2',
        'coupon_discount' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'installment_price' => 'decimal:2',
        'installment_count' => 'integer',
        'in_stock' => 'boolean',
        'is_mismatch' => 'boolean',
        'is_suspicious' => 'boolean',
        'collected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProductSource::class, 'product_source_id');
    }

    public function appliedCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'applied_coupon_id');
    }

    /**
     * Get effective best price (coupon price if lower, otherwise pix price, otherwise regular price).
     */
    public function getEffectivePriceAttribute(): ?float
    {
        $prices = array_filter([
            $this->coupon_price !== null ? (float) $this->coupon_price : null,
            $this->pix_price !== null ? (float) $this->pix_price : null,
            $this->regular_price !== null ? (float) $this->regular_price : null,
        ], fn ($p) => $p !== null && $p > 0);

        return ! empty($prices) ? min($prices) : null;
    }
}
