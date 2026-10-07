<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-m-shopping-bag" class="h-5 w-5 text-primary-500" />
                <span>Produtos em Monitoramento</span>
            </div>
        </x-slot>

        <x-slot name="afterHeader">
            <x-filament::button
                size="sm"
                color="primary"
                icon="heroicon-m-sparkles"
                wire:click="discoverAll"
                wire:loading.attr="disabled"
            >
                Buscar Ofertas em Todos
            </x-filament::button>
        </x-slot>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-top: 0.75rem;">
            @foreach ($this->getProducts() as $product)
                <div style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.25rem; border-radius: 0.75rem; border: 1px solid rgba(229, 231, 235, 1); background-color: #ffffff; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);" class="dark:bg-gray-900 dark:border-gray-800">
                    <div>
                        <div style="display: flex; align-items: flex-start; gap: 1rem;">
                            @if ($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" style="width: 72px; height: 72px; min-width: 72px; max-width: 72px; object-fit: contain; border-radius: 0.5rem; border: 1px solid #f3f4f6; background-color: #fafafa; padding: 4px; flex-shrink: 0;" />
                            @else
                                <div style="width: 72px; height: 72px; min-width: 72px; max-width: 72px; border-radius: 0.5rem; background-color: #f3f4f6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #9ca3af;">
                                    <x-filament::icon icon="heroicon-m-photo" class="h-8 w-8" />
                                </div>
                            @endif

                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.25rem;">
                                    <span style="display: inline-flex; align-items: center; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; background-color: #f3f4f6; color: #1f2937;">
                                        {{ $product['brand'] }}
                                    </span>
                                    @if ($product['voltage'])
                                        <span style="display: inline-flex; align-items: center; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; background-color: #fef3c7; color: #92400e; border: 1px solid rgba(251, 191, 36, 0.4);">
                                            {{ $product['voltage'] }}
                                        </span>
                                    @endif
                                </div>
                                <h4 style="font-size: 0.875rem; font-weight: 700; color: #111827; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ $product['name'] }}">
                                    {{ $product['commercial_name'] }}
                                </h4>
                                <p style="font-size: 0.75rem; color: #6b7280; font-family: monospace; margin-top: 0.125rem;">
                                    {{ $product['model_code'] ?? 'Universal' }}
                                </p>
                            </div>
                        </div>

                        <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #f3f4f6; display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.75rem;">
                            <div>
                                <span style="color: #6b7280; display: block;">Preço Alvo</span>
                                <span style="font-weight: 600; color: #374151;">
                                    {{ $product['target_price'] ? 'R$ ' . number_format($product['target_price'], 2, ',', '.') : 'Não definido' }}
                                </span>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.25rem;">
                                    <span style="color: #6b7280; display: block;">{{ $product['price_label'] }}</span>
                                    @if ($product['price_badge'])
                                        <span style="font-size: 0.625rem; font-weight: 600; padding: 0.0625rem 0.375rem; border-radius: 0.25rem; {{ $product['badge_style'] }}">
                                            {{ $product['price_badge'] }}
                                        </span>
                                    @endif
                                </div>
                                @if ($product['current_price'])
                                    <span style="font-weight: 700; font-size: 1rem; display: block; color: {{ $product['is_below_target'] ? '#059669' : ($product['price_type'] === 'in_stock' ? '#2563eb' : '#d97706') }};">
                                        R$ {{ number_format($product['current_price'], 2, ',', '.') }}
                                    </span>
                                    @if (! empty($product['price_subtext']))
                                        <span style="display: block; font-size: 0.6875rem; color: #6b7280; line-height: 1.15; margin-top: 0.125rem;">
                                            {{ $product['price_subtext'] }}
                                        </span>
                                    @endif
                                @else
                                    <span style="font-size: 0.75rem; font-weight: 600; color: #d97706; display: block; margin-top: 0.125rem;">
                                        Aguardando coleta
                                    </span>
                                    <span style="display: block; font-size: 0.6875rem; color: #9ca3af; line-height: 1.15; margin-top: 0.125rem;">
                                        Nenhum valor encontrado
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- 3 Cenários Reais de Preço (Cartão, PIX, Cupom) -->
                        @if ($product['current_price'])
                            <div style="margin-top: 0.75rem; background-color: #f8fafc; border-radius: 0.5rem; padding: 0.5rem 0.625rem; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.6875rem;" class="dark:bg-gray-800/60 dark:border-gray-800">
                                <!-- 1. Cartão / Parcelado -->
                                <div style="display: flex; align-items: center; justify-content: space-between; color: #475569;" class="dark:text-gray-300">
                                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                                        <span>💳</span> <span style="color: #64748b;">Cartão:</span>
                                    </span>
                                    <span style="font-weight: 600;">
                                        {{ $product['regular_price'] ? 'R$ ' . number_format($product['regular_price'], 2, ',', '.') : 'Consulte' }}
                                        @if ($product['installments_text'])
                                            <span style="font-size: 0.625rem; color: #94a3b8; font-weight: normal;">({{ $product['installments_text'] }})</span>
                                        @endif
                                    </span>
                                </div>

                                <!-- 2. À Vista no Pix -->
                                <div style="display: flex; align-items: center; justify-content: space-between; color: #475569;" class="dark:text-gray-300">
                                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                                        <span>⚡</span> <span style="color: #64748b;">À vista (Pix):</span>
                                    </span>
                                    <span style="font-weight: 600; color: {{ $product['pix_price'] ? '#0284c7' : 'inherit' }};">
                                        {{ $product['pix_price'] ? 'R$ ' . number_format($product['pix_price'], 2, ',', '.') : ($product['regular_price'] ? 'R$ ' . number_format($product['regular_price'], 2, ',', '.') : 'Consulte') }}
                                    </span>
                                </div>

                                <!-- 3. Com Cupom -->
                                <div style="display: flex; align-items: center; justify-content: space-between; color: #475569;" class="dark:text-gray-300">
                                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                                        <span>🎟️</span> <span style="color: #64748b;">Com Cupom:</span>
                                    </span>
                                    @if ($product['coupon_price'] && $product['coupon_code'])
                                        <span style="font-weight: 700; color: #059669; display: flex; align-items: center; gap: 0.25rem;">
                                            R$ {{ number_format($product['coupon_price'], 2, ',', '.') }}
                                            <span style="font-family: monospace; font-size: 0.5625rem; padding: 0.05rem 0.25rem; background-color: #dcfce7; color: #166534; border-radius: 0.25rem; border: 1px solid #bbf7d0;">
                                                {{ $product['coupon_code'] }}
                                            </span>
                                        </span>
                                    @else
                                        <span style="color: #94a3b8; font-size: 0.625rem;">
                                            Sem cupom ativo
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                    <div style="margin-top: 1rem; padding-top: 0.5rem; border-top: 1px solid #f9fafb; display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem; color: #6b7280;">
                        <span>{{ $product['active_sources_count'] }} fonte(s) ativa(s)</span>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <x-filament::button
                                size="xs"
                                color="gray"
                                icon="heroicon-m-sparkles"
                                wire:click="discoverProduct({{ $product['id'] }})"
                                wire:loading.attr="disabled"
                                title="Buscar ofertas para este produto"
                            >
                                Buscar Ofertas
                            </x-filament::button>

                            <a href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product['id']]) }}" style="font-weight: 500; color: #2563eb; display: inline-flex; align-items: center; gap: 0.25rem; text-decoration: none; padding: 0.25rem 0.5rem;">
                                <span>Ver</span>
                                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

