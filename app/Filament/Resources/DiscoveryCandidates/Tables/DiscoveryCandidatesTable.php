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
                    ->url(fn (DiscoveryCandidate $record): string => $record->url)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->weight('medium')
                    ->limit(55)
                    ->tooltip(fn (DiscoveryCandidate $record): string => ($record->raw_title ?? '') . ' (Clique para abrir na loja)'),

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
                    ->limit(35)
                    ->tooltip(fn (DiscoveryCandidate $record): ?string => $record->rejection_reason),

                TextColumn::make('discovery_provider')
                    ->label('Provedor(es)')
                    ->badge()
                    ->separator(', ')
                    ->color('info'),

                TextColumn::make('discovered_at')
                    ->label('Descoberto em')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('discovered_at', 'desc')
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('product_id')
                    ->label('Filtrar por Produto')
                    ->options(fn () => \App\Models\Product::all()->mapWithKeys(fn ($p) => [$p->id => ($p->brand ? $p->brand . ' - ' : '') . ($p->commercial_name ?: $p->name)])->toArray())
                    ->searchable()
                    ->preload(),

                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'auto_approved' => 'Auto Aprovado',
                        'approved' => 'Aprovado',
                        'pending_review' => 'Pendente',
                        'rejected' => 'Rejeitado',
                    ]),

                \Filament\Tables\Filters\SelectFilter::make('discovered_store_name')
                    ->label('Filtrar por Loja')
                    ->options(fn () => \App\Models\DiscoveryCandidate::whereNotNull('discovered_store_name')->distinct()->pluck('discovered_store_name', 'discovered_store_name')->toArray()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Aprovar e Monitorar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DiscoveryCandidate $record): bool => in_array($record->status, ['pending_review', 'rejected'], true))
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

                        // Run immediate collection on this new source in background
                        dispatch(function () use ($source) {
                            try {
                                app(\App\Services\Collector\Pipeline\CollectionPipeline::class)->run($source);
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning("Collection on approve failed for source {$source->id}: {$e->getMessage()}");
                            }
                        })->afterResponse();

                        Notification::make()
                            ->title('Oferta aprovada com sucesso!')
                            ->body('Fonte adicionada e coleta inicial disparada.')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DiscoveryCandidate $record): bool => in_array($record->status, ['pending_review', 'auto_approved', 'approved'], true))
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
