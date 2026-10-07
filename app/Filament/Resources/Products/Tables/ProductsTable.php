<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Services\Discovery\ProductDiscoveryService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Foto')
                    ->square()
                    ->size(48),

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
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),

                TextColumn::make('candidates_count')
                    ->label('Ofertas Descobertas')
                    ->counts('candidates')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('discover')
                    ->label('Buscar Ofertas')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Descobrir Novas Ofertas')
                    ->modalDescription('O PriceWatch pesquisará os varejistas e motores de busca para encontrar novas fontes de compra para este produto.')
                    ->action(function (Product $record) {
                        $service = app(ProductDiscoveryService::class);
                        $result = $service->discoverForProduct($record, triggerType: 'manual');

                        Notification::make()
                            ->title('Descoberta finalizada!')
                            ->body("{$result['candidates_found']} ofertas analisadas: {$result['candidates_auto_approved']} auto-aprovadas, {$result['candidates_pending']} pendentes, {$result['candidates_rejected']} rejeitadas.")
                            ->success()
                            ->send();
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
