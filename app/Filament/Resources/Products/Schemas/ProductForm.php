<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome do Produto')
                    ->required()
                    ->maxLength(255),

                TextInput::make('brand')
                    ->label('Marca')
                    ->required()
                    ->maxLength(100),

                TextInput::make('model_code')
                    ->label('Código do Modelo')
                    ->required()
                    ->maxLength(100),

                TextInput::make('image_url')
                    ->label('Caminho ou URL da Foto do Produto')
                    ->placeholder('ex: /images/products/midea-ma512w165.jpg')
                    ->maxLength(500),

                TextInput::make('capacity_kg')
                    ->label('Capacidade (kg)')
                    ->numeric()
                    ->required(),

                Select::make('voltage')
                    ->label('Tensão')
                    ->options([
                        '220V' => '220V',
                        '127V' => '127V',
                        'Bivolt' => 'Bivolt',
                    ])
                    ->default('220V')
                    ->required(),

                TextInput::make('target_price')
                    ->label('Preço Alvo (R$)')
                    ->numeric()
                    ->prefix('R$'),

                Toggle::make('active')
                    ->label('Ativo')
                    ->default(true),

                KeyValue::make('metadata')
                    ->label('Metadados / Especificações Adicionais')
                    ->columnSpanFull(),
            ]);
    }
}
