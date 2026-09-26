<?php

namespace App\Filament\Resources\AlertRules;

use App\Filament\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Resources\AlertRules\Tables\AlertRulesTable;
use App\Models\AlertRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $modelLabel = 'Regra de Alerta';

    protected static ?string $pluralModelLabel = 'Regras de Alerta';

    protected static ?string $navigationLabel = 'Regras de Alerta';

    protected static string|UnitEnum|null $navigationGroup = 'Alertas WhatsApp';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return AlertRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertRulesTable::configure($table);
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
            'index' => ListAlertRules::route('/'),
            'create' => CreateAlertRule::route('/create'),
            'edit' => EditAlertRule::route('/{record}/edit'),
        ];
    }
}
