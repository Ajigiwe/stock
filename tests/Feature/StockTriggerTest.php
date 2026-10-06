<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the 12 MySQL triggers that took over from the Postgres RPCs:
 * available is derived on insert, never client-writable, and overselling or
 * over-correcting must fail loudly with the original messages.
 *
 * Uses the query builder directly (no Eloquent) so the assertions are about
 * the database, not about the models being ported alongside this file.
 */
class StockTriggerTest extends TestCase
{
    use RefreshDatabase;

    private string $shopId;
    private string $otherShopId;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = (string) Str::uuid();
        $this->otherShopId = (string) Str::uuid();
        $this->userId = (string) Str::uuid();

        DB::table('shops')->insert([
            ['id' => $this->shopId, 'name' => 'Osu Shop'],
            ['id' => $this->otherShopId, 'name' => 'Kumasi Shop'],
        ]);
        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Owner',
            'role' => 'owner',
            'active' => 1,
        ]);
    }

    private function makeModel(string $id, int $opening, ?string $shopId = null): string
    {
        DB::table('phone_models')->insert([
            'id' => $id,
            'shop_id' => $shopId ?? $this->shopId,
            'model_name' => 'Model '.$id,
            'condition' => 'new',
            'opening_stock' => $opening,
            'bought_in' => 0,
            'available' => 0,
        ]);

        return $id;
    }

    private function makeTransaction(): string
    {
        $id = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id' => $id,
            'shop_id' => $this->shopId,
            'staff_id' => $this->userId,
            'type' => 'sale',
            'payment_method' => 'cash',
            'amount' => 100,
        ]);

        return $id;
    }

    private function available(string $modelId): int
    {
        return (int) DB::table('phone_models')->where('id', $modelId)->value('available');
    }

    private function expectFailure(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail('Expected a database error containing: '.$needle);
    }

    public function test_available_is_derived_on_insert_and_ignores_client_values(): void
    {
        $id = $this->makeModel((string) Str::uuid(), 5);

        $this->assertSame(5, $this->available($id));
    }

    public function test_selling_moves_stock_out(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5);
        $tx = $this->makeTransaction();

        DB::table('transaction_items')->insert([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx,
            'phone_model_id' => $model,
            'direction' => 'out',
            'qty' => 2,
        ]);

        $this->assertSame(3, $this->available($model));
    }

    public function test_overselling_is_blocked_with_the_original_message(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5);
        $tx = $this->makeTransaction();

        $this->expectFailure(function () use ($model, $tx): void {
            DB::table('transaction_items')->insert([
                'id' => (string) Str::uuid(),
                'transaction_id' => $tx,
                'phone_model_id' => $model,
                'direction' => 'out',
                'qty' => 6,
            ]);
        }, 'Insufficient stock: only 5 available for this model');

        $this->assertSame(5, $this->available($model));
    }

    public function test_a_line_item_may_not_cross_shops(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 1, $this->otherShopId);
        $tx = $this->makeTransaction();

        $this->expectFailure(function () use ($model, $tx): void {
            DB::table('transaction_items')->insert([
                'id' => (string) Str::uuid(),
                'transaction_id' => $tx,
                'phone_model_id' => $model,
                'direction' => 'out',
                'qty' => 1,
            ]);
        }, "does not belong to this transaction's shop");
    }

    public function test_restocking_increments_available_and_bought_in(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5);

        DB::table('stock_adjustments')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'phone_model_id' => $model,
            'staff_id' => $this->userId,
            'type' => 'restock',
            'delta' => 4,
        ]);

        $row = DB::table('phone_models')->where('id', $model)->first();
        $this->assertSame(9, (int) $row->available);
        $this->assertSame(4, (int) $row->bought_in);
    }

    public function test_a_correction_cannot_drive_stock_negative(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5);

        $this->expectFailure(function () use ($model): void {
            DB::table('stock_adjustments')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopId,
                'phone_model_id' => $model,
                'staff_id' => $this->userId,
                'type' => 'correction',
                'delta' => -6,
            ]);
        }, 'Insufficient stock to correct: only 5 available');

        $this->assertSame(5, $this->available($model));
    }

    public function test_a_zero_delta_adjustment_is_rejected_by_check(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5);

        $this->expectFailure(function () use ($model): void {
            DB::table('stock_adjustments')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopId,
                'phone_model_id' => $model,
                'staff_id' => $this->userId,
                'type' => 'correction',
                'delta' => 0,
            ]);
        }, 'sa_delta_ck');
    }

    public function test_deleting_a_line_credits_stock_back(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5);
        $tx = $this->makeTransaction();
        $lineId = (string) Str::uuid();

        DB::table('transaction_items')->insert([
            'id' => $lineId,
            'transaction_id' => $tx,
            'phone_model_id' => $model,
            'direction' => 'out',
            'qty' => 2,
        ]);
        $this->assertSame(3, $this->available($model));

        DB::table('transaction_items')->where('id', $lineId)->delete();

        $this->assertSame(5, $this->available($model));
    }

    public function test_adjustments_must_reference_their_own_shops_model(): void
    {
        $model = $this->makeModel((string) Str::uuid(), 5, $this->otherShopId);

        $this->expectFailure(function () use ($model): void {
            DB::table('stock_adjustments')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopId,
                'phone_model_id' => $model,
                'staff_id' => $this->userId,
                'type' => 'restock',
                'delta' => 1,
            ]);
        }, 'Phone model does not belong to this shop');
    }

    public function test_the_restore_flag_lets_a_backup_choose_available(): void
    {
        DB::unprepared('SET @mrjeff_no_stock_effects = 1');

        // Backup rows carry their own authoritative `available`; with the flag
        // set the normalize trigger must keep the file's value.
        $id = (string) Str::uuid();
        DB::table('phone_models')->insert([
            'id' => $id,
            'shop_id' => $this->shopId,
            'model_name' => 'Restored model',
            'condition' => 'used',
            'opening_stock' => 0,
            'bought_in' => 0,
            'available' => 42,
        ]);

        DB::unprepared('SET @mrjeff_no_stock_effects = 0');

        $this->assertSame(42, $this->available($id));

        // ...and the flag is not sticky: normal inserts are derived again.
        $derived = $this->makeModel((string) Str::uuid(), 7);
        $this->assertSame(7, $this->available($derived));
    }

    public function test_a_model_cannot_be_created_for_another_shop(): void
    {
        // phone_models carries no shop-match trigger (it *is* the shop row's
        // child), but its FK still has to hold.
        $this->expectFailure(function (): void {
            DB::table('phone_models')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => '00000000-0000-0000-0000-000000000000',
                'model_name' => 'Orphan',
                'opening_stock' => 1,
            ]);
        }, 'pm_shop_fk');
    }

    protected function tearDown(): void
    {
        // The restore flag is a session variable — never let it leak into the
        // next test if one of them died mid-restore.
        DB::unprepared('SET @mrjeff_no_stock_effects = 0');

        parent::tearDown();
    }
}
