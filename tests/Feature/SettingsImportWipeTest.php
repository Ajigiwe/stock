<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Settings additions beyond the Next.js parity scope: the CSV product import
 * (controller parses the file into the bulk-add row shape, so
 * StockService::bulkCreate is the single validation path for both entry
 * points) and the data wipe (BackupService::wipe — same transaction, same
 * @mrjeff_no_stock_effects guard, same FK-safe order as restore(), but
 * nothing is re-inserted and login accounts survive).
 */
class SettingsImportWipeTest extends TestCase
{
    use RefreshDatabase;

    private string $shopId;
    private string $modelId;
    private User $owner;
    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = (string) Str::uuid();
        $this->modelId = (string) Str::uuid();

        DB::table('shops')->insert(['id' => $this->shopId, 'name' => 'Osu Shop']);
        DB::table('phone_models')->insert([
            'id' => $this->modelId,
            'shop_id' => $this->shopId,
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
                'id' => $ownerId = (string) Str::uuid(),
                'name' => 'Owner',
                'role' => 'owner',
                'shop_id' => null,
                'active' => 1,
            ],
            [
                'id' => $attendantId = (string) Str::uuid(),
                'name' => 'Kofi',
                'role' => 'attendant',
                'shop_id' => $this->shopId,
                'active' => 1,
            ],
        ]);
        $this->owner = User::findOrFail($ownerId);
        $this->attendant = User::findOrFail($attendantId);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $content);
    }

    public function test_owner_imports_a_csv_and_skips_existing_or_invalid_rows(): void
    {
        $csv = "model_name,condition,cost_price,sale_price,opening_stock,low_stock_threshold\n"
            ."iPhone 13 128GB,used,1500,2000,5,2\n"
            ."Tecno Spark 20,new,80,100,4,5\n"       // already in this shop
            ."Nokia 3310,used,not-a-price,50,3,2\n";  // invalid cost price

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopId,
                'csv' => $this->csv($csv),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', '1 devices imported.');

        $this->assertSame(2, DB::table('phone_models')->count());
        $imported = DB::table('phone_models')->where('model_name', 'iPhone 13 128GB')->first();
        $this->assertNotNull($imported);
        $this->assertSame('used', $imported->condition);
        $this->assertSame(5, (int) $imported->available); // insert trigger derives opening stock

        // respond() flashes one warning per skipped row.
        $warnings = session('warnings');
        $this->assertIsArray($warnings);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('Already exists in this shop', $warnings[0]);
        $this->assertStringContainsString('Nokia 3310', $warnings[1]);
    }

    public function test_csv_import_reports_missing_file_bad_header_and_empty_file(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', ['shopId' => $this->shopId])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'CSV file is missing or too large.']);

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopId,
                'csv' => $this->csv("product,price\nWidget,5\n"),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'CSV must include a model_name column.']);

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopId,
                'csv' => $this->csv("model_name,condition\n"),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'No rows to import.']);
    }

    public function test_csv_import_is_owner_only_and_needs_a_shop(): void
    {
        // Same guard as the bulk-add form: StockService::bulkCreate.
        $this->actingAs($this->attendant)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopId,
                'csv' => $this->csv("model_name\niPhone X\n"),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Only the owner can bulk add devices.']);
        $this->assertSame(1, DB::table('phone_models')->count());

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', [
                'csv' => $this->csv("model_name\niPhone X\n"),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Select a shop.']);
        $this->assertSame(1, DB::table('phone_models')->count());
    }

    public function test_template_download_is_owner_only(): void
    {
        $csv = $this->actingAs($this->owner)->get('/settings/models/import/template');
        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $csv->assertHeader(
            'Content-Disposition',
            'attachment; filename="mr-jeff-stock-import-template.csv"'
        );
        $this->assertStringStartsWith(
            "model_name,condition,sim_type,color,category,cost_price,sale_price,opening_stock,low_stock_threshold\n",
            $csv->getContent()
        );

        $this->actingAs($this->attendant)
            ->get('/settings/models/import/template')
            ->assertForbidden();
    }

    public function test_owner_wipes_all_business_data_and_keeps_login_accounts(): void
    {
        // Real history so child tables (items, events, logs) are covered too.
        $this->actingAs($this->owner)->postJson('/transactions', [
            'shopId' => $this->shopId,
            'type' => 'sale',
            'paymentMethod' => 'cash',
            'amount' => '100',
            'customerName' => 'Ama',
            'customerPhone' => '0240000001',
            'outItems' => [['modelId' => $this->modelId, 'qty' => '1']],
            'date' => '2026-01-15',
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertGreaterThan(0, DB::table('transaction_items')->count());

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/wipe', ['confirm' => 'WIPE'])
            ->assertRedirect('/settings');

        $this->assertMatchesRegularExpression(
            '/^All data wiped \(\d+ rows removed\)\.$/',
            (string) session('success')
        );

        foreach ([
            'shops', 'phone_models', 'transactions', 'transaction_items',
            'stock_adjustments', 'stock_logs', 'transaction_events', 'login_logs',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        // Login accounts survive — and the attendant's shop link is detached
        // (users_shop_fk is ON DELETE SET NULL).
        $this->assertSame(2, DB::table('users')->count());
        $this->assertNull(DB::table('users')->where('id', $this->attendant->id)->value('shop_id'));
        $this->assertNull(DB::table('users')->where('id', $this->owner->id)->value('shop_id'));

        // Still signed in, and the app renders its empty state instead of 500.
        $this->get('/')->assertOk();
        $this->get('/settings')->assertOk();
    }

    public function test_wipe_requires_the_typed_phrase_and_the_owner_role(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/wipe', ['confirm' => 'wipe it'])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Type WIPE to confirm.']);
        $this->assertSame(1, DB::table('shops')->count());

        // Wrong confirm is checked after the role, so an attendant always
        // gets the role error — with or without the phrase.
        $this->actingAs($this->attendant)
            ->from('/settings')
            ->post('/settings/wipe', ['confirm' => 'WIPE'])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Only the owner can wipe data.']);
        $this->assertSame(1, DB::table('shops')->count());
        $this->assertSame(1, DB::table('phone_models')->count());
    }
}
