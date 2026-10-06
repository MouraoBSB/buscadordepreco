<?php

namespace App\Services\Discovery;

use App\Models\Product;
use App\Services\Discovery\DTOs\RawCandidateDto;

class CandidateValidator
{
    /**
     * Validate candidate against universal product rules and hard constraints.
     */
    public function validate(Product $product, RawCandidateDto $candidate): CandidateValidationResult
    {
        $text = mb_strtolower($candidate->title.' '.($candidate->snippet ?? ''), 'UTF-8');
        $matches = [];

        // 0. Filter out Search and Category listing URLs (Amazon /s?, Casas Bahia /b, Magalu /busca)
        $urlLower = strtolower($candidate->url);
        if (preg_match('/(\/s\?|\/s\/|\/busca|\/search|\/categoria|\/departamento|\/lista|\/c\/|\/ofertas|\/b$|\/b\/)/', $urlLower)) {
            return CandidateValidationResult::reject('URL é página de busca ou listagem de categoria, não anúncio individual de produto.');
        }

        // 0.1 Filter out clearly Out-of-Stock results from search snippets/titles
        if (preg_match('/\b(esgotado|indispon[íi]vel|sem\s+estoque|fora\s+de\s+estoque|avise-me\s+quando\s+chegar)\b/iu', $text)) {
            return CandidateValidationResult::reject('Anúncio identificado como esgotado ou indisponível.');
        }

        // 1. Core Subject Relevance Check (must match brand, model, or identifying commercial keywords)
        if (! $this->hasRelevantProductTerms($product, $text)) {
            return CandidateValidationResult::reject('Título do anúncio não possui correspondência com o produto monitorado (marca ou termos principais ausentes).');
        }

        // 1.1 Generation / Version Check (e.g. Switch 2 vs Switch 1, PS5 vs PS4)
        $nameForVersion = $product->commercial_name ?: $product->name;
        if (preg_match('/\b([a-zA-Z]+)\s*(\d{1,2})\b/u', $nameForVersion, $mVersion)) {
            $wordPrefix = mb_strtolower($mVersion[1], 'UTF-8');
            $verNum = $mVersion[2];

            if (! in_array($wordPrefix, ['de', 'da', 'do', 'em', 'kg', 'litros', 'polegadas', 'pol', 'volts', 'volt', 'v'], true)) {
                $hasVersionNumber = (bool) preg_match('/\b'.preg_quote($wordPrefix, '/').'\s*'.$verNum.'\b/iu', $text)
                    || (bool) preg_match('/\b'.$verNum.'\b/u', $text);

                if (! $hasVersionNumber) {
                    return CandidateValidationResult::reject("Geração/Versão incompatível: anúncio não menciona a versão {$verNum} ({$wordPrefix} {$verNum}).");
                }
            }
        }

        // 2. Forbidden Terms Check (contextual protection from Product Profiling)
        $forbiddenTerms = $product->forbidden_terms ?? [];
        foreach ($forbiddenTerms as $term) {
            $termLower = mb_strtolower($term, 'UTF-8');
            if (str_contains($text, $termLower)) {
                return CandidateValidationResult::reject("Termo proibido detectado: '{$term}'");
            }
        }

        // 2. Hard Constraints Check (mandatory requirements set by user)
        $hardConstraints = $product->hard_constraints ?? [];

        // Check Voltage
        if (isset($hardConstraints['voltage'])) {
            $expectedVoltage = $hardConstraints['voltage'];
            $has220v = preg_match('/\b(220\s*v|220\s*volts)\b/i', $text);
            $has127v = preg_match('/\b(110\s*v|127\s*v|110\s*volts|127\s*volts)\b/i', $text);

            if ($expectedVoltage === '220V' && $has127v && ! $has220v) {
                return CandidateValidationResult::reject('Tensão incompatível: identificado 127V/110V, esperado 220V.');
            }
            if ($expectedVoltage === '127V' && $has220v && ! $has127v) {
                return CandidateValidationResult::reject('Tensão incompatível: identificado 220V, esperado 127V.');
            }
            if ($has220v || $has127v) {
                $matches['voltage_confirmed'] = true;
            }
        }

        // Check Capacity (kg)
        $expectedCapacity = $product->capacity_kg ?? $hardConstraints['capacity_kg'] ?? null;
        if ($expectedCapacity) {
            $capFloat = (float) $expectedCapacity;
            if (preg_match_all('/\b(\d{1,2}(?:[\.,]\d)?)\s*(?:kg|kilos|quilos)\b/ui', $text, $capMatches)) {
                $foundCapacities = [];
                foreach ($capMatches[1] as $rawVal) {
                    $foundCapacities[] = (float) str_replace(',', '.', $rawVal);
                }

                $hasMatchingCapacity = false;
                foreach ($foundCapacities as $foundCap) {
                    if (abs($foundCap - $capFloat) < 0.2) {
                        $hasMatchingCapacity = true;
                        break;
                    }
                }

                if (! $hasMatchingCapacity && count($foundCapacities) > 0) {
                    $foundStr = implode('kg, ', $foundCapacities).'kg';

                    return CandidateValidationResult::reject("Capacidade incompatível: anúncio menciona {$foundStr}, mas o produto monitorado é de {$expectedCapacity}kg.");
                }

                if ($hasMatchingCapacity) {
                    $matches['capacity_confirmed'] = true;
                }
            }
        }

        // Check Agitator
        if (isset($hardConstraints['has_agitator']) && $hardConstraints['has_agitator'] === false) {
            $isExplicitlyWithout = (bool) preg_match('/(sem\s*agitador|dispensa\s*agitador)/i', $text);
            $hasAgitatorMention = (bool) preg_match('/(com\s*agitador|agitador\s*central)/i', $text);

            if ($hasAgitatorMention && ! $isExplicitlyWithout) {
                return CandidateValidationResult::reject('Incompatível: modelo possui agitador central, produto exige sem agitador.');
            }
            if ($isExplicitlyWithout) {
                $matches['no_agitator_confirmed'] = true;
            }
        }

        // Check Screen Size (if specified)
        if (isset($hardConstraints['screen_size'])) {
            $expectedSize = (int) str_replace('"', '', $hardConstraints['screen_size']);
            if (preg_match('/(\d{2,3})\s*(?:polegadas|pol|\")/i', $text, $m)) {
                $foundSize = (int) $m[1];
                if ($foundSize !== $expectedSize) {
                    return CandidateValidationResult::reject("Tamanho incompatível: anunciado {$foundSize}\", esperado {$expectedSize}\".");
                }
                $matches['screen_size_confirmed'] = true;
            }
        }

        // Check Power / Wattage (W)
        $expectedPower = $product->hard_constraints['power_w'] ?? null;
        if (! $expectedPower && preg_match('/\b(\d{3,5})\s*w\b/iu', $product->name, $mW)) {
            $expectedPower = (int) $mW[1];
        }
        if ($expectedPower) {
            $expInt = (int) $expectedPower;
            if (preg_match_all('/\b(\d{3,5})\s*(?:w|watts|watt)\b/ui', $text, $wMatches)) {
                $foundPowers = array_map('intval', $wMatches[1]);
                $hasMatchingPower = in_array($expInt, $foundPowers, true);

                if (! $hasMatchingPower && count($foundPowers) > 0) {
                    $foundStr = implode('W, ', $foundPowers).'W';

                    return CandidateValidationResult::reject("Potência incompatível: anúncio menciona {$foundStr}, mas o produto monitorado é de {$expInt}W.");
                }

                if ($hasMatchingPower) {
                    $matches['power_confirmed'] = true;
                }
            }
        }

        // 3. Strict Model Code Check (if enabled by user)
        $modelCode = $product->model_code;
        if ($modelCode) {
            $modelFound = $this->matchesModelCode($modelCode, $text);
            if ($product->strict_model && ! $modelFound) {
                return CandidateValidationResult::reject("Código de modelo exato '{$modelCode}' não identificado na oferta.");
            }
            if ($modelFound) {
                $matches['model_code_matched'] = true;
            }
        }

        // 4. Required Terms Check
        $requiredTerms = $product->required_terms ?? [];
        foreach ($requiredTerms as $req) {
            $reqLower = mb_strtolower($req, 'UTF-8');
            if (str_contains($text, $reqLower)) {
                $matches['required_term_'.$req] = true;
            }
        }

        // 5. Brand Check & Conflicting Brand Detection
        if ($product->brand) {
            $brandLower = mb_strtolower($product->brand, 'UTF-8');
            if (str_contains($text, $brandLower) || str_contains(mb_strtolower($candidate->getDomain(), 'UTF-8'), $brandLower)) {
                $matches['brand_matched'] = true;
            } else {
                // If candidate title mentions a different known competing brand, reject as brand conflict
                $competingBrands = [
                    'midea', 'panasonic', 'samsung', 'lg', 'apple', 'sony', 'nintendo',
                    'electrolux', 'brastemp', 'consul', 'dell', 'asus', 'lenovo', 'xiaomi',
                    'tcl', 'philips', 'jbl', 'motorola', 'acer', 'britânia', 'mondial',
                    'arno', 'philco', 'oster', 'cadence', 'walita', 'zurbe', 'ouxi', 'panda',
                    'engwe', 'nexor', 'caloi', 'sense', 'ogawa',
                ];
                foreach ($competingBrands as $cb) {
                    if ($cb !== $brandLower && preg_match('/\b'.preg_quote($cb, '/').'\b/iu', $candidate->title)) {
                        return CandidateValidationResult::reject("Marca incompatível: anúncio pertence à marca '".ucfirst($cb)."', esperado '".ucfirst($product->brand)."'.");
                    }
                }
            }
        }

        // 6. Name Keywords Match
        $matchedTokens = $this->getMatchedProductTokens($product, $text);
        if (! empty($matchedTokens)) {
            $matches['name_keywords_matched'] = count($matchedTokens);
        }

        return CandidateValidationResult::valid($matches);
    }

    /**
     * Stop words and generic category words that cannot distinguish a product.
     */
    protected array $genericStopWords = [
        'de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'nos', 'nas',
        'para', 'com', 'sem', 'por', 'um', 'uma', 'uns', 'umas', 'sob', 'sobre',
        'bivolt', '220v', '127v', '110v', 'volts', 'volt', 'litros', 'quilos', 'polegadas', 'pol', 'cor',
        'bicicleta', 'bike', 'ebike', 'bicicletas', 'bikes',
        'lavadora', 'maquina', 'máquina', 'lava', 'seca', 'secadora', 'lavadoras',
        'geladeira', 'refrigerador', 'freezer', 'geladeiras',
        'fogao', 'fogão', 'cooktop', 'forno', 'microondas', 'micro-ondas',
        'tv', 'smart', 'televisao', 'televisão', 'televisor', 'televisores',
        'celular', 'smartphone', 'celulares', 'smartphones', 'telefone',
        'notebook', 'laptop', 'computador', 'pc', 'desktop',
        'tablet', 'tablets', 'smartwatch', 'relogio', 'relógio',
        'fone', 'headphone', 'headset', 'earphone', 'fones',
        'caixa', 'soundbar', 'speaker', 'som',
        'aspirador', 'ventilador', 'ar', 'condicionado', 'climatizador',
        'fritadeira', 'airfryer', 'panela',
        'console', 'videogame', 'video-game',
        'patinete', 'scooter', 'moto', 'motocicleta',
        'eletrica', 'elétrica', 'eletrico', 'elétrico', 'digital', 'automatico', 'automática',
        'portatil', 'portátil', 'gamer', 'inteligente', 'wireless', 'bluetooth',
        'turbo', 'inverter', 'inox', 'titanium', 'titânio', 'preto', 'branco', 'cinza', 'prata',
        'novo', 'nova', 'novos', 'novas', 'original', 'oficial', 'promocao', 'promoção',
        'oferta', 'barato', 'frete', 'gratis', 'grátis', 'pronta', 'entrega', 'garantia',
        'alta', 'potencia', 'potência', 'forte', 'grande', 'pequeno', 'medio', 'médio',
        'pro', 'plus', 'max', 'ultra', 'mini', 'lite',
    ];

    /**
     * Check if the candidate text contains at least one significant identifying keyword of the product.
     */
    protected function hasRelevantProductTerms(Product $product, string $text): bool
    {
        // 1. If model code is present and matches, it's relevant
        if ($product->model_code && $this->matchesModelCode($product->model_code, $text)) {
            return true;
        }

        // 2. Check significant distinctive product tokens (e.g. 'gt73pro3000w', 'ecobubble', 'oled')
        $matchedTokens = $this->getMatchedProductTokens($product, $text);
        if (! empty($matchedTokens)) {
            return true;
        }

        // 3. If brand is present, check brand ONLY IF no distinctive tokens exist on product
        $productDistinctiveTokens = $this->extractDistinctiveProductTokens($product);
        if ($product->brand && empty($productDistinctiveTokens)) {
            $brandLower = mb_strtolower($product->brand, 'UTF-8');
            if (str_contains($text, $brandLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract distinctive tokens belonging to the product (excluding brand, model code, category words and stop words).
     */
    public function extractDistinctiveProductTokens(Product $product): array
    {
        $nameToExtract = $product->commercial_name ?: $product->name;
        if ($product->brand) {
            $nameToExtract = str_ireplace($product->brand, '', $nameToExtract);
        }
        if ($product->model_code) {
            $nameToExtract = str_ireplace($product->model_code, '', $nameToExtract);
        }

        $tokens = preg_split('/[\s,\.\-\/\+]+/', mb_strtolower($nameToExtract, 'UTF-8'));

        return array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) >= 3 && ! in_array($t, $this->genericStopWords, true);
        }));
    }

    /**
     * Extract significant identifying tokens from candidate text.
     */
    public function getMatchedProductTokens(Product $product, string $text): array
    {
        $distinctive = $this->extractDistinctiveProductTokens($product);

        $matched = [];
        foreach ($distinctive as $token) {
            if (str_contains($text, $token)) {
                $matched[] = $token;
            }
        }

        return $matched;
    }

    /**
     * Fuzzy / Tokenized model code matcher.
     */
    protected function matchesModelCode(string $modelCode, string $text): bool
    {
        $codeClean = preg_replace('/[^A-Za-z0-9]/', '', mb_strtolower($modelCode, 'UTF-8'));
        $textClean = preg_replace('/[^A-Za-z0-9]/', '', $text);

        // Check stripped exact match
        if (str_contains($textClean, $codeClean)) {
            return true;
        }

        // Check primary prefix before slash (e.g. MA512W165 from MA512W165/GK-05)
        $parts = explode('/', $modelCode);
        if (count($parts) > 1) {
            $prefixClean = preg_replace('/[^A-Za-z0-9]/', '', mb_strtolower($parts[0], 'UTF-8'));
            if (strlen($prefixClean) >= 5 && str_contains($textClean, $prefixClean)) {
                return true;
            }
        }

        // Check model family root (e.g. WA17CG from WA17CG6746BVBZ)
        if (strlen($codeClean) >= 8) {
            $root8 = substr($codeClean, 0, 8);
            $root6 = substr($codeClean, 0, 6);
            if (str_contains($textClean, $root8) || (strlen($root6) >= 6 && str_contains($textClean, $root6))) {
                return true;
            }
        }

        return false;
    }
}
