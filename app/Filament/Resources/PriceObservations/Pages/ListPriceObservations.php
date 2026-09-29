<?php

namespace App\Filament\Resources\PriceObservations\Pages;

use App\Filament\Resources\PriceObservations\PriceObservationResource;
use App\Filament\Resources\PriceObservations\Widgets\PriceHistoryChartWidget;
use Filament\Resources\Pages\ListRecords;

class ListPriceObservations extends ListRecords
{
    protected static string $resource = PriceObservationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PriceHistoryChartWidget::class,
        ];
    }
}
