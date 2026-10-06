<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data for local/UAT use: two shops, an owner, two attendants, stock and
 * a few days of sales. Inserts go through the real tables so the 12 triggers
 * compute `available` exactly as they will in production.
 *
 *   php artisan db:seed
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('users')->where('role', 'owner')->exists()) {
            $this->command?->info('Owner already exists — skipping demo data.');

            return;
        }

        $now = now()->format('Y-m-d H:i:s');

        $osu = $this->id();
        $kumasi = $this->id();

        DB::table('shops')->insert([
            ['id' => $osu, 'name' => 'Osu Flagship', 'location' => 'Oxford Street, Osu', 'phone' => '024 000 0001', 'created_at' => $now],
            ['id' => $kumasi, 'name' => 'Kumasi Central', 'location' => 'Adum, Kumasi', 'phone' => '024 000 0002', 'created_at' => $now],
        ]);

        $owner = $this->id();
        $kofi = $this->id();
        ['id' => $ama] = ['id' => $this->id()];

        DB::table('users')->insert([
            ['id' => $owner, 'name' => 'Jeff Owner', 'email' => 'owner@example.com', 'password' => Hash::make('password123'), 'role' => 'owner', 'shop_id' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $kofi, 'name' => 'Kofi Attendant', 'email' => 'kofi@example.com', 'password' => Hash::make('password123'), 'role' => 'attendant', 'shop_id' => $osu, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $ama, 'name' => 'Ama Attendant', 'email' => 'ama@example.com', 'password' => Hash::make('password123'), 'role' => 'attendant', 'shop_id' => $kumasi, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $catalogue = [
            ['Tecno Spark 20', 'new', 750, 950, 8, 5],
            ['Tecno Camon 20', 'new', 1100, 1450, 5, 3],
            ['Infinix Hot 40', 'new', 820, 1050, 6, 4],
            ['Samsung A15', 'new', 1650, 2100, 4, 3],
            ['Samsung A25', 'new', 2400, 3000, 2, 3],
            ['iPhone 13', 'used', 4200, 5200, 3, 2],
            ['iPhone 11', 'used', 2600, 3300, 4, 2],
            ['Redmi 13C', 'new', 690, 890, 7, 5],
        ];

        $modelIds = [];
        foreach ($catalogue as [$name, $condition, $cost, $sale, $opening, $threshold]) {
            $id = $this->id();
            $modelIds[] = $id;
            DB::table('phone_models')->insert([
                'id' => $id,
                'shop_id' => $osu,
                'model_name' => $name,
                'condition' => $condition,
                'cost_price' => $cost,
                'sale_price' => $sale,
                'opening_stock' => $opening,
                'bought_in' => 0,
                'available' => 0,
                'low_stock_threshold' => $threshold,
                'created_at' => $now,
            ]);
        }

        // A second shop needs its own stock: items may never cross shops.
        $otherModel = $this->id();
        DB::table('phone_models')->insert([
            'id' => $otherModel,
            'shop_id' => $kumasi,
            'model_name' => 'Tecno Spark 20',
            'condition' => 'new',
            'cost_price' => 750,
            'sale_price' => 950,
            'opening_stock' => 6,
            'bought_in' => 0,
            'available' => 0,
            'low_stock_threshold' => 5,
            'created_at' => $now,
        ]);

        // A week of sales, all at the Osu shop.
        foreach (range(0, 6) as $daysAgo) {
            $date = now()->subDays($daysAgo)->setTime(11, 30)->format('Y-m-d H:i:s');
            $sales = max(1, 4 - intdiv($daysAgo, 2));

            for ($i = 0; $i < $sales; $i++) {
                // Only sell what is actually on the shelf: the item trigger
                // refuses overselling, exactly as it will in production.
                $inStock = DB::table('phone_models')
                    ->whereIn('id', $modelIds)
                    ->where('available', '>', 0)
                    ->pluck('id')
                    ->all();

                if ($inStock === []) {
                    break;
                }

                $tx = $this->id();
                $modelId = $inStock[array_rand($inStock)];
                $salePrice = (int) DB::table('phone_models')->where('id', $modelId)->value('sale_price');

                $customerNames = ['Yaw', 'Akosua', 'Kwame', 'Efua'];
                $paymentMethods = ['cash', 'mobile_money', 'cash', 'mobile_money'];

                DB::table('transactions')->insert([
                    'id' => $tx,
                    'shop_id' => $osu,
                    'staff_id' => $kofi,
                    'customer_name' => $customerNames[array_rand($customerNames)],
                    'customer_phone' => null,
                    'type' => 'sale',
                    'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                    'amount' => $salePrice,
                    'date' => $date,
                    'created_at' => $date,
                    'status' => 'completed',
                ]);

                DB::table('transaction_items')->insert([
                    'id' => $this->id(),
                    'transaction_id' => $tx,
                    'phone_model_id' => $modelId,
                    'direction' => 'out',
                    'qty' => 1,
                ]);

                DB::table('transaction_events')->insert([
                    'id' => $this->id(),
                    'transaction_id' => $tx,
                    'actor_id' => $kofi,
                    'action' => 'created',
                    'created_at' => $date,
                ]);
            }
        }

        // A restock so bought_in and the adjustment history are non-empty.
        DB::table('stock_adjustments')->insert([
            'id' => $this->id(),
            'shop_id' => $osu,
            'phone_model_id' => $modelIds[0],
            'staff_id' => $owner,
            'type' => 'restock',
            'delta' => 10,
            'reason' => 'Weekly restock',
            'date' => $now,
        ]);

        DB::table('stock_logs')->insert([
            'id' => $this->id(),
            'shop_id' => $osu,
            'phone_model_id' => $modelIds[0],
            'staff_id' => $owner,
            'action' => 'adjust_stock',
            'model_name' => 'Tecno Spark 20',
            'condition' => 'new',
            'details' => json_encode(['delta' => 10, 'type' => 'restock', 'reason' => 'Weekly restock']),
            'created_at' => $now,
        ]);

        $this->command?->info('Demo data ready — sign in as owner@example.com / password123');
    }

    private function id(): string
    {
        return (string) Str::uuid();
    }
}
