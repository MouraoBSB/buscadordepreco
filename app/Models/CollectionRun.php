<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_source_id',
        'status',
        'http_status',
        'duration_ms',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProductSource::class, 'product_source_id');
    }
}
