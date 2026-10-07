<?php

namespace App\Services\Collector;

class PriceResult
{
    public function __construct(
        public bool $isSuccess = false,
        public ?float $regularPrice = null,
        public ?float $pixPrice = null,
        public ?float $couponPrice = null,
        public ?string $couponCode = null,
        public ?float $couponDiscount = null,
        public ?string $couponType = null,
        public ?float $shippingPrice = null,
        public ?float $installmentPrice = null,
        public ?int $installmentCount = null,
        public bool $inStock = true,
        public ?string $seller = null,
        public ?string $sellerType = null,
        public ?string $rawTitle = null,
        public bool $isMismatch = false,
        public ?string $mismatchReason = null,
        public array $metadata = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public int $durationMs = 0,
    ) {}

    public static function success(
        ?float $regularPrice,
        ?float $pixPrice = null,
        bool $inStock = true,
        ?string $rawTitle = null,
        ?string $seller = null,
        ?string $sellerType = null,
        ?float $installmentPrice = null,
        ?int $installmentCount = null,
        ?float $shippingPrice = null,
        ?float $couponPrice = null,
        ?string $couponCode = null,
        ?float $couponDiscount = null,
        ?string $couponType = null,
        array $metadata = [],
        ?int $httpStatus = 200,
        int $durationMs = 0
    ): self {
        return new self(
            isSuccess: true,
            regularPrice: $regularPrice,
            pixPrice: $pixPrice,
            couponPrice: $couponPrice,
            couponCode: $couponCode,
            couponDiscount: $couponDiscount,
            couponType: $couponType,
            shippingPrice: $shippingPrice,
            installmentPrice: $installmentPrice,
            installmentCount: $installmentCount,
            inStock: $inStock,
            seller: $seller,
            sellerType: $sellerType,
            rawTitle: $rawTitle,
            isMismatch: false,
            mismatchReason: null,
            metadata: $metadata,
            errorCode: null,
            errorMessage: null,
            httpStatus: $httpStatus,
            durationMs: $durationMs
        );
    }

    public static function mismatch(
        string $reason,
        ?string $rawTitle = null,
        array $metadata = [],
        ?int $httpStatus = 200,
        int $durationMs = 0
    ): self {
        return new self(
            isSuccess: false,
            rawTitle: $rawTitle,
            isMismatch: true,
            mismatchReason: $reason,
            metadata: $metadata,
            errorCode: 'MISMATCH',
            errorMessage: $reason,
            httpStatus: $httpStatus,
            durationMs: $durationMs
        );
    }

    public static function failure(
        string $errorMessage,
        string $errorCode = 'COLLECT_ERROR',
        ?int $httpStatus = null,
        int $durationMs = 0,
        array $metadata = []
    ): self {
        return new self(
            isSuccess: false,
            metadata: $metadata,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            httpStatus: $httpStatus,
            durationMs: $durationMs
        );
    }

    public function getEffectivePrice(): ?float
    {
        if ($this->pixPrice !== null && $this->pixPrice > 0) {
            return $this->pixPrice;
        }

        return $this->regularPrice !== null && $this->regularPrice > 0 ? $this->regularPrice : null;
    }
}
