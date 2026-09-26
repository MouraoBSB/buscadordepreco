<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(40),

                TextColumn::make('brand')
                    ->label('Marca')
                    ->sortable()
                    ->badge(),

                TextColumn::make('model_code')
                    ->label('Modelo')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('capacity_kg')
                    ->label('Capacidade')
                    ->suffix(' kg')
                    ->sortable(),

                TextColumn::make('voltage')
                    ->label('Tensão')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('target_price')
                    ->label('Preço Alvo')
                    ->money('BRL')
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
