<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * TPIX TRADE — กระเป๋าบอท (API ใช้ร่วมกันระหว่างเว็บและแอพ).
 *
 * ทุก endpoint อยู่หลัง VerifyWalletOwnership — กระเป๋าบอทผูกกับกระเป๋าเจ้าของที่ยืนยัน
 * แล้วเท่านั้น และ "ถอน" มีปลายทางเดียวคือเจ้าของ จึงไม่มีช่อง to_address ให้กรอกเลย
 *
 * Developed by Xman Studio.
 */
class AiBotWalletController extends Controller
{
    private const ERROR_MESSAGES = [
        BotWalletService::ERR_DISABLED => 'กระเป๋าบอทยังไม่เปิดให้ใช้ — โหมดจริงจะเปิดพร้อมกับกระเป๋าบอทเมื่อระบบผ่านการทดสอบ',
        BotWalletService::ERR_NOT_FOUND => 'ยังไม่มีกระเป๋าบอทของกระเป๋านี้',
        BotWalletService::ERR_LOCKED => 'กระเป๋าบอทถูกล็อกโดยทีมงาน — ติดต่อฝ่ายสนับสนุน',
        BotWalletService::ERR_ASSET => 'ไม่รู้จักสินทรัพย์ที่เลือก',
        BotWalletService::ERR_AMOUNT => 'จำนวนไม่ถูกต้อง หรือน้อยกว่าขั้นต่ำที่ถอนได้',
        BotWalletService::ERR_BALANCE => 'ยอดในกระเป๋าบอทไม่พอ',
        BotWalletService::ERR_GAS => 'ต้องเหลือ BNB ไว้จ่ายค่าแก๊สในกระเป๋าบอท — ลดจำนวนที่ถอนหรือเติม BNB ก่อน',
        BotWalletService::ERR_IN_FLIGHT => 'มีรายการถอนที่ยังไม่เสร็จอยู่ — รอให้รายการเดิมสำเร็จก่อน',
        BotWalletService::ERR_DAILY_CAP => 'เกินเพดานถอนต่อวันของกระเป๋าบอท',
        BotWalletService::ERR_NOT_CANCELLABLE => 'รายการนี้ถูกส่งไปแล้ว ยกเลิกไม่ได้ — รอผลจากเชน',
    ];

    public function __construct(
        private readonly BotWalletService $wallets,
        private readonly AiBotService $bots,
    ) {}

    /** สถานะกระเป๋าบอท + ยอด + รายการโอนล่าสุด (null = ยังไม่ได้สร้าง) */
    public function show(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $wallet = $this->wallets->find($owner);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $this->wallets->enabled(),
                'chain_id' => $this->wallets->chainId(),
                'wallet' => $wallet ? $this->wallets->present($wallet) : null,
                'transfers' => $wallet ? $this->transfersOf($owner) : [],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        try {
            $wallet = $this->wallets->ensure($owner);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), $e->getMessage() === BotWalletService::ERR_DISABLED ? 503 : 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['wallet' => $this->wallets->present($wallet), 'transfers' => []],
        ], $wallet->wasRecentlyCreated ? 201 : 200);
    }

    /** อ่านยอดจากเชนใหม่ตอนนี้ (throttle ที่ route — เชนตอบช้าได้) */
    public function refresh(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $wallet = $this->wallets->find($owner);

        if (! $wallet) {
            return $this->failure(BotWalletService::ERR_NOT_FOUND, 404);
        }

        return response()->json([
            'success' => true,
            'data' => ['wallet' => $this->wallets->present($this->wallets->refreshBalances($wallet))],
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $validated = $request->validate([
            'asset' => ['required', 'string', 'max:12'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $transfer = $this->wallets->requestWithdraw($owner, $validated['asset'], (float) $validated['amount'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), match ($e->getMessage()) {
                BotWalletService::ERR_DISABLED => 503,
                BotWalletService::ERR_NOT_FOUND => 404,
                default => 422,
            });
        }

        return response()->json([
            'success' => true,
            'data' => ['transfer' => $this->wallets->presentTransfer($transfer), 'transfers' => $this->transfersOf($owner)],
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $owner = $this->owner($request);

        try {
            $transfer = $this->wallets->cancelWithdraw($owner, $id);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), $e->getMessage() === BotWalletService::ERR_NOT_FOUND ? 404 : 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['transfer' => $this->wallets->presentTransfer($transfer), 'transfers' => $this->transfersOf($owner)],
        ]);
    }

    private function transfersOf(string $owner): array
    {
        return AiBotWalletTransfer::where('owner_address', $owner)
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (AiBotWalletTransfer $t) => $this->wallets->presentTransfer($t))
            ->all();
    }

    /** กระเป๋าของผู้เรียก — ต้องมีเสมอ (VerifyWalletOwnership ปล่อยผ่านถ้าไม่ส่งมา) */
    private function owner(Request $request): string
    {
        $wallet = $this->bots->normalize((string) ($request->input('wallet_address') ?? $request->query('wallet_address', '')));

        abort_unless(
            preg_match('/^0x[a-f0-9]{40}$/', $wallet) === 1,
            response()->json(['success' => false, 'error' => ['code' => 'INVALID_WALLET', 'message' => 'ต้องระบุ wallet_address']], 422),
        );

        return $wallet;
    }

    private function failure(string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => self::ERROR_MESSAGES[$code] ?? 'ทำรายการไม่สำเร็จ'],
        ], $status);
    }
}
