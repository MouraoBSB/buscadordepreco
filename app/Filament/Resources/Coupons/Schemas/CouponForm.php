<?php

namespace App\Filament\Resources\Coupons\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Código do Cupom')
                    ->required()
                    ->maxLength(50)
                    ->placeholder('ex: VALE100, TEC10')
                    ->extraInputAttributes(['style' => 'text-transform: uppercase; font-family: monospace; font-weight: bold;']),

                TextInput::make('description')
                    ->label('Descrição / Regras')
                    ->maxLength(255)
                    ->placeholder('ex: R$ 100 OFF acima de R$ 1.500 no app'),

                Select::make('store_id')
                    ->label('Loja Específica')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Válido em todas as lojas')
                    ->helperText('Deixe vazio se o cupom for aceito em qualquer loja'),

                Select::make('product_id')
                    ->label('Produto Específico')
                    ->relationship('product', 'commercial_name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Válido em todos os produtos')
                    ->helperText('Deixe vazio se o cupom for de categoria ou geral'),

                Select::make('discount_type')
                    ->label('Tipo de Desconto')
                    ->options([
                        'fixed' => 'Valor Fixo (R$)',
                        'percentage' => 'Percentual (%)',
                    ])
                    ->default('fixed')
                    ->required(),

                TextInput::make('discount_value')
                    ->label('Valor do Desconto')
                    ->numeric()
                    ->required()
                    ->helperText('Ex: 100 para R$ 100,00 ou 10 para 10%'),

                TextInput::make('max_discount')
                    ->label('Teto Máximo de Desconto (R$)')
                    ->numeric()
                    ->prefix('R$')
                    ->helperText('Opcional. Ex: 10% até o teto de R$ 150'),

                TextInput::make('min_order_value')
                    ->label('Valor Mínimo do Pedido (R$)')
                    ->numeric()
                    ->prefix('R$')
                    ->helperText('Opcional. Preço mínimo para o cupom ser aplicável'),

                Toggle::make('applies_to_pix')
                    ->label('Cumulativo com PIX?')
                    ->helperText('Se ativado, aplica o desconto sobre o preço com PIX. Se desativado, apenas sobre o preço regular.')
                    ->default(true),

                Select::make('source_type')
                    ->label('Origem do Cupom')
                    ->options([
                        'manual' => 'Cadastrado Manualmente',
                        'auto_detected' => 'Detectado pelo Coletor',
                        'whatsapp_group' => 'Grupo de Ofertas (WhatsApp)',
                    ])
                    ->default('manual')
                    ->required(),

                DateTimePicker::make('expires_at')
                    ->label('Data e Hora de Expiração')
                    ->helperText('Deixe vazio se não tiver data limite conhecida'),

                Toggle::make('active')
                    ->label('Cupom Ativo')
                    ->default(true),
            ]);
    }
}
