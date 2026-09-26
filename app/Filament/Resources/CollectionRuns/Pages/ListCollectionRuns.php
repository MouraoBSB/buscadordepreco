<?php

namespace App\Filament\Resources\CollectionRuns\Pages;

use App\Filament\Resources\CollectionRuns\CollectionRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCollectionRuns extends ListRecords
{
    protected static string $resource = CollectionRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
