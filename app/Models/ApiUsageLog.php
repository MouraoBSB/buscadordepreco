<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiUsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'purpose',
        'query',
        'credits_used',
        'response_status',
        'duration_ms',
        'metadata',
    ];

    protected $casts = [
        'credits_used' => 'integer',
        'response_status' => 'integer',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Record an API request log.
     */
    public static function logRequest(string $provider, string $purpose, ?string $query = null, int $credits = 1, ?int $status = null, ?int $durationMs = null, array $metadata = []): self
    {
        return self::create([
            'provider' => $provider,
            'purpose' => $purpose,
            'query' => $query,
            'credits_used' => $credits,
            'response_status' => $status,
            'duration_ms' => $durationMs,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Total credits used this month for a provider.
     */
    public static function getMonthlyUsage(string $provider = 'tavily'): int
    {
        return (int) self::where('provider', $provider)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('credits_used');
    }

    /**
     * Total credits used today for a provider.
     */
    public static function getDailyUsage(string $provider = 'tavily'): int
    {
        return (int) self::where('provider', $provider)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->sum('credits_used');
    }

    /**
     * Get usage percentage of configured quota.
     */
    public static function getMonthlyUsagePercentage(string $provider = 'tavily', int $quotaLimit = 1000): float
    {
        if ($quotaLimit <= 0) {
            return 0.0;
        }

        $used = self::getMonthlyUsage($provider);

        return round(($used / $quotaLimit) * 100, 1);
    }
}
