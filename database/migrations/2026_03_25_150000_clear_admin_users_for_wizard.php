<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear all existing admin users to enable the Setup Wizard.
 * The wizard creates a new super_admin on first access.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clear related tables first (foreign key constraints)
        DB::table('admin_notifications')->truncate();

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->whereNotNull('admin_id')->update(['admin_id' => null]);
        }

        if (Schema::hasTable('support_tickets')) {
            DB::table('support_tickets')->whereNotNull('assigned_to')->update(['assigned_to' => null]);
        }

        // Now safe to clear admin users
        DB::table('admin_users')->delete();
    }

    public function down(): void
    {
        // Cannot restore deleted admins
    }
};
