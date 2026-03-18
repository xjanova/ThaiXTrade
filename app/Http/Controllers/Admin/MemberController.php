<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\UserWalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * TPIX TRADE — Admin Member Management
 * จัดการสมาชิก (Traders): ดูข้อมูล, ban/unban, KYC, wallet history
 * Developed by Xman Studio.
 */
class MemberController extends Controller
{
    public function __construct(
        private UserWalletService $walletService,
    ) {}

    /**
     * รายการสมาชิกทั้งหมด + search/filter
     */
    public function index(Request $request): \Inertia\Response
    {
        $query = User::query()
            ->withCount('walletConnections')
            ->search($request->search);

        // Filter ตามสถานะ
        if ($request->status === 'banned') {
            $query->banned();
        } elseif ($request->status === 'active') {
            $query->active();
        }

        // Filter ตาม KYC
        if ($request->kyc && $request->kyc !== 'all') {
            $query->where('kyc_status', $request->kyc);
        }

        $members = $query->orderByDesc('last_active_at')
            ->paginate(20)
            ->withQueryString();

        // สถิติรวม
        $stats = $this->walletService->getStats();

        return Inertia::render('Admin/Members/Index', [
            'members' => $members,
            'stats' => $stats,
            'filters' => [
                'search' => $request->search,
                'status' => $request->status,
                'kyc' => $request->kyc,
            ],
        ]);
    }

    /**
     * รายละเอียดสมาชิก + wallet history
     */
    public function show(User $member): \Inertia\Response
    {
        $member->load('walletConnections', 'referrer', 'referrals');

        return Inertia::render('Admin/Members/Show', [
            'member' => $member,
            'connections' => $member->walletConnections()
                ->orderByDesc('connected_at')
                ->limit(50)
                ->get(),
            'referrals_count' => $member->referrals()->count(),
        ]);
    }

    /**
     * แบนสมาชิก
     */
    public function ban(Request $request, User $member): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $member->ban($request->reason ?? 'Banned by admin');

        return back()->with('success', "แบน {$member->wallet_address} สำเร็จ");
    }

    /**
     * ปลดแบน
     */
    public function unban(User $member): \Illuminate\Http\RedirectResponse
    {
        $member->unban();

        return back()->with('success', "ปลดแบน {$member->wallet_address} สำเร็จ");
    }

    /**
     * อัปเดต KYC status
     */
    public function updateKyc(Request $request, User $member): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['kyc_status' => 'required|in:none,pending,approved,rejected']);
        $member->update(['kyc_status' => $request->kyc_status]);

        return back()->with('success', "อัปเดต KYC เป็น {$request->kyc_status} สำเร็จ");
    }
}
