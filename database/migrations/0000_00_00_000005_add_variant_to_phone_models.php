<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product variants: SIM type + color. A variant is a full model row, so
     * every stock movement (sales, adjustments, counts, triggers) works
     * untouched — only identity grows from (shop, name, condition) to
     * (shop, name, condition, sim, color). Blank means unspecified, which is
     * also what every pre-existing row backfills to.
     *
     * Create-model requests carry the same two fields so an attendant filing
     * for approval describes the exact variant.
     */
    public function up(): void
    {
        Schema::table('phone_models', function (Blueprint $table): void {
            $table->string('sim_type', 32)->default('')->after('condition');
            $table->string('color', 64)->default('')->after('sim_type');
            $table->dropUnique('pm_shop_model_condition_unique');
            $table->unique(
                ['shop_id', 'model_name', 'condition', 'sim_type', 'color'],
                'pm_shop_variant_unique'
            );
        });

        Schema::table('stock_requests', function (Blueprint $table): void {
            $table->string('sim_type', 32)->default('')->after('condition');
            $table->string('color', 64)->default('')->after('sim_type');
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table): void {
            $table->dropColumn(['sim_type', 'color']);
        });

        Schema::table('phone_models', function (Blueprint $table): void {
            $table->dropUnique('pm_shop_variant_unique');
            $table->dropColumn(['sim_type', 'color']);
            $table->unique(
                ['shop_id', 'model_name', 'condition'],
                'pm_shop_model_condition_unique'
            );
        });
    }
};
