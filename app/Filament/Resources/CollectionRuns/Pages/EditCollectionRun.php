<?php

namespace App\Filament\Resources\CollectionRuns\Pages;

use App\Filament\Resources\CollectionRuns\CollectionRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCollectionRun extends EditRecord
{
    protected static string $resource = CollectionRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
