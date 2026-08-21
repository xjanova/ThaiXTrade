<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingOrderTicket;
use App\Models\TradingPair;
use App\Services\ChainResolver;
use App\Services\Trading\OrderTicketService;
use App\Services\Trading\TradingCreditService;
use App\Services\Trading\TradingFeeQuoteService;
use App\Services\Trading\TradingTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * TPIX TRADE — คลัง TPIX และใบอนุญาตวางไม้.
 *
 * ทุกปลายทางที่แตะเงินอยู่หลัง VerifyWalletOwnership — ผู้เรียกต้องพิสูจน์แล้วว่า
 * คุมกระเป๋าใบนั้นจริง ยกเว้นตารางราคาที่เป็นข้อมูลสาธารณะ
 *
 * Developed by Xman Studio.
 */
class TradingFeeController extends Controller
{
    /** ข้อความที่ผู้ใช้เห็น — ไม่เปิดเผยรายละเอียดภายใน */
    private const MESSAGES = [
        TradingCreditService::ERR_INSUFFICIENT => 'เครดิต TPIX ไม่พอสำหรับค่าบริการของไม้นี้',
        OrderTicketService::ERR_TICKET_NOT_FOUND => 'ไม่พบใบอนุญาตวางไม้ใบนี้',
        OrderTicketService::ERR_TICKET_USED => 'ใบอนุญาตนี้ถูกใช้ไปแล้ว',
        OrderTicketService::ERR_TICKET_EXPIRED => 'ใบอนุญาตหมดอายุแล้ว กรุณาขอใหม่',
        OrderTicketService::ERR_FEE_TX_REQUIRED => 'ต้องมีธุรกรรมการจ่ายค่าบริการก่อนถึงวางไม้ได้',
        TradingTopupService::ERR_NO_TREASURY => 'ยังไม่เปิดให้เติมเครดิต — ผู้ดูแลระบบยังไม่ได้ตั้งกระเป๋ารับเงิน',
        TradingTopupService::ERR_TX_NOT_FOUND => 'หาธุรกรรมนี้บนเชนไม่เจอ — รอสักครู่แล้วลองใหม่',
        TradingTopupService::ERR_TX_FAILED => 'ธุรกรรมนี้ไม่สำเร็จบนเชน จึงลงเครดิตให้ไม่ได้',
        TradingTopupService::ERR_WRONG_RECIPIENT => 'ธุรกรรมนี้ไม่ได้โอนเข้ากระเป๋ารับเงินของแพลตฟอร์ม',
        TradingTopupService::ERR_WRONG_SENDER => 'ธุรกรรมนี้ไม่ได้โอนจากกระเป๋าที่คุณเชื่อมอยู่',
        TradingTopupService::ERR_BELOW_MINIMUM => 'ยอดเติมต่ำกว่าขั้นต่ำที่กำหนด',
    ];

    public function __construct(
        private readonly TradingCreditService $credits,
        private readonly TradingFeeQuoteService $quotes,
        private readonly OrderTicketService $tickets,
        private readonly TradingTopupService $topups,
        private readonly ChainResolver $chains,
    ) {}

    /**
     * แปลง chain id ของบล็อกเชน → เลขแถวในตาราง chains (0 = หาไม่เจอ).
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ★ สองเลขนี้คนละชุดกัน และเคยถูกส่งสลับกันมาตลอด
     * ═══════════════════════════════════════════════════════════════════════
     * client ส่ง chain_id ของบล็อกเชนมา (56, 4289) แต่ fee_configs.chain_id
     * เก็บเลขแถว (1..11) การส่งต่อดิบๆ ทำให้เงื่อนไข "ค่าธรรมเนียมเฉพาะเชน"
     * ใน FeeCalculationService ไม่มีทางตรงกับแถวไหนได้เลย
     *
     * วันนี้ยังไม่เห็นผลเพราะมันตกไปใช้อัตรากลางแทนอย่างเงียบๆ แต่แปลว่า
     * ถ้าแอดมินตั้งค่าธรรมเนียมรายเชนที่ /admin/fees ค่านั้นจะไม่ถูกใช้เลย
     * — เป็นการตั้งค่าที่ไม่มีผลอะไร ซึ่งอันตรายกว่าตั้งไม่ได้
     *
     * คืน 0 เมื่อหาไม่เจอ เพื่อให้ตกไปใช้อัตรากลางเหมือนพฤติกรรมเดิมทุกประการ
     * (FeeCalculationService เช็ค `if ($chainId)` ซึ่ง 0 เป็นเท็จ)
     */
    private function chainRowId(int $blockchainChainId): int
    {
        return (int) ($this->chains->resolveActive($blockchainChainId)?->id ?? 0);
    }

    /** ตารางขั้นบันไดค่าบริการ — สาธารณะ ให้ดูได้ก่อนเชื่อมกระเป๋า */
    public function tiers(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'enabled' => $this->quotes->enabled(),
            'tiers' => $this->quotes->tiers(),
            'topup' => $this->topups->info(),
            'refund_gas_fee' => $this->tickets->refundGasFee(),
            'ticket_ttl_minutes' => $this->tickets->ttlMinutes(),
        ]]);
    }

    /** ยอดคลัง TPIX + ประวัติเดินบัญชี */
    public function balance(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);

        return response()->json(['success' => true, 'data' => [
            'balance' => $this->credits->balanceFor($wallet),
            'minimum_topup' => $this->credits->minimumTopup(),
            'history' => $this->credits->history($wallet),
        ]]);
    }

    /**
     * ค่าบริการของไม้ขนาดนี้ พร้อมสองทางให้เปรียบเทียบ.
     *
     * หน้าเว็บเรียกทุกครั้งที่ผู้ใช้พิมพ์จำนวน — ต้องเบาและไม่เขียนอะไร
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'order_value_usd' => ['required', 'numeric', 'gte:0'],
            'chain_id' => ['required', 'integer', 'min:1'],
            'pair' => ['nullable', 'string', 'max:32'],
        ]);

        $pairId = null;
        if (! empty($validated['pair'])) {
            $pairId = TradingPair::where('symbol', str_replace('/', '-', strtoupper($validated['pair'])))
                ->value('id');
        }

        return response()->json(['success' => true, 'data' => $this->quotes->quote(
            $validated['wallet_address'] ?? null,
            (float) $validated['order_value_usd'],
            $this->chainRowId((int) $validated['chain_id']),
            $pairId,
        )]);
    }

    /**
     * ขอใบอนุญาตวางไม้ — ค่าบริการถูกเก็บตรงนี้ ก่อนผู้ใช้จะเซ็นธุรกรรมของไม้.
     *
     * ⚠️ นี่คือจุดที่เงินออกจากกระเป๋าผู้ใช้จริง ต้องได้ใบอนุญาตก่อนเสมอ
     *    ไม่มีใบ = หน้าเว็บไม่ปล่อยให้กดยืนยัน
     */
    public function issueTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'pair' => ['required', 'string', 'max:32'],
            'side' => ['required', 'string', 'in:buy,sell'],
            'order_value_usd' => ['required', 'numeric', 'gt:0'],
            'chain_id' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:tpix_credit,onchain'],
            // เฉพาะทาง onchain — ต้องจ่ายมาก่อนถึงขอใบอนุญาตได้
            'fee_tx_hash' => ['required_if:method,onchain', 'nullable', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            'fee_amount' => ['required_if:method,onchain', 'nullable', 'numeric', 'gt:0'],
            'fee_currency' => ['nullable', 'string', 'max:20'],
        ]);

        $wallet = $this->wallet($request);

        try {
            $ticket = $validated['method'] === 'tpix_credit'
                ? $this->tickets->issueWithCredit(
                    $wallet,
                    $validated['pair'],
                    $validated['side'],
                    (float) $validated['order_value_usd'],
                    $this->chainRowId((int) $validated['chain_id']),
                )
                : $this->tickets->issueWithOnchainFee(
                    $wallet,
                    $validated['pair'],
                    $validated['side'],
                    (float) $validated['order_value_usd'],
                    (float) $validated['fee_amount'],
                    $validated['fee_currency'] ?? 'USDT',
                    $validated['fee_tx_hash'],
                );
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($ticket)], 201);
    }

    /** ไม้ลงจริงแล้ว — ปิดใบอนุญาต */
    public function consumeTicket(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'order_tx_hash' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);

        try {
            $ticket = $this->tickets->consume($uuid, $this->wallet($request), $validated['order_tx_hash'] ?? null);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($ticket)]);
    }

    /**
     * ไม้ไม่ได้ลง — คืนค่าบริการ.
     *
     * เรียกเมื่อผู้ใช้กดยกเลิกในกระเป๋า ธุรกรรมล้มเหลว หรือยกเลิกไม้ที่ตั้งไว้
     * จ่ายด้วยเครดิตคืนเต็ม · จ่ายบนเชนคืนโดยหักค่าแก๊สตามที่แจ้งไว้ล่วงหน้า
     */
    public function refundTicket(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $ticket = $this->tickets->refund(
                $uuid,
                $this->wallet($request),
                (string) $request->input('reason', 'ไม่ได้วางไม้'),
            );
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($ticket)]);
    }

    /** ยืนยันการโอน TPIX เข้าคลัง */
    public function confirmTopup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);

        try {
            $result = $this->topups->credit($this->wallet($request), $validated['tx_hash']);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    private function present(TradingOrderTicket $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'pair' => $ticket->pair,
            'side' => $ticket->side,
            'fee_method' => $ticket->fee_method,
            'fee_amount' => (float) $ticket->fee_amount,
            'fee_currency' => $ticket->fee_currency,
            'status' => $ticket->status,
            'refund_amount' => $ticket->refund_amount === null ? null : (float) $ticket->refund_amount,
            'gas_deducted' => $ticket->gas_deducted === null ? null : (float) $ticket->gas_deducted,
            'refunds_in_full' => $ticket->refundsInFull(),
            'expires_at' => $ticket->expires_at?->toIso8601String(),
            'balance' => $this->credits->balanceFor($ticket->wallet_address),
        ];
    }

    private function failure(string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => self::MESSAGES[$code] ?? $code,
            ],
        ], $status);
    }

    private function wallet(Request $request): string
    {
        $wallet = $this->credits->normalize(
            (string) ($request->input('wallet_address') ?? $request->query('wallet_address', ''))
        );

        abort_unless(
            preg_match('/^0x[a-f0-9]{40}$/', $wallet) === 1,
            response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_WALLET', 'message' => 'Invalid wallet address format.'],
            ], 422)
        );

        return $wallet;
    }
}
