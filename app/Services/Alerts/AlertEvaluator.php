<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\User;
use App\Services\Alerts\Channels\GoWaChannel;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
        // Outlier and Mismatch check: Never alert on mismatch, out-of-stock or suspicious price
        if ($observation->is_mismatch || ! $observation->in_stock || $observation->is_suspicious) {
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

        // Price Sanity Check: If price drops >60% below target or historical average, mark suspicious and suppress alert
        $isSuspicious = false;
        $suspiciousReason = null;

        if ($product->target_price && $effectivePrice < ((float) $product->target_price * 0.40)) {
            $isSuspicious = true;
            $suspiciousReason = 'PRICE_OUTLIER_DETECTED: Preço mais de 60% abaixo do preço-alvo. Possível erro de precificação ou anúncio de peças.';
        } else {
            $historicalAvg = PriceObservation::query()
                ->whereHas('source', fn ($q) => $q->where('product_id', $product->id))
                ->where('is_mismatch', false)
                ->where('is_suspicious', false)
                ->where('id', '!=', $observation->id)
                ->avg('regular_price');

            if ($historicalAvg && $effectivePrice < ((float) $historicalAvg * 0.40)) {
                $isSuspicious = true;
                $suspiciousReason = 'PRICE_OUTLIER_DETECTED: Preço mais de 60% abaixo da média histórica do produto.';
            }
        }

        if ($isSuspicious) {
            $observation->update([
                'is_suspicious' => true,
                'sanity_check_reason' => $suspiciousReason,
            ]);
            Log::warning("Alerta suprimido pelo Sanity Check para produto {$product->id}: R$ {$effectivePrice}. Motivo: {$suspiciousReason}");

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

        // 3 price scenarios
        $regularFormatted = $observation->regular_price ? 'R$ '.number_format((float) $observation->regular_price, 2, ',', '.') : 'N/D';
        if ($observation->installment_price && $observation->installment_count) {
            $regularFormatted .= " ({$observation->installment_count}x de R$ ".number_format((float) $observation->installment_price, 2, ',', '.').')';
        }

        $pixFormatted = $observation->pix_price ? 'R$ '.number_format((float) $observation->pix_price, 2, ',', '.') : null;
        $couponFormatted = $observation->coupon_price ? 'R$ '.number_format((float) $observation->coupon_price, 2, ',', '.') : null;

        $priceScenariosText = "💳 *Cartão/Parcelado:* {$regularFormatted}\n";
        if ($pixFormatted) {
            $priceScenariosText .= "⚡ *À Vista no Pix:* {$pixFormatted}\n";
        }
        if ($couponFormatted && $observation->coupon_code) {
            $discountText = $observation->coupon_discount ? ' (Economia de R$ '.number_format((float) $observation->coupon_discount, 2, ',', '.').')' : '';
            $priceScenariosText .= "🎟️ *Com Cupom:* {$couponFormatted}{$discountText}\n"
                ."🏷️ *Código do Cupom:* `{$observation->coupon_code}`\n";
        }

        $prodName = $product->commercial_name ?: $product->name;
        $voltageText = $product->voltage ? " | Tensão: *{$product->voltage}*" : '';
        $modelText = $product->model_code ? "📌 Modelo: `{$product->model_code}`{$voltageText}\n\n" : '';

        $message = "🚨 *ALERTA PRICEWATCH*\n\n"
            ."*{$prodName}*\n"
            .$modelText
            ."{$reason}\n\n"
            ."📊 *Cenários de Preço:*\n"
            .$priceScenariosText."\n"
            .'💰 *Melhor Preço Final: R$ '.number_format($price, 2, ',', '.')."*\n"
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
                'regular_price' => $observation->regular_price,
                'pix_price' => $observation->pix_price,
                'coupon_price' => $observation->coupon_price,
                'coupon_code' => $observation->coupon_code,
            ],
        ]);

        // 1. Send via GoWA (WhatsApp)
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

        // 2. Send via Filament Database Notification (Web Dashboard)
        try {
            $users = User::all();
            if ($users->isNotEmpty()) {
                Notification::make()
                    ->title("Alerta: {$prodName}")
                    ->body("{$reason} • Melhor valor: R$ ".number_format($price, 2, ',', '.').($observation->coupon_code ? " [Cupom: {$observation->coupon_code}]" : ''))
                    ->icon('heroicon-o-bell-alert')
                    ->iconColor('success')
                    ->actions([
                        Action::make('view_offer')
                            ->button()
                            ->label('Ver Oferta')
                            ->url($source->url)
                            ->openUrlInNewTab(),
                    ])
                    ->sendToDatabase($users);
            }
        } catch (\Throwable $e) {
            Log::warning("Erro ao enviar notificação interna do painel: {$e->getMessage()}");
        }
    }
}
