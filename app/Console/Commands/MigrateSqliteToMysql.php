<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrate important data from old SQLite database to new MySQL.
 *
 * Transfers:
 *   - site_settings (API keys, fee config, social auth, turnstile, etc.)
 *   - admin_users (if any exist in SQLite)
 *   - chains (custom configs)
 *   - tokens, trading_pairs, swap_configs, fee_configs
 *   - banners, sale_phases
 *
 * Does NOT transfer: users, transactions, wallet_connections (fresh start)
 *
 * Usage: php artisan migrate:sqlite-to-mysql
 */
class MigrateSqliteToMysql extends Command
{
    protected $signature = 'migrate:sqlite-to-mysql
                            {--sqlite-path= : Path to SQLite database file}
                            {--dry-run : Show what would be transferred without writing}';

    protected $description = 'Transfer settings and config data from old SQLite to current MySQL database';

    // Tables to migrate (order matters for foreign keys)
    private array $tables = [
        'site_settings',
        'admin_users',
        'fee_configs',
        'swap_configs',
        'banners',
        'sale_phases',
    ];

    public function handle(): int
    {
        $sqlitePath = $this->option('sqlite-path')
            ?: database_path('database.sqlite');

        if (! file_exists($sqlitePath)) {
            $this->error("SQLite file not found: {$sqlitePath}");
            $this->info('Use --sqlite-path=/path/to/database.sqlite');

            return 1;
        }

        $this->info("📦 Source: {$sqlitePath}");
        $this->info('🎯 Target: '.config('database.default').' ('.config('database.connections.'.config('database.default').'.database').')');
        $this->newLine();

        // Configure SQLite connection
        config(['database.connections.sqlite_old' => [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $totalRows = 0;

        foreach ($this->tables as $table) {
            $count = $this->migrateTable($table);
            $totalRows += $count;
        }

        $this->newLine();
        if ($this->option('dry-run')) {
            $this->warn("🔍 Dry run complete. {$totalRows} rows would be transferred.");
        } else {
            $this->info("✅ Migration complete! {$totalRows} rows transferred.");
        }

        // Clear caches so new settings take effect
        if (! $this->option('dry-run')) {
            $this->call('cache:clear');
            $this->call('config:clear');
            $this->info('🧹 Caches cleared.');
        }

        return 0;
    }

    private function migrateTable(string $table): int
    {
        // Check if table exists in both databases
        try {
            $rows = DB::connection('sqlite_old')->table($table)->get();
        } catch (\Exception $e) {
            $this->warn("  ⏭  {$table}: not found in SQLite, skipping");

            return 0;
        }

        if ($rows->isEmpty()) {
            $this->line("  ⏭  {$table}: empty in SQLite, skipping");

            return 0;
        }

        if (! Schema::hasTable($table)) {
            $this->warn("  ⏭  {$table}: not found in MySQL, skipping");

            return 0;
        }

        $count = $rows->count();

        if ($this->option('dry-run')) {
            $this->info("  📋 {$table}: {$count} rows (would transfer)");
            foreach ($rows->take(3) as $row) {
                $preview = collect((array) $row)->only(['id', 'name', 'email', 'group', 'key', 'value'])->toJson();
                $this->line("     → {$preview}");
            }

            return $count;
        }

        $this->info("  📥 {$table}: transferring {$count} rows...");

        $transferred = 0;

        foreach ($rows as $row) {
            $data = (array) $row;

            // Remove auto-increment ID for tables that generate their own
            // Keep ID for site_settings (upsert by group+key)
            if ($table === 'site_settings') {
                unset($data['id']);
                try {
                    DB::table($table)->updateOrInsert(
                        ['group' => $data['group'], 'key' => $data['key']],
                        $data,
                    );
                    $transferred++;
                } catch (\Exception $e) {
                    $this->warn("     ⚠ Skipped {$data['group']}.{$data['key']}: {$e->getMessage()}");
                }
            } elseif ($table === 'admin_users') {
                unset($data['id']);
                try {
                    DB::table($table)->updateOrInsert(
                        ['email' => $data['email']],
                        $data,
                    );
                    $transferred++;
                } catch (\Exception $e) {
                    $this->warn("     ⚠ Skipped admin {$data['email']}: {$e->getMessage()}");
                }
            } else {
                try {
                    // Try insert, skip on duplicate
                    DB::table($table)->insertOrIgnore($data);
                    $transferred++;
                } catch (\Exception $e) {
                    $this->warn("     ⚠ Skipped row: {$e->getMessage()}");
                }
            }
        }

        $this->info("     ✅ {$transferred}/{$count} rows transferred");

        return $transferred;
    }
}
