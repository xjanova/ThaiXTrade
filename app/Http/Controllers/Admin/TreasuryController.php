<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TreasuryLedger;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWhitelist;
use App\Services\TreasuryService;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TreasuryController — ชั้นคลัง TPIX (หลังบ้าน tpix.online).
 *
 * กระเป๋าคลัง 6 ใบ **ดูอย่างเดียว** ระบบไม่มีคีย์ของมัน
 * การจ่ายเงินทำผ่านกระเป๋าร้อนเท่านั้น และยัง **ปิดอยู่** จนกว่า
 * TreasuryService::readiness() จะผ่านครบทุกข้อ
 *
 * Developed by Xman Studio.
 */
class TreasuryController extends Controller
{
    public function __construct(private TreasuryService $treasury) {}

    /**
     * แดชบอร์ดคลัง — ยอด 6 ใบสด + กระเป๋าร้อน + สถานะความพร้อม.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/Treasury/Index', [
            'pools' => $this->treasury->pools(),
            'hotWallet' => $this->treasury->hotWallet(),
            'readiness' => $this->treasury->readiness(),
            'totalSupply' => config('treasury.total_supply'),
            'blockNumber' => $this->treasury->blockNumber(),
            'rpcUrl' => config('treasury.rpc_url'),
            'explorerUrl' => config('chains.chains.4289.explorer', 'https://explorer.tpix.online'),
            'limits' => [
                'per_transaction' => config('treasury.limits.per_transaction'),
                'per_day' => config('treasury.limits.per_day'),
                'require_whitelist' => config('treasury.limits.require_whitelist'),
            ],
            'pendingCount' => TreasuryPayout::pending()->count(),
        ]);
    }

    /**
     * API: รีเฟรชยอด (หน้า poll ทุก 30 วินาที).
     */
    public function balances(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'pools' => $this->treasury->pools(),
                'hotWallet' => $this->treasury->hotWallet(),
                'blockNumber' => $this->treasury->blockNumber(),
                'readiness' => $this->treasury->readiness(),
            ],
        ]);
    }

    // ── คิวจ่ายเงิน ──────────────────────────────────────────────────────

    public function payouts(Request $request): InertiaResponse
    {
        $status = $request->query('status');

        $payouts = TreasuryPayout::with(['requester:id,name', 'approver:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Treasury/Payouts', [
            'payouts' => $payouts,
            'filters' => ['status' => $status],
            'readiness' => $this->treasury->readiness(),
            'hotWallet' => $this->treasury->hotWallet(),
            'whitelist' => TreasuryWhitelist::active()->orderBy('label')->get(),
            'limits' => [
                'per_transaction' => config('treasury.limits.per_transaction'),
                'per_day' => config('treasury.limits.per_day'),
            ],
            'spentToday' => Wei::format($this->treasury->spentTodayWei()),
            'explorerUrl' => config('chains.chains.4289.explorer', 'https://explorer.tpix.online'),
        ]);
    }

    /**
     * สร้างรายการจ่ายเงินเข้าคิว.
     *
     * ต้องส่ง idempotency_key มาด้วย — ถ้าซ้ำจะคืนรายการเดิม ไม่สร้างใหม่
     * กันกรณีกดปุ่มรัว ๆ หรือ client retry
     */
    public function storePayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,18})?$/'],
            'purpose' => ['required', 'string', Rule::in(['masternode', 'token_sale', 'refund', 'other'])],
            'memo' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $amountWei = Wei::toWei($validated['amount']);

        // idempotency: มีอยู่แล้วก็คืนตัวเดิม
        $existing = TreasuryPayout::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'message' => 'รายการนี้ถูกสร้างไว้แล้ว ไม่ได้สร้างซ้ำ',
                'data' => $existing,
            ]);
        }

        $check = $this->treasury->validatePayout($validated['to_address'], $amountWei);
        if (! $check['ok']) {
            return response()->json([
                'success' => false,
                'message' => 'สร้างรายการไม่ได้',
                'errors' => $check['errors'],
            ], 422);
        }

        try {
            $payout = TreasuryPayout::create([
                'idempotency_key' => $validated['idempotency_key'],
                'to_address' => $validated['to_address'],
                'amount_wei' => $amountWei,
                'purpose' => $validated['purpose'],
                'memo' => $validated['memo'] ?? null,
                'status' => TreasuryPayout::STATUS_PENDING,
                'requested_by' => $request->user()?->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // ชนกันพอดีระหว่างสอง request ที่ใช้ key เดียวกัน — unique index กันไว้
            $existing = TreasuryPayout::where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'รายการนี้ถูกสร้างไว้แล้ว ไม่ได้สร้างซ้ำ',
                    'data' => $existing,
                ]);
            }

            throw $e;
        }

        return response()->json(['success' => true, 'data' => $payout]);
    }

    /**
     * อนุมัติรายการ — ยังไม่เซ็น ยังไม่ broadcast.
     *
     * ล็อกแถวด้วย lockForUpdate ก่อนเช็คสถานะ เพื่อกันสองแอดมินกดอนุมัติ
     * พร้อมกันแล้วผ่านทั้งคู่
     */
    public function approvePayout(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id) {
            $payout = TreasuryPayout::lockForUpdate()->find($id);

            if (! $payout) {
                return ['code' => 404, 'body' => ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            if (! $payout->isOpen()) {
                return ['code' => 409, 'body' => [
                    'success' => false,
                    'message' => 'รายการนี้ถูกดำเนินการไปแล้ว (สถานะ: '.$payout->status.')',
                ]];
            }

            // ตรวจกฎซ้ำอีกรอบ — วงเงินหรือ whitelist อาจถูกแก้ระหว่างรออนุมัติ
            $check = $this->treasury->validatePayout(
                $payout->to_address,
                (string) $payout->amount_wei,
                $payout->id,
            );

            if (! $check['ok']) {
                return ['code' => 422, 'body' => [
                    'success' => false,
                    'message' => 'อนุมัติไม่ได้ กฎเปลี่ยนไปตั้งแต่ตอนสร้างรายการ',
                    'errors' => $check['errors'],
                ]];
            }

            $hot = $this->treasury->hotWallet();

            $payout->update([
                'status' => TreasuryPayout::STATUS_APPROVED,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'hot_balance_at_approval_wei' => $hot['balance_wei'],
            ]);

            return ['code' => 200, 'body' => ['success' => true, 'data' => $payout->fresh()]];
        });

        return response()->json($result['body'], $result['code']);
    }

    public function rejectPayout(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($validated, $id) {
            $payout = TreasuryPayout::lockForUpdate()->find($id);

            if (! $payout) {
                return ['code' => 404, 'body' => ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            if (! $payout->isOpen()) {
                return ['code' => 409, 'body' => [
                    'success' => false,
                    'message' => 'รายการนี้ถูกดำเนินการไปแล้ว (สถานะ: '.$payout->status.')',
                ]];
            }

            $payout->update([
                'status' => TreasuryPayout::STATUS_REJECTED,
                'failure_reason' => $validated['reason'],
            ]);

            return ['code' => 200, 'body' => ['success' => true, 'data' => $payout->fresh()]];
        });

        return response()->json($result['body'], $result['code']);
    }

    /**
     * เซ็นและส่งขึ้นเชน — **ยังไม่เปิดใช้งาน**.
     *
     * ตั้งใจให้ endpoint นี้มีอยู่แต่ปฏิเสธทุกครั้งจนกว่าจะพร้อมจริง
     * เพื่อให้หน้าจอเรียกได้และได้คำตอบที่บอกเหตุผลชัดเจน แทนที่จะ 404
     * แล้วคนเข้าใจว่าระบบพัง
     *
     * เหตุผลที่ยังไม่เขียนส่วนเซ็น: กระเป๋าร้อนยังไม่ถูกเติมเงินและยังไม่ได้
     * วาง keystore บนเซิร์ฟเวอร์ การเขียนโค้ดเซ็นทิ้งไว้โดยทดสอบจริงไม่ได้
     * เสี่ยงกว่าการยังไม่เขียน — โค้ดจ่ายเงินที่ไม่เคยรันคือโค้ดที่ไม่รู้ว่าถูก
     */
    public function broadcastPayout(int $id): JsonResponse
    {
        $readiness = $this->treasury->readiness();

        Log::info('treasury: มีคนพยายามสั่งจ่ายเงินขณะที่ยังปิดอยู่', [
            'payout_id' => $id,
            'blocking' => array_column($readiness['blocking'], 'key'),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'ระบบจ่ายเงินยังไม่เปิดใช้งาน',
            'readiness' => $readiness,
        ], 423); // 423 Locked
    }

    // ── whitelist ────────────────────────────────────────────────────────

    public function whitelist(): InertiaResponse
    {
        return Inertia::render('Admin/Treasury/Whitelist', [
            'entries' => TreasuryWhitelist::with('creator:id,name')->latest()->get(),
            'limits' => [
                'per_transaction' => config('treasury.limits.per_transaction'),
                'per_day' => config('treasury.limits.per_day'),
                'require_whitelist' => config('treasury.limits.require_whitelist'),
            ],
        ]);
    }

    public function storeWhitelist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'label' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'purpose' => ['required', 'string', Rule::in(['masternode', 'token_sale', 'refund', 'other'])],
            'max_per_tx' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,18})?$/'],
            'max_per_day' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,18})?$/'],
        ]);

        // เทียบแบบไม่สนตัวพิมพ์ — model normalize เป็นตัวเล็กให้อยู่แล้ว
        if (TreasuryWhitelist::where('address', strtolower($validated['address']))->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'ที่อยู่นี้อยู่ใน whitelist แล้ว',
            ], 422);
        }

        $entry = TreasuryWhitelist::create([
            'address' => $validated['address'],
            'label' => $validated['label'],
            'note' => $validated['note'] ?? null,
            'purpose' => $validated['purpose'],
            'max_per_tx_wei' => filled($validated['max_per_tx'] ?? null) ? Wei::toWei($validated['max_per_tx']) : null,
            'max_per_day_wei' => filled($validated['max_per_day'] ?? null) ? Wei::toWei($validated['max_per_day']) : null,
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['success' => true, 'data' => $entry->load('creator:id,name')]);
    }

    public function toggleWhitelist(int $id): JsonResponse
    {
        $entry = TreasuryWhitelist::findOrFail($id);
        $entry->update(['is_active' => ! $entry->is_active]);

        return response()->json(['success' => true, 'data' => $entry]);
    }

    public function destroyWhitelist(int $id): JsonResponse
    {
        TreasuryWhitelist::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ── สมุดบัญชี + กระทบยอด ─────────────────────────────────────────────

    public function ledger(Request $request): InertiaResponse
    {
        $walletKey = $request->query('wallet');

        $entries = TreasuryLedger::with(['recorder:id,name', 'payout:id,tx_hash,status'])
            ->when($walletKey, fn ($q) => $q->where('wallet_key', $walletKey))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Treasury/Ledger', [
            'entries' => $entries,
            'filters' => ['wallet' => $walletKey],
            'reconciliation' => $this->treasury->reconcile(),
            'pools' => config('treasury.pools'),
            'explorerUrl' => config('chains.chains.4289.explorer', 'https://explorer.tpix.online'),
        ]);
    }

    /**
     * API: กระทบยอดใหม่ตามคำสั่ง (ปุ่มบนหน้า).
     */
    public function reconcile(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->treasury->reconcile(),
        ]);
    }
}
