<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Models\Coupon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->badge()
                    ->color('warning')
                    ->weight('bold'),

                TextColumn::make('discount')
                    ->label('Desconto')
                    ->state(function (Coupon $record): string {
                        if ($record->discount_type === 'percentage') {
                            $text = "{$record->discount_value}%";
                            if ($record->max_discount) {
                                $text .= ' (máx R$ '.number_format((float) $record->max_discount, 2, ',', '.').')';
                            }

                            return $text;
                        }

                        return 'R$ '.number_format((float) $record->discount_value, 2, ',', '.');
                    })
                    ->weight('semibold'),

                TextColumn::make('store.name')
                    ->label('Loja')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Todas as Lojas')
                    ->badge()
                    ->color('info'),

                TextColumn::make('product.commercial_name')
                    ->label('Produto Específico')
                    ->searchable()
                    ->limit(25)
                    ->placeholder('Todos os Produtos'),

                TextColumn::make('min_order_value')
                    ->label('Mínimo')
                    ->money('BRL', locale: 'pt_BR')
                    ->placeholder('Sem mínimo')
                    ->sortable(),

                IconColumn::make('applies_to_pix')
                    ->label('Com Pix?')
                    ->boolean(),

                TextColumn::make('source_type')
                    ->label('Origem')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manual' => 'primary',
                        'auto_detected' => 'info',
                        'whatsapp_group' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'manual' => 'Manual',
                        'auto_detected' => 'Auto-detectado',
                        'whatsapp_group' => 'Grupo WhatsApp',
                        default => $state,
                    }),

                TextColumn::make('expires_at')
                    ->label('Validade')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sem expiração')
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
