<?php

namespace Database\Seeders;

use App\Models\User;
USE App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Product::create([
        'strName' => 'PS5',
        'strSKU' => 'SONY-PS5',
        'decPrice' => 699.99,
        'intStock' => 10,
    ]);
    }
}
