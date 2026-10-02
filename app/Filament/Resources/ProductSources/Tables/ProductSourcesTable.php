<?php

namespace App\Filament\Resources\ProductSources\Tables;

use App\Models\ProductSource;
use App\Services\Collector\Pipeline\CollectionPipeline;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ProductSource $record): ?string => $record->url, shouldOpenInNewTab: true)
            ->recordAction(null)
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->weight('bold')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after')
                    ->url(fn (ProductSource $record): ?string => $record->url)
                    ->openUrlInNewTab()
                    ->tooltip(fn (ProductSource $record): string => 'Abrir link na loja')
                    ->limit(35),

                TextColumn::make('store.name')
                    ->label('Loja')
                    ->badge()
                    ->url(fn (ProductSource $record): ?string => $record->url)
                    ->openUrlInNewTab()
                    ->sortable(),

                TextColumn::make('expected_voltage')
                    ->label('Tensão')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('latestObservation.effective_price')
                    ->label('Último Preço')
                    ->money('BRL', locale: 'pt_BR')
                    ->placeholder('Nenhum'),

                TextColumn::make('latestObservation.in_stock')
                    ->label('Estoque')
                    ->formatStateUsing(fn ($state) => $state ? 'Em estoque' : 'Esgotado')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger'),

                TextColumn::make('latestRun.status')
                    ->label('Status Coleta')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'mismatch' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('latestRun.finished_at')
                    ->label('Última Coleta')
                    ->since()
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('open_url')
                    ->label('Abrir Link')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (ProductSource $record): ?string => $record->url)
                    ->openUrlInNewTab(),

                Action::make('collect_now')
                    ->label('Coletar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Coleta Manual de Preço')
                    ->modalDescription('Deseja iniciar a requisição imediatamente para esta URL?')
                    ->action(function (ProductSource $record) {
                        $pipeline = app(CollectionPipeline::class);
                        $result = $pipeline->run($record);

                        if ($result->isSuccess) {
                            $priceStr = number_format($result->getEffectivePrice() ?? 0, 2, ',', '.');
                            Notification::make()
                                ->title('Coleta realizada com sucesso!')
                                ->body("Preço identificado: R$ {$priceStr}")
                                ->success()
                                ->send();
                        } elseif ($result->isMismatch) {
                            Notification::make()
                                ->title('Alerta de Mismatch')
                                ->body($result->mismatchReason)
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Falha na coleta')
                                ->body($result->errorMessage)
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
