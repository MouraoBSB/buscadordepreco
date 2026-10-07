<?php

namespace App\Services\Collector\Pipeline;

use App\Models\CollectionRun;
use App\Models\PriceObservation;
use App\Models\ProductSource;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Collector\CollectorManager;
use App\Services\Collector\PriceResult;
use App\Services\Coupons\CouponCalculatorService;
use Carbon\Carbon;
use Throwable;

class CollectionPipeline
{
    public function __construct(
        protected CollectorManager $collectorManager,
        protected AlertEvaluator $alertEvaluator,
        protected CouponCalculatorService $couponCalculator
    ) {}

    /**
     * Execute full collection pipeline for a given ProductSource.
     */
    public function run(ProductSource $source): PriceResult
    {
        $startedAt = Carbon::now();

        $run = CollectionRun::create([
            'product_source_id' => $source->id,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $result = $this->collectorManager->collect($source);

            $finishedAt = Carbon::now();

            if ($result->isMismatch) {
                $run->update([
                    'status' => 'mismatch',
                    'http_status' => $result->httpStatus,
                    'duration_ms' => $result->durationMs,
                    'error_code' => $result->errorCode,
                    'error_message' => $result->mismatchReason,
                    'finished_at' => $finishedAt,
                ]);

                // Persist mismatch observation for audit trail
                PriceObservation::create([
                    'product_source_id' => $source->id,
                    'raw_title' => $result->rawTitle,
                    'is_mismatch' => true,
                    'mismatch_reason' => $result->mismatchReason,
                    'in_stock' => false,
                    'collected_at' => $startedAt,
                    'metadata' => $result->metadata,
                ]);

                return $result;
            }

            if (! $result->isSuccess) {
                $run->update([
                    'status' => 'failed',
                    'http_status' => $result->httpStatus,
                    'duration_ms' => $result->durationMs,
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                    'finished_at' => $finishedAt,
                ]);

                return $result;
            }

            // Calculate best eligible coupon (from scraper detection or active coupons table)
            $couponResult = $this->couponCalculator->calculateBestCoupon(
                source: $source,
                regularPrice: $result->regularPrice,
                pixPrice: $result->pixPrice,
                detectedCouponCode: $result->couponCode,
                detectedCouponDiscount: $result->couponDiscount,
                detectedDiscountType: $result->couponType ?? 'fixed'
            );

            // Success: Persist immutable observation
            $observation = PriceObservation::create([
                'product_source_id' => $source->id,
                'regular_price' => $result->regularPrice,
                'pix_price' => $result->pixPrice,
                'coupon_price' => $couponResult['coupon_price'],
                'applied_coupon_id' => $couponResult['applied_coupon_id'],
                'coupon_code' => $couponResult['coupon_code'],
                'coupon_discount' => $couponResult['coupon_discount'],
                'shipping_price' => $result->shippingPrice,
                'installment_price' => $result->installmentPrice,
                'installment_count' => $result->installmentCount,
                'in_stock' => $result->inStock,
                'seller' => $result->seller,
                'seller_type' => $result->sellerType,
                'raw_title' => $result->rawTitle,
                'is_mismatch' => false,
                'collected_at' => $startedAt,
                'metadata' => array_merge($result->metadata, [
                    'coupon_applied_on' => $couponResult['applied_on'],
                ]),
            ]);

            $run->update([
                'status' => 'success',
                'http_status' => $result->httpStatus,
                'duration_ms' => $result->durationMs,
                'finished_at' => $finishedAt,
            ]);

            // Evaluate Alert Rules asynchronously or directly
            $this->alertEvaluator->evaluate($observation);

            return $result;
        } catch (Throwable $e) {
            $finishedAt = Carbon::now();

            $run->update([
                'status' => 'failed',
                'duration_ms' => (int) round(($finishedAt->getTimestamp() - $startedAt->getTimestamp()) * 1000),
                'error_code' => 'EXCEPTION',
                'error_message' => $e->getMessage(),
                'finished_at' => $finishedAt,
            ]);

            return PriceResult::failure(
                errorMessage: $e->getMessage(),
                errorCode: 'EXCEPTION'
            );
        }
    }
}
