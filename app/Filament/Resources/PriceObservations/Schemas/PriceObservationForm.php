<?php

namespace App\Filament\Resources\PriceObservations\Schemas;

use App\Models\PriceObservation;
use Filament\Actions\Action;
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
                TextInput::make('source_url')
                    ->label('Link do Produto na Loja')
                    ->formatStateUsing(fn (?PriceObservation $record): ?string => $record?->source?->url)
                    ->columnSpanFull()
                    ->disabled()
                    ->suffixAction(
                        Action::make('openSourceUrl')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->tooltip('Abrir link em nova aba')
                            ->url(fn (?PriceObservation $record): ?string => $record?->source?->url)
                            ->openUrlInNewTab()
                            ->visible(fn (?PriceObservation $record): bool => filled($record?->source?->url))
                    ),

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
                    ->disabled()
                    ->suffixAction(
                        Action::make('openRawTitleUrl')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->tooltip('Abrir link do produto')
                            ->url(fn (?PriceObservation $record): ?string => $record?->source?->url)
                            ->openUrlInNewTab()
                            ->visible(fn (?PriceObservation $record): bool => filled($record?->source?->url))
                    ),

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
