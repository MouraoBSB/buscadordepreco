<?php

namespace App\Filament\Resources\CollectionRuns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CollectionRunsTable
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

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'mismatch' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('http_status')
                    ->label('HTTP')
                    ->badge(),

                TextColumn::make('duration_ms')
                    ->label('Duração')
                    ->suffix(' ms')
                    ->sortable(),

                TextColumn::make('error_message')
                    ->label('Detalhes / Erro')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->error_message),

                TextColumn::make('started_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('started_at', 'desc')
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
