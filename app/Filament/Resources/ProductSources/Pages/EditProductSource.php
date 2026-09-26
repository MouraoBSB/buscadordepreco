<?php

namespace App\Filament\Resources\ProductSources\Pages;

use App\Filament\Resources\ProductSources\ProductSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductSource extends EditRecord
{
    protected static string $resource = ProductSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
