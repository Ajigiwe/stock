<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The superadmin tier: a role above the owner with a command-center
 * dashboard, owner management, and every owner capability. Owners and
 * attendants are locked out of the dashboard and cannot mint owners.
 */
class SuperadminTest extends TestCase
{
    use RefreshDatabase;

    private string $shopId;
    private string $modelId;
    private User $superadmin;
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
                'id' => $attendantId = (string) Str::uuid(),
                'name' => 'Kofi',
                'role' => 'attendant',
                'shop_id' => $this->shopId,
                'active' => 1,
            ],
        ]);
        $this->superadmin = User::findOrFail($superId);
        $this->owner = User::findOrFail($ownerId);
        $this->attendant = User::findOrFail($attendantId);
    }

    public function test_dashboard_is_superadmin_only(): void
    {
        $page = $this->actingAs($this->superadmin)->get('/superadmin');
        $page->assertOk();
        foreach (['Money overview', 'Approvals inbox', 'Stock alerts', 'Staff oversight', 'System &amp; data'] as $section) {
            $page->assertSee($section, false);
        }

        $this->actingAs($this->owner)->get('/superadmin')->assertForbidden();
        $this->actingAs($this->attendant)->get('/superadmin')->assertForbidden();
    }

    public function test_sidebar_links_the_dashboard_for_superadmins_only(): void
    {
        $this->actingAs($this->superadmin)->get('/')->assertSee('/superadmin', false);
        $this->actingAs($this->owner)->get('/')->assertDontSee('/superadmin', false);
    }

    public function test_superadmin_holds_every_owner_capability(): void
    {
        // Direct stock adjust (no request filed).
        $this->actingAs($this->superadmin)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models/'.$this->modelId.'/adjust', [
                'delta' => '2',
                'reason' => 'Recount',
            ])
            ->assertSessionHas('success', 'Stock updated.');
        $this->assertSame(12, (int) DB::table('phone_models')->where('id', $this->modelId)->value('available'));

        // Backup download + wipe + settings page.
        $this->actingAs($this->superadmin)->get('/settings/backup/download')->assertOk();
        $this->actingAs($this->superadmin)->get('/settings')->assertOk();
        $this->actingAs($this->superadmin)->get('/devices')->assertOk();
        $this->actingAs($this->superadmin)->get('/logs')->assertOk();
    }

    public function test_only_superadmins_can_add_owners(): void
    {
        $this->actingAs($this->superadmin)
            ->from('/superadmin')
            ->post('/superadmin/owners', [
                'name' => 'Second Owner',
                'email' => 'second@example.com',
                'password' => 'password123',
            ])
            ->assertRedirect('/superadmin')
            ->assertSessionHas('success', 'Owner account for Second Owner created.');

        $this->assertDatabaseHas('users', ['email' => 'second@example.com', 'role' => 'owner']);

        $this->actingAs($this->owner)
            ->from('/superadmin')
            ->post('/superadmin/owners', [
                'name' => 'Sneaky Owner',
                'email' => 'sneaky@example.com',
                'password' => 'password123',
            ])
            ->assertRedirect('/superadmin')
            ->assertSessionHasErrors(['action' => 'Only a superadmin can add owners.']);
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_superadmin_can_deactivate_an_owner_but_not_self(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/superadmin/users/'.$this->owner->id.'/deactivate')
            ->assertSessionHas('success', 'Owner deactivated.');
        $this->assertSame(0, (int) DB::table('users')->where('id', $this->owner->id)->value('active'));

        // An owner still cannot touch another owner.
        $thirdId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $thirdId, 'name' => 'Third', 'role' => 'owner',
            'shop_id' => null, 'active' => 1,
        ]);
        $secondId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $secondId, 'name' => 'Second', 'role' => 'owner',
            'shop_id' => null, 'active' => 1,
        ]);
        $this->actingAs(User::findOrFail($thirdId))
            ->post('/settings/staff/'.$secondId.'/deactivate')
            ->assertSessionHasErrors(['action' => 'Invalid staff account.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', $secondId)->value('active'));
    }

    public function test_restore_never_demotes_the_superadmin(): void
    {
        $download = $this->actingAs($this->superadmin)->get('/settings/backup/download');
        $download->assertOk();

        $file = json_decode($download->getContent(), true);
        $this->assertIsArray($file);

        $json = json_encode($file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $this->actingAs($this->superadmin)
            ->post('/settings/backup/restore', [
                'backup' => \Illuminate\Http\UploadedFile::fake()->createWithContent('backup.json', $json),
            ])
            ->assertSessionHas('success', 'Backup restored (0 transactions loaded).');

        $this->assertSame('superadmin', DB::table('users')->where('id', $this->superadmin->id)->value('role'));
        $this->actingAs($this->superadmin)->get('/superadmin')->assertOk();
    }

    public function test_make_superadmin_command_mints_an_account(): void
    {
        $this->artisan('mrjeff:make-superadmin', [
            '--name' => 'Root',
            '--email' => 'root@example.com',
            '--password' => 'password123',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'root@example.com', 'role' => 'superadmin']);

        $this->artisan('mrjeff:make-superadmin', [
            '--name' => 'Copy',
            '--email' => 'root@example.com',
            '--password' => 'password123',
            '--no-interaction' => true,
        ])->assertExitCode(1);
    }
}
