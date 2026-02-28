<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuditAdmin Middleware
 *
 * Logs all mutating admin requests (POST, PUT, PATCH, DELETE) to the audit_logs table.
 * Captures the admin user, route action, IP address, and user agent.
 * GET/HEAD/OPTIONS requests are skipped to reduce noise.
 */
class AuditAdmin
{
    /**
     * HTTP methods that should be audited.
     *
     * @var array<int, string>
     */
    protected array $auditMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), $this->auditMethods)) {
            $this->logRequest($request);
        }

        return $response;
    }

    /**
     * Log the admin request to the audit_logs table.
     */
    protected function logRequest(Request $request): void
    {
        try {
            $admin = Auth::guard('admin')->user();

            AuditLog::create([
                'admin_id' => $admin?->id,
                'action' => $request->route()?->getName() ?? $request->path(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Silently fail audit logging to avoid disrupting admin operations
            report($e);
        }
    }
}
