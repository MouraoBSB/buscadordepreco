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

        // 1. Forbidden Terms Check (contextual protection from Product Profiling)
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

        // 5. Brand Check
        if ($product->brand) {
            $brandLower = mb_strtolower($product->brand, 'UTF-8');
            if (str_contains($text, $brandLower) || str_contains(mb_strtolower($candidate->getDomain(), 'UTF-8'), $brandLower)) {
                $matches['brand_matched'] = true;
            }
        }

        return CandidateValidationResult::valid($matches);
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

        return false;
    }
}
