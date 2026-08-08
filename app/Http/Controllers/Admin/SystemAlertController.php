<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * SystemAlertController.
 *
 * จ่ายข้อมูลคาดแดง (เหตุวิกฤตโครงสร้างพื้นฐาน) ให้ AdminLayout โพลทุก 30 วิ
 * และรับการกดรับทราบจากแอดมิน — อยู่หลัง admin.auth + admin.audit เสมอ
 */
class SystemAlertController extends Controller
{
    /**
     * GET /admin/system-alerts/active — เหตุที่ยัง active สำหรับแถบคาดแดง (JSON)
     */
    public function active(): JsonResponse
    {
        $alerts = SystemAlert::active()
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get(['id', 'node', 'alert_key', 'severity', 'message', 'occurrences', 'first_seen_at', 'last_seen_at']);

        return response()->json(['alerts' => $alerts]);
    }

    /**
     * POST /admin/system-alerts/{systemAlert}/resolve — แอดมินกดรับทราบ/ปิดเหตุ.
     */
    public function resolve(SystemAlert $systemAlert): RedirectResponse
    {
        if ($systemAlert->status === 'active') {
            $admin = Auth::guard('admin')->user();
            $systemAlert->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by' => $admin?->name ?? 'admin#'.Auth::guard('admin')->id(),
            ]);
        }

        return back()->with('success', 'Alert resolved.');
    }
}
