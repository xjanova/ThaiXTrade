<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * TPIX TRADE - Create Admin User Command
 * Usage: php artisan admin:create.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {--name= : Admin name}
                            {--email= : Admin email}
                            {--password= : Admin password}
                            {--role=super_admin : Admin role (super_admin, admin, moderator, support)}';

    protected $description = 'Create a new admin user for TPIX TRADE';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║    TPIX TRADE - Admin Setup          ║');
        $this->info('║    Developed by Xman Studio          ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->info('');

        $name = $this->option('name') ?: $this->ask('Admin Name', 'Super Admin');
        $email = $this->option('email') ?: $this->ask('Admin Email');
        $password = $this->option('password') ?: $this->secret('Admin Password (min 8 characters)');
        $role = $this->option('role') ?: $this->choice('Role', ['super_admin', 'admin', 'moderator', 'support'], 0);

        // Validate
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:super_admin,admin,moderator,support',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error("  ✗ {$error}");
            }

            return Command::FAILURE;
        }

        // Create admin user
        $admin = AdminUser::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // Auto-hashed by model cast
            'role' => $role,
            'is_active' => true,
        ]);

        $this->info('');
        $this->info('  ✓ Admin user created successfully!');
        $this->info('  ┌─────────────────────────────────');
        $this->info("  │ Name:  {$admin->name}");
        $this->info("  │ Email: {$admin->email}");
        $this->info("  │ Role:  {$admin->role}");
        $this->info('  │ URL:   '.config('app.url').'/admin/login');
        $this->info('  └─────────────────────────────────');
        $this->info('');

        return Command::SUCCESS;
    }
}
