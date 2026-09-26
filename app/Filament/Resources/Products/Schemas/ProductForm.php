<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Services\Profiling\ProductEnrichmentService;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Perfilador Inteligente de Produto')
                    ->description('Descreva o produto em linguagem natural para que o sistema extraia marca, modelo, restrições e termos contextuais.')
                    ->schema([
                        TextInput::make('natural_input')
                            ->label('Descrição em Linguagem Natural')
                            ->placeholder('Ex: Midea MA512W165/GK-05 16,5 kg 220 V sem agitador central ou LG OLED C6 55 polegadas')
                            ->columnSpanFull()
                            ->suffixAction(
                                Action::make('profile')
                                    ->label('Interpretar e Preencher')
                                    ->icon('heroicon-m-sparkles')
                                    ->color('primary')
                                    ->action(function (Get $get, Set $set) {
                                        $input = (string) $get('natural_input');
                                        if (empty(trim($input))) {
                                            return;
                                        }

                                        $profiler = app(ProductEnrichmentService::class);
                                        $profile = $profiler->profileFromText($input);

                                        $set('name', $profile->name);
                                        $set('commercial_name', $profile->commercialName);
                                        $set('brand', $profile->brand);
                                        $set('model_code', $profile->modelCode);
                                        if ($profile->voltage) {
                                            $set('voltage', $profile->voltage);
                                        }
                                        if ($profile->capacityKg) {
                                            $set('capacity_kg', $profile->capacityKg);
                                        }
                                        $set('strict_model', $profile->strictModel);
                                        $set('required_terms', $profile->requiredTerms);
                                        $set('forbidden_terms', $profile->forbiddenTerms);
                                        $set('hard_constraints', $profile->hardConstraints);
                                        $set('inferred_attributes', $profile->inferredAttributes);

                                        $brandInfo = $profile->brand ? "Marca: {$profile->brand}" : 'Marca genérica';
                                        $modelInfo = $profile->modelCode ? "Modelo: {$profile->modelCode}" : 'Sem código estrito';

                                        Notification::make()
                                            ->title('Produto analisado com sucesso!')
                                            ->body("{$brandInfo} | {$modelInfo} | Tensão: ".($profile->voltage ?? 'N/A'))
                                            ->success()
                                            ->send();
                                    })
                            ),
                    ]),

                Section::make('Dados Básicos do Produto')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do Produto')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('commercial_name')
                            ->label('Nome Comercial / Linha')
                            ->maxLength(255),

                        TextInput::make('brand')
                            ->label('Marca')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('model_code')
                            ->label('Código do Modelo / SKU')
                            ->maxLength(100),

                        TextInput::make('image_url')
                            ->label('Caminho ou URL da Foto do Produto')
                            ->placeholder('ex: /images/products/midea-ma512w165.jpg')
                            ->maxLength(500),

                        TextInput::make('capacity_kg')
                            ->label('Capacidade (kg)')
                            ->numeric(),

                        Select::make('voltage')
                            ->label('Tensão')
                            ->options([
                                '220V' => '220V',
                                '127V' => '127V',
                                'Bivolt' => 'Bivolt',
                            ])
                            ->default('220V'),

                        TextInput::make('target_price')
                            ->label('Preço Alvo (R$)')
                            ->numeric()
                            ->prefix('R$'),

                        Toggle::make('active')
                            ->label('Ativo para Monitoramento')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Regras de Descoberta & Validação (Universal)')
                    ->description('Configurações geradas pelo Perfilador ou ajustadas manualmente para validação das fontes encontradas.')
                    ->collapsed()
                    ->schema([
                        Toggle::make('strict_model')
                            ->label('Exigir Modelo Exato (Rejeita ofertas sem o código exato no título/descrição)')
                            ->default(false)
                            ->columnSpanFull(),

                        TagsInput::make('required_terms')
                            ->label('Termos Obrigatórios na Oferta')
                            ->placeholder('Adicionar termo obrigatório...')
                            ->columnSpanFull(),

                        TagsInput::make('forbidden_terms')
                            ->label('Termos Proibidos / Incompatíveis (Contextuais)')
                            ->placeholder('Adicionar termo proibido...')
                            ->helperText('Definidos contextualmente com base na intenção do produto (ex: evita peças, acessórios ou usados para produtos novos).')
                            ->columnSpanFull(),

                        KeyValue::make('hard_constraints')
                            ->label('Requisitos Rígidos (Hard Constraints - Eliminatórios)')
                            ->helperText('Ex: voltage => 220V, screen_size => 55", has_agitator => false')
                            ->columnSpanFull(),

                        KeyValue::make('inferred_attributes')
                            ->label('Atributos Inferidos (Informativos - Não Eliminatórios)')
                            ->helperText('Ex: resolution => 4K, panel => OLED, color => Titanium')
                            ->columnSpanFull(),

                        KeyValue::make('metadata')
                            ->label('Metadados Adicionais')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
