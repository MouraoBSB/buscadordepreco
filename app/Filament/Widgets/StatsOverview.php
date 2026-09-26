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

        // Get the 3 initial target products
        $targetProducts = Product::where('active', true)->take(3)->get();
        $stats = [
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

        foreach ($targetProducts as $prod) {
            $lowestObs = PriceObservation::query()
                ->whereHas('source', fn ($q) => $q->where('product_id', $prod->id))
                ->where('is_mismatch', false)
                ->where('in_stock', true)
                ->orderByRaw('COALESCE(pix_price, regular_price) ASC')
                ->first();

            $priceText = $lowestObs
                ? 'R$ '.number_format($lowestObs->effective_price, 2, ',', '.')
                : 'Aguardando coleta';

            $targetText = $prod->target_price
                ? 'Meta: R$ '.number_format((float) $prod->target_price, 2, ',', '.')
                : '';

            $isBelowTarget = $lowestObs && $prod->target_price && $lowestObs->effective_price <= (float) $prod->target_price;

            $stats[] = Stat::make($prod->brand.' '.$prod->model_code, $priceText)
                ->description($targetText)
                ->descriptionIcon($isBelowTarget ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-tag')
                ->color($isBelowTarget ? 'success' : 'info');
        }

        return $stats;
    }
}
