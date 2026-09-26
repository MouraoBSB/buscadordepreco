<?php

namespace App\Filament\Resources\DiscoveryCandidates\Tables;

use App\Models\DiscoveryCandidate;
use App\Models\ProductSource;
use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DiscoveryCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(35),

                TextColumn::make('discovered_store_name')
                    ->label('Loja')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('raw_title')
                    ->label('Título do Anúncio')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (DiscoveryCandidate $record): string => $record->raw_title ?? ''),

                TextColumn::make('confidence_score')
                    ->label('Score')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state >= 85 => 'success',
                        $state >= 60 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'auto_approved' => 'success',
                        'approved' => 'info',
                        'pending_review' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'auto_approved' => 'Auto Aprovado',
                        'approved' => 'Aprovado',
                        'pending_review' => 'Pendente',
                        'rejected' => 'Rejeitado',
                        default => $state,
                    }),

                TextColumn::make('rejection_reason')
                    ->label('Motivo / Incompatibilidade')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (DiscoveryCandidate $record): ?string => $record->rejection_reason),

                TextColumn::make('discovery_provider')
                    ->label('Provedor')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('discovered_at')
                    ->label('Descoberto em')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('discovered_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Aprovar e Monitorar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DiscoveryCandidate $record): bool => in_array($record->status, ['pending_review', 'rejected'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Aprovar Oferta para Monitoramento')
                    ->modalDescription('Esta oferta será convertida em uma Fonte de Coleta ativa e terá seu preço monitorado diariamente.')
                    ->action(function (DiscoveryCandidate $record) {
                        // Find or create store
                        $domain = $record->discovered_store_domain;
                        $store = null;
                        if (! empty($domain)) {
                            $cleanDomain = preg_replace('/^www\./', '', $domain);
                            $store = Store::firstOrCreate(
                                ['domain' => $cleanDomain],
                                [
                                    'name' => ucfirst(explode('.', $cleanDomain)[0]),
                                    'active' => true,
                                    'trust_level' => 'standard',
                                ]
                            );
                        }

                        // Create ProductSource
                        $source = ProductSource::firstOrCreate(
                            [
                                'product_id' => $record->product_id,
                                'url' => $record->url,
                            ],
                            [
                                'store_id' => $store?->id,
                                'collector_type' => 'generic_jsonld',
                                'expected_model' => $record->product->model_code,
                                'expected_voltage' => $record->product->voltage,
                                'active' => true,
                                'status' => 'active',
                                'priority' => 10,
                                'discovery_candidate_id' => $record->id,
                            ]
                        );

                        $record->update([
                            'status' => 'approved',
                            'product_source_id' => $source->id,
                            'reviewed_at' => now(),
                            'rejection_reason' => null,
                        ]);

                        Notification::make()
                            ->title('Oferta aprovada com sucesso!')
                            ->body('A URL foi adicionada às fontes ativas de monitoramento.')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DiscoveryCandidate $record): bool => in_array($record->status, ['pending_review', 'auto_approved', 'approved'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Rejeitar Oferta')
                    ->modalDescription('Esta oferta não será monitorada. Se já houver fonte vinculada, ela será desativada.')
                    ->action(function (DiscoveryCandidate $record) {
                        if ($record->product_source_id) {
                            ProductSource::where('id', $record->product_source_id)->update(['active' => false, 'status' => 'inactive']);
                        }

                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => 'Rejeitado manualmente pelo usuário.',
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Oferta rejeitada.')
                            ->warning()
                            ->send();
                    }),

                Action::make('open_url')
                    ->label('Abrir na Loja')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (DiscoveryCandidate $record): string => $record->url)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
