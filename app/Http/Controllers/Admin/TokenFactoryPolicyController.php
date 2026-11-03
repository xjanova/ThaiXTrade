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
            // ไว้ให้กดคัดลอกมาใส่ได้เท่านั้น — ระบบไม่ใช้ค่านี้เองแล้ว
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
            $errors = array_map(fn ($message) => is_string($message) ? __($message) : $message, $prepared['errors']);

            return back()
                ->withErrors($errors)
                ->with('error', __('บันทึกไม่สำเร็จ — :errors', ['errors' => implode(' · ', array_values($errors))]));
        }

        $diff = $policy->save($prepared['policy']);

        if ($diff['new'] !== []) {
            AuditLog::log('token_factory.policy.update', null, $diff['old'], array_merge($diff['new'], [
                '_by' => Auth::guard('admin')->user()?->email,
            ]));
        }

        return back()->with('success', $diff['new'] === []
            ? __('ไม่มีอะไรเปลี่ยน')
            : __('บันทึกนโยบาย Token Factory แล้ว (:count รายการ)', ['count' => count($diff['new'])]));
    }
}
