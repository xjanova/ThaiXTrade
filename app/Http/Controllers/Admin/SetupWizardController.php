<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

/**
 * Super Admin Setup Wizard.
 *
 * First-time setup for the TPIX TRADE admin panel.
 * Only accessible when no admin users exist in the database.
 * Creates the initial super_admin account.
 */
class SetupWizardController extends Controller
{
    /**
     * Show the setup wizard page.
     * Redirect to admin login if admin already exists.
     */
    public function show()
    {
        if (AdminUser::count() > 0) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('Admin/Setup/Wizard');
    }

    /**
     * Process the setup wizard — create super admin.
     */
    public function store(Request $request)
    {
        // Block if admin already exists (prevent re-setup)
        if (AdminUser::count() > 0) {
            return redirect()->route('admin.login')
                ->with('error', 'Setup already completed. Please login.');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'fee_collector_wallet' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        // Create the super admin
        $admin = AdminUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Set fee collector wallet if provided
        if (! empty($validated['fee_collector_wallet'])) {
            \App\Models\SiteSetting::set(
                'trading',
                'fee_collector_wallet',
                $validated['fee_collector_wallet'],
            );
        }

        // Auto-login the new admin
        auth('admin')->login($admin);

        return redirect()->route('admin.dashboard')
            ->with('success', 'Setup complete! Welcome to TPIX TRADE Admin.');
    }
}
