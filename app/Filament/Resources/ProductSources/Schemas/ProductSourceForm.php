<?php

namespace App\Filament\Resources\ProductSources\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->label('Produto')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('store_id')
                    ->relationship('store', 'name')
                    ->label('Loja')
                    ->searchable()
                    ->preload()
                    ->required(),

                Textarea::make('url')
                    ->label('URL do Produto')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                Select::make('collector_type')
                    ->label('Tipo de Coletor')
                    ->options([
                        'generic_jsonld' => 'Genérico (JSON-LD / Schema.org)',
                        'midea_official' => 'Midea Store Oficial',
                        'panasonic_official' => 'Panasonic Loja Oficial',
                        'samsung_official' => 'Samsung Loja Oficial',
                    ])
                    ->default('generic_jsonld')
                    ->required(),

                TextInput::make('expected_model')
                    ->label('Código do Modelo Esperado')
                    ->placeholder('ex: MA512W165/GK-05')
                    ->maxLength(100),

                Select::make('expected_voltage')
                    ->label('Tensão Obrigatória')
                    ->options([
                        '220V' => '220V',
                        '127V' => '127V',
                        'Bivolt' => 'Bivolt',
                    ])
                    ->default('220V')
                    ->required(),

                TextInput::make('priority')
                    ->label('Prioridade')
                    ->numeric()
                    ->default(1),

                Toggle::make('active')
                    ->label('Monitoramento Ativo')
                    ->default(true),
            ]);
    }
}
