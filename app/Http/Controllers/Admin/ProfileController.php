<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ProfileController.
 *
 * Manages the current admin's profile and password.
 */
class ProfileController extends Controller
{
    /**
     * Show the profile edit page.
     */
    public function edit(): InertiaResponse
    {
        $admin = Auth::guard('admin')->user();

        return Inertia::render('Admin/Profile/Edit', [
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'avatar' => $admin->avatar,
                'created_at' => $admin->created_at->format('Y-m-d'),
                'last_login_at' => $admin->last_login_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    /**
     * Update the admin's profile information.
     */
    public function update(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($admin->id)],
        ]);

        $admin->update($validated);

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Update the admin's password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $admin->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        $admin->update(['password' => Hash::make($validated['password'])]);

        return back()->with('success', 'Password updated successfully.');
    }
}
