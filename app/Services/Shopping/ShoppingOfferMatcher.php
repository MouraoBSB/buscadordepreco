<?php

/**
 * Autor: Thiago Mourão — https://github.com/MouraoBSB
 * Criado em: 2026-10-14
 */

namespace App\Services\Shopping;

use App\Models\Product;
use App\Services\Discovery\CandidateValidator;

/**
 * Separa as ofertas do Google Shopping que são de fato o produto monitorado e marca preços fora da curva.
 */
class ShoppingOfferMatcher
{
    public const string Accepted = 'aceita';

    public const string Suspicious = 'suspeita';

    public const string Rejected = 'recusada';

    // Preço abaixo desta fração da mediana das ofertas aceitas é tratado como suspeito
    protected const float SuspiciousPriceRatio = 0.6;

    public function __construct(protected CandidateValidator $validator) {}

    /**
     * @param  array<int, ShoppingOffer>  $offers
     * @return array<int, array{offer: ShoppingOffer, status: string, reason: string|null}>
     */
    public function match(Product $product, array $offers): array
    {
        $results = array_map(function (ShoppingOffer $offer) use ($product): array {
            if ($offer->price === null) {
                return $this->result($offer, self::Rejected, 'Oferta sem preço no Google Shopping.');
            }

            $validation = $this->validator->validateListing($product, $offer->title, $offer->storeName);

            return $validation->isValid
                ? $this->result($offer, self::Accepted)
                : $this->result($offer, self::Rejected, $validation->rejectionReason);
        }, $offers);

        $median = $this->median(array_map(
            fn (array $result): float => $result['offer']->price,
            array_filter($results, fn (array $result): bool => $result['status'] === self::Accepted),
        ));

        if ($median === null) {
            return $results;
        }

        return array_map(function (array $result) use ($median): array {
            if ($result['status'] === self::Accepted && $result['offer']->price < $median * self::SuspiciousPriceRatio) {
                $percent = (int) round((1 - $result['offer']->price / $median) * 100);

                return $this->result($result['offer'], self::Suspicious, "Preço {$percent}% abaixo da mediana das ofertas aceitas.");
            }

            return $result;
        }, $results);
    }

    /**
     * @param  array<int, float>  $values
     */
    protected function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /**
     * @return array{offer: ShoppingOffer, status: string, reason: string|null}
     */
    protected function result(ShoppingOffer $offer, string $status, ?string $reason = null): array
    {
        return ['offer' => $offer, 'status' => $status, 'reason' => $reason];
    }
}
