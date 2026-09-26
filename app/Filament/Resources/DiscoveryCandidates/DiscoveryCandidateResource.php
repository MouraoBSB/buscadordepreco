<?php

namespace App\Filament\Resources\DiscoveryCandidates;

use App\Filament\Resources\DiscoveryCandidates\Pages\ListDiscoveryCandidates;
use App\Filament\Resources\DiscoveryCandidates\Tables\DiscoveryCandidatesTable;
use App\Models\DiscoveryCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DiscoveryCandidateResource extends Resource
{
    protected static ?string $model = DiscoveryCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $modelLabel = 'Oferta Descoberta';

    protected static ?string $pluralModelLabel = 'Ofertas Descobertas';

    protected static ?string $navigationLabel = 'Descoberta de Ofertas';

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return DiscoveryCandidatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscoveryCandidates::route('/'),
        ];
    }
}
