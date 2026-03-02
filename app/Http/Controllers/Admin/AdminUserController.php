<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * AdminUserController.
 *
 * Manages admin user accounts (CRUD). Restricted to super_admin role.
 */
class AdminUserController extends Controller
{
    /**
     * Display a paginated listing of admin users.
     */
    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', 'string', 'in:super_admin,admin,moderator,support'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = AdminUser::query()->orderByDesc('created_at');

        if (! empty($validated['role'])) {
            $query->byRole($validated['role']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $validated,
        ]);
    }

    /**
     * Store a new admin user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'in:super_admin,admin,moderator,support'],
            'is_active' => ['boolean'],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = $validated['is_active'] ?? true;

        AdminUser::create($validated);

        return back()->with('success', 'Admin user created successfully.');
    }

    /**
     * Update an existing admin user.
     */
    public function update(Request $request, AdminUser $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($user->id)],
            'role' => ['required', 'string', 'in:super_admin,admin,moderator,support'],
            'is_active' => ['boolean'],
        ]);

        $user->update($validated);

        return back()->with('success', 'Admin user updated successfully.');
    }

    /**
     * Delete an admin user (soft-delete).
     */
    public function destroy(AdminUser $user): RedirectResponse
    {
        $currentAdmin = Auth::guard('admin')->user();

        // Cannot delete yourself
        if ($user->id === $currentAdmin->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        // Cannot delete the last super_admin
        if ($user->isSuperAdmin()) {
            $superAdminCount = AdminUser::byRole('super_admin')->count();
            if ($superAdminCount <= 1) {
                return back()->with('error', 'Cannot delete the last super admin.');
            }
        }

        $user->delete();

        return back()->with('success', 'Admin user deleted successfully.');
    }

    /**
     * Reset an admin user's password.
     */
    public function resetPassword(Request $request, AdminUser $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        return back()->with('success', 'Password reset successfully.');
    }
}
