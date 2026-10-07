<?php

namespace App\Services\Coupons;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppCouponExtractorService
{
    protected array $blacklistedCodes = [
        'PRODUTO', 'OFERTA', 'DESCONTO', 'LINK', 'COMPRE', 'CLIQUE',
        'FRETE', 'GRATIS', 'HTTPS', 'HTTP', 'WWW', 'VALOR', 'PRECO',
        'PAGAMENTO', 'PARCELADO', 'VISTA', 'AQUI', 'CANAL', 'GRUPO',
        'ATENCAO', 'IMPERDIVEL', 'PROMO', 'PROMOCOES', 'WHATSAPP',
    ];

    /**
     * Parse text from a WhatsApp message, identify any coupon, and store it.
     */
    public function extractAndStore(string $text, array $metadata = []): ?Coupon
    {
        $code = $this->extractCouponCode($text);

        if (! $code) {
            return null;
        }

        $discount = $this->extractDiscount($text, $code);
        $minOrderValue = $this->extractMinOrderValue($text);
        $maxDiscount = $this->extractMaxDiscount($text);
        $store = $this->identifyStore($text);
        $product = $this->identifyProduct($text);

        $description = 'Capturado via grupo WhatsApp';
        if (! empty($metadata['group_name'])) {
            $description .= " ({$metadata['group_name']})";
        }

        // Clean snippet of text for description / notes
        $cleanSnippet = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($text))), 150);

        $coupon = Coupon::updateOrCreate(
            [
                'code' => $code,
                'store_id' => $store?->id,
                'product_id' => $product?->id,
            ],
            [
                'description' => $cleanSnippet,
                'discount_type' => $discount['type'],
                'discount_value' => $discount['value'],
                'max_discount' => $maxDiscount,
                'min_order_value' => $minOrderValue,
                'applies_to_pix' => true,
                'source_type' => 'whatsapp_group',
                'active' => true,
                'metadata' => array_merge($metadata, [
                    'captured_at' => now()->toIso8601String(),
                    'raw_snippet' => $cleanSnippet,
                ]),
            ]
        );

        Log::info("WhatsApp Coupon detected and stored: {$code}", [
            'store' => $store?->name,
            'discount' => $discount,
            'group' => $metadata['group_name'] ?? null,
        ]);

        return $coupon;
    }

    /**
     * Extract coupon code candidate using multiple heuristics.
     */
    public function extractCouponCode(string $text): ?string
    {
        // 1. Explicit coupon keyword prefix
        $patterns = [
            '/(?:cupom|c[oó]digo|c[oó]d\.?|voucher)\s*(?:[:=]|\s+)\s*[\*_\'"]?([A-Z0-9_\-]{3,25})[\*_\'"]?/iu',
            '/(?:use|aplique|insira|digite)\s+o\s+cupom\s+[\*_\'"]?([A-Z0-9_\-]{3,25})[\*_\'"]?/iu',
            '/cupom\s+[\*_\'"]?([A-Z0-9_\-]{3,25})[\*_\'"]?/iu',
            '/[\*_\'"]([A-Z0-9_\-]{4,20})[\*_\'"]\s+(?:com\s+)?(?:\d+%|R\$\s*[\d\.,]+)\s+off/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $candidate = mb_strtoupper(trim($matches[1]));
                if ($this->isValidCode($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Extract discount value and type (fixed or percentage).
     *
     * @return array{type: 'fixed'|'percentage', value: float}
     */
    public function extractDiscount(string $text, string $code): array
    {
        // 1. Look for percentage discount: "15% off", "desconto de 10%"
        if (preg_match('/(?:desconto\s+de\s+|ganhe\s+|com\s+|t[aá]\s+com\s+)?(\d+(?:[\.,]\d+)?)\s*%\s*(?:off|de\s+desconto)?/iu', $text, $matches)) {
            $pct = (float) str_replace(',', '.', $matches[1]);
            if ($pct > 0 && $pct <= 90) {
                return ['type' => 'percentage', 'value' => $pct];
            }
        }

        // 2. Look for fixed discount: "R$ 50 off", "desconto de R$ 100"
        if (preg_match('/(?:desconto\s+de\s+|ganhe\s+|com\s+)?R\$\s*([\d\.,]+)\s*(?:off|de\s+desconto)?/iu', $text, $matches)) {
            $val = $this->parseMoney($matches[1]);
            if ($val !== null && $val > 0) {
                return ['type' => 'fixed', 'value' => $val];
            }
        }

        // 3. Fallback: check if the coupon code ends in numeric value (e.g. VALE100 -> R$ 100 or DESC10 -> 10%)
        if (preg_match('/[A-Z_]+(\d{1,3})$/i', $code, $matches)) {
            $num = (float) $matches[1];
            if ($num > 0) {
                // Numbers <= 50 are usually percentages, > 50 are usually fixed BRL
                if ($num <= 50) {
                    return ['type' => 'percentage', 'value' => $num];
                }

                return ['type' => 'fixed', 'value' => $num];
            }
        }

        // Default generic discount if not explicitly parsed
        return ['type' => 'percentage', 'value' => 10.0];
    }

    /**
     * Extract minimum order value if mentioned.
     */
    public function extractMinOrderValue(string $text): ?float
    {
        if (preg_match('/(?:compras\s+acima\s+de|pedido\s+m[íi]nimo\s+de|a\s+partir\s+de|para\s+pedidos\s+de\s+R\$)\s*R?\$\s*([\d\.,]+)/iu', $text, $matches)) {
            return $this->parseMoney($matches[1]);
        }

        return null;
    }

    /**
     * Extract maximum discount cap if mentioned.
     */
    public function extractMaxDiscount(string $text): ?float
    {
        if (preg_match('/(?:at[ée]|limite\s+de|m[áa]ximo\s+de)\s+R\$\s*([\d\.,]+)/iu', $text, $matches)) {
            return $this->parseMoney($matches[1]);
        }

        return null;
    }

    /**
     * Identify store from links or mentions in the text.
     */
    public function identifyStore(string $text): ?Store
    {
        $textLower = mb_strtolower($text, 'UTF-8');

        // Check common e-commerce domains and aliases
        $storePatterns = [
            'mercadolivre' => ['mercadolivre.com.br', 'mercadolivre', 'meli', 'mlb'],
            'amazon' => ['amazon.com.br', 'amzn.to', 'amazon'],
            'magazineluiza' => ['magazineluiza.com.br', 'magalu', 'magazine luiza'],
            'casasbahia' => ['casasbahia.com.br', 'casas bahia'],
            'kabum' => ['kabum.com.br', 'kabum'],
            'fastshop' => ['fastshop.com.br', 'fast shop', 'fastshop'],
        ];

        foreach ($storePatterns as $key => $identifiers) {
            foreach ($identifiers as $id) {
                if (str_contains($textLower, $id)) {
                    // Find or match existing Store record
                    $store = Store::query()
                        ->where('domain', 'like', "%{$id}%")
                        ->orWhere('name', 'like', "%{$id}%")
                        ->first();

                    if ($store) {
                        return $store;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Identify if this coupon message is targeted to an active monitored product.
     */
    public function identifyProduct(string $text): ?Product
    {
        $textLower = mb_strtolower($text, 'UTF-8');
        $products = Product::query()->where('active', true)->get();

        foreach ($products as $product) {
            // Check model code first
            if (! empty($product->model_code) && str_contains($textLower, mb_strtolower($product->model_code, 'UTF-8'))) {
                return $product;
            }

            // Check distinctive name words
            $nameTokens = array_filter(explode(' ', mb_strtolower($product->commercial_name ?? $product->name, 'UTF-8')), fn ($w) => strlen($w) >= 4);
            $matched = 0;
            foreach ($nameTokens as $token) {
                if (str_contains($textLower, $token)) {
                    $matched++;
                }
            }

            if ($matched >= 2) {
                return $product;
            }
        }

        return null;
    }

    protected function isValidCode(string $code): bool
    {
        if (strlen($code) < 3 || strlen($code) > 25) {
            return false;
        }

        // Must have at least one letter
        if (! preg_match('/[A-Z]/', $code)) {
            return false;
        }

        return ! in_array($code, $this->blacklistedCodes, true);
    }

    protected function parseMoney(string $value): ?float
    {
        $clean = trim($value);
        $clean = preg_replace('/[^\d,\.]/', '', $clean);

        if (empty($clean)) {
            return null;
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }
}
