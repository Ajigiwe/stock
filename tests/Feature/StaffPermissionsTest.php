<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner-granted staff capabilities: an attendant holding a grant acts inside
 * their own shop only — approving requests, adjusting stock directly, and
 * running reconciliation (lock closes, approve/apply counts). Without the
 * grant every original owner-only message still fires; with the grant but
 * outside their shop the row reads as missing.
 */
class StaffPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private string $shopA;
    private string $shopB;
    private string $modelA;
    private string $modelB;
    private User $owner;
    private User $kofi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = (string) Str::uuid();
        $this->shopB = (string) Str::uuid();
        $this->modelA = (string) Str::uuid();
        $this->modelB = (string) Str::uuid();

        DB::table('shops')->insert([
            ['id' => $this->shopA, 'name' => 'Osu Shop'],
            ['id' => $this->shopB, 'name' => 'East Shop'],
        ]);
        DB::table('phone_models')->insert([
            [
                'id' => $this->modelA,
                'shop_id' => $this->shopA,
                'model_name' => 'Tecno Spark 20',
                'condition' => 'new',
                'cost_price' => 80,
                'sale_price' => 100,
                'opening_stock' => 10,
                'bought_in' => 0,
                'available' => 0, // insert trigger derives 10
            ],
            [
                'id' => $this->modelB,
                'shop_id' => $this->shopB,
                'model_name' => 'Itel S23',
                'condition' => 'new',
                'cost_price' => 60,
                'sale_price' => 90,
                'opening_stock' => 4,
                'bought_in' => 0,
                'available' => 0, // insert trigger derives 4
            ],
        ]);
        DB::table('users')->insert([
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
        $this->owner = User::findOrFail($ownerId);
        $this->kofi = User::findOrFail($kofiId);
    }

    private function grant(array $flags): void
    {
        $this->kofi->forceFill(array_merge([
            'perm_approve_requests' => false,
            'perm_adjust_stock' => false,
            'perm_reconcile' => false,
        ], $flags))->save();
        $this->kofi->refresh();
    }

    private function fileAdjustRequest(string $modelId, int $delta): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_requests')->insert([
            'id' => $id,
            'shop_id' => $this->shopA,
            'staff_id' => $this->kofi->id,
            'type' => 'adjust_stock',
            'phone_model_id' => $modelId,
            'delta' => $delta,
            'reason' => 'Recount',
        ]);

        return $id;
    }

    private function available(string $modelId): int
    {
        return (int) DB::table('phone_models')->where('id', $modelId)->value('available');
    }

    public function test_owner_sets_permissions_but_not_on_owners_and_not_as_staff(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/permissions', [
                'perm_approve_requests' => '1',
                'perm_reconcile' => '1',
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', 'Permissions updated for Kofi.');

        // Checked boxes stick; absent boxes read as off.
        $this->assertDatabaseHas('users', [
            'id' => $this->kofi->id,
            'perm_approve_requests' => 1,
            'perm_adjust_stock' => 0,
            'perm_reconcile' => 1,
        ]);

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->owner->id.'/permissions', ['perm_reconcile' => '1'])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'You cannot change an owner\'s permissions.']);

        $this->actingAs($this->kofi)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/permissions', ['perm_reconcile' => '1'])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Only the owner can change permissions.']);
    }

    public function test_granted_attendant_approves_and_rejects_in_their_own_shop(): void
    {
        $this->grant(['perm_approve_requests' => true]);

        $requestId = $this->fileAdjustRequest($this->modelA, 2);
        $this->actingAs($this->kofi)
            ->from('/shops/'.$this->shopA)
            ->post('/requests/'.$requestId.'/approve')
            ->assertSessionHas('success', 'Change approved.');
        $this->assertSame('approved', DB::table('stock_requests')->where('id', $requestId)->value('status'));
        $this->assertSame(12, $this->available($this->modelA));

        $rejectedId = $this->fileAdjustRequest($this->modelA, 3);
        $this->actingAs($this->kofi)
            ->post('/requests/'.$rejectedId.'/reject')
            ->assertSessionHas('success', 'Change rejected.');
        $this->assertSame('rejected', DB::table('stock_requests')->where('id', $rejectedId)->value('status'));
        $this->assertSame(12, $this->available($this->modelA));
    }

    public function test_ungranted_attendant_keeps_the_original_refusals(): void
    {
        $requestId = $this->fileAdjustRequest($this->modelA, 2);

        $this->actingAs($this->kofi)
            ->from('/shops/'.$this->shopA)
            ->post('/requests/'.$requestId.'/approve')
            ->assertRedirect('/shops/'.$this->shopA)
            ->assertSessionHasErrors(['action' => 'Only the owner can approve stock changes.']);

        $this->actingAs($this->kofi)
            ->post('/requests/'.$requestId.'/reject')
            ->assertSessionHasErrors(['action' => 'Only the owner can reject stock changes.']);

        $this->assertSame('pending', DB::table('stock_requests')->where('id', $requestId)->value('status'));
        $this->assertSame(10, $this->available($this->modelA));
    }

    public function test_granted_attendant_cannot_touch_another_shops_requests(): void
    {
        $this->grant(['perm_approve_requests' => true]);

        $otherId = (string) Str::uuid();
        DB::table('stock_requests')->insert([
            'id' => $otherId,
            'shop_id' => $this->shopB,
            'staff_id' => $this->owner->id,
            'type' => 'adjust_stock',
            'phone_model_id' => $this->modelB,
            'delta' => 1,
        ]);

        $this->actingAs($this->kofi)
            ->post('/requests/'.$otherId.'/approve')
            ->assertSessionHasErrors(['action' => 'Pending stock request not found']);

        $this->assertSame('pending', DB::table('stock_requests')->where('id', $otherId)->value('status'));
        $this->assertSame(4, $this->available($this->modelB));
    }

    public function test_approve_all_only_sweeps_the_holders_shop(): void
    {
        $this->grant(['perm_approve_requests' => true]);

        $mine = $this->fileAdjustRequest($this->modelA, 1);
        $theirs = (string) Str::uuid();
        DB::table('stock_requests')->insert([
            'id' => $theirs,
            'shop_id' => $this->shopB,
            'staff_id' => $this->owner->id,
            'type' => 'adjust_stock',
            'phone_model_id' => $this->modelB,
            'delta' => 1,
        ]);

        $this->actingAs($this->kofi)
            ->post('/requests/approve-all')
            ->assertSessionHas('success', '1 stock change(s) approved.');

        $this->assertSame('approved', DB::table('stock_requests')->where('id', $mine)->value('status'));
        $this->assertSame('pending', DB::table('stock_requests')->where('id', $theirs)->value('status'));
    }

    public function test_granted_attendant_adjusts_directly_while_others_file_requests(): void
    {
        $this->grant(['perm_adjust_stock' => true]);

        $this->actingAs($this->kofi)
            ->from('/shops/'.$this->shopA)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/adjust', [
                'delta' => '2',
                'reason' => 'Recount',
            ])
            ->assertSessionHas('success', 'Stock updated.');

        $this->assertSame(12, $this->available($this->modelA));
        $this->assertSame(0, DB::table('stock_requests')->count());

        // Without the grant the same post files a request and moves nothing.
        $this->grant(['perm_adjust_stock' => false]);
        $this->actingAs($this->kofi)
            ->post('/shops/'.$this->shopA.'/models/'.$this->modelA.'/adjust', [
                'delta' => '2',
                'reason' => 'Recount',
            ]);

        $this->assertSame(12, $this->available($this->modelA));
        $this->assertSame(1, DB::table('stock_requests')->where('status', 'pending')->count());
    }

    public function test_granted_attendant_runs_reconciliation_in_their_shop(): void
    {
        $this->grant(['perm_reconcile' => true]);
        $today = gmdate('Y-m-d');

        $this->actingAs($this->kofi)
            ->post('/shops/'.$this->shopA.'/close', [
                'countedCash' => '0',
                'countedMobileMoney' => '0',
                'countedOther' => '0',
                'date' => $today,
            ])
            ->assertSessionHas('success', 'Daily counts submitted.');

        $closeId = DB::table('daily_closes')->where('shop_id', $this->shopA)->value('id');
        $this->assertNotNull($closeId);

        $this->actingAs($this->kofi)
            ->post('/shops/'.$this->shopA.'/close/'.$closeId.'/lock')
            ->assertSessionHas('success', 'Daily close locked.');
        $this->assertSame('locked', DB::table('daily_closes')->where('id', $closeId)->value('status'));

        $this->actingAs($this->kofi)
            ->post('/shops/'.$this->shopA.'/counts', [
                'date' => $today,
                'items' => [['modelId' => $this->modelA, 'countedQty' => '9']],
            ])
            ->assertSessionHas('success', 'Physical stock count submitted for review.');

        $countId = DB::table('stock_counts')->where('shop_id', $this->shopA)->value('id');
        $this->assertNotNull($countId);

        $this->actingAs($this->kofi)
            ->post('/reports/counts/'.$countId.'/approve')
            ->assertSessionHas('success', 'Stock count approved.');
        $this->assertSame('approved', DB::table('stock_counts')->where('id', $countId)->value('status'));

        $this->actingAs($this->kofi)
            ->post('/reports/counts/'.$countId.'/apply', ['reason' => 'Damaged unit'])
            ->assertSessionHas('success', 'Stock correction applied and logged.');
        $this->assertSame(9, $this->available($this->modelA));
    }

    public function test_ungranted_attendant_keeps_the_original_reconciliation_refusals(): void
    {
        $closeId = (string) Str::uuid();
        DB::table('daily_closes')->insert([
            'id' => $closeId,
            'shop_id' => $this->shopA,
            'close_date' => gmdate('Y-m-d'),
            'status' => 'open',
            'submitted_by' => $this->owner->id,
        ]);

        $this->actingAs($this->kofi)
            ->post('/shops/'.$this->shopA.'/close/'.$closeId.'/lock')
            ->assertSessionHasErrors(['action' => 'Only the owner can lock a close.']);
        $this->assertSame('open', DB::table('daily_closes')->where('id', $closeId)->value('status'));
    }

    public function test_owner_moves_attendant_between_shops_and_can_park_them(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/shop', ['shopId' => $this->shopB])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', 'Kofi moved to East Shop.');
        $this->assertSame($this->shopB, DB::table('users')->where('id', $this->kofi->id)->value('shop_id'));

        // The new shop opens; the old one is now forbidden.
        $this->kofi->refresh();
        $this->actingAs($this->kofi)->get('/shops/'.$this->shopB)->assertOk();
        $this->actingAs($this->kofi)->get('/shops/'.$this->shopA)->assertForbidden();

        // Parking without a shop locks them out everywhere.
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/shop', ['shopId' => ''])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', 'Kofi has no shop for now.');
        $this->assertNull(DB::table('users')->where('id', $this->kofi->id)->value('shop_id'));
        $this->kofi->refresh();
        $this->actingAs($this->kofi)->get('/shops/'.$this->shopB)->assertForbidden();
    }

    public function test_move_refuses_bad_shops_owners_and_staff_callers(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/shop', ['shopId' => 'nope'])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Select a valid shop.']);

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/shop', ['shopId' => (string) Str::uuid()])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Select a valid shop.']);
        $this->assertSame($this->shopA, DB::table('users')->where('id', $this->kofi->id)->value('shop_id'));

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/staff/'.$this->owner->id.'/shop', ['shopId' => $this->shopB])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'You cannot move an owner.']);

        $this->actingAs($this->kofi)
            ->from('/settings')
            ->post('/settings/staff/'.$this->kofi->id.'/shop', ['shopId' => $this->shopB])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Only the owner can move staff.']);
    }
}
