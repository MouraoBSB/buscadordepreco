<?php

namespace App\Filament\Resources\ProductSources;

use App\Filament\Resources\ProductSources\Pages\CreateProductSource;
use App\Filament\Resources\ProductSources\Pages\EditProductSource;
use App\Filament\Resources\ProductSources\Pages\ListProductSources;
use App\Filament\Resources\ProductSources\Schemas\ProductSourceForm;
use App\Filament\Resources\ProductSources\Tables\ProductSourcesTable;
use App\Models\ProductSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductSourceResource extends Resource
{
    protected static ?string $model = ProductSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ProductSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductSourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductSources::route('/'),
            'create' => CreateProductSource::route('/create'),
            'edit' => EditProductSource::route('/{record}/edit'),
        ];
    }
}
