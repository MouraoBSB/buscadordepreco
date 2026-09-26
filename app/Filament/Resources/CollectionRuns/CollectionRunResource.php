<?php

namespace App\Filament\Resources\CollectionRuns;

use App\Filament\Resources\CollectionRuns\Pages\CreateCollectionRun;
use App\Filament\Resources\CollectionRuns\Pages\EditCollectionRun;
use App\Filament\Resources\CollectionRuns\Pages\ListCollectionRuns;
use App\Filament\Resources\CollectionRuns\Schemas\CollectionRunForm;
use App\Filament\Resources\CollectionRuns\Tables\CollectionRunsTable;
use App\Models\CollectionRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CollectionRunResource extends Resource
{
    protected static ?string $model = CollectionRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $modelLabel = 'Execução de Coleta';

    protected static ?string $pluralModelLabel = 'Saúde das Fontes';

    protected static ?string $navigationLabel = 'Saúde das Fontes';

    protected static string|UnitEnum|null $navigationGroup = 'Monitoramento';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return CollectionRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CollectionRunsTable::configure($table);
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
            'index' => ListCollectionRuns::route('/'),
            'create' => CreateCollectionRun::route('/create'),
            'edit' => EditCollectionRun::route('/{record}/edit'),
        ];
    }
}
