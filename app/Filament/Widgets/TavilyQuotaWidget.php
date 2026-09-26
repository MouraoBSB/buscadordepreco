<?php

namespace App\Filament\Widgets;

use App\Models\ApiUsageLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TavilyQuotaWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $health = ApiUsageLog::getQuotaHealth('tavily');
        $today = ApiUsageLog::getDailyUsage('tavily');
        $profilingUsed = ApiUsageLog::getMonthlyUsageByPurpose('profiling', 'tavily');
        $discoveryUsed = ApiUsageLog::getMonthlyUsageByPurpose('discovery', 'tavily');

        $color = match ($health['status']) {
            'bloqueado' => 'danger',
            'critico' => 'danger',
            'atencao' => 'warning',
            default => 'success',
        };

        $icon = match ($health['status']) {
            'bloqueado' => 'heroicon-m-shield-exclamation',
            'critico' => 'heroicon-m-exclamation-triangle',
            'atencao' => 'heroicon-m-clock',
            default => 'heroicon-m-check-badge',
        };

        $circuitText = $health['circuit_breaker']
            ? 'Circuit breaker ATIVO (Descobertas pausadas)'
            : 'Proteção ativa: nunca migra para pago';

        return [
            Stat::make('Consumo Tavily Search (Mês)', "{$health['used']} / {$health['limit']}")
                ->description("{$health['percentage']}% utilizado ({$health['remaining']} restantes)")
                ->descriptionIcon($icon)
                ->color($color),

            Stat::make('Consultas Hoje', "{$today} requisições")
                ->description('Auditoria em tempo real')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary'),

            Stat::make('Distribuição de Consultas', "{$profilingUsed} Perfil / {$discoveryUsed} Descoberta")
                ->description($circuitText)
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('info'),
        ];
    }
}
