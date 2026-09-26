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
        'shipping_price',
        'installment_price',
        'installment_count',
        'in_stock',
        'seller',
        'seller_type',
        'raw_title',
        'is_mismatch',
        'mismatch_reason',
        'collected_at',
        'metadata',
    ];

    protected $casts = [
        'regular_price' => 'decimal:2',
        'pix_price' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'installment_price' => 'decimal:2',
        'installment_count' => 'integer',
        'in_stock' => 'boolean',
        'is_mismatch' => 'boolean',
        'collected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProductSource::class, 'product_source_id');
    }

    /**
     * Get effective best price (pix price if available, otherwise regular price).
     */
    public function getEffectivePriceAttribute(): ?float
    {
        if ($this->pix_price !== null && (float) $this->pix_price > 0) {
            return (float) $this->pix_price;
        }

        return $this->regular_price !== null ? (float) $this->regular_price : null;
    }
}
