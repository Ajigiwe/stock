<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner-granted capabilities for attendants: approving stock requests,
     * adjusting stock directly (no approval round-trip), and running
     * reconciliation (lock closes, approve/apply counts). All three stay
     * scoped to the holder's own shop — the services re-check that — and
     * default to off.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('perm_approve_requests')->default(false)->after('phone');
            $table->boolean('perm_adjust_stock')->default(false)->after('perm_approve_requests');
            $table->boolean('perm_reconcile')->default(false)->after('perm_adjust_stock');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['perm_approve_requests', 'perm_adjust_stock', 'perm_reconcile']);
        });
    }
};
