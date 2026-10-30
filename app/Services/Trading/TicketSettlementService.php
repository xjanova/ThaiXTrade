<?php

namespace App\Services\Trading;

use App\Models\TradingOrderTicket;
use App\Models\Transaction;
use App\Services\DexSwapIndexer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — ตัดสินใบอนุญาตวางไม้ที่ผูกธุรกรรมไว้แล้ว จากผลบนเชน.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ ทำไมคำขอของหน้าเว็บตัดสินเองไม่ได้ (ออดิท 2026-10-28 C1)
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมตั๋ว issued ถูกคืนเงินได้เสมอ ทั้งจาก /refund ของหน้าเว็บ และจากตัวเก็บกวาดเมื่อหมดอายุ
 * โดยไม่มีใครดูว่าไม้ลงเชนไปแล้วหรือยัง:
 *   - กดคืนหลังไม้ลงเชน = เทรดฟรี
 *   - consume ล้ม (429 / เน็ตหลุด / ปิดแท็บ) = ตัวเก็บกวาดคืนให้เอง ทั้งที่ไม้ลงแล้ว
 * ตอนนี้ตั๋วที่ผูก tx แล้ว "ปิด" เมื่อเชนยืนยันว่าไม้ลง และ "คืน" เมื่อเชนยืนยันว่าไม่ลงเท่านั้น
 * ระหว่างที่ยังไม่รู้ผล (ยังไม่ลงบล็อก / RPC ล่ม) = รอ ไม่เดา
 *
 * ⚠️ ข้อจำกัดที่ต้องพูดตรงๆ: คนที่แก้หน้าเว็บเองให้ไม่ผูก tx เลย ยังขอคืนตั๋วหลังสวอปได้
 *    บน BSC ค่าธรรมเนียมเป็นธุรกรรมแยกที่เลือกไม่จ่ายได้อยู่แล้ว จนกว่าจะมีสัญญา fee router
 *    ตัวนี้ปิดทางที่ผู้ใช้สุจริตได้ของฟรีโดยไม่ตั้งใจ และทำให้บัญชีของเราตรงกับเชน
 *
 * Developed by Xman Studio.
 */
class TicketSettlementService
{
    public const CONSUME = 'consume';

    public const REFUND = 'refund';

    public const WAIT = 'wait';

    /** RPC ตอบได้แต่ไม่มีใครเห็นธุรกรรมนี้ — อาจยังกระจายไม่ถึง หรือหลุดจาก mempool ไปแล้ว */
    public const GONE = 'gone';

    /** ระยะผ่อนผันหลังหมดอายุก่อนตัวเก็บกวาดแตะตั๋ว — ให้คำขอผูก tx ที่ส่งช้า / RPC ที่ตามหัวไม่ทันมาถึงก่อน */
    public const GRACE_MINUTES = 10;

    /**
     * ธุรกรรมที่ผูกไว้ไม่โผล่บนเชนเลยนานเกินนี้หลังตั๋วหมดอายุ = ไม่ลงแน่
     * (หน้าเว็บตั้ง deadline ของไม้ไม่เกินเวลาหมดอายุของตั๋ว ลงบล็อกหลังจากนั้นก็ revert).
     */
    public const GIVE_UP_HOURS = 2;

    public function __construct(
        private readonly OrderTicketService $tickets,
        private readonly BscSwapVerifier $bsc,
        private readonly DexSwapIndexer $dex,
    ) {}

    /**
     * หน้าเว็บขอคืนค่าบริการ (ไม้ไม่ได้ลง / ผู้ใช้กดยกเลิก).
     *
     * ตั๋วที่ยังไม่ผูก tx = คืนได้ทันทีเหมือนเดิม
     * ตั๋วที่ผูก tx แล้ว = คืนได้เมื่อเชนยืนยันว่า tx นั้นไม่ลง (revert เพราะราคาขยับเป็นเคสที่พบบ่อยสุด)
     *
     * @throws RuntimeException ERR_TICKET_PENDING (ยังไม่รู้ผล) · ERR_TICKET_USED (ไม้ลงแล้ว — ปิดตั๋วให้)
     */
    public function requestRefund(string $uuid, string $wallet, string $reason = ''): TradingOrderTicket
    {
        $ticket = TradingOrderTicket::where('uuid', $uuid)->first();
        $bound = $ticket !== null
            && $ticket->wallet_address === strtolower(trim($wallet))
            && $ticket->status === TradingOrderTicket::STATUS_ISSUED
            && $ticket->order_tx_hash !== null;

        if (! $bound) {
            return $this->tickets->refund($uuid, $wallet, $reason);
        }

        $outcome = $this->outcomeOf($ticket);

        if ($outcome === self::REFUND) {
            return $this->tickets->refund($uuid, $wallet, $reason, settled: true);
        }

        if ($outcome === self::CONSUME) {
            $this->tickets->consume($uuid, $wallet, $ticket->order_tx_hash);

            throw new RuntimeException(OrderTicketService::ERR_TICKET_USED);
        }

        throw new RuntimeException(OrderTicketService::ERR_TICKET_PENDING);
    }

    /**
     * ตัวเก็บกวาดรายนาที — ตั๋ว issued ที่หมดอายุเกินระยะผ่อนผัน.
     *
     * ไม่ผูก tx = คืนเงิน (ขอตั๋วแล้วไม่ได้วางไม้) · ผูก tx แล้ว = ถามผลบนเชนก่อน
     *
     * @return array{refunded: int, consumed: int, waiting: int}
     */
    public function sweep(int $limit = 200): array
    {
        $counts = [
            'refunded' => $this->tickets->expireStale($limit, self::GRACE_MINUTES),
            'consumed' => 0,
            'waiting' => 0,
        ];

        $bound = TradingOrderTicket::issued()
            ->whereNotNull('order_tx_hash')
            ->where('expires_at', '<', now()->subMinutes(self::GRACE_MINUTES))
            // ตัวที่เพิ่งถูกถามไปต่อท้ายแถว — ตั๋วที่รอผลนาน (RPC ล่ม) ไม่บังตั๋วใบอื่นตลอดไป
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($bound as $ticket) {
            try {
                $outcome = $this->outcomeOf($ticket);
                if ($outcome === self::GONE && $ticket->expires_at->lt(now()->subHours(self::GIVE_UP_HOURS))) {
                    $outcome = self::REFUND;
                }

                if ($outcome === self::CONSUME) {
                    $this->tickets->consume($ticket->uuid, $ticket->wallet_address, $ticket->order_tx_hash);
                    $counts['consumed']++;
                } elseif ($outcome === self::REFUND) {
                    $this->tickets->refund($ticket->uuid, $ticket->wallet_address, 'ไม้ไม่ลงเชน', settled: true);
                    $counts['refunded']++;
                } else {
                    $ticket->touch();
                    $counts['waiting']++;
                }
            } catch (\Throwable $e) {
                Log::warning('ตัดสินใบอนุญาตที่ผูกธุรกรรมไม่สำเร็จ', [
                    'ticket' => $ticket->uuid,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return $counts;
    }

    /**
     * ผลของธุรกรรมที่ผูกกับตั๋ว: consume | refund | wait | gone.
     */
    public function outcomeOf(TradingOrderTicket $ticket): string
    {
        $hash = strtolower((string) $ticket->order_tx_hash);
        $wallet = strtolower((string) $ticket->wallet_address);

        // ไม้ของธุรกรรมนี้ถูกบันทึกแล้วหลังตรวจใบเสร็จ — ไม่ต้องถามเชนซ้ำ
        $recorded = Transaction::query()
            ->where('type', 'swap')
            ->where('tx_hash', $hash)
            ->where('wallet_address', $wallet)
            ->where('status', 'confirmed')
            ->exists();
        if ($recorded) {
            return self::CONSUME;
        }

        $tpixChain = (int) config('blockchain.tpix_chain_id', 4289);

        return match ((int) $ticket->chain_id) {
            BscSwapVerifier::CHAIN_ID => $this->bscOutcome($hash, $wallet),
            $tpixChain => $this->dexOutcome($hash, $wallet),
            // ตั๋วรุ่นก่อนที่ไม่ได้จดเชนไว้ — ตัดสินบนเชนไม่ได้ ใช้พฤติกรรมเดิม (คืนเงิน)
            default => self::REFUND,
        };
    }

    private function bscOutcome(string $hash, string $wallet): string
    {
        return match ($this->bsc->outcome($hash, $wallet)) {
            BscSwapVerifier::CONFIRMED => self::CONSUME,
            BscSwapVerifier::FAILED, BscSwapVerifier::WALLET_MISMATCH, BscSwapVerifier::WRONG_TARGET => self::REFUND,
            BscSwapVerifier::UNKNOWN => self::GONE,
            default => self::WAIT,
        };
    }

    private function dexOutcome(string $hash, string $wallet): string
    {
        // ตัวอ่าน event ของ DEX บันทึกไม้ให้ด้วยถ้ายังไม่มี (เรียกซ้ำได้ปลอดภัย)
        $result = $this->dex->recordTransaction($hash, $wallet);
        $status = $result['status'] ?? 'pending';

        if ($status === 'recorded' || $status === 'exists') {
            $first = $result['transactions'][0] ?? null;

            return $first !== null && strtolower((string) $first->wallet_address) === $wallet ? self::CONSUME : self::REFUND;
        }

        return match ($status) {
            'failed', 'wallet_mismatch', 'not_swap' => self::REFUND,
            'unavailable' => self::WAIT,
            // pending = ยังไม่มีใบเสร็จ (ยังไม่ลง / หลุดไปแล้ว / RPC ตอบไม่ได้) — ตัวเก็บกวาดรอจนครบเวลาก่อนคืน
            default => self::GONE,
        };
    }
}
