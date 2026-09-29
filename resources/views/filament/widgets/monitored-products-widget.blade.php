<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-m-shopping-bag" class="h-5 w-5 text-primary-500" />
                <span>Produtos em Monitoramento</span>
            </div>
        </x-slot>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2">
            @foreach ($this->getProducts() as $product)
                <div class="flex flex-col justify-between p-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm hover:shadow-md transition duration-200">
                    <div>
                        <div class="flex items-start gap-4">
                            @if ($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-16 h-16 object-contain rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800 p-1 flex-shrink-0" />
                            @else
                                <div class="w-16 h-16 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center flex-shrink-0 text-gray-400">
                                    <x-filament::icon icon="heroicon-m-photo" class="h-8 w-8" />
                                </div>
                            @endif

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                                        {{ $product['brand'] }}
                                    </span>
                                    @if ($product['voltage'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200/50 dark:border-amber-800/40">
                                            {{ $product['voltage'] }}
                                        </span>
                                    @endif
                                </div>
                                <h4 class="text-sm font-bold text-gray-900 dark:text-white truncate" title="{{ $product['name'] }}">
                                    {{ $product['commercial_name'] }}
                                </h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5">
                                    {{ $product['model_code'] }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-gray-500 dark:text-gray-400 block">Preço Alvo</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $product['target_price'] ? 'R$ ' . number_format($product['target_price'], 2, ',', '.') : 'Não definido' }}
                                </span>
                            </div>
                            <div>
                                <span class="text-gray-500 dark:text-gray-400 block">Menor Preço</span>
                                @if ($product['current_price'])
                                    <span class="font-bold text-base {{ $product['is_below_target'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-primary-600 dark:text-primary-400' }}">
                                        R$ {{ number_format($product['current_price'], 2, ',', '.') }}
                                    </span>
                                @else
                                    <span class="text-xs font-medium text-amber-600 dark:text-amber-400">
                                        Aguardando coleta
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ $product['active_sources_count'] }} fonte(s) ativa(s)</span>
                        <a href="{{ url('/admin/products') }}" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 flex items-center gap-1">
                            <span>Ver Produto</span>
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
