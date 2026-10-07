<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A superadmin tier above the owner: sees every shop, holds every
     * capability, and manages owner accounts themselves. ENUM values only
     * ever grow — existing rows keep their meaning.
     */
    public function up(): void
    {
        DB::unprepared("ALTER TABLE users MODIFY COLUMN role ENUM('owner','attendant','superadmin') NOT NULL DEFAULT 'attendant'");
    }

    public function down(): void
    {
        DB::unprepared("UPDATE users SET role = 'owner' WHERE role = 'superadmin'");
        DB::unprepared("ALTER TABLE users MODIFY COLUMN role ENUM('owner','attendant') NOT NULL DEFAULT 'attendant'");
    }
};
