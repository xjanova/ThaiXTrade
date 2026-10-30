<?php

namespace App\Services\SaleCrypto;

use App\Exceptions\PurchaseException;
use App\Models\SaleCryptoOrder;
use App\Models\SaleCryptoPayment;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\WhitelistEntry;
use App\Services\TokenSaleService;
use App\Support\MoneySettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * จับคู่เงินที่ตัวสแกนเห็นกับคำสั่งซื้อ แล้วออก SaleTransaction เข้าสายจ่ายเหรียญเดียวกับบัตร.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * กติกาการตัดสิน (หนึ่ง log = หนึ่งการตัดสิน ตลอดกาล)
 * ═══════════════════════════════════════════════════════════════════════════
 *   เหรียญที่ไม่รับ (USDC/BUSD)            → needs_review: wrong_token
 *   ยอดเป๊ะ + โอนทันเวลา (เวลาบล็อก ≤ หมดอายุ) → credited (ราคาที่ล็อกไว้ แม้เฟสเลื่อนไปแล้ว — เหมือน Stripe)
 *   ยอดเป๊ะ + โอนหลังหมดอายุ              → needs_review: late (ราคาที่ล็อกหมดอายุแล้ว)
 *   ยอดเป๊ะ + คำสั่งซื้อจ่ายไปแล้ว           → needs_review: duplicate_payment
 *   ยอดไม่ตรง แต่ผู้โอน = กระเป๋าของคำสั่งซื้อ → needs_review: underpaid / overpaid (ผูกกับคำสั่งซื้อนั้น)
 *   หาคำสั่งซื้อไม่เจอเลย                  → needs_review: unmatched
 *   ยอดเป๊ะแต่โควตาเฟสหมด / เกินเพดานกระเป๋า → needs_review (ห้ามออกเหรียญเกินที่ขายได้)
 *
 * ไม่มีทางที่เงินเข้ามาแล้ว "ไม่มีแถว" — ทุกยอดที่เกินฝุ่นมีแถวใน sale_crypto_payments
 *
 * กันออกเหรียญซ้ำสามชั้น: unique (เชน, tx, log) ของการชำระเงิน · ล็อกแถวคำสั่งซื้อ+เฟสตอนเครดิต
 * · unique tx_hash ของ sale_transactions ('crypto_<เชน>_<tx>_<log>')
 */
class CryptoPaymentCrediter
{
    public function __construct(
        private SaleCryptoConfig $config,
        private TokenSaleService $sales,
    ) {}

    /**
     * ตัดสินทุกรายการที่ยังค้าง detected — เรียงตามลำดับบนเชน (คนจ่ายก่อนได้โควตาก่อน).
     *
     * @return array{credited: int, needs_review: int, failed: int}
     */
    public function processPending(int $limit = 200): array
    {
        $summary = ['credited' => 0, 'needs_review' => 0, 'failed' => 0];

        $pending = SaleCryptoPayment::where('status', SaleCryptoPayment::STATUS_DETECTED)
            ->orderBy('block_timestamp')
            ->orderBy('block_number')
            ->orderBy('log_index')
            ->limit($limit)
            ->get();

        foreach ($pending as $payment) {
            try {
                $status = $this->process($payment);
                if (isset($summary[$status])) {
                    $summary[$status]++;
                }
            } catch (\Throwable $e) {
                // ค้าง detected ไว้ รอบหน้าลองใหม่ — ไม่ปัดเป็นสถานะอื่นเพราะยังไม่ได้ตัดสินจริง
                $summary['failed']++;
                $payment->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
                Log::error('token-sale: ตัดสินการชำระเงินคริปโตไม่สำเร็จ — รอรอบหน้า', [
                    'payment_id' => $payment->id,
                    'tx' => $payment->tx_hash,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /** ตัดสินหนึ่งรายการ — คืนสถานะสุดท้ายของการชำระเงิน */
    public function process(SaleCryptoPayment $payment): string
    {
        $outcome = DB::transaction(function () use ($payment) {
            $locked = SaleCryptoPayment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== SaleCryptoPayment::STATUS_DETECTED) {
                return ['status' => $locked?->status ?? 'missing'];
            }

            $chain = $this->config->chain($locked->chain_id);
            if ($chain === null || $locked->token_address !== $chain['token']) {
                $order = $this->orderBySender($locked);

                return $this->review($locked, SaleCryptoPayment::REASON_WRONG_TOKEN, $order, false);
            }

            $order = SaleCryptoOrder::where('amount_lock', SaleCryptoOrder::lockKey(
                $locked->chain_id,
                $locked->token_address,
                $locked->to_address,
                $locked->amount_raw,
            ))->lockForUpdate()->first();

            if ($order === null) {
                $order = $this->orderBySender($locked);

                if ($order === null) {
                    return $this->review($locked, SaleCryptoPayment::REASON_UNMATCHED, null, false);
                }

                $reason = bccomp($locked->amount_raw, $order->pay_amount_raw, 0) < 0
                    ? SaleCryptoPayment::REASON_UNDERPAID
                    : SaleCryptoPayment::REASON_OVERPAID;

                return $this->review($locked, $reason, $order, true);
            }

            if ($order->status === SaleCryptoOrder::STATUS_PAID || $order->sale_transaction_id !== null) {
                return $this->review($locked, SaleCryptoPayment::REASON_DUPLICATE, $order, false);
            }

            if ($order->status === SaleCryptoOrder::STATUS_NEEDS_REVIEW) {
                // คำสั่งซื้อนี้มีเรื่องค้างให้ทีมงานดูอยู่แล้ว (เช่นเคยโอนขาด) — ไม่ออกเหรียญซ้อน ให้คนดูทั้งชุด
                return $this->review($locked, SaleCryptoPayment::REASON_DUPLICATE, $order, false);
            }

            if ($locked->block_timestamp > $order->expires_at->getTimestamp()) {
                return $this->review($locked, SaleCryptoPayment::REASON_LATE, $order, true);
            }

            return $this->credit($locked, $order, (float) $order->tpix_amount, (float) $order->amount_usd, null);
        });

        if (($outcome['status'] ?? null) === SaleCryptoPayment::STATUS_CREDITED) {
            $this->afterCredit($outcome['phase_id']);
        }

        return $outcome['status'];
    }

    // =========================================================================
    // ทีมงานจัดการรายการ needs_review (หน้า /admin/token-sales)
    // =========================================================================

    /**
     * ออกเหรียญตาม "ยอดที่ได้รับจริง" ในราคาที่คำสั่งซื้อล็อกไว้ — ใช้กับโอนขาด/เกิน/ช้า/จ่ายซ้ำ.
     *
     * ยังตรวจโควตาเฟสภายใต้ล็อกเหมือนเดิม — ทีมงานอนุมัติได้แต่ขายเกินที่มีไม่ได้
     *
     * @throws PurchaseException
     */
    public function creditReceived(SaleCryptoPayment $payment, ?string $by = null): SaleTransaction
    {
        $outcome = DB::transaction(function () use ($payment, $by) {
            $locked = SaleCryptoPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleCryptoPayment::STATUS_NEEDS_REVIEW) {
                throw new PurchaseException('This payment is not waiting for review.');
            }

            if ($locked->review_reason === SaleCryptoPayment::REASON_WRONG_TOKEN) {
                throw new PurchaseException('Wrong-token payments cannot be credited — refund them instead.');
            }

            $order = $locked->order_id ? SaleCryptoOrder::whereKey($locked->order_id)->lockForUpdate()->first() : null;
            if ($order === null) {
                throw new PurchaseException('This payment is not linked to an order — it can only be marked as refunded.');
            }

            // USDT 1:1 · ปัดลงเป็นเซ็นต์ — ไม่สร้างมูลค่าเกินเงินที่ได้รับ
            $usd = (float) bcdiv($locked->amount_raw, bcpow('10', (string) $locked->token_decimals, 0), 2);
            $price = (float) $order->price_per_tpix;
            $tpix = $price > 0 ? round($usd / $price, 8) : 0.0;

            if ($tpix <= 0) {
                throw new PurchaseException('The received amount is too small to credit.');
            }

            $before = $locked->only(['status', 'review_reason']);

            // โควตาไม่พอ → credit() เขียนเหตุผลใหม่ลงแถวแล้วคืนค่าปกติ ให้ commit ก่อนแล้วค่อยแจ้งข้างนอก
            $outcome = $this->credit($locked, $order, $tpix, $usd, $by);

            // บันทึกอยู่ในธุรกรรมเดียวกับการออกเหรียญ บันทึกไม่สำเร็จเท่ากับยังไม่ออก
            if ($outcome['status'] === SaleCryptoPayment::STATUS_CREDITED) {
                MoneySettings::record('token_sale.crypto_credit', $before, [
                    'id' => $locked->id,
                    'status' => $locked->status,
                    'order' => $order->reference,
                    'sale_transaction_id' => $outcome['sale_transaction_id'],
                    'wallet_address' => $order->wallet_address,
                    'tpix_amount' => $tpix,
                    'payment_usd_value' => $usd,
                ]);
            }

            return $outcome;
        });

        if ($outcome['status'] !== SaleCryptoPayment::STATUS_CREDITED) {
            throw new PurchaseException('Not enough allocation left in this phase to credit this payment — refund it instead.');
        }

        $this->afterCredit($outcome['phase_id']);

        return SaleTransaction::findOrFail($outcome['sale_transaction_id']);
    }

    /** บันทึกว่าคืนเงิน/จัดการนอกระบบแล้ว — ไม่ออกเหรียญ */
    public function markResolved(SaleCryptoPayment $payment, string $note, ?string $by = null): SaleCryptoPayment
    {
        return DB::transaction(function () use ($payment, $note, $by) {
            $locked = SaleCryptoPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleCryptoPayment::STATUS_NEEDS_REVIEW) {
                throw new PurchaseException('This payment is not waiting for review.');
            }

            $before = $locked->only(['status', 'review_reason']);

            $locked->update([
                'status' => SaleCryptoPayment::STATUS_RESOLVED,
                'review_note' => mb_substr($note, 0, 2000),
                'resolved_by' => $by,
                'resolved_at' => now(),
            ]);

            MoneySettings::record('token_sale.crypto_resolve', $before, $locked->only(['id', 'status', 'tx_hash', 'review_note']));

            return $locked->refresh();
        });
    }

    // =========================================================================
    // ภายใน
    // =========================================================================

    /**
     * ออกเหรียญ — ต้องเรียกภายในทรานแซกชันที่ล็อกแถวการชำระเงินและคำสั่งซื้อไว้แล้ว.
     *
     * โควตาไม่พอ = ทำเครื่องหมาย needs_review แล้ว "คืนค่า" (ไม่โยน) ให้ทรานแซกชัน commit เครื่องหมายนั้นจริง
     * (บทเรียนจากทางโอนเงิน: โยน exception ในทรานแซกชัน = เครื่องหมายหายไปกับการม้วนกลับ)
     */
    private function credit(SaleCryptoPayment $payment, SaleCryptoOrder $order, float $tpixAmount, float $usdValue, ?string $by): array
    {
        $phase = SalePhase::whereKey($order->sale_phase_id)->lockForUpdate()->first();
        if ($phase === null || $tpixAmount > $phase->remaining_allocation) {
            return $this->review($payment, SaleCryptoPayment::REASON_ALLOCATION, $order, true);
        }

        // เพดานสะสมต่อกระเป๋าในเฟส whitelist — ด่านเดียวกับ processPurchase
        if ($phase->whitelist_only) {
            $entry = WhitelistEntry::where('sale_phase_id', $phase->id)
                ->where('wallet_address', $order->wallet_address)
                ->lockForUpdate()
                ->first();

            $cap = (float) ($entry->max_allocation ?? 0);
            if ($cap > 0) {
                $already = (float) SaleTransaction::where('sale_phase_id', $phase->id)
                    ->where('wallet_address', $order->wallet_address)
                    ->whereIn('status', ['confirmed', 'claimed'])
                    ->sum('tpix_amount');

                if ($already + $tpixAmount > $cap) {
                    return $this->review($payment, SaleCryptoPayment::REASON_WALLET_CAP, $order, true);
                }
            }
        }

        $sale = TokenSale::find($order->token_sale_id);
        $chain = $this->config->chain($payment->chain_id);

        $tx = SaleTransaction::create([
            'token_sale_id' => $order->token_sale_id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => $order->wallet_address,
            'payment_currency' => 'USDT_'.strtoupper((string) ($chain['short'] ?? $payment->chain_id)),
            'payment_amount' => $payment->amountDisplay(),
            'payment_usd_value' => $usdValue,
            'tpix_amount' => $tpixAmount,
            'price_per_tpix' => $order->price_per_tpix,
            'tx_hash' => $payment->saleTxHash(),
            'status' => 'confirmed',
            // เหมือนบัตรทุกตัวอักษร — vesting เริ่มที่วันจบเฟส/รอบขาย (ไม่ใช่ now())
            'vesting_start_at' => $phase->ends_at ?? $sale?->ends_at ?? now(),
            'metadata' => array_filter([
                'method' => 'crypto',
                'order_reference' => $order->reference,
                'chain_id' => $payment->chain_id,
                'chain_tx_hash' => $payment->tx_hash,
                'log_index' => $payment->log_index,
                'block_number' => $payment->block_number,
                'from' => $payment->from_address,
                'to' => $payment->to_address,
                'token' => $payment->token_address,
                'amount_raw' => $payment->amount_raw,
                'credited_by' => $by,
                'review_reason' => $by !== null ? $payment->review_reason : null,
            ], fn ($v) => $v !== null),
        ]);

        $phase->increment('sold', $tpixAmount);
        if ($sale) {
            $sale->increment('total_sold', $tpixAmount);
            $sale->increment('total_raised_usd', $usdValue);
        }

        $payment->update([
            'status' => SaleCryptoPayment::STATUS_CREDITED,
            'order_id' => $order->id,
            'sale_transaction_id' => $tx->id,
            'resolved_by' => $by,
            'resolved_at' => $by !== null ? now() : null,
            'last_error' => null,
        ]);

        $order->update([
            'status' => SaleCryptoOrder::STATUS_PAID,
            'paid_at' => now(),
            'sale_transaction_id' => $tx->id,
        ]);

        Log::info('token-sale: ออกเหรียญจากการชำระเงินคริปโตแล้ว', [
            'reference' => $order->reference,
            'wallet' => $order->wallet_address,
            'chain' => $payment->chain_id,
            'tx' => $payment->tx_hash,
            'tpix' => $tpixAmount,
            'usd' => $usdValue,
            'by' => $by ?? 'scanner',
        ]);

        return ['status' => SaleCryptoPayment::STATUS_CREDITED, 'phase_id' => $phase->id, 'sale_transaction_id' => $tx->id];
    }

    /** ทำเครื่องหมายให้คนดู — ผูกคำสั่งซื้อไว้ถ้ารู้ และ (ถ้าเกี่ยวโดยตรง) ปักธงที่คำสั่งซื้อด้วย */
    private function review(SaleCryptoPayment $payment, string $reason, ?SaleCryptoOrder $order, bool $flagOrder): array
    {
        $payment->update([
            'status' => SaleCryptoPayment::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
            'order_id' => $order?->id,
            'last_error' => null,
        ]);

        if ($flagOrder && $order !== null && $order->status !== SaleCryptoOrder::STATUS_PAID) {
            $order->update(['status' => SaleCryptoOrder::STATUS_NEEDS_REVIEW]);
        }

        Log::warning('token-sale: เงินคริปโตเข้าแต่ออกเหรียญเองไม่ได้ — รอทีมงานดู', [
            'payment_id' => $payment->id,
            'chain' => $payment->chain_id,
            'tx' => $payment->tx_hash,
            'from' => $payment->from_address,
            'raw' => $payment->amount_raw,
            'reason' => $reason,
            'order' => $order?->reference,
        ]);

        return ['status' => SaleCryptoPayment::STATUS_NEEDS_REVIEW];
    }

    /**
     * คำสั่งซื้อล่าสุดของผู้โอนบนเชน/กระเป๋ารับเงินเดียวกันภายในช่วงโอนช้า — ใช้บอกทีมงานว่า "น่าจะเป็นของใบไหน".
     */
    private function orderBySender(SaleCryptoPayment $payment): ?SaleCryptoOrder
    {
        return SaleCryptoOrder::where('chain_id', $payment->chain_id)
            ->where('pay_to_address', $payment->to_address)
            ->where('wallet_address', $payment->from_address)
            ->whereIn('status', [SaleCryptoOrder::STATUS_AWAITING, SaleCryptoOrder::STATUS_EXPIRED, SaleCryptoOrder::STATUS_NEEDS_REVIEW])
            ->where('created_at', '>=', now()->subHours(max(1, (int) config('sale_crypto.late_window_hours', 72))))
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    /** หลัง commit เท่านั้น — ดูเหตุผลที่ TokenSaleService::rollOverIfExhausted */
    private function afterCredit(int $phaseId): void
    {
        Cache::forget('token_sale:active');

        $phase = SalePhase::find($phaseId);
        if ($phase !== null) {
            $this->sales->rollOverIfExhausted($phase);
        }
    }
}
