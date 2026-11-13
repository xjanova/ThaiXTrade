<?php

namespace App\Services\Trading;

use App\Models\Chain;
use App\Models\LimitOrder;
use App\Models\TradingPair;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TPIX TRADE — คำสั่งตั้งราคา (limit order) ของคู่ที่เทรดบนเชนจริง.
 *
 * เซิร์ฟเวอร์ "จำคำสั่ง" และ "ตัดสินผลจากเชน" เท่านั้น ไม่ถือเหรียญและไม่ส่งธุรกรรมแทนใคร:
 *   - สร้าง/ยกเลิก/หมดอายุ = แถวในตาราง limit_orders
 *   - ราคาถึง → หน้าเทรด/แอปให้เจ้าของยืนยัน แล้วส่งไม้ราคาตลาดที่ minOut = min_receive
 *     (ราคาแย่กว่าที่ตั้ง = ธุรกรรม revert บนเชน ไม่ใช่แค่คำสัญญาของหน้าเว็บ)
 *   - ปิดคำสั่งเป็น filled เมื่อ "เซิร์ฟเวอร์เห็นแถว swap ที่ยืนยันแล้ว" ของ hash นั้นจากกระเป๋าเดียวกัน
 *     (แถวพวกนั้นมาจาก BscSwapRecorder / DexSwapIndexer ซึ่งอ่านใบเสร็จบนเชนเอง)
 *     → client อ้าง hash มั่วไม่ทำให้คำสั่งกลายเป็น "สำเร็จ"
 *
 * Developed by Xman Studio.
 */
class LimitOrderService
{
    public const ERR_PAIR_NOT_TRADABLE = 'PAIR_NOT_TRADABLE';

    public const ERR_TOO_MANY_OPEN = 'TOO_MANY_OPEN_ORDERS';

    public const ERR_INVALID_AMOUNT = 'INVALID_AMOUNT';

    public const ERR_NOT_FOUND = 'ORDER_NOT_FOUND';

    public const ERR_NOT_OPEN = 'ORDER_NOT_OPEN';

    public const ERR_HASH_TAKEN = 'TX_HASH_TAKEN';

    /** เพดานคำสั่งที่เปิดค้างต่อกระเป๋า — กันกดสร้างรัวจนตารางบวม */
    public const MAX_OPEN_PER_WALLET = 20;

    /** อายุคำสั่งที่เลือกได้ (วัน) */
    public const EXPIRY_DAYS = [1, 7, 30];

    public const DEFAULT_EXPIRY_DAYS = 7;

    /** ส่งธุรกรรมแล้วแต่ไม่มีแถว swap ให้เห็นเกินกี่นาที = เปิดคำสั่งคืน */
    public const SUBMITTED_GRACE_MINUTES = 20;

    private const SCALE = 18;

    public function __construct(
        private readonly TicketSettlementService $chain,
    ) {}

    /**
     * สร้างคำสั่งตั้งราคา.
     *
     * ซื้อ: ผู้ใช้กำหนด "ยอดที่จ่าย" (quote) → base = quote ÷ ราคา · ได้รับอย่างน้อย = base
     * ขาย: ผู้ใช้กำหนด "จำนวนที่ขาย" (base) → quote = base × ราคา · ได้รับอย่างน้อย = quote
     *
     * @param  array{pair: string, side: string, price: string, amount: string, slippage?: float|string|null, fee_method?: string|null, expires_in_days?: int|null}  $input
     *                                                                                                                                                                `amount` = ยอด quote เมื่อซื้อ, ยอด base เมื่อขาย
     */
    public function create(string $wallet, array $input): LimitOrder
    {
        $wallet = strtolower($wallet);
        $pair = $this->tradablePair((string) $input['pair']);

        $price = $this->decimal((string) $input['price']);
        $amount = $this->decimal((string) $input['amount']);

        if (bccomp($price, '0', self::SCALE) <= 0 || bccomp($amount, '0', self::SCALE) <= 0) {
            throw new RuntimeException(self::ERR_INVALID_AMOUNT);
        }

        $side = $input['side'] === 'sell' ? 'sell' : 'buy';

        if ($side === 'buy') {
            $quote = $amount;
            $base = bcdiv($quote, $price, self::SCALE);
            $minReceive = $base;
        } else {
            $base = $amount;
            $quote = bcmul($base, $price, self::SCALE);
            $minReceive = $quote;
        }

        // ราคา/จำนวนที่ปัดแล้วเหลือศูนย์ = คำสั่งที่ไม่มีวันลงได้ (minOut 0 คือไม่มีการคุ้มครองราคา)
        if (bccomp($minReceive, '0', self::SCALE) <= 0) {
            throw new RuntimeException(self::ERR_INVALID_AMOUNT);
        }

        $days = (int) ($input['expires_in_days'] ?? self::DEFAULT_EXPIRY_DAYS);
        if (! in_array($days, self::EXPIRY_DAYS, true)) {
            $days = self::DEFAULT_EXPIRY_DAYS;
        }

        $slippage = isset($input['slippage']) && is_numeric($input['slippage'])
            ? max(0.0, min(50.0, (float) $input['slippage']))
            : null;

        /*
         * นับคำสั่งเปิด + สร้าง ในทรานแซกชันเดียวที่ล็อกแถวของกระเป๋านี้
         * กดสองครั้งติดกัน (double-tap) ต้องไม่ทะลุเพดาน
         */
        return DB::transaction(function () use ($wallet, $pair, $side, $price, $base, $quote, $minReceive, $slippage, $input, $days) {
            $open = LimitOrder::forWallet($wallet)->active()->lockForUpdate()->count();

            if ($open >= self::MAX_OPEN_PER_WALLET) {
                throw new RuntimeException(self::ERR_TOO_MANY_OPEN);
            }

            return LimitOrder::create([
                'uuid' => (string) Str::uuid(),
                'wallet_address' => $wallet,
                'trading_pair_id' => $pair->id,
                'pair' => $pair->symbol,
                'network_chain_id' => (int) $pair->chain->chain_id,
                'side' => $side,
                'limit_price' => $price,
                'base_amount' => $base,
                'quote_amount' => $quote,
                'min_receive' => $minReceive,
                'slippage' => $slippage,
                'fee_method' => in_array($input['fee_method'] ?? null, ['tpix_credit', 'onchain'], true) ? $input['fee_method'] : null,
                'status' => LimitOrder::STATUS_OPEN,
                'expires_at' => now()->addDays($days),
            ]);
        });
    }

    /**
     * คำสั่งที่ยังเปิดอยู่ของกระเป๋า (รอราคา + ส่งธุรกรรมแล้วรอผล).
     *
     * @return Collection<int, LimitOrder>
     */
    public function activeFor(string $wallet): Collection
    {
        return LimitOrder::forWallet($wallet)
            ->active()
            ->orderByDesc('created_at')
            ->limit(self::MAX_OPEN_PER_WALLET * 2)
            ->get();
    }

    /**
     * เจ้าของยกเลิก — ได้เฉพาะคำสั่งที่ยังรอราคา.
     *
     * คำสั่งที่ส่งธุรกรรมไปแล้วยกเลิกไม่ได้: ไม้อาจลงเชนแล้ว ผลบนเชนเป็นตัวตัดสิน
     */
    public function cancel(string $wallet, string $uuid): LimitOrder
    {
        return DB::transaction(function () use ($wallet, $uuid) {
            $order = $this->lockOwned($wallet, $uuid);

            if (! $order->isOpen()) {
                throw new RuntimeException(self::ERR_NOT_OPEN);
            }

            $order->update([
                'status' => LimitOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'close_reason' => 'cancelled_by_owner',
            ]);

            return $order;
        });
    }

    /**
     * เจ้าของยืนยันในกระเป๋าแล้ว — ผูกคำสั่งกับธุรกรรมของไม้.
     *
     * เรียกซ้ำด้วย hash เดิมได้ (หน้าเว็บลองใหม่เมื่อเน็ตสะดุด) · hash ที่คำสั่งอื่นใช้แล้วถูกปฏิเสธ
     * แล้วลองปิดคำสั่งทันทีถ้าเซิร์ฟเวอร์เห็นผลบนเชนแล้ว
     */
    public function markSubmitted(string $wallet, string $uuid, string $txHash): LimitOrder
    {
        $txHash = strtolower($txHash);

        $order = DB::transaction(function () use ($wallet, $uuid, $txHash) {
            $order = $this->lockOwned($wallet, $uuid);

            if ($order->tx_hash === $txHash) {
                return $order;
            }

            if (! $order->isOpen()) {
                throw new RuntimeException(self::ERR_NOT_OPEN);
            }

            $taken = LimitOrder::where('tx_hash', $txHash)->where('id', '!=', $order->id)->exists();
            if ($taken) {
                throw new RuntimeException(self::ERR_HASH_TAKEN);
            }

            $order->update([
                'status' => LimitOrder::STATUS_SUBMITTED,
                'tx_hash' => $txHash,
                'submitted_at' => now(),
            ]);

            return $order;
        });

        return $this->settle($order);
    }

    /**
     * ตัดสินคำสั่งที่ส่งธุรกรรมแล้วจากแถว swap ที่เซิร์ฟเวอร์ยืนยันเอง.
     *
     *   แถวยืนยันแล้ว            → filled (ยอดจริงจากใบเสร็จ)
     *   แถวล้ม (revert)          → เปิดคำสั่งคืน รอราคารอบใหม่
     *   ไม่มีแถวเลยเกินช่วงผ่อนผัน → เปิดคำสั่งคืน (ธุรกรรมไม่เคยถึงเชน หรือ hash ไม่ใช่ไม้ของกระเป๋านี้)
     *   ยังรอ                    → คงเดิม
     */
    /**
     * @param  bool  $askChain  ไม่มีแถว swap → ถามผลจากเชนเอง (งานตามเวลาเท่านั้น — ไม่ถาม RPC ในคำขอของผู้ใช้)
     */
    public function settle(LimitOrder $order, bool $askChain = false): LimitOrder
    {
        if ($order->status !== LimitOrder::STATUS_SUBMITTED || ! $order->tx_hash) {
            return $order;
        }

        $swap = $this->swapRowOf($order);

        if ($swap && in_array($swap->status, ['confirmed', 'completed'], true)) {
            return $this->fill($order, $swap);
        }

        if ($swap && in_array($swap->status, ['failed', 'cancelled'], true)) {
            $this->reopenOrExpire($order, 'tx_failed');

            return $order;
        }

        // มีแถวที่ยังรอผล = ตัวบันทึกไม้กำลังตามให้อยู่ รอต่อ
        if ($swap) {
            return $order;
        }

        /*
         * ไม่มีแถว swap เลย — หน้าเว็บ/แอปอาจแจ้งบันทึกไม้ไม่สำเร็จ ทั้งที่ธุรกรรมลงเชนไปแล้ว
         *
         * ⚠️ ห้ามเปิดคำสั่งคืนเพียงเพราะ "เราไม่มีแถว": คำสั่งที่เปิดคืนจะชวนเจ้าของยืนยันอีกรอบ
         *    = ไม้ลงสองรอบ ต้องถามเชนก่อนเสมอ
         *      ลงแล้ว            → ปิดเป็นสำเร็จ (ยอดจากแถวที่ตัวอ่านเชนเพิ่งบันทึก ถ้ามี)
         *      revert / ไม่ใช่ไม้ → เปิดคืน
         *      ยังไม่รู้ผล       → รอ
         *      ไม่มีใครเห็นธุรกรรม → เปิดคืนเมื่อเลยช่วงผ่อนผัน
         */
        $stale = $order->submitted_at
            && $order->submitted_at->lt(now()->subMinutes(self::SUBMITTED_GRACE_MINUTES));

        if (! $askChain) {
            return $order;
        }

        $outcome = $this->chain->chainOutcome($order->tx_hash, $order->wallet_address, (int) $order->network_chain_id);

        if ($outcome === TicketSettlementService::CONSUME) {
            return $this->fill($order, $this->swapRowOf($order));
        }

        if ($outcome === TicketSettlementService::REFUND) {
            $this->reopenOrExpire($order, 'tx_failed');
        } elseif ($outcome === TicketSettlementService::GONE && $stale) {
            $this->reopenOrExpire($order, 'tx_not_found');
        }

        return $order;
    }

    /** แถว swap ของธุรกรรมที่ผูกกับคำสั่งนี้ จากกระเป๋าเดียวกัน (null = ยังไม่มีใครบันทึก) */
    private function swapRowOf(LimitOrder $order): ?Transaction
    {
        // เทียบแบบไม่สนตัวพิมพ์ — แถว swap เก่าบางแถวเก็บ address/hash แบบ checksum
        return Transaction::query()
            ->where('type', 'swap')
            ->whereRaw('LOWER(tx_hash) = ?', [strtolower((string) $order->tx_hash)])
            ->whereRaw('LOWER(wallet_address) = ?', [strtolower($order->wallet_address)])
            ->first();
    }

    /** ปิดคำสั่งเป็นสำเร็จ — ยอดจริงจากแถว swap ถ้ามี (ไม้ที่ลงเชนแต่ไม่มีแถว: ยอดว่างไว้ ไม่เดา) */
    private function fill(LimitOrder $order, ?Transaction $swap): LimitOrder
    {
        $settled = $swap && in_array($swap->status, ['confirmed', 'completed'], true);
        $buy = $order->side === 'buy';

        $order->update([
            'status' => LimitOrder::STATUS_FILLED,
            'filled_at' => now(),
            'filled_base' => $settled ? ($buy ? $swap->to_amount : $swap->from_amount) : null,
            'filled_quote' => $settled ? ($buy ? $swap->from_amount : $swap->to_amount) : null,
            'close_reason' => 'filled',
        ]);

        return $order;
    }

    /**
     * งานตามเวลา: ตัดสินคำสั่งที่รอผล + ปิดคำสั่งที่หมดอายุ.
     *
     * @return array{settled: int, reopened: int, expired: int}
     */
    public function sweep(): array
    {
        $settled = 0;
        $reopened = 0;

        LimitOrder::where('status', LimitOrder::STATUS_SUBMITTED)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (LimitOrder $order) use (&$settled, &$reopened) {
                $after = $this->settle($order, askChain: true)->status;

                if ($after === LimitOrder::STATUS_FILLED) {
                    $settled++;
                } elseif ($after !== LimitOrder::STATUS_SUBMITTED) {
                    $reopened++;
                }
            });

        // เฉพาะคำสั่งที่ยัง "รอราคา" — ตัวที่ส่งธุรกรรมแล้วให้ผลบนเชนตัดสินก่อน
        $expired = LimitOrder::where('status', LimitOrder::STATUS_OPEN)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => LimitOrder::STATUS_EXPIRED,
                'close_reason' => 'expired',
                'updated_at' => now(),
            ]);

        return ['settled' => $settled, 'reopened' => $reopened, 'expired' => $expired];
    }

    /**
     * รูปข้อมูลของคำสั่งที่ส่งให้หน้าเว็บ/แอป — สตริงทศนิยมล้วน ไม่ผ่าน float.
     *
     * @return array<string, mixed>
     */
    public function present(LimitOrder $order): array
    {
        return [
            'id' => $order->uuid,
            'pair' => $order->pair,
            'network_chain_id' => $order->network_chain_id,
            'side' => $order->side,
            'type' => 'limit',
            'price' => $this->trim((string) $order->limit_price),
            'amount' => $this->trim((string) $order->base_amount),
            'total' => $this->trim((string) $order->quote_amount),
            'min_receive' => $this->trim((string) $order->min_receive),
            'filled_amount' => $order->status === LimitOrder::STATUS_FILLED
                ? $this->trim((string) ($order->filled_base ?? $order->base_amount))
                : '0',
            'slippage' => $order->slippage !== null ? (float) $order->slippage : null,
            'fee_method' => $order->fee_method,
            'status' => $order->status,
            'tx_hash' => $order->tx_hash,
            'source' => 'limit',
            'expires_at' => $order->expires_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    private function reopenOrExpire(LimitOrder $order, string $reason): void
    {
        $expired = $order->expires_at && $order->expires_at->lte(now());

        $order->update([
            'status' => $expired ? LimitOrder::STATUS_EXPIRED : LimitOrder::STATUS_OPEN,
            'tx_hash' => null,
            'submitted_at' => null,
            'close_reason' => $expired ? 'expired' : $reason,
        ]);
    }

    private function lockOwned(string $wallet, string $uuid): LimitOrder
    {
        $order = LimitOrder::forWallet($wallet)->where('uuid', $uuid)->lockForUpdate()->first();

        if (! $order) {
            throw new RuntimeException(self::ERR_NOT_FOUND);
        }

        return $order;
    }

    /**
     * คู่ที่ตั้งราคาได้ = คู่ที่เทรดบนเชนจริงและเปิดอยู่ บนเชนที่เปิดใช้งาน
     * (คู่ราคาอ้างอิง `index` และคู่ที่แอดมินปิด ตั้งคำสั่งไม่ได้ — ไม่มีทางลงไม้ได้อยู่แล้ว)
     */
    private function tradablePair(string $symbol): TradingPair
    {
        $symbol = strtoupper(str_replace('/', '-', trim($symbol)));

        $pair = TradingPair::query()
            ->with('chain')
            ->where('symbol', $symbol)
            ->where('is_active', true)
            ->where('execution_mode', 'onchain')
            ->orderBy('id')
            ->get()
            ->first(fn (TradingPair $p) => $p->chain
                && $p->chain->is_active
                && $p->chain->status === Chain::STATUS_LIVE);

        if (! $pair) {
            throw new RuntimeException(self::ERR_PAIR_NOT_TRADABLE);
        }

        return $pair;
    }

    /** สตริงทศนิยมที่ bcmath รับได้ — ผ่าน validation ของ controller มาแล้ว แต่กันอีกชั้น */
    private function decimal(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{1,18}(\.\d{1,18})?$/', $value) !== 1) {
            throw new RuntimeException(self::ERR_INVALID_AMOUNT);
        }

        return $value;
    }

    /** ตัดศูนย์ท้ายทศนิยม — "0.100000000000000000" → "0.1" */
    private function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' ? '0' : $value;
    }
}
