<?php

namespace App\Services\Collector\Collectors;

use App\Models\ProductSource;
use App\Services\Collector\PriceResult;

class GenericJsonLdCollector extends BaseCollector
{
    public function getIdentifier(): string
    {
        return 'generic_jsonld';
    }

    public function collect(ProductSource $source): PriceResult
    {
        $fetch = $this->fetchHtml($source->url);

        if (! $fetch['ok'] || empty($fetch['body'])) {
            return PriceResult::failure(
                errorMessage: $fetch['error'] ?? 'Falha ao carregar página',
                errorCode: 'HTTP_FETCH_FAILED',
                httpStatus: $fetch['status'],
                durationMs: $fetch['duration_ms']
            );
        }

        $html = $fetch['body'];
        $durationMs = $fetch['duration_ms'];
        $jsonLdBlocks = $this->extractJsonLd($html);

        $productData = null;
        foreach ($jsonLdBlocks as $block) {
            if (isset($block['@type']) && (strtolower($block['@type']) === 'product')) {
                $productData = $block;
                break;
            }

            // Check if @graph exists
            if (isset($block['@graph']) && is_array($block['@graph'])) {
                foreach ($block['@graph'] as $subItem) {
                    if (isset($subItem['@type']) && (strtolower($subItem['@type']) === 'product')) {
                        $productData = $subItem;
                        break 2;
                    }
                }
            }
        }

        if (! $productData) {
            // Fallback to title and meta price in HTML
            return $this->fallbackHtmlParsing($source, $html, $fetch['status'], $durationMs);
        }

        $rawTitle = $productData['name'] ?? null;
        $description = $productData['description'] ?? '';
        $fullContextText = $rawTitle.' '.$description.' '.($productData['model'] ?? '');

        // 1. Rigorous Validation: Model and Voltage
        $mismatchReason = $this->validateModelAndVoltage($source, $fullContextText);
        if ($mismatchReason !== null) {
            return PriceResult::mismatch(
                reason: $mismatchReason,
                rawTitle: $rawTitle,
                metadata: ['json_ld' => $productData],
                httpStatus: $fetch['status'],
                durationMs: $durationMs
            );
        }

        // 2. Extract Offer Price & Stock
        $offers = $productData['offers'] ?? null;
        $price = null;
        $inStock = true;
        $seller = null;

        if (is_array($offers)) {
            // AggregateOffer or single Offer
            if (isset($offers['price'])) {
                $price = $this->parsePrice($offers['price']);
            } elseif (isset($offers['lowPrice'])) {
                $price = $this->parsePrice($offers['lowPrice']);
            } elseif (isset($offers[0]['price'])) {
                $price = $this->parsePrice($offers[0]['price']);
            }

            if (isset($offers['availability'])) {
                $avail = strtolower($offers['availability']);
                $inStock = ! str_contains($avail, 'outofstock') && ! str_contains($avail, 'discontinued');
            }

            if (isset($offers['seller']['name'])) {
                $seller = $offers['seller']['name'];
            }
        }

        // Check if price was found
        if ($price === null) {
            // Try extracting from HTML regex
            return $this->fallbackHtmlParsing($source, $html, $fetch['status'], $durationMs, $rawTitle);
        }

        return PriceResult::success(
            regularPrice: $price,
            pixPrice: null, // Will be enriched if detected
            inStock: $inStock,
            rawTitle: $rawTitle,
            seller: $seller,
            metadata: ['collector' => 'generic_jsonld', 'brand' => $productData['brand']['name'] ?? null],
            httpStatus: $fetch['status'],
            durationMs: $durationMs
        );
    }

    /**
     * Fallback parser for stores that don't output valid schema.org JSON-LD.
     */
    protected function fallbackHtmlParsing(ProductSource $source, string $html, ?int $httpStatus, int $durationMs, ?string $detectedTitle = null): PriceResult
    {
        // Extract title from <title> tag if not present
        if (! $detectedTitle && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $detectedTitle = trim(html_entity_decode(strip_tags($m[1])));
        }

        // Validate model and voltage
        $mismatchReason = $this->validateModelAndVoltage($source, $detectedTitle ?? $html);
        if ($mismatchReason !== null) {
            return PriceResult::mismatch(
                reason: $mismatchReason,
                rawTitle: $detectedTitle,
                metadata: ['source' => 'html_fallback'],
                httpStatus: $httpStatus,
                durationMs: $durationMs
            );
        }

        // Try open graph or meta price
        $price = null;
        if (preg_match('/<meta\b[^>]*property=[\'"]product:price:amount[\'"][^>]*content=[\'"](.*?)[\'"]/i', $html, $m)) {
            $price = $this->parsePrice($m[1]);
        } elseif (preg_match('/<meta\b[^>]*itemprop=[\'"]price[\'"][^>]*content=[\'"](.*?)[\'"]/i', $html, $m)) {
            $price = $this->parsePrice($m[1]);
        } elseif (preg_match('/R\$\s*([\d\.]*,\d{2})/i', $html, $m)) {
            $price = $this->parsePrice($m[1]);
        }

        if ($price === null) {
            return PriceResult::failure(
                errorMessage: 'Preço não encontrado na página nem no JSON-LD.',
                errorCode: 'PRICE_NOT_FOUND',
                httpStatus: $httpStatus,
                durationMs: $durationMs
            );
        }

        return PriceResult::success(
            regularPrice: $price,
            pixPrice: null,
            inStock: true,
            rawTitle: $detectedTitle,
            metadata: ['collector' => 'html_fallback'],
            httpStatus: $httpStatus,
            durationMs: $durationMs
        );
    }
}
