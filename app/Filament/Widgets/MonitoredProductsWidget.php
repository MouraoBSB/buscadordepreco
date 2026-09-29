<?php

namespace App\Filament\Widgets;

use App\Models\PriceObservation;
use App\Models\Product;
use Filament\Widgets\Widget;

class MonitoredProductsWidget extends Widget
{
    protected string $view = 'filament.widgets.monitored-products-widget';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public function getProducts(): array
    {
        $products = Product::where('active', true)->get();
        $items = [];

        foreach ($products as $prod) {
            $lowestObs = PriceObservation::query()
                ->whereHas('source', fn ($q) => $q->where('product_id', $prod->id))
                ->where('is_mismatch', false)
                ->where('in_stock', true)
                ->orderByRaw('COALESCE(pix_price, regular_price) ASC')
                ->first();

            $activeSourcesCount = $prod->sources()->where('active', true)->count();

            $items[] = [
                'id' => $prod->id,
                'name' => $prod->name,
                'commercial_name' => $prod->commercial_name ?: $prod->name,
                'brand' => $prod->brand,
                'model_code' => $prod->model_code,
                'voltage' => $prod->voltage,
                'capacity_kg' => $prod->capacity_kg,
                'image_url' => $prod->image_url,
                'target_price' => $prod->target_price ? (float) $prod->target_price : null,
                'current_price' => $lowestObs ? (float) $lowestObs->effective_price : null,
                'active_sources_count' => $activeSourcesCount,
                'is_below_target' => $lowestObs && $prod->target_price && $lowestObs->effective_price <= (float) $prod->target_price,
            ];
        }

        return $items;
    }
}
