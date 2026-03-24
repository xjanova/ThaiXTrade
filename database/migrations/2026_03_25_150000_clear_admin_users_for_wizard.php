<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear all existing admin users to enable the Setup Wizard.
 * The wizard creates a new super_admin on first access.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_users')->truncate();
    }

    public function down(): void
    {
        // Cannot restore deleted admins
    }
};
