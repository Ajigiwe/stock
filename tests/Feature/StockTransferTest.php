<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-shop stock moves: owners and superadmins shift units directly
 * (one correction out, one restock in, both audited); the destination row
 * is created with copied prices when the variant is new there. Attendants
 * keep the refusal — they file requests instead.
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $shopA;
    private string $shopB;
    private string $modelA;
    private User $owner;
    private User $superadmin;
    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = (string) Str::uuid();
        $this->shopB = (string) Str::uuid();
        $this->modelA = (string) Str::uuid();

        DB::table('shops')->insert([
            ['id' => $this->shopA, 'name' => 'Osu Shop'],
            ['id' => $this->shopB, 'name' => 'East Shop'],
        ]);
        DB::table('phone_models')->insert([
            'id' => $this->modelA,
            'shop_id' => $this->shopA,
            'model_name' => 'Tecno Spark 20',
            'condition' => 'new',
            'cost_price' => 80,
            'sale_price' => 100,
            'opening_stock' => 10,
            'bought_in' => 0,
            'available' => 0, // insert trigger derives 10
        ]);
        DB::table('users')->insert([
            [
                'id' => $superId = (string) Str::uuid(),
                'name' => 'Super',
                'role' => 'superadmin',
                'shop_id' => null,
                'active' => 1,
            ],
            [
                'id' => $ownerId = (string) Str::uuid(),
                'name' => 'Owner',
                'role' => 'owner',
                'shop_id' => null,
                'active' => 1,
            ],
            [
                'id' => $kofiId = (string) Str::uuid(),
                'name' => 'Kofi',
                'role' => 'attendant',
                'shop_id' => $this->shopA,
                'active' => 1,
            ],
        ]);
        $this->superadmin = User::findOrFail($superId);
        $this->owner = User::findOrFail($ownerId);
        $this->attendant = User::findOrFail($kofiId);
    }

    private function available(string $modelId): int
    {
        return (int) DB::table('phone_models')->where('id', $modelId)->value('available');
    }

    public function test_owner_moves_stock_to_a_shop_without_that_model(): void
    {
        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopA)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                'toShopId' => $this->shopB,
                'qty' => '3',
            ])
            ->assertRedirect('/shops/'.$this->shopA)
            ->assertSessionHas('success', '3 moved to East Shop.');

        $this->assertSame(7, $this->available($this->modelA));

        // Destination row created with copied prices, opening from the move.
        $dest = DB::table('phone_models')->where('shop_id', $this->shopB)->first();
        $this->assertNotNull($dest);
        $this->assertSame('Tecno Spark 20', $dest->model_name);
        $this->assertSame(0, (int) $dest->opening_stock);
        $this->assertSame(3, (int) $dest->available);

        // Both legs audited as adjustments.
        $this->assertSame(-3, (int) DB::table('stock_adjustments')->where('shop_id', $this->shopA)->value('delta'));
        $this->assertSame(3, (int) DB::table('stock_adjustments')->where('shop_id', $this->shopB)->value('delta'));
    }

    public function test_transfer_tops_up_an_existing_destination_row(): void
    {
        DB::table('phone_models')->insert([
            'id' => $destId = (string) Str::uuid(),
            'shop_id' => $this->shopB,
            'model_name' => 'Tecno Spark 20',
            'condition' => 'new',
            'opening_stock' => 4,
            'available' => 0,
        ]);

        $this->actingAs($this->owner)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                'toShopId' => $this->shopB,
                'qty' => '2',
            ])
            ->assertSessionHas('success', '2 moved to East Shop.');

        $this->assertSame(8, $this->available($this->modelA));
        $this->assertSame(6, $this->available($destId));
        $this->assertSame(1, DB::table('phone_models')->where('shop_id', $this->shopB)->count());
    }

    public function test_transfer_refuses_bad_quantities_shops_and_stock(): void
    {
        foreach (['0', '-2', 'abc'] as $qty) {
            $this->actingAs($this->owner)
                ->from('/shops/'.$this->shopA)
                ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                    'toShopId' => $this->shopB,
                    'qty' => $qty,
                ])
                ->assertRedirect('/shops/'.$this->shopA)
                ->assertSessionHasErrors(['action' => 'Enter a quantity of at least 1.']);
        }

        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopA)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                'toShopId' => $this->shopA,
                'qty' => '1',
            ])
            ->assertSessionHasErrors(['action' => 'Choose a different shop to move stock to.']);

        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopA)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                'toShopId' => $this->shopB,
                'qty' => '99',
            ])
            ->assertSessionHasErrors(['action' => 'Only 10 available to move.']);

        $this->assertSame(10, $this->available($this->modelA));
        $this->assertSame(0, DB::table('phone_models')->where('shop_id', $this->shopB)->count());
        $this->assertSame(0, DB::table('stock_adjustments')->count());
    }

    public function test_attendant_cannot_transfer_but_superadmin_can(): void
    {
        // The move form shows for admins only.
        $this->actingAs($this->owner)->get('/shops/'.$this->shopA)
            ->assertOk()
            ->assertSee('Move to another shop', false);
        $this->actingAs($this->attendant)->get('/shops/'.$this->shopA)
            ->assertOk()
            ->assertDontSee('Move to another shop', false);

        $this->actingAs($this->attendant)
            ->from('/shops/'.$this->shopA)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                'toShopId' => $this->shopB,
                'qty' => '1',
            ])
            ->assertRedirect('/shops/'.$this->shopA)
            ->assertSessionHasErrors(['action' => 'Only the owner can move stock between shops.']);
        $this->assertSame(10, $this->available($this->modelA));

        $this->actingAs($this->superadmin)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/transfer', [
                'toShopId' => $this->shopB,
                'qty' => '1',
            ])
            ->assertSessionHas('success', '1 moved to East Shop.');
        $this->assertSame(9, $this->available($this->modelA));
    }

    public function test_superadmin_updates_stocks_imports_and_wipes(): void
    {
        // Bulk add through the settings form.
        $this->actingAs($this->superadmin)
            ->from('/settings')
            ->post('/settings/models/bulk', [
                'shopId' => $this->shopA,
                'rows' => [['model_name' => 'Itel S23', 'condition' => 'new']],
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', '1 devices added.');

        // CSV import.
        $csv = "model_name,condition,opening_stock\nNokia 105,new,5\n";
        $this->actingAs($this->superadmin)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopA,
                'csv' => \Illuminate\Http\UploadedFile::fake()->createWithContent('a.csv', $csv),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', '1 devices imported.');

        $this->assertSame(3, DB::table('phone_models')->where('shop_id', $this->shopA)->count());

        // Full wipe keeps the superadmin signed in.
        $this->actingAs($this->superadmin)
            ->from('/settings')
            ->post('/settings/wipe', ['confirm' => 'WIPE'])
            ->assertRedirect('/settings');
        $this->assertMatchesRegularExpression(
            '/^All data wiped \(\d+ rows removed\)\.$/',
            (string) session('success')
        );
        $this->assertSame(0, DB::table('phone_models')->count());
        $this->assertSame(1, DB::table('users')->where('role', 'superadmin')->count());
    }
}
