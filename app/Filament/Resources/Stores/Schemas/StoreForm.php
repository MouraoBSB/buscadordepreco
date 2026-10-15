<?php

namespace App\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome da Loja')
                    ->required()
                    ->maxLength(255),

                TextInput::make('domain')
                    ->label('Domínio')
                    ->placeholder('ex: mideastore.com.br (vazio para lojas vistas só no Google Shopping)')
                    ->dehydrateStateUsing(fn (?string $state): string => (string) $state)
                    ->maxLength(255),

                Select::make('trust_level')
                    ->label('Nível de Confiança')
                    ->options([
                        'official' => 'Oficial / Loja Própria',
                        'marketplace_1p' => 'Marketplace 1P (Vendido e entregue pela rede)',
                        'marketplace_3p' => 'Marketplace 3P (Terceiros)',
                        'standard' => 'Não verificada (encontrada automaticamente)',
                    ])
                    ->helperText('Oficial e Marketplace 1P são consideradas confiáveis: o preço delas é o principal no painel e nos alertas.')
                    ->default('official')
                    ->required(),

                Toggle::make('active')
                    ->label('Ativo')
                    ->default(true),
            ]);
    }
}
