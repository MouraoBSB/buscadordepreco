<?php

namespace App\Filament\Widgets;

use App\Models\PriceObservation;
use App\Models\Product;
use App\Services\Collector\Pipeline\CollectionPipeline;
use App\Services\Discovery\ProductDiscoveryService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class MonitoredProductsWidget extends Widget
{
    protected string $view = 'filament.widgets.monitored-products-widget';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

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

    public function discoverProduct(int $productId): void
    {
        @set_time_limit(180);

        $product = Product::find($productId);
        if (! $product) {
            Notification::make()
                ->title('Produto não encontrado')
                ->danger()
                ->send();

            return;
        }

        try {
            $service = app(ProductDiscoveryService::class);
            $result = $service->discoverForProduct($product, triggerType: 'manual');

            // Collect fresh prices for active sources of this product
            $pipeline = app(CollectionPipeline::class);
            $collectedCount = 0;
            foreach ($product->sources()->where('active', true)->get() as $source) {
                try {
                    $pipeline->run($source);
                    $collectedCount++;
                } catch (\Throwable $e) {
                    // Ignore single source errors
                }
            }

            $name = $product->commercial_name ?: $product->name;
            $msg = "{$result['candidates_found']} ofertas analisadas: {$result['candidates_auto_approved']} auto-aprovadas, {$result['candidates_pending']} pendentes.";
            if ($collectedCount > 0) {
                $msg .= " Preços atualizados em {$collectedCount} fonte(s).";
            }

            Notification::make()
                ->title("Busca concluída: {$name}")
                ->body($msg)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao buscar ofertas')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function discoverAll(): void
    {
        @set_time_limit(360);

        $products = Product::where('active', true)->get();
        if ($products->isEmpty()) {
            Notification::make()
                ->title('Nenhum produto ativo')
                ->warning()
                ->send();

            return;
        }

        try {
            $service = app(ProductDiscoveryService::class);
            $pipeline = app(CollectionPipeline::class);
            $totalFound = 0;
            $totalApproved = 0;
            $totalPending = 0;

            foreach ($products as $product) {
                $result = $service->discoverForProduct($product, triggerType: 'manual');
                $totalFound += $result['candidates_found'] ?? 0;
                $totalApproved += $result['candidates_auto_approved'] ?? 0;
                $totalPending += $result['candidates_pending'] ?? 0;

                foreach ($product->sources()->where('active', true)->get() as $source) {
                    try {
                        $pipeline->run($source);
                    } catch (\Throwable $e) {
                        // Ignore single source errors
                    }
                }
            }

            Notification::make()
                ->title('Busca finalizada para todos os produtos!')
                ->body("{$totalFound} ofertas analisadas em {$products->count()} produtos ({$totalApproved} auto-aprovadas, {$totalPending} pendentes).")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao buscar ofertas')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
