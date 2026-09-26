<?php

namespace App\Services\Collector\Collectors;

use App\Models\ProductSource;
use App\Services\Collector\Contracts\PriceCollectorInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class BaseCollector implements PriceCollectorInterface
{
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 PriceWatchBot/1.0';

    protected int $timeoutSeconds = 15;

    /**
     * Perform HTTP GET request with standard headers and error handling.
     */
    protected function fetchHtml(string $url): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
            ])
                ->timeout($this->timeoutSeconds)
                ->get($url);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
                'duration_ms' => $durationMs,
                'error' => $response->successful() ? null : 'HTTP Status '.$response->status(),
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract JSON-LD scripts from HTML.
     */
    protected function extractJsonLd(string $html): array
    {
        $results = [];

        // Match all <script type="application/ld+json"> blocks
        if (preg_match_all('/<script\b[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $content) {
                $content = trim($content);
                if (empty($content)) {
                    continue;
                }

                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $results[] = $data;
                }
            }
        }

        return $results;
    }

    /**
     * Validate whether product model and voltage match expectations.
     * Returns null if valid, or reason string if mismatch.
     */
    protected function validateModelAndVoltage(ProductSource $source, string $rawText): ?string
    {
        $textLower = mb_strtolower($rawText, 'UTF-8');

        // 1. Mandatory Voltage check
        // Check for 127V / 110V indicators
        $has127v = preg_match('/\b(110\s*v|127\s*v|110volts|127volts)\b/i', $textLower);
        $has220v = preg_match('/\b(220\s*v|220volts)\b/i', $textLower);

        if ($source->expected_voltage === '220V') {
            if ($has127v && ! $has220v) {
                return 'Voltagem identificada como 127V/110V, esperado 220V.';
            }
        }

        // 2. Model Code check if specified
        if (! empty($source->expected_model)) {
            $modelClean = Str::slug($source->expected_model);
            $textClean = Str::slug($rawText);

            // Also check without separators
            $rawModelPlain = preg_replace('/[^a-zA-Z0-9]/', '', mb_strtolower($source->expected_model));
            $rawTextPlain = preg_replace('/[^a-zA-Z0-9]/', '', $textLower);

            if (! str_contains($textClean, $modelClean) && ! str_contains($rawTextPlain, $rawModelPlain)) {
                // If model is Panasonic NA-F180P7, check F180P7
                $shortModel = preg_replace('/^(na-|wa-)/i', '', $source->expected_model);
                $shortClean = Str::slug($shortModel);

                if (! str_contains($textClean, $shortClean)) {
                    return "Código do modelo esperado '{$source->expected_model}' não encontrado no anúncio.";
                }
            }
        }

        return null;
    }

    /**
     * Clean and parse price strings into float.
     */
    protected function parsePrice(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $floatVal = (float) $value;

            return $this->isValidPrice($floatVal) ? $floatVal : null;
        }

        if (! is_string($value)) {
            return null;
        }

        // Clean string: R$ 2.499,90 -> 2499.90
        $clean = trim($value);
        $clean = preg_replace('/[^\d,\.]/', '', $clean);

        if (empty($clean)) {
            return null;
        }

        // If format is like 2.499,90
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (str_contains($clean, ',')) {
            // Format like 2499,90
            $clean = str_replace(',', '.', $clean);
        }

        if (is_numeric($clean)) {
            $floatVal = (float) $clean;

            return $this->isValidPrice($floatVal) ? $floatVal : null;
        }

        return null;
    }

    /**
     * Outlier guard: Reject zero or absurd price ranges.
     */
    protected function isValidPrice(float $price): bool
    {
        // Washing machines in scope are expected between R$ 1.000 and R$ 10.000
        return $price >= 500.00 && $price <= 15000.00;
    }
}
