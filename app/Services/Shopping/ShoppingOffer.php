<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Services\Shopping;

/**
 * Oferta individual como listada no Google Shopping (via Serper).
 */
class ShoppingOffer
{
    public function __construct(
        public readonly string $title,
        public readonly string $storeName,
        public readonly ?float $price,
        public readonly ?string $googleProductId = null,
        public readonly ?string $imageUrl = null,
    ) {}

    /**
     * Converte preços no formato brasileiro, como "R$ 3.163,13 agora", em float.
     */
    public static function parseBrazilianPrice(?string $rawPrice): ?float
    {
        if ($rawPrice === null || ! preg_match('/(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:,\d{1,2})?)/u', $rawPrice, $match)) {
            return null;
        }

        $normalized = str_replace(['.', ','], ['', '.'], $match[1]);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
