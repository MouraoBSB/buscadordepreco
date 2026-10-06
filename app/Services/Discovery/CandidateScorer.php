<?php

namespace App\Services\Discovery;

use App\Models\Product;
use App\Services\Discovery\DTOs\RawCandidateDto;

class CandidateScorer
{
    /**
     * Compute confidence score (0-100), classification status and transparent breakdown.
     */
    public function score(Product $product, RawCandidateDto $candidate, CandidateValidationResult $validation): array
    {
        if (! $validation->isValid) {
            return [
                'score' => 0,
                'status' => 'rejected',
                'rejection_reason' => $validation->rejectionReason,
                'breakdown' => [
                    'rejection' => $validation->rejectionReason,
                ],
            ];
        }

        $score = 0;
        $breakdown = [];
        $hasModel = ! empty($product->model_code);

        // 1. Model Code Match (+40 pts)
        if ($hasModel) {
            if (! empty($validation->matches['model_code_matched'])) {
                $score += 40;
                $breakdown['model_code_matched'] = 40;
            } else {
                // If model SKU was not in title, but candidate has high commercial identity match
                // (brand + commercial name keywords matched), award 25 pts so legitimate retail offers
                // can reach auto_approved status instead of being locked at 60%
                $highIdentityMatch = ! empty($validation->matches['brand_matched'])
                    && ($validation->matches['name_keywords_matched'] ?? 0) >= 2;

                if ($highIdentityMatch) {
                    $score += 25;
                    $breakdown['model_code_matched'] = 25;
                } else {
                    $breakdown['model_code_matched'] = 0;
                }
            }
        }

        // 2. Product Name / Significant Keywords Match (+15 pts with model, +45 pts without model)
        $nameKeywordsCount = (int) ($validation->matches['name_keywords_matched'] ?? 0);
        $nameWeight = $hasModel ? 15 : 45;
        if ($nameKeywordsCount > 0) {
            $score += $nameWeight;
            $breakdown['name_keywords_matched'] = $nameWeight;
        } else {
            // If model code matched completely, commercial name was absorbed by model
            if (! empty($validation->matches['model_code_matched'])) {
                $score += $nameWeight;
                $breakdown['name_keywords_matched'] = $nameWeight;
            } else {
                $breakdown['name_keywords_matched'] = 0;
            }
        }

        // 3. Brand Match (+15 pts with model, +25 pts without model)
        $brandWeight = $hasModel ? 15 : 25;
        $brandMatched = ! empty($validation->matches['brand_matched']);
        if ($brandMatched) {
            $score += $brandWeight;
            $breakdown['brand_matched'] = $brandWeight;
        } else {
            // If product has no defined brand, and exact model code matched, award brand points
            if (empty($product->brand) && ! empty($validation->matches['model_code_matched'])) {
                $score += $brandWeight;
                $breakdown['brand_matched'] = $brandWeight;
            } else {
                $breakdown['brand_matched'] = 0;
            }
        }

        // 4. Hard Constraints / Required Terms (+20 pts with model, +15 pts without model)
        $constraintsWeight = $hasModel ? 20 : 15;
        $matchedConstraints = 0;
        $totalConstraints = 0;

        if (isset($product->hard_constraints['voltage'])) {
            $totalConstraints++;
            if (! empty($validation->matches['voltage_confirmed'])) {
                $matchedConstraints++;
            }
        }

        if (isset($product->hard_constraints['screen_size'])) {
            $totalConstraints++;
            if (! empty($validation->matches['screen_size_confirmed'])) {
                $matchedConstraints++;
            }
        }

        if (isset($product->hard_constraints['has_agitator'])) {
            $totalConstraints++;
            if (! empty($validation->matches['no_agitator_confirmed'])) {
                $matchedConstraints++;
            }
        }

        if (isset($product->hard_constraints['power_w'])) {
            $totalConstraints++;
            if (! empty($validation->matches['power_confirmed'])) {
                $matchedConstraints++;
            }
        }

        if ($totalConstraints > 0) {
            $pts = (int) round(($matchedConstraints / $totalConstraints) * $constraintsWeight);
            $score += $pts;
            $breakdown['hard_constraints_matched'] = $pts;
        } else {
            // When no hard constraints exist, award points only if name or brand matched
            if ($nameKeywordsCount > 0 || $brandMatched) {
                $score += $constraintsWeight;
                $breakdown['hard_constraints_matched'] = $constraintsWeight;
            } else {
                $breakdown['hard_constraints_matched'] = 0;
            }
        }

        // 5. Product Page Structure (+10 pts with model, +15 pts without model)
        $urlWeight = $hasModel ? 10 : 15;
        if ($this->isProductPageUrl($candidate->url)) {
            $score += $urlWeight;
            $breakdown['valid_product_url'] = $urlWeight;
        } else {
            $breakdown['valid_product_url'] = 0;
        }

        // Auto-approval safety lock: A candidate CANNOT be auto-approved unless
        // model code was matched, OR brand was matched AND at least 2 distinctive tokens matched!
        $isIdentityConfirmed = ! empty($validation->matches['model_code_matched'])
            || (! empty($validation->matches['brand_matched']) && $nameKeywordsCount >= 2);

        // When no hard constraints exist, revoke free constraint points if identity is unconfirmed
        if ($totalConstraints === 0 && ! $isIdentityConfirmed && isset($breakdown['hard_constraints_matched'])) {
            $score -= $breakdown['hard_constraints_matched'];
            $breakdown['hard_constraints_matched'] = 0;
        }

        // If neither brand nor model code was matched, cap the score at 55% (rejected)
        if (empty($validation->matches['model_code_matched']) && empty($validation->matches['brand_matched'])) {
            $score = min(55, $score);
        }

        $score = min(100, max(0, $score));

        // Status classification
        $status = match (true) {
            $score >= 85 && $isIdentityConfirmed => 'auto_approved',
            $score >= 60 => 'pending_review',
            default => 'rejected',
        };

        if ($status === 'rejected') {
            $missing = [];
            if ($hasModel && empty($breakdown['model_code_matched'])) {
                $missing[] = 'código do modelo não identificado';
            }
            if (empty($breakdown['brand_matched'])) {
                $missing[] = 'marca não confirmada';
            }
            if (empty($breakdown['hard_constraints_matched'])) {
                $missing[] = 'voltagem/capacidade pendente ou divergente';
            }
            $missingText = ! empty($missing) ? ' ('.implode(', ', $missing).')' : '';
            $rejectionReason = "Baixa pontuação de confiança: {$score}% < 60%{$missingText}.";
        } else {
            $rejectionReason = null;
        }

        return [
            'score' => $score,
            'status' => $status,
            'rejection_reason' => $rejectionReason,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Check if URL looks like an individual product page rather than search or category.
     */
    protected function isProductPageUrl(string $url): bool
    {
        $urlLower = strtolower($url);

        // Disqualify search/category/list URLs
        if (preg_match('/(\/busca|\/search|\/categoria|\/departamento|\/lista|\/c\/|\/ofertas)/', $urlLower)) {
            return false;
        }

        // Positive patterns
        if (preg_match('/(\/p$|\/p\/|\/produto\/|\/item\/|\/dp\/|\/pd\/|\.html$|\/MLB-)/', $urlLower)) {
            return true;
        }

        return true;
    }
}
