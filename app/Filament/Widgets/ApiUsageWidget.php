<?php

namespace App\Filament\Widgets;

use App\Models\ApiUsageLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Consumo das APIs pagas: Serper (preços do Google Shopping) e Tavily (só fotos de produto).
 */
class ApiUsageWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $serper = ApiUsageLog::getQuotaHealth('serper');
        $serperToday = ApiUsageLog::getDailyUsage('serper');
        $tavilyMonth = ApiUsageLog::getMonthlyUsage('tavily');

        $color = match ($serper['status']) {
            'bloqueado', 'critico' => 'danger',
            'atencao' => 'warning',
            default => 'success',
        };

        return [
            Stat::make('Consumo Serper / Google Shopping (mês)', "{$serper['used']} / {$serper['limit']}")
                ->description("{$serper['percentage']}% do orçamento mensal ({$serper['remaining']} restantes)")
                ->descriptionIcon($serper['status'] === 'normal' ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                ->color($color),

            Stat::make('Créditos Serper hoje', (string) $serperToday)
                ->description('2 créditos por produto a cada busca')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary'),

            Stat::make('Tavily (fotos de produto) no mês', (string) $tavilyMonth)
                ->description('Usado só para buscar a foto ao cadastrar')
                ->descriptionIcon('heroicon-m-photo')
                ->color('gray'),
        ];
    }
}
