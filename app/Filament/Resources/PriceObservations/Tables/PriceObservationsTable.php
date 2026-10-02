<?php

namespace App\Filament\Resources\PriceObservations\Tables;

use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PriceObservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (PriceObservation $record): ?string => $record->source?->url, shouldOpenInNewTab: true)
            ->recordAction(null)
            ->columns([
                TextColumn::make('source.product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->weight('medium')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after')
                    ->url(fn (PriceObservation $record): ?string => $record->source?->url)
                    ->openUrlInNewTab()
                    ->tooltip(fn (PriceObservation $record): string => $record->source?->url ? 'Abrir link da oferta na loja' : '')
                    ->limit(30),

                TextColumn::make('source.store.name')
                    ->label('Loja')
                    ->badge()
                    ->url(fn (PriceObservation $record): ?string => $record->source?->url)
                    ->openUrlInNewTab(),

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
                SelectFilter::make('product')
                    ->label('Filtrar por Produto')
                    ->options(fn () => Product::all()->mapWithKeys(fn ($p) => [$p->id => ($p->brand ? $p->brand.' - ' : '').($p->commercial_name ?: $p->name)])->toArray())
                    ->query(fn ($query, array $data) => ! empty($data['value']) ? $query->whereHas('source', fn ($q) => $q->where('product_id', $data['value'])) : $query),

                SelectFilter::make('store')
                    ->label('Filtrar por Loja')
                    ->options(fn () => Store::pluck('name', 'id')->toArray())
                    ->query(fn ($query, array $data) => ! empty($data['value']) ? $query->whereHas('source', fn ($q) => $q->where('store_id', $data['value'])) : $query),
            ])
            ->recordActions([
                Action::make('open_url')
                    ->label('Abrir Oferta')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (PriceObservation $record): ?string => $record->source?->url)
                    ->openUrlInNewTab()
                    ->visible(fn (PriceObservation $record): bool => filled($record->source?->url)),

                ViewAction::make()
                    ->extraModalFooterActions(fn (PriceObservation $record): array => array_filter([
                        filled($record->source?->url)
                            ? Action::make('openInStore')
                                ->label('Abrir no Site da Loja')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->color('primary')
                                ->url($record->source?->url)
                                ->openUrlInNewTab()
                            : null,
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
