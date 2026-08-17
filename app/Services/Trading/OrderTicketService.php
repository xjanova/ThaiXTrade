<?php

namespace App\Services\Trading;

use App\Models\SiteSetting;
use App\Models\TradingOrderTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — ใบอนุญาตวางไม้: เก็บค่าบริการก่อน แล้วค่อยให้วางไม้.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมต้องเป็น "ตั๋ว" ไม่ใช่หักตอนส่งคำสั่ง
 * ═══════════════════════════════════════════════════════════════════════════
 * เส้นทางเทรดจริงบน BSC ผู้ใช้เซ็นกับ PancakeSwap ตรงๆ เหรียญไม่ผ่านเราเลย
 * (non-custodial) เราจึงไม่มีจังหวะไหนที่จะ "หักก่อนส่งต่อ" ได้
 *
 * วิธีที่ใช้ได้จริงคือย้ายด่านมาไว้ก่อนหน้า: ขออนุญาตวางไม้ → เก็บเงินตรงนั้น →
 * ได้ตั๋ว → เอาตั๋วไปวางไม้ ตรงกับที่เจ้าของสั่งว่า "ขึ้นออเดอร์เก็บตอนนั้นเลย"
 *
 * ⚠️ ข้อจำกัดที่ต้องพูดตรงๆ: นี่กันการเบี้ยวผ่านหน้าเว็บของเรา ไม่ได้กันคนที่
 *    เรียกสัญญา PancakeSwap เองโดยตรง — ซึ่งเขาก็ไม่ได้ใช้บริการเราอยู่แล้ว
 *
 * ⚠️ ออกตั๋วแล้วผู้ใช้กดยกเลิกในกระเป๋าได้เสมอ ต้องคืนเงินให้
 *    ไม่งั้นเสีย TPIX เพราะกดผิดปุ่มใน MetaMask ซึ่งไม่มีใครยอมรับได้
 *
 * Developed by Xman Studio.
 */
class OrderTicketService
{
    public const ERR_INSUFFICIENT = 'INSUFFICIENT_CREDIT';

    public const ERR_TICKET_NOT_FOUND = 'TICKET_NOT_FOUND';

    public const ERR_TICKET_USED = 'TICKET_ALREADY_USED';

    public const ERR_TICKET_EXPIRED = 'TICKET_EXPIRED';

    public const ERR_FEE_TX_REQUIRED = 'FEE_TX_REQUIRED';

    public function __construct(
        private readonly TradingCreditService $credits,
        private readonly TradingFeeQuoteService $quotes,
    ) {}

    /** ตั๋วมีอายุสั้น — ราคาขยับแล้วมูลค่าไม้ที่คิดค่าบริการไว้ก็ไม่ตรงแล้ว */
    public function ttlMinutes(): int
    {
        return max(1, (int) SiteSetting::get('trading', 'ticket_ttl_minutes', 15));
    }

    /**
     * ออกตั๋วโดยหักจากคลัง TPIX.
     *
     * @throws RuntimeException ERR_INSUFFICIENT เมื่อเครดิตไม่พอ
     */
    public function issueWithCredit(
        string $wallet,
        string $pair,
        string $side,
        float $orderValueUsd,
        int $chainId,
        ?int $tradingPairId = null,
    ): TradingOrderTicket {
        $wallet = $this->credits->normalize($wallet);
        $quote = $this->quotes->quote($wallet, $orderValueUsd, $chainId, $tradingPairId);

        if (! ($quote['tpix']['available'] ?? false)) {
            throw new RuntimeException($quote['tpix']['reason'] ?? 'ยังใช้ค่าบริการแบบ TPIX ไม่ได้');
        }

        $fee = (float) $quote['tpix']['fee_tpix'];

        return DB::transaction(function () use ($wallet, $pair, $side, $orderValueUsd, $fee, $quote) {
            $ticket = TradingOrderTicket::create([
                'wallet_address' => $wallet,
                'pair' => strtoupper($pair),
                'side' => $side,
                'order_value_usd' => $orderValueUsd,
                'fee_method' => TradingOrderTicket::METHOD_CREDIT,
                'fee_amount' => $fee,
                'fee_currency' => 'TPIX',
                'trading_fee_tier_id' => $quote['tpix']['tier_id'] ?? null,
                'status' => TradingOrderTicket::STATUS_ISSUED,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
            ]);

            // หักหลังสร้างตั๋ว เพื่อให้ reference ผูกกับ uuid ของตั๋วใบนี้ได้
            // ทั้งคู่อยู่ใน transaction เดียวกัน — หักไม่ผ่านแล้วตั๋วไม่เกิด
            $this->credits->charge($wallet, $fee, $ticket->uuid, [
                'pair' => $ticket->pair,
                'side' => $side,
                'order_value_usd' => $orderValueUsd,
            ]);

            return $ticket;
        });
    }

    /**
     * ออกตั๋วโดยผู้ใช้จ่ายค่าบริการเป็นเหรียญบนเชนมาแล้ว.
     *
     * ต้องมี tx hash ของการจ่ายจริง — ตั๋วที่ไม่มีหลักฐานการจ่ายคือตั๋วฟรี
     * (unique index บน fee_tx_hash กันเอาธุรกรรมเดิมมาขอตั๋วซ้ำ)
     */
    public function issueWithOnchainFee(
        string $wallet,
        string $pair,
        string $side,
        float $orderValueUsd,
        float $feeAmount,
        string $feeCurrency,
        string $feeTxHash,
    ): TradingOrderTicket {
        if (! preg_match('/^0x[a-fA-F0-9]{64}$/', $feeTxHash)) {
            throw new RuntimeException(self::ERR_FEE_TX_REQUIRED);
        }

        $existing = TradingOrderTicket::where('fee_tx_hash', strtolower($feeTxHash))->first();
        if ($existing) {
            return $existing;   // เรียกซ้ำจาก retry — คืนใบเดิม ไม่ออกใบใหม่
        }

        return TradingOrderTicket::create([
            'wallet_address' => $this->credits->normalize($wallet),
            'pair' => strtoupper($pair),
            'side' => $side,
            'order_value_usd' => $orderValueUsd,
            'fee_method' => TradingOrderTicket::METHOD_ONCHAIN,
            'fee_amount' => $feeAmount,
            'fee_currency' => strtoupper($feeCurrency),
            'fee_tx_hash' => strtolower($feeTxHash),
            'status' => TradingOrderTicket::STATUS_ISSUED,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);
    }

    /** ใช้ตั๋วแล้ว — ไม้ลงจริงบนเชน */
    public function consume(string $uuid, string $wallet, ?string $orderTxHash = null): TradingOrderTicket
    {
        return DB::transaction(function () use ($uuid, $wallet, $orderTxHash) {
            $ticket = $this->lockTicket($uuid, $wallet);

            if ($ticket->status === TradingOrderTicket::STATUS_CONSUMED) {
                return $ticket;   // เรียกซ้ำ — ไม่ถือเป็น error
            }

            if ($ticket->status !== TradingOrderTicket::STATUS_ISSUED) {
                throw new RuntimeException(self::ERR_TICKET_USED);
            }

            $ticket->update([
                'status' => TradingOrderTicket::STATUS_CONSUMED,
                'order_tx_hash' => $orderTxHash ? strtolower($orderTxHash) : null,
            ]);

            return $ticket->fresh();
        });
    }

    /**
     * คืนค่าบริการของตั๋วที่ไม่ได้ใช้.
     *
     * จ่ายด้วยคลัง TPIX → คืนเข้าคลังเต็มจำนวนทันที (บัญชีในระบบเรา ไม่มีค่าแก๊ส)
     * จ่ายเป็นเหรียญบนเชน → บันทึกยอดที่ต้องคืนหลังหักค่าแก๊ส แล้วรอโอนคืนจริง
     *   (การโอนคืนเป็นธุรกรรมที่เราจ่ายแก๊สเอง จึงหักออกจากยอดคืนตามที่แจ้งไว้)
     */
    public function refund(string $uuid, string $wallet, string $reason = ''): TradingOrderTicket
    {
        return DB::transaction(function () use ($uuid, $wallet, $reason) {
            $ticket = $this->lockTicket($uuid, $wallet);

            if ($ticket->status === TradingOrderTicket::STATUS_REFUNDED) {
                return $ticket;   // เรียกซ้ำ — คืนไปแล้ว
            }

            if ($ticket->status === TradingOrderTicket::STATUS_CONSUMED) {
                throw new RuntimeException(self::ERR_TICKET_USED);
            }

            $fee = (float) $ticket->fee_amount;

            if ($ticket->refundsInFull()) {
                $this->credits->refund($wallet, $fee, $ticket->uuid, ['reason' => $reason]);

                $ticket->update([
                    'status' => TradingOrderTicket::STATUS_REFUNDED,
                    'refund_amount' => $fee,
                    'gas_deducted' => 0,
                    'note' => $reason ?: null,
                ]);

                return $ticket->fresh();
            }

            // จ่ายเป็นเหรียญบนเชน — คืนโดยหักค่าแก๊ส
            $gas = $this->refundGasFee();
            $refundable = max(0.0, round($fee - $gas, 8));

            $ticket->update([
                'status' => TradingOrderTicket::STATUS_REFUNDED,
                'refund_amount' => $refundable,
                'gas_deducted' => min($gas, $fee),
                'note' => trim($reason.' · รอโอนคืน (หักค่าแก๊สแล้ว)'),
            ]);

            Log::info('ค่าบริการบนเชนรอโอนคืน', [
                'ticket' => $ticket->uuid,
                'wallet' => $wallet,
                'refund_amount' => $refundable,
                'gas_deducted' => $gas,
            ]);

            return $ticket->fresh();
        });
    }

    /**
     * ปิดตั๋วที่หมดอายุแล้วคืนเงิน.
     *
     * ตั๋วค้างสถานะ issued คือเงินที่ถูกหักไปแล้วแต่ไม่มีใครได้อะไรเลย —
     * ต้องมีตัวเก็บกวาด ไม่ใช่รอให้ผู้ใช้มาทวง
     *
     * @return int จำนวนตั๋วที่ปิด
     */
    public function expireStale(int $limit = 200): int
    {
        $stale = TradingOrderTicket::issued()
            ->where('expires_at', '<', now())
            ->limit($limit)
            ->get();

        $closed = 0;

        foreach ($stale as $ticket) {
            try {
                $this->refund($ticket->uuid, $ticket->wallet_address, 'ตั๋วหมดอายุ ไม่ได้วางไม้');
                $closed++;
            } catch (\Throwable $e) {
                Log::warning('ปิดตั๋วหมดอายุไม่สำเร็จ', ['ticket' => $ticket->uuid, 'error' => $e->getMessage()]);
            }
        }

        return $closed;
    }

    /** ค่าแก๊สที่หักจากยอดคืนของการจ่ายบนเชน (หน่วยเดียวกับค่าบริการ) */
    public function refundGasFee(): float
    {
        return max(0.0, (float) SiteSetting::get('trading', 'refund_gas_fee', 0));
    }

    private function lockTicket(string $uuid, string $wallet): TradingOrderTicket
    {
        $ticket = TradingOrderTicket::where('uuid', $uuid)
            ->where('wallet_address', $this->credits->normalize($wallet))
            ->lockForUpdate()
            ->first();

        if (! $ticket) {
            throw new RuntimeException(self::ERR_TICKET_NOT_FOUND);
        }

        return $ticket;
    }
}
