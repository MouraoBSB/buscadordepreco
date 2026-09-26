<?php

namespace App\Filament\Resources\ProductSources\Pages;

use App\Filament\Resources\ProductSources\ProductSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductSource extends CreateRecord
{
    protected static string $resource = ProductSourceResource::class;
}
