<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Initial Stores
        $stores = [
            [
                'name' => 'Midea Oficial',
                'domain' => 'mideastore.com.br',
                'trust_level' => 'official',
            ],
            [
                'name' => 'Panasonic Oficial',
                'domain' => 'panasonic.com',
                'trust_level' => 'official',
            ],
            [
                'name' => 'Samsung Oficial',
                'domain' => 'samsung.com',
                'trust_level' => 'official',
            ],
            [
                'name' => 'Magazine Luiza',
                'domain' => 'magazineluiza.com.br',
                'trust_level' => 'marketplace_1p',
            ],
            [
                'name' => 'Mercado Livre',
                'domain' => 'mercadolivre.com.br',
                'trust_level' => 'marketplace_1p',
            ],
            [
                'name' => 'Fast Shop',
                'domain' => 'fastshop.com.br',
                'trust_level' => 'official',
            ],
            [
                'name' => 'Amazon Brasil',
                'domain' => 'amazon.com.br',
                'trust_level' => 'marketplace_1p',
            ],
        ];

        foreach ($stores as $storeData) {
            Store::firstOrCreate(
                ['domain' => $storeData['domain']],
                $storeData
            );
        }

        // 2. Initial Products (Section 5 from spec)
        $products = [
            [
                'name' => 'Lavadora de Roupas Midea 16,5 kg Cinza Escuro Top Load',
                'commercial_name' => 'Midea Top Load 16,5kg Titanium',
                'brand' => 'Midea',
                'image_url' => '/images/products/midea-ma512w165.jpg',
                'model_code' => 'MA512W165/GK-05',
                'capacity_kg' => 16.5,
                'voltage' => '220V',
                'target_price' => 2200.00,
                'active' => true,
                'hard_constraints' => [
                    'voltage' => '220V',
                    'capacity_kg' => 16.5,
                    'has_agitator' => false,
                ],
                'inferred_attributes' => [
                    'color' => 'Titanium / Cinza Escuro',
                    'drum' => 'Inox',
                    'type' => 'Top Load',
                ],
                'required_terms' => ['220V'],
                'forbidden_terms' => ['110V', '127V', 'agitador', 'com agitador', 'peça', 'placa', 'válvula', 'mangueira', 'usado'],
                'strict_model' => false,
                'metadata' => [
                    'type' => 'top-load',
                    'agitator' => false,
                    'drum_material' => 'inox',
                    'color' => 'Titanium/Cinza Escuro',
                    'notes' => 'Top-load, sem agitador central, 220V obrigatório.',
                ],
                'alert_rules' => [
                    ['type' => 'target_price', 'threshold' => 2200.00],
                    ['type' => 'lowest_price', 'threshold' => null],
                ],
            ],
            [
                'name' => 'Lavadora de Roupas Panasonic 18 kg Titânio Top Load',
                'commercial_name' => 'Panasonic Titânio 18kg Top Load',
                'brand' => 'Panasonic',
                'image_url' => '/images/products/panasonic-na-f180p7.jpg',
                'model_code' => 'NA-F180P7',
                'capacity_kg' => 18.0,
                'voltage' => '220V',
                'target_price' => 2500.00,
                'active' => true,
                'hard_constraints' => [
                    'voltage' => '220V',
                    'capacity_kg' => 18.0,
                    'has_agitator' => false,
                ],
                'inferred_attributes' => [
                    'color' => 'Titânio',
                    'drum' => 'Inox',
                    'type' => 'Top Load',
                ],
                'required_terms' => ['220V'],
                'forbidden_terms' => ['110V', '127V', 'agitador', 'com agitador', 'peça', 'placa', 'válvula', 'mangueira', 'usado'],
                'strict_model' => false,
                'metadata' => [
                    'type' => 'top-load',
                    'agitator' => false,
                    'drum_material' => 'inox',
                    'color' => 'Titânio',
                    'notes' => 'Top-load, sem agitador central, variante oficial 220V confirmada.',
                ],
                'alert_rules' => [
                    ['type' => 'target_price', 'threshold' => 2500.00],
                    ['type' => 'lowest_price', 'threshold' => null],
                ],
            ],
            [
                'name' => 'Lavadora de Roupas Samsung 17 kg Ecobubble Top Load',
                'commercial_name' => 'Samsung Ecobubble 17kg Black Caviar',
                'brand' => 'Samsung',
                'image_url' => '/images/products/samsung-wa17cg6746.jpg',
                'model_code' => 'WA17CG6746BVBZ',
                'capacity_kg' => 17.0,
                'voltage' => '220V',
                'target_price' => 3000.00,
                'active' => true,
                'hard_constraints' => [
                    'voltage' => '220V',
                    'capacity_kg' => 17.0,
                    'has_agitator' => false,
                ],
                'inferred_attributes' => [
                    'color' => 'Black Caviar',
                    'technology' => 'Ecobubble',
                    'drum' => 'Inox',
                    'type' => 'Top Load',
                ],
                'required_terms' => ['220V'],
                'forbidden_terms' => ['110V', '127V', 'agitador', 'com agitador', 'peça', 'placa', 'válvula', 'mangueira', 'usado'],
                'strict_model' => false,
                'metadata' => [
                    'type' => 'top-load',
                    'agitator' => false,
                    'drum_material' => 'inox',
                    'color' => 'Black Caviar',
                    'notes' => 'Top-load, sem agitador central, Ecobubble 220V.',
                ],
                'alert_rules' => [
                    ['type' => 'target_price', 'threshold' => 3000.00],
                    ['type' => 'lowest_price', 'threshold' => null],
                ],
            ],
        ];

        foreach ($products as $prodData) {
            $alertRules = $prodData['alert_rules'];
            unset($prodData['alert_rules']);

            $product = Product::updateOrCreate(
                ['model_code' => $prodData['model_code'], 'voltage' => $prodData['voltage']],
                $prodData
            );

            foreach ($alertRules as $rule) {
                AlertRule::firstOrCreate([
                    'product_id' => $product->id,
                    'type' => $rule['type'],
                ], [
                    'threshold' => $rule['threshold'],
                    'channel' => 'gowa_whatsapp',
                    'active' => true,
                ]);
            }
        }
    }
}
