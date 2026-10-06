<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Settings > Backup round-trip: the download must be the raw backup object
 * the original src/app/(app)/settings/backup/route.ts emitted (no {ok, backup}
 * envelope — restore() reads that shape back), and feeding the file straight
 * in through either entry point (mrjeff:import-backup or the UI upload)
 * must restore every captured table, including the derived `available`
 * invariant, plus the original's verbatim error strings.
 */
class BackupRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private string $shopId;
    private string $modelId;
    private User $owner;

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
                'id' => (string) Str::uuid(),
                'name' => 'Kofi',
                'role' => 'attendant',
                'shop_id' => $this->shopId,
                'active' => 1,
            ],
        ]);
        $this->owner = User::findOrFail($ownerId);
    }

    /** Real history: one sale (10 → 9) then an owner adjustment (+2 → 11). */
    private function buildHistory(): int
    {
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

        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models/'.$this->modelId.'/adjust', [
                'delta' => '2',
                'reason' => 'Stock count fix',
            ])
            ->assertSessionHas('success', 'Stock updated.');

        $available = (int) DB::table('phone_models')->where('id', $this->modelId)->value('available');
        $this->assertSame(11, $available);

        return $available;
    }

    /** @return array<string, mixed> decoded file exactly as the app serves it */
    private function downloadBackup(): array
    {
        $download = $this->actingAs($this->owner)->get('/settings/backup/download');
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/json');
        $download->assertHeader(
            'Content-Disposition',
            'attachment; filename="mr-jeff-stock-backup-'.gmdate('Y-m-d').'.json"'
        );

        $file = json_decode($download->getContent(), true);
        $this->assertIsArray($file);

        // Raw backup object like the original route.ts — not an {ok, backup}
        // envelope, and never credential columns.
        $this->assertArrayNotHasKey('ok', $file);
        $this->assertSame('mr-jeff-stock', $file['app'] ?? null);
        $this->assertSame(1, $file['version'] ?? null);
        foreach (['users', 'shops', 'phone_models', 'transactions', 'transaction_items', 'stock_adjustments'] as $table) {
            $this->assertArrayHasKey($table, $file, $table);
        }
        $this->assertArrayNotHasKey('password', $file['users'][0] ?? []);
        $this->assertArrayNotHasKey('remember_token', $file['users'][0] ?? []);

        return $file;
    }

    /** Every table (and the derived available) comes back exactly as captured. */
    private function assertRoundTripRestored(int $availableBefore): void
    {
        $this->assertSame('Osu Shop', DB::table('shops')->where('id', $this->shopId)->value('name'));
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(1, DB::table('phone_models')->count());
        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertSame(1, DB::table('transaction_items')->count());
        $this->assertSame(1, DB::table('stock_adjustments')->count());
        $this->assertSame('Ama', DB::table('transactions')->value('customer_name'));
        $this->assertSame(
            $availableBefore,
            (int) DB::table('phone_models')->where('id', $this->modelId)->value('available')
        );
    }

    public function test_downloaded_file_imports_through_the_artisan_command(): void
    {
        $availableBefore = $this->buildHistory();
        $json = json_encode($this->downloadBackup(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        // Damage the data, then feed the file straight back in.
        DB::table('shops')->where('id', $this->shopId)->update(['name' => 'Renamed Shop']);
        DB::table('phone_models')->where('id', $this->modelId)->update(['available' => 3]);

        $path = tempnam(sys_get_temp_dir(), 'mrjeff-backup-');
        $this->assertIsString($path);
        file_put_contents($path, $json);

        try {
            $this->artisan('mrjeff:import-backup', ['file' => $path, '--force' => true])
                ->assertExitCode(0);
        } finally {
            @unlink($path);
        }

        $this->assertRoundTripRestored($availableBefore);
    }

    public function test_downloaded_file_restores_through_the_settings_upload(): void
    {
        $availableBefore = $this->buildHistory();
        $json = json_encode($this->downloadBackup(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        DB::table('shops')->where('id', $this->shopId)->update(['name' => 'Renamed Shop']);
        DB::table('phone_models')->where('id', $this->modelId)->update(['available' => 3]);

        // backup-restore.tsx: "Backup restored (N transactions loaded)."
        $this->actingAs($this->owner)
            ->post('/settings/backup/restore', [
                'backup' => UploadedFile::fake()->createWithContent('backup.json', $json),
            ])
            ->assertSessionHas('success', 'Backup restored (1 transactions loaded).');

        $this->assertRoundTripRestored($availableBefore);
    }

    public function test_restore_rejects_a_missing_file_and_non_json_with_the_original_messages(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/backup/restore')
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'Backup file is missing or too large.']);

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/backup/restore', [
                'backup' => UploadedFile::fake()->createWithContent('backup.json', '{not json'),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'File is not valid JSON.']);
    }
}
