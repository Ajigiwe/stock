<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gadget categories: the shop sells more than phones, and every item
     * rides the same stock rails (rows → ticket → sale). The category joins
     * the identity key so identically-named items in different categories
     * coexist; everything recorded before categories existed backfills to
     * 'phone'.
     *
     * Create-model requests carry the category too, like the variant fields.
     */
    public function up(): void
    {
        Schema::table('phone_models', function (Blueprint $table): void {
            $table->string('category', 32)->default('phone')->after('color');
            $table->dropUnique('pm_shop_variant_unique');
            $table->unique(
                ['shop_id', 'model_name', 'condition', 'sim_type', 'color', 'category'],
                'pm_shop_item_unique'
            );
        });

        Schema::table('stock_requests', function (Blueprint $table): void {
            $table->string('category', 32)->default('phone')->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table): void {
            $table->dropColumn('category');
        });

        Schema::table('phone_models', function (Blueprint $table): void {
            $table->dropUnique('pm_shop_item_unique');
            $table->dropColumn('category');
            $table->unique(
                ['shop_id', 'model_name', 'condition', 'sim_type', 'color'],
                'pm_shop_variant_unique'
            );
        });
    }
};
