<?php

namespace App\Filament\Resources\PriceObservations\Widgets;

use App\Models\PriceObservation;
use App\Models\Product;
use Filament\Widgets\ChartWidget;

class PriceHistoryChartWidget extends ChartWidget
{
    protected ?string $heading = 'Evolução dos Preços Coletados';

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public ?string $filter = null;

    protected function getFilters(): ?array
    {
        $products = Product::whereHas('sources.observations')
            ->get()
            ->mapWithKeys(fn ($p) => [(string) $p->id => ($p->brand ? $p->brand . ' - ' : '') . ($p->commercial_name ?: $p->name)])
            ->toArray();

        if (empty($products)) {
            $products = Product::all()
                ->mapWithKeys(fn ($p) => [(string) $p->id => ($p->brand ? $p->brand . ' - ' : '') . ($p->commercial_name ?: $p->name)])
                ->toArray();
        }

        return $products;
    }

    protected function getData(): array
    {
        $filters = $this->getFilters();
        $productId = $this->filter;

        if (! $productId || ! isset($filters[$productId])) {
            $productId = ! empty($filters) ? (string) array_key_first($filters) : null;
            $this->filter = $productId;
        }

        if (! $productId) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $product = Product::find($productId);

        $observations = PriceObservation::query()
            ->whereHas('source', fn ($q) => $q->where('product_id', $productId))
            ->whereNotNull('regular_price')
            ->with('source.store')
            ->orderBy('collected_at', 'asc')
            ->get();

        if ($observations->isEmpty()) {
            return [
                'datasets' => [
                    [
                        'label' => 'Aguardando coletas...',
                        'data' => [],
                    ],
                ],
                'labels' => [],
            ];
        }

        $labels = [];
        $data = [];

        foreach ($observations as $obs) {
            $date = $obs->collected_at ? $obs->collected_at->format('d/m H:i') : $obs->created_at->format('d/m H:i');
            $store = $obs->source->store->name ?? 'Loja';
            $labels[] = "{$date} ({$store})";
            $data[] = (float) $obs->effective_price;
        }

        $productName = $product ? ($product->commercial_name ?: $product->name) : 'Produto';

        return [
            'datasets' => [
                [
                    'label' => "Preço (R$) - {$productName}",
                    'data' => $data,
                    'borderColor' => '#059669',
                    'backgroundColor' => 'rgba(5, 150, 105, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
