<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * AuditLogController
 *
 * Read-only view of admin audit logs.
 * Restricted to super_admin role via route middleware.
 */
class AuditLogController extends Controller
{
    /**
     * Display a paginated listing of audit logs with filters.
     */
    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'admin_id' => ['nullable', 'integer', 'exists:admin_users,id'],
            'action' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = AuditLog::with('admin')
            ->orderByDesc('created_at');

        if (! empty($validated['admin_id'])) {
            $query->byAdmin($validated['admin_id']);
        }

        if (! empty($validated['action'])) {
            $query->where('action', 'like', "%{$validated['action']}%");
        }

        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to'] . ' 23:59:59');
        }

        $perPage = $validated['per_page'] ?? 20;
        $logs = $query->paginate($perPage)->withQueryString();

        $admins = AdminUser::select('id', 'name', 'email')->get();

        return Inertia::render('Admin/AuditLog/Index', [
            'logs' => $logs,
            'admins' => $admins,
            'filters' => $validated,
        ]);
    }
}
