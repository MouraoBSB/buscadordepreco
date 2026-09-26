<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'store_id',
        'discovered_store_name',
        'discovered_store_domain',
        'url',
        'url_hash',
        'raw_title',
        'detected_model',
        'detected_price',
        'seller',
        'seller_type',
        'confidence_score',
        'scoring_breakdown',
        'status',
        'rejection_reason',
        'discovery_provider',
        'product_source_id',
        'metadata',
        'discovered_at',
        'reviewed_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'detected_price' => 'decimal:2',
        'scoring_breakdown' => 'array',
        'metadata' => 'array',
        'discovered_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function productSource(): BelongsTo
    {
        return $this->belongsTo(ProductSource::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_review';
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'auto_approved'], true);
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
