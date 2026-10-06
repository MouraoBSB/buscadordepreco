<?php

namespace App\Services\Profiling;

use App\Models\ApiUsageLog;
use Illuminate\Support\Facades\Http;

class ProductEnrichmentService
{
    protected array $knownBrands = [
        'Midea', 'Panasonic', 'Samsung', 'LG', 'Apple', 'Sony', 'Nintendo',
        'Microsoft', 'Xbox', 'PlayStation', 'Valve', 'Steam', 'Electrolux',
        'Brastemp', 'Consul', 'Dell', 'Asus', 'Lenovo', 'Xiaomi', 'TCL',
        'Philips', 'JBL', 'Motorola', 'Acer', 'Britânia', 'Mondial', 'Arno',
        'Philco', 'Oster', 'Cadence', 'Walita', 'Kingston', 'Corsair', 'Logitech',
        'Razer', 'HyperX', 'Anker', 'Baseus', 'Google', 'Amazon', 'Intel', 'AMD',
    ];

    /**
     * Parse natural language product input into a normalized ProductProfileDto.
     */
    public function profileFromText(string $rawInput, ?float $targetPrice = null, bool $useWebRecon = false): ProductProfileDto
    {
        $input = trim($rawInput);
        $inputLower = mb_strtolower($input, 'UTF-8');

        // 1. Brand Detection
        $brand = $this->detectBrand($input);

        // 2. Hard Constraints Extraction
        $hardConstraints = [];
        $voltage = $this->detectVoltage($inputLower);
        if ($voltage) {
            $hardConstraints['voltage'] = $voltage;
        }

        $capacity = $this->detectCapacity($inputLower);
        if ($capacity !== null) {
            $hardConstraints['capacity_kg'] = $capacity;
        }

        $screenSize = $this->detectScreenSize($inputLower);
        if ($screenSize) {
            $hardConstraints['screen_size'] = $screenSize;
        }

        $wattage = $this->detectWattage($inputLower);
        if ($wattage !== null) {
            $hardConstraints['power_w'] = $wattage;
        }

        $ramStorage = $this->detectRamStorage($inputLower);
        if (! empty($ramStorage)) {
            $hardConstraints = array_merge($hardConstraints, $ramStorage);
        }

        $hasAgitatorCheck = preg_match('/sem\s+agitador/i', $inputLower);
        if ($hasAgitatorCheck) {
            $hardConstraints['has_agitator'] = false;
        }

        // 3. Model Code Detection
        $modelCode = $this->detectModelCode($input, $brand);

        // 4. Category and Commercial Name
        $category = $this->detectCategory($inputLower);
        $commercialName = $this->cleanCommercialName($input, $brand, $modelCode);

        // 5. Inferred Attributes
        $inferred = [];
        if (preg_match('/(oled|qled|nanocell|mini\s*led|ips|amoled)/i', $inputLower, $m)) {
            $inferred['panel_technology'] = strtoupper($m[1]);
        }
        if (preg_match('/(4k|8k|full\s*hd|fhd)/i', $inputLower, $m)) {
            $inferred['resolution'] = strtoupper($m[1]);
        }
        if (preg_match('/(cinza|titanium|titânio|black\s*caviar|preto|branco|inox|prata)/i', $inputLower, $m)) {
            $inferred['color'] = ucfirst(mb_strtolower($m[1], 'UTF-8'));
        }
        if (preg_match('/(ecobubble|inverter|direct\s*drive|healthguard)/i', $inputLower, $m)) {
            $inferred['technology'] = ucfirst(mb_strtolower($m[1], 'UTF-8'));
        }

        // 6. Context-Aware Required and Forbidden Terms (Guideline #1)
        $requiredTerms = [];
        if ($voltage) {
            $requiredTerms[] = $voltage;
        }
        if ($screenSize) {
            $requiredTerms[] = $screenSize;
        }
        if ($capacity) {
            $requiredTerms[] = str_replace('.', ',', (string) $capacity).'kg';
        }
        if ($wattage) {
            $requiredTerms[] = $wattage.'W';
        }

        $forbiddenTerms = $this->generateContextualForbiddenTerms($inputLower, $hardConstraints);

        // 7. Suggested Queries for Discovery
        $suggestedQueries = $this->generateSuggestedQueries($brand, $commercialName, $modelCode, $hardConstraints);

        $dto = new ProductProfileDto(
            name: $input,
            commercialName: $commercialName,
            brand: $brand,
            category: $category,
            modelCode: $modelCode,
            capacityKg: $capacity,
            voltage: $voltage,
            targetPrice: $targetPrice,
            hardConstraints: $hardConstraints,
            inferredAttributes: $inferred,
            requiredTerms: array_values(array_unique($requiredTerms)),
            forbiddenTerms: array_values(array_unique($forbiddenTerms)),
            strictModel: ! empty($modelCode),
            suggestedQueries: $suggestedQueries
        );

        // 8. Optional Web Reconnaissance via Tavily
        if ($useWebRecon && ! empty(config('services.tavily.key'))) {
            $dto = $this->enrichWithTavily($dto);
        }

        return $dto;
    }

    public array $categoryStopWords = [
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
        'eletrica', 'elétrica', 'eletrico', 'elétrico',
    ];

    /**
     * Detect brand from known list or first significant word.
     */
    protected function detectBrand(string $input): ?string
    {
        foreach ($this->knownBrands as $brand) {
            if (preg_match('/\b'.preg_quote($brand, '/').'\b/i', $input)) {
                return $brand;
            }
        }

        $words = array_values(array_filter(explode(' ', trim($input))));
        $stopWords = ['o', 'a', 'os', 'as', 'um', 'uma', 'de', 'do', 'da', 'com', 'sem', 'para', 'em', 'no', 'na', 'the'];

        foreach ($words as $word) {
            $wordLower = mb_strtolower($word, 'UTF-8');
            if (mb_strlen($word) < 3) {
                continue;
            }
            if (in_array($wordLower, $stopWords, true) || in_array($wordLower, $this->categoryStopWords, true)) {
                continue;
            }
            // Model codes with numbers are not brands
            if (preg_match('/\d/', $word)) {
                continue;
            }
            // Generic commercial words
            if (in_array($wordLower, ['novo', 'nova', 'original', 'oficial', 'bivolt', 'pro', 'max', 'plus', 'ultra', 'mini', 'lite', 'comprar'], true)) {
                continue;
            }

            return ucfirst($wordLower);
        }

        return null;
    }

    /**
     * Detect power / wattage in Watts.
     */
    protected function detectWattage(string $inputLower): ?int
    {
        if (preg_match('/\b(\d{2,5})\s*(?:w|watts|watt)\b/iu', $inputLower, $m)) {
            $val = (int) $m[1];
            if ($val >= 50 && $val <= 15000 && ! in_array($val, [110, 127, 220], true)) {
                return $val;
            }
        }

        // Attached pattern like gt73pro3000w
        if (preg_match('/(?:pro|max|plus|gt|[a-z])(\d{3,4})w\b/iu', $inputLower, $m)) {
            $val = (int) $m[1];
            if ($val >= 50 && $val <= 15000 && ! in_array($val, [110, 127, 220], true)) {
                return $val;
            }
        }

        return null;
    }

    /**
     * Detect voltage constraint.
     */
    protected function detectVoltage(string $inputLower): ?string
    {
        if (preg_match('/\b(220\s*v|220\s*volts|220volt)\b/i', $inputLower)) {
            return '220V';
        }
        if (preg_match('/\b(110\s*v|127\s*v|110\s*volts|127\s*volts)\b/i', $inputLower)) {
            return '127V';
        }
        if (preg_match('/\b(bivolt)\b/i', $inputLower)) {
            return 'Bivolt';
        }

        return null;
    }

    /**
     * Detect laundry capacity.
     */
    protected function detectCapacity(string $inputLower): ?float
    {
        if (preg_match('/(\d+(?:[,\.]\d+)?)\s*(?:kg|quilos)/i', $inputLower, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    /**
     * Detect screen size for TVs or monitors.
     */
    protected function detectScreenSize(string $inputLower): ?string
    {
        if (preg_match('/(\d{2,3})\s*(?:polegadas|pol|\")/i', $inputLower, $m)) {
            return $m[1].'"';
        }

        return null;
    }

    /**
     * Detect RAM / Storage for laptops / phones.
     */
    protected function detectRamStorage(string $inputLower): array
    {
        $res = [];
        if (preg_match('/\b(\d{1,2})\s*gb\s*(?:ram|memória)?\b/i', $inputLower, $m)) {
            $res['ram'] = $m[1].'GB';
        }
        if (preg_match('/\b(\d{2,4})\s*(gb|tb)\s*(?:ssd|armazenamento|disco)?\b/i', $inputLower, $m)) {
            $res['storage'] = $m[1].strtoupper($m[2]);
        }

        return $res;
    }

    /**
     * Detect specific model code pattern.
     */
    protected function detectModelCode(string $input, ?string $brand): ?string
    {
        // 1. Slashed or hyphenated models (e.g. MA512W165/GK-05, NA-F180P7, WA17CG6746BVBZ, OLED55C4PSA)
        if (preg_match('/\b([A-Za-z0-9]{2,}[\-\/][A-Za-z0-9\-\/]+)\b/u', $input, $m)) {
            $candidate = $m[1];
            if (! preg_match('/^(220V|127V|110V|16KG|17KG|18KG|OLED|QLED|BIVOLT)$/i', $candidate)) {
                return $candidate;
            }
        }

        // 2. Alphanumeric model codes like Gt73pro3000w, GT73, S20, V10, FT03, R02, WA17CG6746BVBZ
        if (preg_match('/\b([A-Za-z]{1,4}\d{1,4}[A-Za-z0-9\-\/]*|[A-Za-z0-9]{2,}\d{2,}[A-Za-z0-9\-\/]*)\b/u', $input, $m)) {
            $candidate = $m[1];
            if (! preg_match('/^(220V|127V|110V|16KG|17KG|18KG|OLED|QLED|BIVOLT|\d+W|\d+KG|\d+POL|\d+V)$/i', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Detect general product category.
     */
    protected function detectCategory(string $inputLower): string
    {
        if (preg_match('/(bicicleta|bike|ebike|scooter|patinete|moto\s*elétrica)/i', $inputLower)) {
            return 'Mobilidade Elétrica';
        }
        if (preg_match('/(lavadora|lava\s*e\s*seca|máquina\s*de\s*lavar)/i', $inputLower)) {
            return 'Lavadoras & Secadoras';
        }
        if (preg_match('/(tv|smart\s*tv|televis|oled|qled)/i', $inputLower)) {
            return 'Televisores & Áudio';
        }
        if (preg_match('/(notebook|macbook|laptop|computador|ssd)/i', $inputLower)) {
            return 'Informática & Armazenamento';
        }
        if (preg_match('/(videogame|video\s*game|console|switch|playstation|ps5|ps4|xbox|nintendo)/i', $inputLower)) {
            return 'Games & Consoles';
        }
        if (preg_match('/(celular|smartphone|iphone|galaxy)/i', $inputLower)) {
            return 'Smartphones & Telefonia';
        }

        return 'Geral';
    }

    /**
     * Clean commercial name from technical codes.
     */
    protected function cleanCommercialName(string $input, ?string $brand, ?string $modelCode): string
    {
        $clean = $input;
        if ($modelCode) {
            $clean = str_ireplace($modelCode, '', $clean);
        }
        if ($brand && str_starts_with(mb_strtolower($clean, 'UTF-8'), mb_strtolower($brand, 'UTF-8'))) {
            $clean = trim(substr($clean, strlen($brand)));
        }
        // Remove multiple spaces
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        return $clean;
    }

    /**
     * Context-aware forbidden terms generation (Rule #1 from user).
     * Only adds parts/used exclusions if the user is NOT explicitly seeking them!
     */
    protected function generateContextualForbiddenTerms(string $inputLower, array $hardConstraints): array
    {
        $forbidden = [];

        // 1. Voltage contradictions
        if (isset($hardConstraints['voltage'])) {
            if ($hardConstraints['voltage'] === '220V') {
                $forbidden[] = '110V';
                $forbidden[] = '127V';
                $forbidden[] = '110 volts';
                $forbidden[] = '127 volts';
            } elseif ($hardConstraints['voltage'] === '127V') {
                $forbidden[] = '220V';
                $forbidden[] = '220 volts';
            }
        }

        // 2. Agitator contradiction
        if (isset($hardConstraints['has_agitator']) && $hardConstraints['has_agitator'] === false) {
            $forbidden[] = 'com agitador';
        }

        // 3. Screen size contradictions (if TV)
        if (isset($hardConstraints['screen_size'])) {
            $size = (int) str_replace('"', '', $hardConstraints['screen_size']);
            $conflicting = [43, 48, 50, 55, 65, 75];
            foreach ($conflicting as $conf) {
                if ($conf !== $size) {
                    $forbidden[] = $conf.'"';
                    $forbidden[] = $conf.' polegadas';
                }
            }
        }

        // 3.1 Power / Wattage contradiction
        if (isset($hardConstraints['power_w'])) {
            $expectedW = (int) $hardConstraints['power_w'];
            $commonW = [250, 350, 500, 750, 800, 1000, 1200, 1500, 2000, 3000, 5000];
            foreach ($commonW as $cw) {
                if ($cw !== $expectedW) {
                    $forbidden[] = $cw.'w';
                    $forbidden[] = $cw.' watts';
                }
            }
        }

        // 4. Parts / Accessories context check
        // Check if user INTENTIONALLY asked for parts or accessories
        $isSearchingParts = preg_match('/\b(peça|peças|placa|placas|suporte|capa|filtro|acessório|válvula|mangueira|trava|correia|bomba)\b/i', $inputLower);
        if (! $isSearchingParts) {
            // User wants a complete product, so forbid spare parts/accessories
            $forbidden[] = 'peça';
            $forbidden[] = 'placa';
            $forbidden[] = 'suporte';
            $forbidden[] = 'capa protetora';
            $forbidden[] = 'filtro de reposição';
            $forbidden[] = 'válvula';
            $forbidden[] = 'mangueira';
            $forbidden[] = 'trava da porta';
            $forbidden[] = 'trava';
            $forbidden[] = 'bomba de drenagem';
            $forbidden[] = 'correia';
        }

        // 5. Used / Refurbished context check
        $isSearchingUsed = preg_match('/\b(usado|seminovo|recondicionado|com defeito|sucata)\b/i', $inputLower);
        if (! $isSearchingUsed) {
            // User wants a new product
            $forbidden[] = 'usado';
            $forbidden[] = 'com defeito';
            $forbidden[] = 'sucata';
            $forbidden[] = 'recondicionado';
        }

        return $forbidden;
    }

    /**
     * Generate progressive funnel queries (from broad to narrow).
     */
    protected function generateSuggestedQueries(?string $brand, string $commercialName, ?string $modelCode, array $hardConstraints): array
    {
        $queries = [];

        // 1. Broad query: Brand + Commercial Name
        $queries[] = trim(($brand ? $brand.' ' : '').$commercialName);

        // 2. Focused query: Commercial Name + Key constraint (voltage, size, etc.)
        $keyConstraint = '';
        if (isset($hardConstraints['voltage'])) {
            $keyConstraint .= ' '.$hardConstraints['voltage'];
        }
        if (isset($hardConstraints['screen_size'])) {
            $keyConstraint .= ' '.$hardConstraints['screen_size'];
        }
        if (isset($hardConstraints['power_w'])) {
            $keyConstraint .= ' '.$hardConstraints['power_w'].'W';
        }
        if ($keyConstraint) {
            $queries[] = trim(($brand ? $brand.' ' : '').$commercialName.$keyConstraint);
            $queries[] = trim('comprar '.($brand ? $brand.' ' : '').$commercialName.$keyConstraint);
        }

        // 3. Model Code queries (with and without quotes, and model family root)
        if ($modelCode) {
            $queries[] = trim(($brand ? $brand.' ' : '').$modelCode.($keyConstraint ? ' '.$keyConstraint : ''));

            // Clean model prefix (e.g. MA512W165 from MA512W165/GK-05)
            $modelPrefix = explode('/', $modelCode)[0];
            if ($modelPrefix !== $modelCode && strlen($modelPrefix) >= 5) {
                $queries[] = trim(($brand ? $brand.' ' : '').$modelPrefix.($keyConstraint ? ' '.$keyConstraint : ''));
            }

            // Clean 6-character root for long codes (e.g. WA17CG from WA17CG6746BVBZ)
            if (strlen($modelCode) >= 8) {
                $root6 = substr(preg_replace('/[^A-Za-z0-9]/', '', $modelCode), 0, 6);
                if (strlen($root6) >= 5) {
                    $queries[] = trim(($brand ? $brand.' ' : '').$root6.($keyConstraint ? ' '.$keyConstraint : ''));
                }
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * Web Reconnaissance via Tavily API with quota logging.
     */
    protected function enrichWithTavily(ProductProfileDto $dto): ProductProfileDto
    {
        $apiKey = config('services.tavily.key');
        if (empty($apiKey)) {
            return $dto;
        }

        // Quota safeguard: Check if monthly limit is already near cap
        $monthlyLimit = (int) config('services.tavily.monthly_limit', 1000);
        $used = ApiUsageLog::getMonthlyUsage('tavily');
        if ($used >= ($monthlyLimit * 0.95)) {
            // 95% quota reached - skip non-essential web reconnaissance
            return $dto;
        }

        $query = $dto->suggestedQueries[0] ?? $dto->name;
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
                ->timeout(8)
                ->post('https://api.tavily.com/search', [
                    'api_key' => $apiKey,
                    'query' => $query.' preco comprar brasil',
                    'search_depth' => 'basic',
                    'max_results' => 3,
                ]);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            ApiUsageLog::logRequest(
                provider: 'tavily',
                purpose: 'profiling',
                query: $query,
                credits: 1,
                status: $response->status(),
                durationMs: $durationMs,
                metadata: ['results_count' => count($response->json('results', []))]
            );

            if ($response->successful()) {
                $results = $response->json('results', []);
                // Inspect snippets to see if a prominent model code appears
                foreach ($results as $res) {
                    $snippet = $res['title'].' '.($res['content'] ?? '');
                    if (! $dto->modelCode) {
                        $foundModel = $this->detectModelCode($snippet, $dto->brand);
                        if ($foundModel) {
                            $dto->modelCode = $foundModel;
                            $dto->strictModel = true;
                            // Regenerate queries with newly discovered model
                            $dto->suggestedQueries = $this->generateSuggestedQueries($dto->brand, $dto->commercialName, $foundModel, $dto->hardConstraints);
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail gracefully - profiling still works with heuristic parsing
        }

        return $dto;
    }
}
