<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     *   php artisan db:seed          demo owner/shops/stock
     *   php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
