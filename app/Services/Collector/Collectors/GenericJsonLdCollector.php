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

        // Also check HTML out-of-stock indicators even if JSON-LD exists
        if ($this->detectOutOfStock($html, $source)) {
            $inStock = false;
        }

        // Check if price was found
        if ($price === null) {
            // Try extracting from HTML regex
            return $this->fallbackHtmlParsing($source, $html, $fetch['status'], $durationMs, $rawTitle, $inStock);
        }

        $pixPrice = $this->extractPixPrice($html, $price);
        $couponData = $this->extractCouponData($html, $source);

        return PriceResult::success(
            regularPrice: $price,
            pixPrice: $pixPrice,
            inStock: $inStock,
            rawTitle: $rawTitle,
            seller: $seller,
            couponCode: $couponData['code'] ?? null,
            couponDiscount: $couponData['discount'] ?? null,
            couponType: $couponData['type'] ?? null,
            metadata: ['collector' => 'generic_jsonld', 'brand' => $productData['brand']['name'] ?? null],
            httpStatus: $fetch['status'],
            durationMs: $durationMs
        );
    }

    /**
     * Fallback parser for stores that don't output valid schema.org JSON-LD.
     */
    protected function fallbackHtmlParsing(
        ProductSource $source,
        string $html,
        ?int $httpStatus,
        int $durationMs,
        ?string $detectedTitle = null,
        bool $knownInStock = true
    ): PriceResult {
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

        // Check availability / stock
        $inStock = $knownInStock && ! $this->detectOutOfStock($html, $source);

        // Scope HTML to main product container to avoid capturing recommendation/carousel prices
        $searchHtml = $this->isolateMainProductHtml($html, $source);

        // Try open graph or meta price
        $price = null;
        if (preg_match('/<meta\b[^>]*property=[\'"]product:price:amount[\'"][^>]*content=[\'"](.*?)[\'"]/i', $html, $m)) {
            $price = $this->parsePrice($m[1]);
        } elseif (preg_match('/<meta\b[^>]*itemprop=[\'"]price[\'"][^>]*content=[\'"](.*?)[\'"]/i', $html, $m)) {
            $price = $this->parsePrice($m[1]);
        } elseif (preg_match('/class=[\'"]a-price-whole[\'"][^>]*>([\d\.,]+)/i', $searchHtml, $mWhole)) {
            // Amazon price pattern: separate whole and fraction tags inside main product section
            $whole = preg_replace('/[^\d]/', '', $mWhole[1]);
            $fraction = '00';
            if (preg_match('/class=[\'"]a-price-fraction[\'"][^>]*>(\d{2})/i', $searchHtml, $mFraction)) {
                $fraction = $mFraction[1];
            }
            $price = (float) ($whole.'.'.$fraction);
        } elseif (preg_match('/class=[\'"]a-offscreen[\'"][^>]*>\s*R\$\s*([\d\.,]+)/iu', $searchHtml, $m)) {
            $price = $this->parsePrice($m[1]);
        } elseif (preg_match('/R\$\s*([\d\.]*,\d{2})/iu', $searchHtml, $m)) {
            $price = $this->parsePrice($m[1]);
        }

        // If product is out of stock and has no main price, record as out-of-stock observation
        if ($price === null) {
            if (! $inStock) {
                return PriceResult::success(
                    regularPrice: null,
                    pixPrice: null,
                    inStock: false,
                    rawTitle: $detectedTitle,
                    metadata: ['collector' => 'html_fallback', 'stock_status' => 'out_of_stock'],
                    httpStatus: $httpStatus,
                    durationMs: $durationMs
                );
            }

            return PriceResult::failure(
                errorMessage: 'Preço não encontrado na página nem no JSON-LD.',
                errorCode: 'PRICE_NOT_FOUND',
                httpStatus: $httpStatus,
                durationMs: $durationMs
            );
        }

        $pixPrice = $this->extractPixPrice($html, $price);
        $couponData = $this->extractCouponData($html, $source);

        return PriceResult::success(
            regularPrice: $price,
            pixPrice: $pixPrice,
            inStock: $inStock,
            rawTitle: $detectedTitle,
            couponCode: $couponData['code'] ?? null,
            couponDiscount: $couponData['discount'] ?? null,
            couponType: $couponData['type'] ?? null,
            metadata: ['collector' => 'html_fallback'],
            httpStatus: $httpStatus,
            durationMs: $durationMs
        );
    }

    /**
     * Detect if the product page indicates out of stock / unavailable.
     */
    protected function detectOutOfStock(string $html, ProductSource $source): bool
    {
        $domain = parse_url($source->url, PHP_URL_HOST) ?? '';

        if (str_contains($domain, 'amazon.com')) {
            // Amazon availability div or outOfStock div
            if (preg_match('/id=[\'"](?:availability|outOfStock)[\'"][^>]*>(.*?)<\/div>/is', $html, $m)) {
                $text = mb_strtolower(strip_tags($m[1]), 'UTF-8');
                if (preg_match('/(n[ãa]o\s+dispon[íi]vel|atualmente\s+indispon[íi]vel|esgotado|sem\s+estoque|out\s+of\s+stock)/iu', $text)) {
                    return true;
                }
            }

            // Amazon specific phrase in buybox
            if (preg_match('/(n[ãa]o\s+temos\s+previs[ãa]o\s+de\s+quando\s+este\s+produto\s+estar[áa]\s+dispon[íi]vel|atualmente\s+indispon[íi]vel|n[ãa]o\s+dispon[íi]vel\s+por\s+este\s+vendedor)/iu', $html)) {
                return true;
            }
        }

        // Generic patterns across e-commerce stores
        $patterns = [
            '/id=[\'"](?:availability|outOfStock|estoque-indisponivel)[\'"][^>]*>(.*?)<\/(?:div|span|p)>/is',
            '/\b(?:produto\s+(?:temporariamente\s+)?indispon[íi]vel|produto\s+esgotado|avise-me\s+quando\s+chegar|fora\s+de\s+estoque|sem\s+estoque)\b/iu',
            '/ops!?[,\s]+(?:este\s+produto\s+est[áa]\s+esgotado|j[áa]\s+vendemos\s+todo\s+o\s+estoque)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Isolate the main product section from the HTML to avoid parsing prices from recommendation carousels.
     */
    protected function isolateMainProductHtml(string $html, ProductSource $source): string
    {
        $domain = parse_url($source->url, PHP_URL_HOST) ?? '';

        if (str_contains($domain, 'amazon.com')) {
            // Priority 1: Direct price blocks
            if (preg_match('/<div\b[^>]*id=[\'"](?:apex_desktop|corePrice_desktop|corePriceDisplay_desktop_feature_div)[\'"][^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $mPrice)) {
                return $mPrice[0];
            }

            // Priority 2: Product Page Details (#ppd or #centerCol)
            if (preg_match('/<div\b[^>]*id=[\'"](?:ppd|centerCol)[\'"][^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $mPpd)) {
                return $mPpd[0];
            }

            // Priority 3: Cut off at first recommendation carousel
            $parts = preg_split('/id=[\'"](?:desktop-dp-sims|sp_detail|session-similarities|dp-ads|sims-consolidated)[\'"]/i', $html);
            if (! empty($parts[0])) {
                return $parts[0];
            }
        }

        // Generic: cut off recommendation/carousel sections if present
        $cutPatterns = [
            '/class=[\'"](?:carousel|recommended-products|related-products|quem-comprou-comprou-tambem)[\'"]/i',
            '/id=[\'"](?:carousel|recommendations|related-products)[\'"]/i',
        ];

        foreach ($cutPatterns as $cutPattern) {
            $parts = preg_split($cutPattern, $html);
            if (! empty($parts[0]) && strlen($parts[0]) > 1000) {
                return $parts[0];
            }
        }

        return $html;
    }

    /**
     * Extract Pix / à vista discount price if present in HTML.
     */
    protected function extractPixPrice(string $html, ?float $regularPrice): ?float
    {
        $patterns = [
            '/(?:no\s+Pix|[àa]\s+vista\s+(?:no\s+Pix)?|no\s+boleto)\s*:?\s*R\$\s*([\d\.,]+)/iu',
            '/R\$\s*([\d\.,]+)\s+(?:no\s+Pix|[àa]\s+vista\s+(?:no\s+Pix)?|no\s+boleto)/iu',
            '/class=[\'"][^\'"]*(?:pix|avista|cash-price)[^\'"]*[\'"][^>]*>\s*(?:[^\d<]*)\s*R\$\s*([\d\.,]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $pix = $this->parsePrice($m[1]);
                if ($pix !== null && $pix > 0 && ($regularPrice === null || $pix < $regularPrice)) {
                    return $pix;
                }
            }
        }

        return null;
    }

    /**
     * Attempt to detect coupon code and discount from page HTML.
     *
     * @return array{code: string, discount: float, type: string}|null
     */
    protected function extractCouponData(string $html, ProductSource $source): ?array
    {
        $domain = parse_url($source->url, PHP_URL_HOST) ?? '';

        // 1. Amazon: "Economize R$ X com este cupom" or "Economize X% com este cupom"
        if (str_contains($domain, 'amazon.com')) {
            if (preg_match('/economize\s+R\$\s*([\d\.,]+)\s+com\s+(?:este\s+)?cupom/iu', $html, $m)) {
                $discount = $this->parsePrice($m[1]);
                if ($discount && $discount > 0) {
                    return [
                        'code' => 'AMAZON_CUPOM',
                        'discount' => $discount,
                        'type' => 'fixed',
                    ];
                }
            }
            if (preg_match('/economize\s+(\d+(?:[\.,]\d+)?)\s*%\s+com\s+(?:este\s+)?cupom/iu', $html, $m)) {
                $pct = (float) str_replace(',', '.', $m[1]);
                if ($pct > 0 && $pct <= 80) {
                    return [
                        'code' => 'AMAZON_CUPOM',
                        'discount' => $pct,
                        'type' => 'percentage',
                    ];
                }
            }
        }

        // 2. Mercado Livre
        if (str_contains($domain, 'mercadolivre.com')) {
            // Pattern: Cupom de R$ X OFF com código Y
            if (preg_match('/cupom\s+(?:de\s+)?R\$\s*([\d\.,]+)(?:\s*off)?(?:\s+(?:com\s+o\s+c[oó]digo|c[oó]digo)\s+([a-z0-9_\-]+))?/iu', $html, $m)) {
                $discount = $this->parsePrice($m[1]);
                $code = ! empty($m[2]) ? mb_strtoupper(trim($m[2])) : 'CUPOM_ML';
                if ($discount && $discount > 0) {
                    return [
                        'code' => $code,
                        'discount' => $discount,
                        'type' => 'fixed',
                    ];
                }
            }
            // Pattern: Cupom de X% OFF com código Y
            if (preg_match('/cupom\s+(?:de\s+)?(\d+(?:[\.,]\d+)?)\s*%(?:\s*off)?(?:\s+(?:com\s+o\s+c[oó]digo|c[oó]digo)\s+([a-z0-9_\-]+))?/iu', $html, $m)) {
                $pct = (float) str_replace(',', '.', $m[1]);
                $code = ! empty($m[2]) ? mb_strtoupper(trim($m[2])) : 'CUPOM_ML';
                if ($pct > 0 && $pct <= 80) {
                    return [
                        'code' => $code,
                        'discount' => $pct,
                        'type' => 'percentage',
                    ];
                }
            }
        }

        // 3. Generic store patterns
        if (preg_match('/(?:use|aplique|cupom)\s+(?:o\s+cupom\s+)?[\'"]?([a-z0-9_\-]{3,20})[\'"]?\s+(?:e\s+ganhe|para\s+ganhar|para|com)?\s*R\$\s*([\d\.,]+)/iu', $html, $m)) {
            $code = mb_strtoupper(trim($m[1]));
            $discount = $this->parsePrice($m[2]);
            if ($discount && $discount > 0 && ! in_array($code, ['PRODUTO', 'OFERTA', 'DESCONTO'])) {
                return [
                    'code' => $code,
                    'discount' => $discount,
                    'type' => 'fixed',
                ];
            }
        }

        if (preg_match('/(?:use|aplique|cupom)\s+(?:o\s+cupom\s+)?[\'"]?([a-z0-9_\-]{3,20})[\'"]?\s+(?:e\s+ganhe|para\s+ganhar|para|com)?\s*(\d+(?:[\.,]\d+)?)\s*%/iu', $html, $m)) {
            $code = mb_strtoupper(trim($m[1]));
            $pct = (float) str_replace(',', '.', $m[2]);
            if ($pct > 0 && $pct <= 80 && ! in_array($code, ['PRODUTO', 'OFERTA', 'DESCONTO'])) {
                return [
                    'code' => $code,
                    'discount' => $pct,
                    'type' => 'percentage',
                ];
            }
        }

        return null;
    }
}
