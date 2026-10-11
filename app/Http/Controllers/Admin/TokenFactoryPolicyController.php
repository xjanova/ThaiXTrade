<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Services\TokenFactory\TokenFactoryPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า "ตั้งค่า Token Factory" — เฉพาะ super_admin (กันที่ route).
 *
 * ทุกการบันทึกลง audit_logs พร้อมค่าเดิม → ค่าใหม่ เฉพาะช่องที่เปลี่ยน
 * ของที่ล็อกในโค้ดเปิดไม่ได้ — TokenFactoryPolicy::prepareUpdate() ปฏิเสธให้
 *
 * Developed by Xman Studio
 */
class TokenFactoryPolicyController extends Controller
{
    public function show(TokenFactoryPolicy $policy): Response
    {
        return Inertia::render('Admin/TokenFactory/Policy', [
            'policy' => $policy->adminConfig(),
            'resolvedFeeWallet' => $policy->feeWallet(),
            // ไว้ให้กดคัดลอกมาใส่ได้เท่านั้น — ระบบไม่ใช้ค่านี้เองแล้ว (แอดมินทุกระดับแก้แท็บรายได้ได้)
            'revenueTpixWallet' => strtolower(trim((string) SiteSetting::get('revenue', 'tpix_wallet', ''))),
        ]);
    }

    public function update(Request $request, TokenFactoryPolicy $policy): RedirectResponse
    {
        $request->validate([
            'types' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'reserved_symbols' => ['nullable', 'array'],
        ]);

        $prepared = $policy->prepareUpdate($request->all());

        if ($prepared['errors'] !== []) {
            return back()
                ->withErrors($prepared['errors'])
                ->with('error', 'บันทึกไม่สำเร็จ — '.implode(' · ', array_values($prepared['errors'])));
        }

        $diff = $policy->save($prepared['policy']);

        if ($diff['new'] !== []) {
            /*
             * บันทึกตรงด้วย guard admin — AuditLog::log() อ่าน auth()->id() ของ guard ปริยาย (web)
             * ซึ่งเป็น null สำหรับแอดมิน ทำให้ไม่รู้ว่าใครเปลี่ยนนโยบาย
             */
            $admin = Auth::guard('admin')->user();

            AuditLog::create([
                'admin_id' => $admin?->id,
                'action' => 'token_factory.policy.update',
                'old_values' => $diff['old'],
                'new_values' => array_merge($diff['new'], ['_by' => $admin?->email]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return back()->with('success', $diff['new'] === []
            ? 'ไม่มีอะไรเปลี่ยน'
            : 'บันทึกนโยบาย Token Factory แล้ว ('.count($diff['new']).' รายการ)');
    }
}
