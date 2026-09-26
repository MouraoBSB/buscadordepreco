<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin User for Filament Dashboard
        User::firstOrCreate(
            ['email' => env('ADMIN_DEFAULT_EMAIL', 'admin@mgnexus.com.br')],
            [
                'name' => 'Admin PriceWatch',
                'password' => Hash::make(env('ADMIN_DEFAULT_PASSWORD', 'PriceWatch#2026!')),
            ]
        );

        $this->call([
            ProductSeeder::class,
        ]);
    }
}
