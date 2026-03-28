<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminAuth Middleware.
 *
 * Ensures the current user is authenticated via the 'admin' guard.
 * Additionally verifies that the authenticated admin account is active.
 * Inactive accounts are logged out and redirected with an error message.
 */
class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            // Inertia request จาก public frontend → ต้อง full redirect (ไม่ใช่ Inertia redirect)
            // ป้องกัน 409 Conflict หรือ Error component แสดงแทน login page
            if ($request->header('X-Inertia')) {
                return Inertia::location(route('admin.login'));
            }

            return redirect()->route('admin.login');
        }

        $admin = Auth::guard('admin')->user();

        if (! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->with('error', 'Your account has been deactivated. Please contact a super administrator.');
        }

        return $next($request);
    }
}
