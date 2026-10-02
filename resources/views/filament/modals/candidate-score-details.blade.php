@php
    $product = $candidate->product;
    $breakdown = $candidate->scoring_breakdown ?? [];
    $score = $candidate->confidence_score ?? 0;
    $status = $candidate->status;
    $reason = $candidate->rejection_reason;
@endphp

<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    {{-- Banner de Status --}}
    <div @class([
        'p-3.5 rounded-lg border flex items-start gap-3',
        'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900 dark:text-red-300' => $status === 'rejected',
        'bg-green-50 border-green-200 text-green-800 dark:bg-green-950/40 dark:border-green-900 dark:text-green-300' => in_array($status, ['auto_approved', 'approved']),
        'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-950/40 dark:border-amber-900 dark:text-amber-300' => $status === 'pending_review',
    ])>
        <div class="flex-1">
            <div class="flex items-center justify-between font-semibold">
                <span>
                    Status: 
                    @if ($status === 'auto_approved')
                        Auto Aprovado (Alta Confiança)
                    @elseif ($status === 'approved')
                        Aprovado Manualmente
                    @elseif ($status === 'pending_review')
                        Pendente de Revisão Humana
                    @elseif ($status === 'rejected')
                        Oferta Rejeitada / Descartada
                    @else
                        {{ ucfirst($status) }}
                    @endif
                </span>
                <span class="text-base font-bold">{{ $score }}% de Pontuação</span>
            </div>
            @if ($reason)
                <p class="mt-1 text-xs opacity-90">
                    <strong>Motivo:</strong> {{ $reason }}
                </p>
            @endif
        </div>
    </div>

    {{-- Comparativo Produto Esperado vs Anúncio Encontrado --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
        <div class="p-3 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700">
            <span class="font-bold text-gray-900 dark:text-gray-100 block mb-1">🎯 Produto Esperado (Monitorado)</span>
            <div class="space-y-1">
                <div><span class="text-gray-500">Nome:</span> {{ $product->commercial_name ?: $product->name }}</div>
                <div><span class="text-gray-500">Marca:</span> <span class="font-semibold">{{ $product->brand ?? 'Qualquer' }}</span></div>
                <div><span class="text-gray-500">Modelo Exato:</span> <code class="bg-gray-200 dark:bg-gray-700 px-1 py-0.5 rounded">{{ $product->model_code ?? 'Não especificado' }}</code></div>
                @if ($product->capacity_kg)
                    <div><span class="text-gray-500">Capacidade:</span> <span class="font-semibold">{{ $product->capacity_kg }} kg</span></div>
                @endif
                @if ($product->voltage)
                    <div><span class="text-gray-500">Voltagem:</span> <span class="font-semibold">{{ $product->voltage }}</span></div>
                @endif
            </div>
        </div>

        <div class="p-3 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700">
            <span class="font-bold text-gray-900 dark:text-gray-100 block mb-1">🔍 Oferta Encontrada</span>
            <div class="space-y-1">
                <div><span class="text-gray-500">Título:</span> {{ $candidate->raw_title }}</div>
                <div><span class="text-gray-500">Loja:</span> <span class="font-semibold">{{ $candidate->discovered_store_name ?? 'Desconhecida' }}</span></div>
                @if ($candidate->detected_price)
                    <div><span class="text-gray-500">Preço Capturado:</span> <span class="font-bold text-emerald-600">R$ {{ number_format($candidate->detected_price, 2, ',', '.') }}</span></div>
                @endif
                <div class="pt-1">
                    <a href="{{ $candidate->url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 dark:text-primary-400 underline font-medium inline-flex items-center gap-1">
                        <span>Abrir Link do Anúncio</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabela de Critérios de Pontuação --}}
    <div>
        <h5 class="font-semibold text-xs text-gray-900 dark:text-gray-100 uppercase tracking-wider mb-2">
            Detalhamento dos Critérios de Confiança
        </h5>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden text-xs">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-100 dark:bg-gray-800 font-semibold text-gray-700 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">Critério Avaliado</th>
                        <th class="px-3 py-2 text-center">Peso Máx.</th>
                        <th class="px-3 py-2 text-right">Pontos Obtidos</th>
                        <th class="px-3 py-2 text-center">Resultado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-900">
                    {{-- 1. Modelo --}}
                    <tr>
                        <td class="px-3 py-2">
                            <span class="font-medium">Código do Modelo</span>
                            <span class="block text-gray-400 text-[11px]">Bate com o código ou prefixo de família (ex: WA17CG)</span>
                        </td>
                        <td class="px-3 py-2 text-center">40 pts</td>
                        <td class="px-3 py-2 text-right font-bold {{ ($breakdown['model_code_matched'] ?? 0) > 0 ? 'text-green-600' : 'text-gray-400' }}">
                            +{{ $breakdown['model_code_matched'] ?? 0 }} pts
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if (($breakdown['model_code_matched'] ?? 0) >= 40)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-300">Exato</span>
                            @elseif (($breakdown['model_code_matched'] ?? 0) > 0)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-300">Parcial</span>
                            @else
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Não Identificado</span>
                            @endif
                        </td>
                    </tr>

                    {{-- 2. Restrições Rígidas (Tensão / Capacidade / Agitador) --}}
                    <tr>
                        <td class="px-3 py-2">
                            <span class="font-medium">Restrições Obrigatórias</span>
                            <span class="block text-gray-400 text-[11px]">Tensão (220V/127V), Capacidade (kg) e sem agitador</span>
                        </td>
                        <td class="px-3 py-2 text-center">20 pts</td>
                        <td class="px-3 py-2 text-right font-bold {{ ($breakdown['hard_constraints_matched'] ?? 0) > 0 ? 'text-green-600' : 'text-gray-400' }}">
                            +{{ $breakdown['hard_constraints_matched'] ?? 0 }} pts
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if (($breakdown['hard_constraints_matched'] ?? 0) > 0)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-300">Compatível</span>
                            @else
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Pendente/Ausente</span>
                            @endif
                        </td>
                    </tr>

                    {{-- 3. Marca --}}
                    <tr>
                        <td class="px-3 py-2">
                            <span class="font-medium">Marca do Produto</span>
                            <span class="block text-gray-400 text-[11px]">Presença explícita da marca no título ou loja oficial</span>
                        </td>
                        <td class="px-3 py-2 text-center">15 pts</td>
                        <td class="px-3 py-2 text-right font-bold {{ ($breakdown['brand_matched'] ?? 0) > 0 ? 'text-green-600' : 'text-gray-400' }}">
                            +{{ $breakdown['brand_matched'] ?? 0 }} pts
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if (($breakdown['brand_matched'] ?? 0) > 0)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-300">Confirmada</span>
                            @else
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Não Identificada</span>
                            @endif
                        </td>
                    </tr>

                    {{-- 4. Termos Principais / Nome --}}
                    <tr>
                        <td class="px-3 py-2">
                            <span class="font-medium">Termos Comerciais</span>
                            <span class="block text-gray-400 text-[11px]">Palavras-chave do modelo (ex: ecobubble, top load)</span>
                        </td>
                        <td class="px-3 py-2 text-center">15 pts</td>
                        <td class="px-3 py-2 text-right font-bold {{ ($breakdown['name_keywords_matched'] ?? 0) > 0 ? 'text-green-600' : 'text-gray-400' }}">
                            +{{ $breakdown['name_keywords_matched'] ?? 0 }} pts
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if (($breakdown['name_keywords_matched'] ?? 0) > 0)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-300">Encontrados</span>
                            @else
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Ausentes</span>
                            @endif
                        </td>
                    </tr>

                    {{-- 5. URL de Produto Válida --}}
                    <tr>
                        <td class="px-3 py-2">
                            <span class="font-medium">Estrutura de URL</span>
                            <span class="block text-gray-400 text-[11px]">Página de compra do produto (não busca nem categoria)</span>
                        </td>
                        <td class="px-3 py-2 text-center">10 pts</td>
                        <td class="px-3 py-2 text-right font-bold {{ ($breakdown['valid_product_url'] ?? 0) > 0 ? 'text-green-600' : 'text-gray-400' }}">
                            +{{ $breakdown['valid_product_url'] ?? 0 }} pts
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if (($breakdown['valid_product_url'] ?? 0) > 0)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-300">Válida</span>
                            @else
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Incerta</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold border-t border-gray-200 dark:border-gray-700">
                    <tr>
                        <td class="px-3 py-2.5">Total Geral</td>
                        <td class="px-3 py-2.5 text-center">100 pts</td>
                        <td class="px-3 py-2.5 text-right {{ $score >= 85 ? 'text-green-600' : ($score >= 60 ? 'text-amber-600' : 'text-red-600') }}">
                            {{ $score }} pts
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            @if ($score >= 85)
                                <span class="text-green-600 font-semibold">≥ 85% (Auto Aprovado)</span>
                            @elseif ($score >= 60)
                                <span class="text-amber-600 font-semibold">60-84% (Revisão)</span>
                            @else
                                <span class="text-red-600 font-semibold">&lt; 60% (Rejeitado)</span>
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
