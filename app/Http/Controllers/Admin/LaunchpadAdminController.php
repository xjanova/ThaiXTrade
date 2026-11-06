<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaunchpadAgentTx;
use App\Models\LaunchpadOrder;
use App\Models\LaunchpadReport;
use App\Models\LaunchpadSale;
use App\Services\Bridge\BridgeConfig;
use App\Services\Launchpad\LaunchpadDepositMatcher;
use App\Services\Launchpad\LaunchpadOrderException;
use App\Services\Launchpad\LaunchpadRegistrar;
use App\Services\Launchpad\LaunchpadRiskReader;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * หลังบ้าน Launchpad — อนุมัติ/ซ่อนหน้าขาย · ดูคำสั่งซื้อและธุรกรรมของตัวแทนที่ต้องให้คนดู · อ่านรายงานจากผู้ใช้.
 *
 * หน้านี้ไม่เคยเคลื่อนเงิน — ไม่มีปุ่มส่งเหรียญ/คืนเงิน/เซ็นธุรกรรม (ตัวแทนขายทำฝั่ง CLI: launchpad:deliver)
 * ลงทะเบียนอ่านทุกค่าจากเชน (ไม่มีช่องพิมพ์ราคา/ที่อยู่) · อนุมัติกันชื่อ/สัญลักษณ์ซ้ำ (LaunchpadRegistrar)
 * ทุกปุ่มที่เปลี่ยนสถานะล็อกแถวก่อน — สองคนกดพร้อมกัน คนที่สองได้ 409
 *
 * ข้อผิดพลาดตอบเป็น { success:false, error:{ code, message } } — หน้าเว็บแปล code เป็นภาษาที่เลือกเอง
 *
 * Developed by Xman Studio.
 */
class LaunchpadAdminController extends Controller
{
    private const TABS = ['sales', 'orders', 'txs', 'reports'];

    public function __construct(
        private LaunchpadRegistrar $registrar,
        private LaunchpadDepositMatcher $matcher,
        private LaunchpadRiskReader $risk,
        private BridgeConfig $bridge,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'sales';
        $orderStatus = (string) $request->query('status', 'attention');

        $sales = LaunchpadSale::query()
            ->withCount([
                'orders',
                'orders as open_orders_count' => fn ($q) => $q->whereIn('status', LaunchpadOrder::OPEN),
            ])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        $openReports = LaunchpadReport::where('status', LaunchpadReport::STATUS_OPEN)
            ->selectRaw('launchpad_sale_id, count(*) as total')->groupBy('launchpad_sale_id')->pluck('total', 'launchpad_sale_id');

        $orders = LaunchpadOrder::with('sale')
            ->when($orderStatus === 'attention', fn ($q) => $q->where(fn ($w) => $w
                ->where('status', LaunchpadOrder::STATUS_NEEDS_REVIEW)
                // จ่ายแล้วแต่ยังไม่จบเกิน 20 นาที = ตัวแทนติดอะไรสักอย่าง
                ->orWhere(fn ($s) => $s->whereIn('status', [LaunchpadOrder::STATUS_PAID, LaunchpadOrder::STATUS_BUYING, LaunchpadOrder::STATUS_REFUNDING])
                    ->where('updated_at', '<', now()->subMinutes(20)))))
            ->when($orderStatus !== 'attention' && $orderStatus !== 'all', fn ($q) => $q->where('status', $orderStatus))
            ->latest('id')
            ->paginate(25, ['*'], 'opage')->withQueryString()
            ->through(fn (LaunchpadOrder $o) => $this->orderRow($o));

        $txs = LaunchpadAgentTx::query()
            ->orderByRaw("CASE WHEN status IN ('pending','stuck') THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (LaunchpadAgentTx $tx) => [
                'id' => $tx->id,
                'kind' => $tx->kind,
                'sale_id' => $tx->launchpad_sale_id,
                'order_id' => $tx->launchpad_order_id,
                'tx_hash' => $tx->tx_hash,
                'nonce' => (int) $tx->nonce,
                'status' => $tx->status,
                'last_error' => $tx->last_error,
                'broadcast_at' => $tx->broadcast_at?->toIso8601String(),
            ]);

        $reports = LaunchpadReport::with('sale:id,token_symbol,token_name')
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(25, ['*'], 'rpage')->withQueryString()
            ->through(fn (LaunchpadReport $r) => [
                'id' => $r->id,
                'sale_id' => $r->launchpad_sale_id,
                'symbol' => $r->sale?->token_symbol,
                'reason' => $r->reason,
                'details' => $r->details,
                'reporter_wallet' => $r->reporter_wallet,
                'status' => $r->status,
                'reviewed_by' => $r->reviewed_by,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Launchpad/Index', [
            'tab' => $tab,
            'filters' => ['status' => $orderStatus],
            'state' => [
                'enabled' => (bool) config('launchpad.enabled'),
                'agent_address' => $this->matcher->agentAddress(),
                'source_chains' => array_values((array) config('launchpad.source_chains', [])),
            ],
            'sales' => $sales->map(fn (LaunchpadSale $s) => $s->toPublicArray() + [
                'orders_count' => (int) $s->orders_count,
                'open_orders_count' => (int) $s->open_orders_count,
                'open_reports' => (int) ($openReports[$s->id] ?? 0),
                'status_note' => $s->status_note,
                'reviewed_by' => $s->reviewed_by,
                'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            ])->values(),
            'orders' => $orders,
            'txs' => $txs,
            'reports' => $reports,
            'counts' => [
                'pending_sales' => $sales->where('status', LaunchpadSale::STATUS_PENDING)->count(),
                'open_reports' => (int) $openReports->sum(),
                'unresolved_txs' => LaunchpadAgentTx::whereIn('status', LaunchpadAgentTx::UNRESOLVED)->count(),
                'needs_review' => LaunchpadOrder::where('status', LaunchpadOrder::STATUS_NEEDS_REVIEW)->count(),
            ],
            'explorers' => [
                $this->bridge->tpixChainId() => $this->bridge->explorer($this->bridge->tpixChainId()),
            ],
        ]);
    }

    /** POST /admin/launchpad/register — อ่านหน้าขายจากเชน (สร้างใหม่เป็น pending หรืออัปเดตค่าจากเชน) */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['sale_address' => 'required|string|max:42']);

        return $this->guard(function () use ($request, $data) {
            $result = $this->registrar->register($data['sale_address']);
            $sale = $result['sale'];
            $this->risk->forget($sale);
            Log::info('launchpad: แอดมินลงทะเบียนหน้าขาย', ['sale_id' => $sale->id, 'created' => $result['created'], 'by' => $request->user()?->id]);

            return response()->json(['success' => true, 'data' => [
                'id' => $sale->id, 'symbol' => $sale->token_symbol, 'status' => $sale->status, 'created' => $result['created'],
            ]], $result['created'] ? 201 : 200);
        });
    }

    /** POST /admin/launchpad/sales/{id}/status — approve | hide | reject | pending */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'hide', 'reject', 'pending'])],
            'note' => 'nullable|string|max:300',
        ]);
        $by = mb_substr((string) ($request->user()?->name ?? 'admin'), 0, 80);

        return $this->guard(function () use ($request, $data, $id, $by) {
            $sale = DB::transaction(function () use ($data, $id, $by) {
                $sale = LaunchpadSale::lockForUpdate()->find($id);
                if (! $sale) {
                    throw new LaunchpadOrderException('NOT_FOUND', 'ไม่พบหน้าขาย', 404);
                }

                $target = match ($data['action']) {
                    'approve' => LaunchpadSale::STATUS_APPROVED,
                    'hide' => LaunchpadSale::STATUS_HIDDEN,
                    'reject' => LaunchpadSale::STATUS_REJECTED,
                    'pending' => LaunchpadSale::STATUS_PENDING,
                };
                if ($sale->status === $target) {
                    throw new LaunchpadOrderException('CONFLICT', 'สถานะนี้อยู่แล้ว', 409);
                }

                return $data['action'] === 'approve'
                    ? $this->registrar->approve($sale, $by, $data['note'] ?? null)
                    : $this->registrar->setStatus($sale, $target, $by, $data['note'] ?? null);
            });

            $this->risk->forget($sale);
            Log::warning('launchpad: แอดมินเปลี่ยนสถานะหน้าขาย', ['sale_id' => $sale->id, 'status' => $sale->status, 'by' => $request->user()?->id]);

            return response()->json(['success' => true, 'data' => ['id' => $sale->id, 'status' => $sale->status]]);
        });
    }

    /** POST /admin/launchpad/reports/{id} — reviewed | dismissed */
    public function updateReport(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([LaunchpadReport::STATUS_REVIEWED, LaunchpadReport::STATUS_DISMISSED])]]);

        return $this->guard(function () use ($request, $data, $id) {
            $report = DB::transaction(function () use ($request, $data, $id) {
                $report = LaunchpadReport::lockForUpdate()->find($id);
                if (! $report) {
                    throw new LaunchpadOrderException('NOT_FOUND', 'ไม่พบรายงาน', 404);
                }
                if ($report->status !== LaunchpadReport::STATUS_OPEN) {
                    throw new LaunchpadOrderException('CONFLICT', 'รายงานนี้ตรวจไปแล้ว', 409);
                }
                $report->update([
                    'status' => $data['status'],
                    'reviewed_by' => mb_substr((string) ($request->user()?->name ?? 'admin'), 0, 80),
                    'reviewed_at' => now(),
                ]);

                return $report;
            });

            return response()->json(['success' => true, 'data' => ['id' => $report->id, 'status' => $report->status]]);
        });
    }

    private function orderRow(LaunchpadOrder $o): array
    {
        return [
            'id' => $o->id,
            'public_id' => $o->public_id,
            'sale_id' => $o->launchpad_sale_id,
            'symbol' => $o->sale?->token_symbol,
            'buyer' => $o->buyer_address,
            'source_chain_id' => $o->source_chain_id,
            'amount' => Wei::format((string) $o->amount_units, 6),
            'status' => $o->status,
            'attempts' => (int) $o->attempts,
            'last_error' => $o->last_error,
            'bridge_deposit_id' => $o->bridge_deposit_id,
            'delivery_tx_hash' => $o->delivery_tx_hash,
            'refund_tx_hash' => $o->refund_tx_hash,
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }

    private function guard(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (LaunchpadOrderException $e) {
            return response()->json(['success' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('launchpad admin: ผิดพลาดที่ไม่คาดไว้', ['type' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return response()->json(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'ระบบขัดข้อง']], 500);
        }
    }
}
