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
     * Total credits used this month for a specific purpose (profiling or discovery).
     */
    public static function getMonthlyUsageByPurpose(string $purpose, string $provider = 'tavily'): int
    {
        return (int) self::where('provider', $provider)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('credits_used');
    }

    /**
     * Get usage percentage of configured quota.
     */
    public static function getMonthlyUsagePercentage(string $provider = 'tavily', ?int $quotaLimit = null): float
    {
        $limit = $quotaLimit ?? (int) config('services.tavily.monthly_limit', 1000);
        if ($limit <= 0) {
            return 0.0;
        }

        $used = self::getMonthlyUsage($provider);

        return round(($used / $limit) * 100, 1);
    }

    /**
     * Health check and alert state for external quota.
     *
     * @return array{status: string, percentage: float, used: int, limit: int, remaining: int, circuit_breaker: bool, alert_level: ?string}
     */
    public static function getQuotaHealth(string $provider = 'tavily'): array
    {
        $limit = (int) config("services.{$provider}.monthly_limit", 1000);
        $used = self::getMonthlyUsage($provider);
        $pct = $limit > 0 ? round(($used / $limit) * 100, 1) : 0.0;
        $remaining = max(0, $limit - $used);

        $status = match (true) {
            $pct >= 95.0 => 'bloqueado',
            $pct >= 85.0 => 'critico',
            $pct >= 70.0 => 'atencao',
            default => 'normal',
        };

        $alertLevel = match (true) {
            $pct >= 95.0 => '95% - Circuit Breaker Ativo (Descobertas Recorrentes Bloqueadas)',
            $pct >= 85.0 => '85% - Atenção Crítica na Cota',
            $pct >= 70.0 => '70% - Consumo Elevado',
            default => null,
        };

        return [
            'status' => $status,
            'percentage' => $pct,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'circuit_breaker' => $pct >= 95.0,
            'alert_level' => $alertLevel,
        ];
    }
}
