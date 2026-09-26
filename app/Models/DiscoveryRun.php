<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'provider',
        'trigger_type',
        'status',
        'queries_executed',
        'provider_stats',
        'candidates_found',
        'candidates_auto_approved',
        'candidates_pending',
        'candidates_rejected',
        'error_message',
        'duration_ms',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'queries_executed' => 'array',
        'provider_stats' => 'array',
        'candidates_found' => 'integer',
        'candidates_auto_approved' => 'integer',
        'candidates_pending' => 'integer',
        'candidates_rejected' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
