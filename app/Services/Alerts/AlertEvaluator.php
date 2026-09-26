<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Services\Alerts\Channels\GoWaChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AlertEvaluator
{
    public function __construct(
        protected GoWaChannel $goWaChannel
    ) {}

    /**
     * Evaluate rules and trigger alerts for a new price observation.
     */
    public function evaluate(PriceObservation $observation): void
    {
        // Outlier and Mismatch check: Never alert on mismatch or out-of-stock
        if ($observation->is_mismatch || ! $observation->in_stock) {
            return;
        }

        $source = $observation->source;
        if (! $source || ! $source->product) {
            return;
        }

        $product = $source->product;
        $effectivePrice = $observation->effective_price;

        if ($effectivePrice === null || $effectivePrice <= 0) {
            return;
        }

        // Active rules for product
        $rules = $product->alertRules()->where('active', true)->get();

        foreach ($rules as $rule) {
            $shouldAlert = false;
            $alertReason = '';

            if ($rule->type === 'target_price' && $rule->threshold !== null) {
                if ($effectivePrice <= (float) $rule->threshold) {
                    $shouldAlert = true;
                    $alertReason = '🎯 Preço abaixo da meta (Meta: R$ '.number_format((float) $rule->threshold, 2, ',', '.').')';
                }
            } elseif ($rule->type === 'lowest_price') {
                // Check lowest historical observation before this one
                $previousLowest = PriceObservation::query()
                    ->whereHas('source', fn ($q) => $q->where('product_id', $product->id))
                    ->where('is_mismatch', false)
                    ->where('id', '!=', $observation->id)
                    ->whereNotNull('pix_price')
                    ->min('pix_price');

                if ($previousLowest === null) {
                    $previousLowest = PriceObservation::query()
                        ->whereHas('source', fn ($q) => $q->where('product_id', $product->id))
                        ->where('is_mismatch', false)
                        ->where('id', '!=', $observation->id)
                        ->whereNotNull('regular_price')
                        ->min('regular_price');
                }

                if ($previousLowest !== null && $effectivePrice < (float) $previousLowest) {
                    $diff = (float) $previousLowest - $effectivePrice;
                    $shouldAlert = true;
                    $alertReason = '📉 Novo Menor Preço Histórico! (R$ '.number_format($diff, 2, ',', '.').' mais barato que o anterior R$ '.number_format((float) $previousLowest, 2, ',', '.').')';
                }
            }

            if ($shouldAlert) {
                // Cooldown / Deduplication: don't alert if sent within last 24h with same or lower price
                $recentAlert = Alert::where('product_id', $product->id)
                    ->where('rule_id', $rule->id)
                    ->where('status', 'sent')
                    ->where('sent_at', '>=', Carbon::now()->subHours(24))
                    ->latest('sent_at')
                    ->first();

                if ($recentAlert && isset($recentAlert->payload['price']) && (float) $recentAlert->payload['price'] <= $effectivePrice) {
                    Log::info("Alerta suprimido por cooldown para produto {$product->id}");

                    continue;
                }

                $this->dispatchAlert($product, $observation, $rule, $alertReason, $effectivePrice);
            }
        }
    }

    protected function dispatchAlert(Product $product, PriceObservation $observation, AlertRule $rule, string $reason, float $price): void
    {
        $source = $observation->source;
        $storeName = $source->store ? $source->store->name : 'Loja';
        $seller = $observation->seller ?: $storeName;

        $pixText = $observation->pix_price ? ' (no Pix)' : '';
        $installments = $observation->installment_price && $observation->installment_count
            ? " ou {$observation->installment_count}x de R$ ".number_format((float) $observation->installment_price, 2, ',', '.')
            : '';

        $message = "🚨 *ALERTA PRICEWATCH*\n\n"
            ."*{$product->name}*\n"
            ."📌 Modelo: `{$product->model_code}` | Tensão: *{$product->voltage}*\n\n"
            ."{$reason}\n\n"
            .'💰 *Valor: R$ '.number_format($price, 2, ',', '.')."*{$pixText}{$installments}\n"
            ."🏪 Loja: *{$storeName}* (Vendido por: {$seller})\n"
            ."🔗 Link da Oferta:\n{$source->url}\n\n"
            .'⏰ Coletado em: '.Carbon::parse($observation->collected_at)->format('d/m/Y H:i');

        $alertRecord = Alert::create([
            'product_id' => $product->id,
            'observation_id' => $observation->id,
            'rule_id' => $rule->id,
            'status' => 'pending',
            'payload' => [
                'price' => $price,
                'message' => $message,
                'reason' => $reason,
                'url' => $source->url,
            ],
        ]);

        // Send via GoWA
        $result = $this->goWaChannel->sendMessage($message);

        if ($result['ok']) {
            $alertRecord->update([
                'status' => 'sent',
                'sent_at' => Carbon::now(),
            ]);
        } else {
            $alertRecord->update([
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Erro desconhecido ao enviar pelo GoWA',
            ]);
        }
    }
}
