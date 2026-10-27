<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FactoryToken;
use App\Services\TokenFactory\TokenFactoryException;
use App\Services\TokenFactory\TokenFactoryPolicy;
use App\Services\TokenFactory\TokenFactoryRefunds;
use App\Services\TokenFactoryService;
use App\Services\TokenList\TokenLogoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TokenFactoryController extends Controller
{
    public function __construct(
        private TokenFactoryService $tokenFactoryService,
        private TokenLogoStorage $logos,
        private TokenFactoryRefunds $refunds,
        private TokenFactoryPolicy $policy,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $search = $request->query('search');
        $tokens = $this->tokenFactoryService->getAllTokens($status, $search);
        $stats = $this->tokenFactoryService->getStats();
        $readiness = $this->tokenFactoryService->isFactoryReady(withDetails: true);
        $factoryConfig = $this->tokenFactoryService->getFactoryConfig();

        return Inertia::render('Admin/TokenFactory/Index', [
            'tokens' => $tokens,
            'stats' => $stats,
            'currentStatus' => $status,
            'currentSearch' => $search,
            'factoryReady' => $readiness,
            'factoryConfig' => $factoryConfig,
            'canManagePolicy' => Auth::guard('admin')->user()?->role === 'super_admin',
            'canModerate' => in_array(Auth::guard('admin')->user()?->role, ['super_admin', 'admin'], true),
            'logoRules' => ['max_kb' => TokenLogoStorage::MAX_KB, 'min_px' => TokenLogoStorage::MIN_PX, 'types' => 'PNG, WebP'],
            'refundMode' => $this->policy->refundMode(),
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->approveToken($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.approve', $token);

        return back()->with('success', "อนุมัติ {$token->symbol} แล้ว — กำลังสร้างเหรียญบนเชน");
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->rejectToken($token, $request->input('reason'));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.reject', $token, null, ['reason' => $request->input('reason')]);

        return back()->with('success', "ปฏิเสธ {$token->symbol} แล้ว");
    }

    public function toggleVerified(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);
        $before = (bool) $token->is_verified;

        try {
            $token = $this->tokenFactoryService->toggleVerified($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.verify_toggle', $token, ['is_verified' => $before], ['is_verified' => (bool) $token->is_verified]);

        return back()->with('success', $token->is_verified ? "{$token->symbol} ได้ป้าย Verified แล้ว" : "ถอดป้าย Verified ของ {$token->symbol} แล้ว");
    }

    public function toggleListed(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);
        $before = (bool) $token->is_listed;

        try {
            $token = $this->tokenFactoryService->toggleListed($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.list_toggle', $token, ['is_listed' => $before], ['is_listed' => (bool) $token->is_listed]);

        return back()->with('success', $token->is_listed
            ? "ลิสต์ {$token->symbol} แล้ว — คู่เทรดจะเปิดในรอบซิงก์ถัดไป (ถ้ามีพูลสภาพคล่อง)"
            : "เลิกลิสต์ {$token->symbol} แล้ว — คู่เทรดจะถูกปิดในรอบซิงก์ถัดไป");
    }

    /**
     * ตั้ง/เปลี่ยนโลโก้ของเหรียญที่ deploy แล้ว — แอดมินแก้ให้ได้โดยไม่ต้องรอผู้สร้าง.
     *
     * ไฟล์ไปอยู่ token-logos/{chainId}/… (ชื่อผูกกับเนื้อไฟล์) ไม่ใช่โฟลเดอร์ของกระเป๋าผู้สร้าง
     * ซึ่งถูกหมุนเก็บแค่ 3 ไฟล์ล่าสุด — โลโก้ของเหรียญที่ลิสต์แล้วต้องไม่หายเพราะผู้สร้างอัปรูปใหม่
     */
    public function updateLogo(Request $request, int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        $request->validate(['logo' => TokenLogoStorage::rules()], TokenLogoStorage::messages('logo'));

        if ($token->status !== 'deployed' || ! $token->contract_address) {
            return back()->with('error', 'ตั้งโลโก้ได้เฉพาะเหรียญที่ deploy แล้ว');
        }

        $before = $token->logo_url;
        $path = $this->logos->store($request->file('logo'), (int) $token->chain_id, (string) $token->contract_address);
        $url = '/storage/'.$path;

        $token->update(['logo_url' => $url]);

        if ($before !== $url) {
            $this->logos->deleteIfUnused($before, null, $token);
        }

        $this->audit('token_factory.logo_update', $token, ['logo_url' => $before], ['logo_url' => $url]);

        return back()->with('success', "เปลี่ยนโลโก้ {$token->symbol} แล้ว");
    }

    public function retry(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->approveToken($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.retry', $token);

        return back()->with('success', "สั่ง deploy {$token->symbol} ใหม่แล้ว");
    }

    /**
     * แถวค้าง deploying โดยไม่มีธุรกรรม (คิวล่ม / งานรุ่นเก่า / worker ตายก่อนเซ็น) → failed.
     * เงื่อนไขอยู่ใน service: ไม่มี tx_hash และค้างเกิน STUCK_MINUTES.
     */
    public function resetStuck(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->resetStuckDeployment($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.reset_stuck', $token, ['status' => 'deploying'], ['status' => 'failed']);

        return back()->with('success', "รีเซ็ต {$token->symbol} เป็น failed แล้ว — ตรวจสอบก่อนกด retry");
    }

    /** ตรวจผลธุรกรรมที่ส่งไปแล้วอีกครั้ง — ไม่ส่งธุรกรรมใหม่ */
    public function recheck(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->recheckDeployment($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.recheck', $token);

        return back()->with('success', "สั่งตรวจผลธุรกรรมของ {$token->symbol} แล้ว");
    }

    /**
     * แอดมินโอนคืนเองแล้ว — วาง tx hash ให้ระบบตรวจบนเชน (ผู้รับ / ผู้โอน / ยอด / สถานะ) ก่อนปิดรายการ.
     */
    public function refundManual(Request $request, int $id): RedirectResponse
    {
        $request->validate(['tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/']], [
            'tx_hash.regex' => 'tx hash ต้องขึ้นต้นด้วย 0x ตามด้วยตัวอักษรฐานสิบหก 64 ตัว',
        ]);

        $token = FactoryToken::findOrFail($id);

        try {
            $token = $this->refunds->confirmManual($token, (string) $request->input('tx_hash'));
        } catch (TokenFactoryException $e) {
            // รวม REFUND_TX_PENDING (ยังยืนยันไม่ครบ) — ข้อความบอกให้กดซ้ำ
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.refund_manual', $token, null, [
            'refund_tx_hash' => $token->refund_tx_hash,
            'refund_amount_wei' => $token->refund_amount_wei,
        ]);

        return back()->with('success', "ยืนยันการคืนค่าธรรมเนียม {$token->symbol} แล้ว");
    }

    /**
     * สั่งจ่ายคืนจากกระเป๋าร้อนของคลัง (ครั้งแรกในโหมด manual หรือสั่งใหม่หลังล้มเหลว).
     */
    public function refundAuto(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $payout = $this->refunds->queueAuto($token);
        } catch (TokenFactoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit('token_factory.refund_auto', $token, null, [
            'payout_id' => $payout->id,
            'refund_amount_wei' => $payout->amount_wei,
        ]);

        return back()->with('success', "เข้าคิวจ่ายคืน {$token->symbol} แล้ว (คิว #{$payout->id}) — ระบบจะส่งในรอบถัดไป");
    }

    /**
     * บันทึกด้วย guard admin — AuditLog::log() อ่าน auth()->id() ของ guard ปริยาย (web)
     * และเดิมส่ง $token->id (int) เข้าช่องที่รับ Model = TypeError หลังอนุมัติไปแล้ว (500).
     */
    private function audit(string $action, FactoryToken $token, ?array $old = null, ?array $new = null): void
    {
        AuditLog::create([
            'admin_id' => Auth::guard('admin')->id(),
            'action' => $action,
            'model_type' => FactoryToken::class,
            'model_id' => $token->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
