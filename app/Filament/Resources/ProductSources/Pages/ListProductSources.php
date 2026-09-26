<?php

namespace App\Filament\Resources\ProductSources\Pages;

use App\Filament\Resources\ProductSources\ProductSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductSources extends ListRecords
{
    protected static string $resource = ProductSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
