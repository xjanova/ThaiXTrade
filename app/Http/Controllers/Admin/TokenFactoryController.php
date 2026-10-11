<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FactoryToken;
use App\Services\TokenFactoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TokenFactoryController extends Controller
{
    public function __construct(
        private TokenFactoryService $tokenFactoryService,
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
     * บันทึกด้วย guard admin — AuditLog::log() อ่าน auth()->id() ของ guard ปริยาย (web)
     * และเดิมส่ง $token->id (int) เข้าช่องที่รับ Model = TypeError หลังอนุมัติไปแล้ว (500)
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
