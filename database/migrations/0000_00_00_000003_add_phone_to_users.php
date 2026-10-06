<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff sign in with a phone number instead of an email address, so the
     * login identifier lives on the user row. Nullable with a UNIQUE
     * constraint — MySQL permits any number of NULLs, so "no number" never
     * collides, exactly like the nullable unique email beside it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
            $table->unique('phone', 'users_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_phone_unique');
            $table->dropColumn('phone');
        });
    }
};
