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
                $breakdown['model_code_matched'] = 0;
            }
        }

        // 2. Hard Constraints / Required Terms (+35 pts with model, +60 pts without model)
        $constraintsWeight = $hasModel ? 35 : 60;
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

        if ($totalConstraints > 0) {
            $pts = (int) round(($matchedConstraints / $totalConstraints) * $constraintsWeight);
            $score += $pts;
            $breakdown['hard_constraints_matched'] = $pts;
        } else {
            // No specific hard constraints, award full points if no negative signals
            $score += $constraintsWeight;
            $breakdown['hard_constraints_matched'] = $constraintsWeight;
        }

        // 3. Brand Match (+15 pts with model, +25 pts without model)
        $brandWeight = $hasModel ? 15 : 25;
        if (! empty($validation->matches['brand_matched'])) {
            $score += $brandWeight;
            $breakdown['brand_matched'] = $brandWeight;
        } else {
            $breakdown['brand_matched'] = 0;
        }

        // 4. Product Page Structure (+10 pts with model, +15 pts without model)
        $urlWeight = $hasModel ? 10 : 15;
        if ($this->isProductPageUrl($candidate->url)) {
            $score += $urlWeight;
            $breakdown['valid_product_url'] = $urlWeight;
        } else {
            $breakdown['valid_product_url'] = 0;
        }

        $score = min(100, max(0, $score));

        // Status classification
        $status = match (true) {
            $score >= 85 => 'auto_approved',
            $score >= 60 => 'pending_review',
            default => 'rejected',
        };

        $rejectionReason = ($status === 'rejected') ? 'Baixa pontuação de confiança (score < 60%).' : null;

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
