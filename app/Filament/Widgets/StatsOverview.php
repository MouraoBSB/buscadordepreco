<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\ProductSource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $activeProductsCount = Product::where('active', true)->count();
        $activeSourcesCount = ProductSource::where('active', true)->count();
        $totalObservations = PriceObservation::where('is_mismatch', false)->count();
        $alertsSent = Alert::where('status', 'sent')->count();

        return [
            Stat::make('Produtos Monitorados', (string) $activeProductsCount)
                ->description("{$activeSourcesCount} fontes ativas")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Histórico de Preços', (string) $totalObservations)
                ->description('Observações imutáveis')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('success'),

            Stat::make('Alertas WhatsApp Enviados', (string) $alertsSent)
                ->description('Via GoWA')
                ->descriptionIcon('heroicon-m-bell')
                ->color('warning'),
        ];
    }
}
