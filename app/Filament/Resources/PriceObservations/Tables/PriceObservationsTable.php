<?php

namespace App\Filament\Resources\PriceObservations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceObservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source.product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('source.store.name')
                    ->label('Loja')
                    ->badge(),

                TextColumn::make('regular_price')
                    ->label('Preço Normal')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),

                TextColumn::make('pix_price')
                    ->label('Preço Pix')
                    ->money('BRL', locale: 'pt_BR')
                    ->weight('bold')
                    ->color('success')
                    ->sortable(),

                TextColumn::make('seller')
                    ->label('Vendedor')
                    ->placeholder('Própria loja')
                    ->limit(20),

                IconColumn::make('in_stock')
                    ->label('Estoque')
                    ->boolean(),

                IconColumn::make('is_mismatch')
                    ->label('Mismatch')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),

                TextColumn::make('collected_at')
                    ->label('Data Coleta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('collected_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
