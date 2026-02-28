<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminRole Middleware.
 *
 * Verifies the authenticated admin user has one of the required roles.
 * Accepts a comma-separated list of role names as the parameter.
 *
 * Usage in routes: ->middleware('admin.role:super_admin,admin')
 */
class AdminRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  Comma-separated role names
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin || ! in_array($admin->role, $roles)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
