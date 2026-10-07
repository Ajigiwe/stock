<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Drives the write layer through the HTTP kernel exactly like the real forms
 * and the offline queue do: POS sales as a form POST and as a JSON replay
 * (idempotency key), stock adjustments on the owner and attendant paths, the
 * owner approving a filed request, and an owner-only guard. The services and
 * the MySQL triggers do the work — these tests pin the wiring (route →
 * controller → service → respond() flash/redirect/JSON shape).
 */
class TransactionFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $shopId;
    private string $modelId;
    private string $ownerId;
    private string $attendantId;
    private User $owner;
    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = (string) Str::uuid();
        $this->modelId = (string) Str::uuid();
        $this->ownerId = (string) Str::uuid();
        $this->attendantId = (string) Str::uuid();

        DB::table('shops')->insert(['id' => $this->shopId, 'name' => 'Osu Shop']);
        DB::table('phone_models')->insert([
            'id' => $this->modelId,
            'shop_id' => $this->shopId,
            'model_name' => 'Tecno Spark 20',
            'condition' => 'new',
            'cost_price' => 80,
            'sale_price' => 100, // sales refuse phones without a listed price
            'opening_stock' => 10,
            'bought_in' => 0,
            'available' => 0, // the insert trigger derives 10
        ]);
        DB::table('users')->insert([
            [
                'id' => $this->ownerId,
                'name' => 'Owner',
                'role' => 'owner',
                'shop_id' => null,
                'active' => 1,
            ],
            [
                'id' => $this->attendantId,
                'name' => 'Kofi',
                'role' => 'attendant',
                'shop_id' => $this->shopId,
                'active' => 1,
            ],
        ]);

        $this->owner = User::findOrFail($this->ownerId);
        $this->attendant = User::findOrFail($this->attendantId);
    }

    private function available(): int
    {
        return (int) DB::table('phone_models')->where('id', $this->modelId)->value('available');
    }

    /** @return array<string, mixed> */
    private function salePayload(int $qty = 1): array
    {
        return [
            'shopId' => $this->shopId,
            'type' => 'sale',
            'paymentMethod' => 'cash',
            'amount' => (string) ($qty * 100), // sits exactly on the price floor
            // The original requires a customer on every type (actions.ts:475).
            'customerName' => 'Ama Customer',
            'customerPhone' => '0240000000',
            'outItems' => [
                ['modelId' => $this->modelId, 'qty' => (string) $qty],
            ],
            'idempotencyKey' => (string) Str::uuid(),
        ];
    }

    public function test_recording_a_sale_through_the_pos_form_moves_stock_and_opens_the_shop(): void
    {
        $response = $this->actingAs($this->owner)
            ->from('/transactions/new')
            ->post('/transactions', $this->salePayload());

        // transaction-form.tsx pushed to the shop page after recording.
        $response->assertRedirect('/shops/'.$this->shopId);
        $response->assertSessionHas('success', 'Transaction recorded.');

        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertSame(1, DB::table('transaction_items')->count());
        $this->assertSame(9, $this->available());
    }

    public function test_a_swap_accepts_a_trade_in_name_not_on_the_list(): void
    {
        // The trade-in picker suggests iPhone models but any typed name
        // records — the server never validated against the list.
        $payload = $this->salePayload();
        $payload['type'] = 'swap';
        $payload['swapIn'] = [['name' => 'Tecno Camon 30']];

        $this->actingAs($this->owner)
            ->from('/transactions/new?type=swap')
            ->post('/transactions', $payload)
            ->assertRedirect('/shops/'.$this->shopId)
            ->assertSessionHas('success', 'Transaction recorded.');

        $this->assertDatabaseHas('swapped_phones', [
            'shop_id' => $this->shopId,
            'model_name' => 'Tecno Camon 30',
        ]);
    }

    public function test_the_offline_queues_json_replay_with_one_idempotency_key_records_once(): void
    {
        $payload = $this->salePayload();

        $first = $this->actingAs($this->owner)->postJson('/transactions', $payload);
        $first->assertOk()->assertJsonPath('ok', true);
        $firstId = $first->json('id');
        $this->assertNotNull($firstId);

        // The queue retries after a timeout where the first attempt landed:
        // the same key must return the existing transaction, not a new one.
        $second = $this->actingAs($this->owner)->postJson('/transactions', $payload);
        $second->assertOk()->assertJsonPath('ok', true)->assertJsonPath('id', $firstId);

        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertSame(9, $this->available());
    }

    public function test_overselling_is_refused_with_the_original_message_on_both_response_shapes(): void
    {
        // Form path: redirect back with the message in the action error bag.
        $form = $this->actingAs($this->owner)
            ->from('/transactions/new')
            ->post('/transactions', $this->salePayload(11));

        $form->assertRedirect('/transactions/new');
        $form->assertSessionHasErrors([
            'action' => 'Insufficient stock: only 10 available for this model',
        ]);

        // JSON path: the offline queue reads {ok:false, error} with 422.
        $json = $this->actingAs($this->owner)->postJson('/transactions', $this->salePayload(11));
        $json->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Insufficient stock: only 10 available for this model');

        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(10, $this->available());
    }

    public function test_an_attendants_stock_change_files_a_request_instead_of_moving_stock(): void
    {
        $response = $this->actingAs($this->attendant)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models/'.$this->modelId.'/adjust', [
                'delta' => '3',
                'reason' => 'Restock from HQ',
            ]);

        $response->assertRedirect('/shops/'.$this->shopId);
        $response->assertSessionHas('success', 'Stock change sent — awaiting owner approval.');

        $request = DB::table('stock_requests')->first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame('adjust_stock', $request->type);
        $this->assertSame(3, (int) $request->delta);
        $this->assertSame(10, $this->available()); // untouched until approved
    }

    public function test_the_owner_adjusts_stock_immediately(): void
    {
        $response = $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models/'.$this->modelId.'/adjust', [
                'delta' => '2',
                'reason' => 'Found in back room',
            ]);

        $response->assertRedirect('/shops/'.$this->shopId);
        $response->assertSessionHas('success', 'Stock updated.');

        $this->assertSame(12, $this->available());
        $this->assertSame(1, DB::table('stock_adjustments')->count());
        $this->assertSame(0, DB::table('stock_requests')->count());
    }

    public function test_the_owner_approving_a_filed_change_applies_it(): void
    {
        $this->actingAs($this->attendant)
            ->post('/shops/'.$this->shopId.'/models/'.$this->modelId.'/adjust', [
                'delta' => '3',
                'reason' => 'Restock from HQ',
            ]);

        $requestId = DB::table('stock_requests')->value('id');
        $this->assertNotNull($requestId);

        $response = $this->actingAs($this->owner)->post('/requests/'.$requestId.'/approve');

        $response->assertSessionHas('success', 'Change approved.');
        $this->assertSame(13, $this->available());
        $this->assertSame('approved', DB::table('stock_requests')->where('id', $requestId)->value('status'));
        $this->assertSame(1, DB::table('stock_adjustments')->count());
    }

    public function test_an_attendant_cannot_add_a_shop(): void
    {
        $response = $this->actingAs($this->attendant)
            ->from('/settings')
            ->post('/settings/shops', ['name' => 'Sneaky Shop', 'location' => 'Nowhere']);

        $response->assertRedirect('/settings');
        $response->assertSessionHasErrors(['action' => 'Only the owner can add shops.']);
        $this->assertSame(1, DB::table('shops')->count()); // fixture only
    }

    public function test_the_pos_type_comes_from_the_sidebar_link(): void
    {
        // Default (plain Record link): sale.
        $sale = $this->actingAs($this->owner)->get('/transactions/new');
        $sale->assertOk();
        $sale->assertSee('Record sale', false);
        $sale->assertSee("type: 'sale'", false);

        // Sidebar sub-links drive swap/repair; anything else falls back.
        $swap = $this->actingAs($this->owner)->get('/transactions/new?type=swap');
        $swap->assertOk();
        $swap->assertSee('Record swap', false);
        $swap->assertSee("type: 'swap'", false);

        $bogus = $this->actingAs($this->owner)->get('/transactions/new?type=refund');
        $bogus->assertOk();
        $bogus->assertSee("type: 'sale'", false);

        // The sidebar carries all three links on every page.
        $home = $this->actingAs($this->owner)->get('/');
        $home->assertOk();
        foreach (['type=sale', 'type=swap', 'type=repair'] as $link) {
            $home->assertSee($link, false);
        }

        // Owner tools are linked in the desktop sidebar for owners only.
        $home->assertSee('/settings', false);
        $home->assertSee('/logs', false);
        $staffHome = $this->actingAs($this->attendant)->get('/');
        $staffHome->assertOk();
        $staffHome->assertDontSee('/settings', false);
        $staffHome->assertDontSee('/logs', false);
    }
}
