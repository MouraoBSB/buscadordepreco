<?php

namespace App\Filament\Resources\AlertRules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AlertRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->label('Produto')
                    ->required(),

                Select::make('type')
                    ->label('Tipo de Regra')
                    ->options([
                        'target_price' => 'Preço Alvo (Abaixo de um valor)',
                        'lowest_price' => 'Menor Preço Histórico',
                        'percentage_drop' => 'Queda Percentual Significativa',
                        'stock_change' => 'Mudança de Estoque',
                    ])
                    ->required(),

                TextInput::make('threshold')
                    ->label('Gatilho / Valor Limite (R$ ou %)')
                    ->numeric(),

                Select::make('channel')
                    ->label('Canal de Alerta')
                    ->options([
                        'gowa_whatsapp' => 'WhatsApp (GoWA)',
                    ])
                    ->default('gowa_whatsapp')
                    ->required(),

                Toggle::make('active')
                    ->label('Regra Ativa')
                    ->default(true),
            ]);
    }
}
