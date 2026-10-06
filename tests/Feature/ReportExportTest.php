<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins GET /reports/export to the original route's bytes (src/app/reports/
 * export/route.ts): UTF-8 BOM, the ten fixed columns, the formula-injection
 * `esc()` (prefix `'` on =+-@/TAB/CR, quote + double embedded quotes), "\n"
 * joins with no trailing newline, filename report-{from}-{to}.csv — plus the
 * RLS scoping: attendants export only their own shop, shopless 403.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private string $shopA;
    private string $shopB;
    private string $ownerId;
    private string $kofiId;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = (string) Str::uuid();
        $this->shopB = (string) Str::uuid();
        $this->ownerId = (string) Str::uuid();
        $this->kofiId = (string) Str::uuid();

        DB::table('shops')->insert([
            ['id' => $this->shopA, 'name' => 'Osu Shop'],
            ['id' => $this->shopB, 'name' => 'Kumasi Shop'],
        ]);
        DB::table('phone_models')->insert([
            [
                'id' => $id = (string) Str::uuid(),
                'shop_id' => $this->shopA,
                'model_name' => 'Tecno Spark 20',
                'condition' => 'new',
                'cost_price' => 80,
                'sale_price' => 100,
                'opening_stock' => 10,
                'bought_in' => 0,
                'available' => 0,
            ],
            [
                'id' => $modelB = (string) Str::uuid(),
                'shop_id' => $this->shopB,
                'model_name' => 'Itel A23',
                'condition' => 'used',
                'cost_price' => 30,
                'sale_price' => 50,
                'opening_stock' => 5,
                'bought_in' => 0,
                'available' => 0,
            ],
        ]);
        $this->modelA = $id;
        $this->modelB = $modelB;

        DB::table('users')->insert([
            [
                'id' => $this->ownerId,
                'name' => 'Owner',
                'role' => 'owner',
                'shop_id' => null,
                'active' => 1,
            ],
            [
                'id' => $this->kofiId,
                'name' => 'Kofi',
                'role' => 'attendant',
                'shop_id' => $this->shopA,
                'active' => 1,
            ],
        ]);

        $this->owner = User::findOrFail($this->ownerId);
    }

    private string $modelA;
    private string $modelB;

    /** @return array<string, mixed> */
    private function sale(string $shopId, string $modelId, string $customer, string $phone, string $amount, string $date): array
    {
        return [
            'shopId' => $shopId,
            'type' => 'sale',
            'paymentMethod' => 'cash',
            'amount' => $amount,
            'customerName' => $customer,
            'customerPhone' => $phone,
            'outItems' => [
                ['modelId' => $modelId, 'qty' => '1'],
            ],
            'date' => $date,
            'idempotencyKey' => (string) Str::uuid(),
        ];
    }

    public function test_owner_export_is_byte_faithful_to_the_original_route(): void
    {
        // A customer name crafted as a spreadsheet formula, with quotes and a
        // comma — the exact input the original esc() existed for.
        $payload = $this->sale(
            $this->shopA,
            $this->modelA,
            '=HYPERLINK("http://evil","click")',
            '0240000000',
            '100',
            '2026-01-15'
        );
        $this->actingAs($this->owner)->postJson('/transactions', $payload)->assertOk();

        $response = $this->actingAs($this->owner)
            ->get('/reports/export?from=2026-01-15&to=2026-01-15');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="report-2026-01-15-2026-01-15.csv"');

        $body = $response->getContent();

        // BOM + header line, LF only, and no trailing newline (join("\n")).
        $this->assertStringStartsWith(
            "\u{FEFF}date,shop,staff,type,customer,customer_phone,payment_method,amount_ghs,items_out,items_in\n",
            $body
        );
        $this->assertStringEndsNotWith("\n", $body);

        $lines = explode("\n", substr($body, 3)); // strip the BOM
        $this->assertCount(2, $lines, 'header + exactly one row');
        $this->assertStringNotContainsString("\r", $body);

        $this->assertSame(
            // txDate() anchors business days at midday UTC (InputTest pins this).
            '2026-01-15T12:00:00+00:00,Osu Shop,Owner,sale,'
            .'"\'=HYPERLINK(""http://evil"",""click"")",'
            .'0240000000,cash,100,1 x Tecno Spark 20 (new),',
            $lines[1]
        );
    }

    public function test_an_attendant_exports_only_their_own_shops_rows(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/transactions', $this->sale($this->shopA, $this->modelA, 'Ama', '0240000001', '100', '2026-01-15'))
            ->assertOk();
        $this->actingAs($this->owner)
            ->postJson('/transactions', $this->sale($this->shopB, $this->modelB, 'Bob', '0700000002', '50', '2026-01-16'))
            ->assertOk();

        $kofi = User::findOrFail($this->kofiId);
        $response = $this->actingAs($kofi)->get('/reports/export');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('Osu Shop', $body);
        $this->assertStringContainsString('Ama', $body);
        $this->assertStringNotContainsString('Kumasi Shop', $body);
        $this->assertStringNotContainsString('Bob', $body);
    }

    public function test_an_attendant_without_a_shop_gets_403_like_the_original_route(): void
    {
        $wandererId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $wandererId,
            'name' => 'Wanderer',
            'role' => 'attendant',
            'shop_id' => null,
            'active' => 1,
        ]);

        $this->actingAs(User::findOrFail($wandererId))
            ->get('/reports/export')
            ->assertForbidden();
    }
}
