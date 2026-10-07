<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product variants (SIM type + color): same name and condition may coexist
 * with different variants, but the full key stays unique — through the
 * single-add form, the edit modal, the settings bulk form, the CSV import
 * (with the `colour` alias), and the attendant request approval path.
 */
class ModelVariantTest extends TestCase
{
    use RefreshDatabase;

    private string $shopId;
    private User $owner;
    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = (string) Str::uuid();

        DB::table('shops')->insert(['id' => $this->shopId, 'name' => 'Osu Shop']);
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

    public function test_owner_creates_a_variant_and_invalid_variants_are_refused(): void
    {
        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'iPhone 18 Pro Max 256GB',
                'condition' => 'new',
                'simType' => 'esim',
                'color' => 'Desert Titanium',
                'salePrice' => '9000',
                'openingStock' => '3',
            ])
            ->assertRedirect('/shops/'.$this->shopId);

        $this->assertDatabaseHas('phone_models', [
            'shop_id' => $this->shopId,
            'model_name' => 'iPhone 18 Pro Max 256GB',
            'sim_type' => 'esim',
            'color' => 'Desert Titanium',
        ]);

        // Same name + condition with another SIM is a different product.
        $this->actingAs($this->owner)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'iPhone 18 Pro Max 256GB',
                'condition' => 'new',
                'simType' => 'physical_sim',
                'color' => 'Desert Titanium',
            ]);
        $this->assertSame(2, DB::table('phone_models')->count());

        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'iPhone 18 Pro Max 256GB',
                'condition' => 'new',
                'simType' => 'satellite',
            ])
            ->assertRedirect('/shops/'.$this->shopId)
            ->assertSessionHasErrors(['action' => 'Choose a valid SIM type.']);
    }

    public function test_edit_modal_updates_the_variant(): void
    {
        $modelId = (string) Str::uuid();
        DB::table('phone_models')->insert([
            'id' => $modelId,
            'shop_id' => $this->shopId,
            'model_name' => 'iPhone 17 128GB',
            'condition' => 'used',
            'opening_stock' => 2,
            'available' => 0,
        ]);

        $this->actingAs($this->owner)
            ->post('/shops/'.$this->shopId.'/models/'.$modelId, [
                'modelName' => 'iPhone 17 128GB',
                'condition' => 'used',
                'simType' => 'physical_sim_locked',
                'color' => 'Black',
                'lowStockThreshold' => '2',
            ])
            ->assertSessionHas('success', 'Product details saved.');

        $this->assertDatabaseHas('phone_models', [
            'id' => $modelId,
            'sim_type' => 'physical_sim_locked',
            'color' => 'Black',
        ]);

        // The shop page exposes SIM + color in the add/edit forms and rows.
        $shop = $this->actingAs($this->owner)->get('/shops/'.$this->shopId);
        $shop->assertOk();
        $shop->assertSee('name="simType"', false);
        $shop->assertSee('Physical SIM Locked', false);
        $shop->assertSee('Black', false);
    }

    public function test_bulk_form_and_csv_import_carry_variants(): void
    {
        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/bulk', [
                'shopId' => $this->shopId,
                'rows' => [
                    ['model_name' => 'iPhone 18 256GB', 'condition' => 'new', 'sim_type' => 'esim', 'color' => 'Blue'],
                    ['model_name' => 'iPhone 18 256GB', 'condition' => 'new', 'sim_type' => 'esim', 'color' => 'Blue'],
                    ['model_name' => 'iPhone 18 256GB', 'condition' => 'new', 'sim_type' => 'physical_sim', 'color' => 'Blue'],
                ],
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', '2 devices added.');

        $this->assertSame(2, DB::table('phone_models')->count());

        $csv = "model_name,condition,sim_type,colour,opening_stock\n"
            ."iPhone 17 Pro,new,esim_unlocked,White,4\n"
            ."Broken Row,new,fax-modem,,1\n";

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopId,
                'csv' => UploadedFile::fake()->createWithContent('products.csv', $csv),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', '1 devices imported.');

        $this->assertDatabaseHas('phone_models', [
            'model_name' => 'iPhone 17 Pro',
            'sim_type' => 'esim_unlocked',
            'color' => 'White',
        ]);
        $warnings = session('warnings');
        $this->assertIsArray($warnings);
        $this->assertStringContainsString('Invalid SIM type', $warnings[0]);
    }

    public function test_attendant_request_approval_keeps_the_variant(): void
    {
        $this->actingAs($this->attendant)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'iPhone 18 Pro Max 256GB',
                'condition' => 'new',
                'simType' => 'esim',
                'color' => 'Natural',
                'openingStock' => '2',
            ])
            ->assertSessionHas('success', 'Request sent — awaiting owner approval.');

        $requestId = DB::table('stock_requests')->value('id');
        $this->assertNotNull($requestId);

        $this->actingAs($this->owner)->post('/requests/'.$requestId.'/approve');

        $this->assertDatabaseHas('phone_models', [
            'model_name' => 'iPhone 18 Pro Max 256GB',
            'sim_type' => 'esim',
            'color' => 'Natural',
            'available' => 2,
        ]);
    }

    public function test_pos_and_devices_show_the_variant(): void
    {
        DB::table('phone_models')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'model_name' => 'iPhone 18 Pro Max 256GB',
            'condition' => 'new',
            'sim_type' => 'esim',
            'color' => 'Desert Titanium',
            'sale_price' => 9000,
            'opening_stock' => 3,
            'available' => 0,
        ]);

        $pos = $this->actingAs($this->owner)->get('/transactions/new');
        $pos->assertOk();
        $pos->assertSee('eSIM', false);
        $pos->assertSee('Desert Titanium', false);

        $devices = $this->actingAs($this->owner)->get('/devices');
        $devices->assertOk();
        $devices->assertSee('eSIM', false);
        $devices->assertSee('Desert Titanium', false);
    }

    public function test_gadgets_live_beside_phones_as_their_own_category(): void
    {
        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'AirPods Pro 2',
                'condition' => 'new',
                'category' => 'audio',
                'salePrice' => '3500',
                'openingStock' => '6',
            ])
            ->assertRedirect('/shops/'.$this->shopId);

        $this->assertDatabaseHas('phone_models', [
            'model_name' => 'AirPods Pro 2',
            'category' => 'audio',
            'sim_type' => '',
        ]);

        // Same name in another category is a different product.
        $this->actingAs($this->owner)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'AirPods Pro 2',
                'condition' => 'new',
                'category' => 'accessory',
            ]);
        $this->assertSame(2, DB::table('phone_models')->where('model_name', 'AirPods Pro 2')->count());

        // Unknown categories are refused, not silently filed as phones.
        $this->actingAs($this->owner)
            ->from('/shops/'.$this->shopId)
            ->post('/shops/'.$this->shopId.'/models', [
                'modelName' => 'Mystery Box',
                'condition' => 'new',
                'category' => 'spaceship',
            ])
            ->assertRedirect('/shops/'.$this->shopId)
            ->assertSessionHasErrors(['action' => 'Choose a valid category.']);
        $this->assertDatabaseMissing('phone_models', ['model_name' => 'Mystery Box']);

        // Bulk + CSV carry the column too (colour alias included).
        $csv = "model_name,condition,category,colour,opening_stock\n"
            ."MacBook Air M3,new,laptop,Silver,2\n";

        $this->actingAs($this->owner)
            ->from('/settings')
            ->post('/settings/models/import', [
                'shopId' => $this->shopId,
                'csv' => UploadedFile::fake()->createWithContent('gadgets.csv', $csv),
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', '1 devices imported.');

        $this->assertDatabaseHas('phone_models', [
            'model_name' => 'MacBook Air M3',
            'category' => 'laptop',
            'color' => 'Silver',
        ]);

        // POS catalog and devices both surface the category.
        $pos = $this->actingAs($this->owner)->get('/transactions/new');
        $pos->assertOk();
        $pos->assertSee('Audio', false);

        $devices = $this->actingAs($this->owner)->get('/devices');
        $devices->assertOk();
        $devices->assertSee('Audio', false);
        $devices->assertSee('Laptop', false);
    }
}
