<?php

namespace App\Filament\Resources\AlertRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlertRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->limit(35),

                TextColumn::make('type')
                    ->label('Tipo de Regra')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'target_price' => 'success',
                        'lowest_price' => 'warning',
                        'percentage_drop' => 'danger',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'target_price' => 'Preço Alvo',
                        'lowest_price' => 'Menor Histórico',
                        'percentage_drop' => 'Queda %',
                        'stock_change' => 'Estoque',
                        default => $state,
                    }),

                TextColumn::make('threshold')
                    ->label('Limite')
                    ->money('BRL', locale: 'pt_BR')
                    ->placeholder('Qualquer queda'),

                TextColumn::make('channel')
                    ->label('Canal')
                    ->badge(),

                IconColumn::make('active')
                    ->label('Ativa')
                    ->boolean(),
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
