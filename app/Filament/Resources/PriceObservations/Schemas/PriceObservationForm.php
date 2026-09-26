<?php

namespace App\Filament\Resources\PriceObservations\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PriceObservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('regular_price')
                    ->label('Preço Normal')
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),

                TextInput::make('pix_price')
                    ->label('Preço Pix')
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),

                TextInput::make('shipping_price')
                    ->label('Frete')
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),

                TextInput::make('installment_price')
                    ->label('Preço Parcelado')
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),

                TextInput::make('seller')
                    ->label('Vendedor')
                    ->disabled(),

                TextInput::make('raw_title')
                    ->label('Título Capturado')
                    ->columnSpanFull()
                    ->disabled(),

                Toggle::make('in_stock')
                    ->label('Em Estoque')
                    ->disabled(),

                Toggle::make('is_mismatch')
                    ->label('É Mismatch')
                    ->disabled(),

                TextInput::make('mismatch_reason')
                    ->label('Motivo Mismatch')
                    ->columnSpanFull()
                    ->disabled(),

                KeyValue::make('metadata')
                    ->label('Metadados da Coleta')
                    ->columnSpanFull()
                    ->disabled(),
            ]);
    }
}
