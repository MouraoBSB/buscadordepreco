<?php

namespace App\Services\Coupons;

use App\Models\Coupon;
use App\Models\ProductSource;

class CouponCalculatorService
{
    /**
     * Calculate the best coupon discount for a given source and price scenario.
     *
     * @return array{
     *     coupon_price: ?float,
     *     applied_coupon_id: ?int,
     *     coupon_code: ?string,
     *     coupon_discount: ?float,
     *     applied_coupon: ?Coupon,
     *     applied_on: ?string
     * }
     */
    public function calculateBestCoupon(
        ProductSource $source,
        ?float $regularPrice,
        ?float $pixPrice = null,
        ?string $detectedCouponCode = null,
        ?float $detectedCouponDiscount = null,
        string $detectedDiscountType = 'fixed'
    ): array {
        $emptyResult = [
            'coupon_price' => null,
            'applied_coupon_id' => null,
            'coupon_code' => null,
            'coupon_discount' => null,
            'applied_coupon' => null,
            'applied_on' => null,
        ];

        if (($regularPrice === null || $regularPrice <= 0) && ($pixPrice === null || $pixPrice <= 0)) {
            return $emptyResult;
        }

        // 1. If scraper detected a coupon directly on the page, ensure it is recorded in the coupons table
        if (! empty($detectedCouponCode) && $detectedCouponDiscount !== null && $detectedCouponDiscount > 0) {
            Coupon::updateOrCreate(
                [
                    'code' => mb_strtoupper(trim($detectedCouponCode)),
                    'store_id' => $source->store_id,
                    'product_id' => $source->product_id,
                ],
                [
                    'discount_type' => in_array($detectedDiscountType, ['fixed', 'percentage']) ? $detectedDiscountType : 'fixed',
                    'discount_value' => $detectedCouponDiscount,
                    'applies_to_pix' => true,
                    'source_type' => 'auto_detected',
                    'active' => true,
                ]
            );
        }

        // 2. Fetch all eligible active coupons for this store / product
        $query = Coupon::query()
            ->where('active', true)
            ->where(function ($q) use ($source) {
                $q->whereNull('store_id')
                    ->orWhere('store_id', $source->store_id);
            })
            ->where(function ($q) use ($source) {
                $q->whereNull('product_id')
                    ->orWhere('product_id', $source->product_id);
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        $coupons = $query->get();

        if ($coupons->isEmpty()) {
            return $emptyResult;
        }

        $bestFinalPrice = null;
        $bestCoupon = null;
        $bestDiscount = 0.0;
        $bestAppliedOn = null;

        // Base lowest price before coupon
        $baseLowest = null;
        if ($pixPrice !== null && $pixPrice > 0 && $regularPrice !== null && $regularPrice > 0) {
            $baseLowest = min($pixPrice, $regularPrice);
        } elseif ($pixPrice !== null && $pixPrice > 0) {
            $baseLowest = $pixPrice;
        } else {
            $baseLowest = $regularPrice;
        }

        foreach ($coupons as $coupon) {
            // Test applying to Pix (if applies_to_pix and pixPrice available)
            if ($coupon->applies_to_pix && $pixPrice !== null && $pixPrice > 0) {
                $discountPix = $coupon->calculateDiscount($pixPrice, isPix: true);
                if ($discountPix > 0) {
                    $finalPix = round($pixPrice - $discountPix, 2);
                    if ($bestFinalPrice === null || $finalPix < $bestFinalPrice) {
                        $bestFinalPrice = $finalPix;
                        $bestCoupon = $coupon;
                        $bestDiscount = $discountPix;
                        $bestAppliedOn = 'pix';
                    }
                }
            }

            // Test applying to Regular price
            if ($regularPrice !== null && $regularPrice > 0) {
                $discountReg = $coupon->calculateDiscount($regularPrice, isPix: false);
                if ($discountReg > 0) {
                    $finalReg = round($regularPrice - $discountReg, 2);
                    if ($bestFinalPrice === null || $finalReg < $bestFinalPrice) {
                        $bestFinalPrice = $finalReg;
                        $bestCoupon = $coupon;
                        $bestDiscount = $discountReg;
                        $bestAppliedOn = 'regular';
                    }
                }
            }
        }

        // Only consider coupon valid if it actually reduced the price below base lowest
        if ($bestCoupon && $bestFinalPrice !== null && $bestFinalPrice < $baseLowest) {
            return [
                'coupon_price' => $bestFinalPrice,
                'applied_coupon_id' => $bestCoupon->id,
                'coupon_code' => $bestCoupon->code,
                'coupon_discount' => $bestDiscount,
                'applied_coupon' => $bestCoupon,
                'applied_on' => $bestAppliedOn,
            ];
        }

        return $emptyResult;
    }
}
