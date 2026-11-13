<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LimitOrder;
use App\Services\Trading\LimitOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — API ของคำสั่งตั้งราคา (limit order).
 *
 * ทุกปลายทางอยู่หลัง VerifyWalletOwnership และ "บังคับ" ให้ส่ง wallet_address:
 * ด่านกระเป๋าปล่อยผ่านคำขอที่ไม่ส่งกระเป๋ามาเลย — ถ้า controller ไม่บังคับเอง
 * คำขอแบบนั้นจะเข้ามาถึงตรงนี้โดยไม่มีใครพิสูจน์ตัว
 *
 * Developed by Xman Studio.
 */
class LimitOrderController extends Controller
{
    private const WALLET_RULE = ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'];

    private const DECIMAL_RULE = ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,18})?$/'];

    public function __construct(
        private LimitOrderService $orders,
    ) {}

    /**
     * คำสั่งที่ยังเปิดอยู่ของกระเป๋า — หน้าเทรด/แอปใช้เฝ้าราคา.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['wallet_address' => self::WALLET_RULE]);

        $rows = $this->orders->activeFor($validated['wallet_address'])
            ->map(fn (LimitOrder $order) => $this->orders->present($order))
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * สร้างคำสั่งตั้งราคา.
     *
     * `amount` = ยอดที่จ่าย (เหรียญอ้างอิง) เมื่อซื้อ · จำนวนที่ขาย (เหรียญหลัก) เมื่อขาย
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => self::WALLET_RULE,
            'pair' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+[-\/][A-Za-z0-9]+$/'],
            'side' => ['required', 'string', 'in:buy,sell'],
            'price' => self::DECIMAL_RULE,
            'amount' => self::DECIMAL_RULE,
            'slippage' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'fee_method' => ['nullable', 'string', 'in:tpix_credit,onchain'],
            'expires_in_days' => ['nullable', 'integer', 'in:'.implode(',', LimitOrderService::EXPIRY_DAYS)],
        ]);

        try {
            $order = $this->orders->create($validated['wallet_address'], $validated);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return response()->json(['success' => true, 'data' => $this->orders->present($order)], 201);
    }

    /**
     * เจ้าของยืนยันในกระเป๋าแล้ว — ผูกคำสั่งกับธุรกรรมของไม้.
     */
    public function submitted(string $uuid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => self::WALLET_RULE,
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);

        try {
            $order = $this->orders->markSubmitted($validated['wallet_address'], $uuid, $validated['tx_hash']);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return response()->json(['success' => true, 'data' => $this->orders->present($order)]);
    }

    /**
     * ยกเลิกคำสั่งที่ยังรอราคา.
     */
    public function destroy(string $uuid, Request $request): JsonResponse
    {
        $validated = $request->validate(['wallet_address' => self::WALLET_RULE]);

        try {
            $order = $this->orders->cancel($validated['wallet_address'], $uuid);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return response()->json([
            'success' => true,
            'data' => ['order_id' => $order->uuid, 'status' => $order->status],
        ]);
    }

    /**
     * แปลงรหัสข้อผิดพลาดของ service เป็นคำตอบ — ไม่ส่งข้อความดิบของ exception ออกไป
     * รหัสที่ไม่รู้จัก (เช่น QueryException ที่หลุดมาเป็น RuntimeException) = 500 ข้อความกลาง + log
     */
    private function failure(RuntimeException $e): JsonResponse
    {
        [$status, $message] = match ($e->getMessage()) {
            LimitOrderService::ERR_PAIR_NOT_TRADABLE => [422, __('คู่นี้ยังตั้งราคาไม่ได้ — ตั้งได้เฉพาะคู่ที่เทรดบนเชนจริงและเปิดอยู่')],
            LimitOrderService::ERR_TOO_MANY_OPEN => [422, __('มีคำสั่งตั้งราคาเปิดค้างครบ :max รายการแล้ว ยกเลิกบางรายการก่อน', ['max' => LimitOrderService::MAX_OPEN_PER_WALLET])],
            LimitOrderService::ERR_INVALID_AMOUNT => [422, __('ราคาหรือจำนวนไม่ถูกต้อง')],
            LimitOrderService::ERR_NOT_FOUND => [404, __('ไม่พบคำสั่งนี้')],
            LimitOrderService::ERR_NOT_OPEN => [409, __('คำสั่งนี้ไม่ได้รอราคาอยู่แล้ว')],
            LimitOrderService::ERR_HASH_TAKEN => [409, __('ธุรกรรมนี้ถูกผูกกับคำสั่งอื่นแล้ว')],
            default => [500, null],
        };

        if ($message === null) {
            Log::error('LimitOrder: unexpected failure', ['exception' => $e::class]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'LIMIT_ORDER_FAILED', 'message' => __('ทำรายการไม่สำเร็จ ลองใหม่อีกครั้ง')],
            ], 500);
        }

        return response()->json([
            'success' => false,
            'error' => ['code' => $e->getMessage(), 'message' => $message],
        ], $status);
    }
}
