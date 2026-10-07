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
            // 1. Current in-stock price from active sources
            $lowestInStockObs = PriceObservation::query()
                ->whereHas('source', fn ($q) => $q->where('product_id', $prod->id)->where('active', true))
                ->where('is_mismatch', false)
                ->where('in_stock', true)
                ->whereRaw('COALESCE(pix_price, regular_price) > 0')
                ->orderByRaw('COALESCE(pix_price, regular_price) ASC')
                ->first();

            // 2. Lowest historical price ever recorded (even if currently out of stock)
            $lowestHistoricalObs = PriceObservation::query()
                ->whereHas('source', fn ($q) => $q->where('product_id', $prod->id))
                ->where('is_mismatch', false)
                ->whereRaw('COALESCE(pix_price, regular_price) > 0')
                ->orderByRaw('COALESCE(pix_price, regular_price) ASC')
                ->first();

            // 3. Latest price collected (most recent observation)
            $latestObs = PriceObservation::query()
                ->whereHas('source', fn ($q) => $q->where('product_id', $prod->id))
                ->where('is_mismatch', false)
                ->whereRaw('COALESCE(pix_price, regular_price) > 0')
                ->orderBy('collected_at', 'DESC')
                ->first();

            // 4. Lowest detected candidate price (from discovery)
            $lowestCandidate = $prod->discoveryCandidates()
                ->whereIn('status', ['auto_approved', 'approved', 'pending_review'])
                ->whereNotNull('detected_price')
                ->where('detected_price', '>', 0)
                ->orderBy('detected_price', 'ASC')
                ->first();

            $price = null;
            $priceType = 'none';
            $priceLabel = 'Menor Preço';
            $priceBadge = null;
            $badgeStyle = '';
            $priceSubtext = null;

            if ($lowestInStockObs) {
                $price = (float) $lowestInStockObs->effective_price;
                $priceType = 'in_stock';
                $priceLabel = 'Menor Preço';
                $priceBadge = 'Em estoque';
                $badgeStyle = 'background-color: #dcfce7; color: #166534;';

                if ($lowestHistoricalObs && (float) $lowestHistoricalObs->effective_price < $price) {
                    $histFormatted = number_format((float) $lowestHistoricalObs->effective_price, 2, ',', '.');
                    $priceSubtext = "Menor histórico: R$ {$histFormatted}";
                }
            } elseif ($lowestHistoricalObs) {
                $price = (float) $lowestHistoricalObs->effective_price;
                $priceType = 'historical';
                $priceLabel = 'Menor Histórico';
                $priceBadge = 'Menor histórico';
                $badgeStyle = 'background-color: #fef3c7; color: #92400e;';

                $latestPrice = $latestObs ? (float) $latestObs->effective_price : null;
                $latestDate = $latestObs?->collected_at ? $latestObs->collected_at->format('d/m') : null;

                if ($latestPrice && abs($latestPrice - $price) > 0.01) {
                    $latestFormatted = number_format($latestPrice, 2, ',', '.');
                    $priceSubtext = "Último: R$ {$latestFormatted}".($latestDate ? " ({$latestDate})" : '');
                } elseif ($latestDate) {
                    $priceSubtext = "Coletado em {$latestDate} (sem estoque atual)";
                } else {
                    $priceSubtext = 'Sem estoque no momento';
                }
            } elseif ($latestObs) {
                $price = (float) $latestObs->effective_price;
                $priceType = 'latest';
                $priceLabel = 'Último Coletado';
                $priceBadge = 'Último coletado';
                $badgeStyle = 'background-color: #f3f4f6; color: #374151;';
                $latestDate = $latestObs->collected_at ? $latestObs->collected_at->format('d/m') : null;
                $priceSubtext = $latestDate ? "Coletado em {$latestDate}" : 'Último valor registrado';
            } elseif ($lowestCandidate) {
                $price = (float) $lowestCandidate->detected_price;
                $priceType = 'detected';
                $priceLabel = 'Preço Detectado';
                $priceBadge = 'Detectado na busca';
                $badgeStyle = 'background-color: #e0f2fe; color: #0369a1;';
                $storeName = $lowestCandidate->discovered_store_name ?: 'Oferta online';
                $priceSubtext = "Encontrado em {$storeName}";
            }

            $activeSourcesCount = $prod->sources()->where('active', true)->count();
            $isBelowTarget = $price !== null && $prod->target_price && $price <= (float) $prod->target_price;

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
                'current_price' => $price,
                'price_type' => $priceType,
                'price_label' => $priceLabel,
                'price_badge' => $priceBadge,
                'badge_style' => $badgeStyle,
                'price_subtext' => $priceSubtext,
                'active_sources_count' => $activeSourcesCount,
                'is_below_target' => $isBelowTarget,
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
