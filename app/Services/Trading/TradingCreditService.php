<?php

namespace App\Services\Trading;

use App\Models\SiteSetting;
use App\Models\TradingCredit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TPIX TRADE — คลัง TPIX ที่ผู้ใช้เติมไว้ล่วงหน้าสำหรับจ่ายค่าบริการวางไม้.
 *
 * โครงเดียวกับ AiBotService::record ที่ใช้งานจริงมาแล้ว — ตั้งใจให้เหมือนกัน
 * เพราะเป็นแบบที่พิสูจน์แล้วว่าทนต่อ retry และคำขอคู่ขนาน ไม่ใช่คิดใหม่ให้ต่าง
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ เงินของผู้ใช้ — ทุกการเขียนต้องอยู่ใต้ transaction + lockForUpdate
 * ═══════════════════════════════════════════════════════════════════════════
 * อ่านยอดแล้วค่อยเขียนโดยไม่ล็อก = คำขอสองใบพร้อมกันอ่านยอดเดียวกัน แล้วต่าง
 * คนต่างหักจากยอดนั้น ผลคือหักได้เกินที่มี (ยอดติดลบ หรือได้ของฟรีหนึ่งไม้)
 *
 * Developed by Xman Studio.
 */
class TradingCreditService
{
    public const ERR_INSUFFICIENT = 'INSUFFICIENT_CREDIT';

    public const ERR_BELOW_MINIMUM = 'BELOW_MINIMUM_TOPUP';

    /** ยอดคงเหลือของกระเป๋านี้ */
    public function balanceFor(string $wallet): float
    {
        $latest = TradingCredit::where('wallet_address', $this->normalize($wallet))
            ->latest('id')
            ->first();

        return (float) ($latest->balance_after ?? 0);
    }

    /** ยอดพอจ่ายค่าบริการจำนวนนี้ไหม */
    public function hasEnough(string $wallet, float $amount): bool
    {
        return $this->balanceFor($wallet) >= $amount;
    }

    /**
     * เขียนรายการเดินบัญชี 1 แถวแบบ atomic.
     *
     * reference ต้องไม่ซ้ำต่อกระเป๋า (มี unique index รองรับ) — เรียกซ้ำจาก retry
     * ของ client แล้วไม่หัก/เพิ่มสองรอบ คืนแถวเดิมแทน
     *
     * @param  float  $amount  บวก = เพิ่ม, ลบ = ตัด
     *
     * @throws RuntimeException เมื่อยอดจะติดลบ
     */
    public function record(string $wallet, string $type, float $amount, string $reference, array $meta = []): TradingCredit
    {
        $wallet = $this->normalize($wallet);

        return DB::transaction(function () use ($wallet, $type, $amount, $reference, $meta) {
            $existing = TradingCredit::where('wallet_address', $wallet)
                ->where('reference', $reference)
                ->first();

            if ($existing) {
                return $existing;
            }

            $current = (float) (TradingCredit::where('wallet_address', $wallet)
                ->lockForUpdate()
                ->latest('id')
                ->first()->balance_after ?? 0);

            $next = round($current + $amount, 8);

            if ($next < 0) {
                throw new RuntimeException(self::ERR_INSUFFICIENT);
            }

            try {
                return TradingCredit::create([
                    'wallet_address' => $wallet,
                    'type' => $type,
                    'amount' => round($amount, 8),
                    'balance_after' => $next,
                    'reference' => $reference,
                    'meta' => $meta ?: null,
                ]);
            } catch (QueryException $e) {
                // ชน unique index จากคำขอคู่ขนาน — อีกฝั่งเขียนสำเร็จแล้ว ใช้ของเขา
                $row = TradingCredit::where('wallet_address', $wallet)
                    ->where('reference', $reference)
                    ->first();

                if ($row) {
                    return $row;
                }

                throw $e;
            }
        });
    }

    /** หักค่าบริการของตั๋วใบหนึ่ง */
    public function charge(string $wallet, float $amount, string $ticketUuid, array $meta = []): TradingCredit
    {
        return $this->record($wallet, TradingCredit::TYPE_CHARGE, -abs($amount), "ticket:{$ticketUuid}", $meta);
    }

    /**
     * คืนค่าบริการของตั๋วที่ไม่ได้ใช้.
     *
     * reference คนละตัวกับตอนหัก — ถ้าใช้ตัวเดียวกันจะชน unique index แล้วคืนแถว
     * ของการหักกลับมา กลายเป็น "คืนเงินสำเร็จ" ทั้งที่ไม่ได้คืนอะไรเลย
     */
    public function refund(string $wallet, float $amount, string $ticketUuid, array $meta = []): TradingCredit
    {
        return $this->record($wallet, TradingCredit::TYPE_REFUND, abs($amount), "refund:{$ticketUuid}", $meta);
    }

    /** เติมเครดิตจากธุรกรรมบนเชน — 1 ธุรกรรมลงได้ครั้งเดียว */
    public function topup(string $wallet, float $amount, string $txHash, array $meta = []): TradingCredit
    {
        return $this->record($wallet, TradingCredit::TYPE_TOPUP, abs($amount), 'topup:'.strtolower($txHash), $meta);
    }

    /** ยอดเติมขั้นต่ำที่แอดมินตั้งไว้ */
    public function minimumTopup(): float
    {
        return max(0.0, (float) SiteSetting::get('trading', 'tpix_min_topup', 10));
    }

    /** ประวัติเดินบัญชีล่าสุด */
    public function history(string $wallet, int $limit = 30): array
    {
        return TradingCredit::where('wallet_address', $this->normalize($wallet))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (TradingCredit $row) => [
                'type' => $row->type,
                'amount' => (float) $row->amount,
                'balance_after' => (float) $row->balance_after,
                'reference' => $row->reference,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function normalize(string $wallet): string
    {
        return strtolower(trim($wallet));
    }
}
