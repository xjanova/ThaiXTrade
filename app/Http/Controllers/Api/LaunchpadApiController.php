<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaunchpadOrder;
use App\Models\LaunchpadSale;
use App\Services\Launchpad\LaunchpadOrderException;
use App\Services\Launchpad\LaunchpadOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TPIX Launchpad — API ของหน้าขายเหรียญลูก.
 *
 * ไม่มี endpoint ที่สั่งให้ระบบ "ส่งเหรียญ/คืนเงิน" — ผู้ซื้อแค่เซ็นคำสั่ง (ไม่มีธุรกรรม) แล้วโอน USDT บนเชนเอง
 * ตัวสแกนของสะพานเห็นเงินจากเชนจริงแล้วจับคู่ ตัวแทนขายทำที่เหลือฝั่ง CLI
 *
 * รูปแบบตอบ: { success, data } หรือ { success: false, error: { code, message } }
 * error.message เป็นภาษาไทย — หน้าเว็บใช้ error.code แปลเป็นภาษาที่ผู้ใช้เลือก
 */
class LaunchpadApiController extends Controller
{
    public function __construct(private LaunchpadOrderService $orders) {}

    /** GET /api/v1/launchpad/sales — หน้าขายที่แอดมินอนุมัติแล้ว */
    public function sales(): JsonResponse
    {
        $sales = LaunchpadSale::where('status', LaunchpadSale::STATUS_APPROVED)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get()
            ->map->toPublicArray();

        return response()->json(['success' => true, 'data' => $sales->values()]);
    }

    /** GET /api/v1/launchpad/sales/{id} */
    public function sale(string $id): JsonResponse
    {
        $sale = $this->findSale($id);

        return $sale
            ? response()->json(['success' => true, 'data' => $sale->toPublicArray()])
            : $this->error('SALE_NOT_FOUND', 'ไม่พบหน้าขายนี้', 404);
    }

    /** POST /api/v1/launchpad/orders/prepare — ตรวจทุกข้อแล้วคืนข้อความให้กระเป๋าเซ็น */
    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => 'required|integer',
            'buyer' => 'required|string|max:42',
            'amount' => 'required|string|max:20',
            'source_chain_id' => 'required|integer',
        ]);

        $sale = $this->findSale((string) $data['sale_id']);
        if (! $sale) {
            return $this->error('SALE_NOT_FOUND', 'ไม่พบหน้าขายนี้', 404);
        }

        return $this->guard(fn () => response()->json([
            'success' => true,
            'data' => $this->orders->prepare($sale, $data['buyer'], $data['amount'], (int) $data['source_chain_id']),
        ]));
    }

    /** POST /api/v1/launchpad/orders — รับข้อความที่เซ็นแล้ว สร้างคำสั่งรอโอน */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => 'required|integer',
            'buyer' => 'required|string|max:42',
            'amount' => 'required|string|max:20',
            'source_chain_id' => 'required|integer',
            'issued_at' => 'required|string|max:40',
            'nonce' => 'required|string|max:32',
            'signature' => 'required|string|max:200',
            'risk_accepted' => 'required|boolean',
        ]);

        $sale = $this->findSale((string) $data['sale_id']);
        if (! $sale) {
            return $this->error('SALE_NOT_FOUND', 'ไม่พบหน้าขายนี้', 404);
        }

        $data['risk_accepted'] = filter_var($data['risk_accepted'], FILTER_VALIDATE_BOOLEAN);

        return $this->guard(function () use ($sale, $data, $request) {
            $order = $this->orders->create($sale, $data, $request->ip());

            return response()->json(['success' => true, 'data' => $order->load('sale')->toPublicArray()], 201);
        });
    }

    /** GET /api/v1/launchpad/orders/{id} */
    public function show(string $id): JsonResponse
    {
        $order = $this->findOrder($id);

        return $order
            ? response()->json(['success' => true, 'data' => $order->toPublicArray()])
            : $this->error('ORDER_NOT_FOUND', 'ไม่พบคำสั่งซื้อนี้', 404);
    }

    /** GET /api/v1/launchpad/wallet/{wallet}/orders — ประวัติของกระเป๋า (ข้อมูลเดียวกับที่อยู่บนเชนอยู่แล้ว) */
    public function walletOrders(string $wallet): JsonResponse
    {
        $wallet = strtolower($wallet);
        if (! preg_match('/^0x[0-9a-f]{40}$/', $wallet)) {
            return $this->error('INVALID_WALLET', 'ที่อยู่กระเป๋าไม่ถูกต้อง', 422);
        }

        $orders = LaunchpadOrder::with('sale')
            ->where('buyer_address', $wallet)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map->toPublicArray();

        return response()->json(['success' => true, 'data' => $orders->values()]);
    }

    /** GET /api/v1/launchpad/orders/{id}/cancel-message — ข้อความที่ต้องเซ็นเพื่อยกเลิก */
    public function cancelMessage(string $id): JsonResponse
    {
        $order = $this->findOrder($id);

        return $order
            ? response()->json(['success' => true, 'data' => ['message' => $this->orders->cancelMessage($order)]])
            : $this->error('ORDER_NOT_FOUND', 'ไม่พบคำสั่งซื้อนี้', 404);
    }

    /** POST /api/v1/launchpad/orders/{id}/cancel */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['signature' => 'required|string|max:200']);
        $order = $this->findOrder($id);

        if (! $order) {
            return $this->error('ORDER_NOT_FOUND', 'ไม่พบคำสั่งซื้อนี้', 404);
        }

        return $this->guard(fn () => response()->json([
            'success' => true,
            'data' => $this->orders->cancel($order, $data['signature'])->load('sale')->toPublicArray(),
        ]));
    }

    private function findSale(string $id): ?LaunchpadSale
    {
        return ctype_digit($id) && strlen($id) <= 12
            ? LaunchpadSale::where('status', LaunchpadSale::STATUS_APPROVED)->find((int) $id)
            : null;
    }

    private function findOrder(string $id): ?LaunchpadOrder
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $id)
            ? LaunchpadOrder::with('sale')->where('public_id', strtolower($id))->first()
            : null;
    }

    /** ข้อผิดพลาดที่ตั้งใจ = ส่งรหัส + ข้อความไทย · อย่างอื่น = ข้อความกลาง ๆ (ไม่ปล่อย SQL/RPC ออกไป) */
    private function guard(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (LaunchpadOrderException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + ($e->context ? ['details' => $e->context] : []),
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('launchpad api: ผิดพลาดที่ไม่คาดไว้', ['type' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return $this->error('SERVER_ERROR', 'ระบบขัดข้องชั่วคราว ลองใหม่อีกครั้ง', 500);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
