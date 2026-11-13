<?php

namespace App\Services\AiBot\Copy;

use App\Models\AiCopyFee;
use App\Models\TradingCredit;
use App\Services\TpixDexService;
use App\Services\Trading\TradingCreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — เก็บค่าบริการ Copy Trade จากคลัง TPIX ที่ผู้ตามเติมไว้ แล้วลงส่วนของมาสเตอร์ให้มาสเตอร์.
 *
 * เจ้าของสั่ง 2026-11-13: "หักจากยอดเลย ใครไม่พอก็กดตามก๊อปเทรดไม่ได้ เตือนด้วย"
 *
 * คลังที่ใช้คือคลังเดียวกับค่าบริการวางไม้ (trading_credits ผ่าน TradingCreditService) — ผู้ใช้เติม TPIX ที่เดียว
 * ไม่มีกระเป๋าเงินชุดที่สอง และทุกการเขียนยอดยังอยู่ใต้ transaction + lockForUpdate ของบริการนั้น
 *
 *   ผู้ตาม    ถูกหัก fee_tpix            reference  copyfee:<id>      (type charge)
 *   มาสเตอร์  ได้ master_tpix            reference  copyincome:<id>   (type bonus)
 *   แพลตฟอร์ม ได้ส่วนต่าง — เป็น TPIX ที่ผู้ตามจ่ายเข้าคลังของเว็บไปแล้วตอนเติม ไม่ต้องมีรายการโอน
 *
 * reference ผูกกับรหัสรายการค่าบริการ → ไล่เก็บซ้ำกี่รอบ ไม้หนึ่งไม้ก็ถูกหักครั้งเดียว จ่ายมาสเตอร์ครั้งเดียว
 *
 * ⚠️ ส่วนของมาสเตอร์ลงเป็นยอดในคลัง TPIX ของมาสเตอร์บนเว็บ — ยังไม่มีรางถอนออกเชน (ต้องใช้กระเป๋ากลางเซ็นจ่าย
 *    เป็นเส้นทางเงินจริงที่เจ้าของต้องตัดสินแยก)
 *
 * Developed by Xman Studio.
 */
class FeeCollector
{
    /** ยอด TPIX ที่เติมไว้ไม่พอสำหรับเริ่มตาม / มีค่าบริการค้างจ่าย */
    public const ERR_BALANCE_LOW = 'COPY_FEE_BALANCE_LOW';

    public function __construct(private readonly TradingCreditService $credits) {}

    /** เปิดเก็บค่าบริการจริงอยู่ไหม — ปิด = ลงบัญชีให้เห็นเฉยๆ ไม่มีการหัก ไม่มีด่านยอดคงเหลือ */
    public function collecting(): bool
    {
        return (bool) config('aibot.copy_trade.fees.collect', false);
    }

    public function balance(string $wallet): float
    {
        return $this->credits->balanceFor($wallet);
    }

    /** ราคา TPIX เป็น USD จากพูลของเราเอง — ไม่มีราคา = null (ไม่เดาราคา) */
    public function tpixUsdPrice(): ?float
    {
        try {
            $price = app(TpixDexService::class)->tpixUsdPrice();

            return $price !== null && $price > 0 ? (float) $price : null;
        } catch (\Throwable $e) {
            Log::warning('copy trade: อ่านราคา TPIX ไม่ได้', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** ยอดขั้นต่ำตายตัว — ต่ำกว่านี้บอทผู้ตามไม่เปิดไม้ใหม่ */
    public function minimumBalance(): float
    {
        return max(0.0, (float) config('aibot.copy_trade.fees.min_balance_tpix', 10));
    }

    /**
     * ยอดที่ต้องมีก่อนกดตาม = พอจ่ายค่าบริการของกำไร reserve_profit_pct% ของทุนที่ตั้งไว้ (ไม่ต่ำกว่ายอดขั้นต่ำ).
     *
     * ค่าบริการเกิดหลังได้กำไร จึงรู้ล่วงหน้าไม่ได้ว่าเท่าไหร่ — กันไว้เท่ากับกำไรก้อนแรกที่สมเหตุสมผล
     * ไม่มีราคา TPIX = ใช้ยอดขั้นต่ำตายตัว (ไม่เดาราคา)
     */
    public function requiredToFollow(float $capUsd, float $feeRatePct, ?float $tpixPrice = null): float
    {
        $minimum = $this->minimumBalance();
        $reservePct = max(0.0, (float) config('aibot.copy_trade.fees.reserve_profit_pct', 5));
        $tpixPrice ??= $this->tpixUsdPrice();

        if ($tpixPrice === null || $reservePct <= 0) {
            return $minimum;
        }

        return round(max($minimum, max(0.0, $capUsd) * $reservePct / 100 * $feeRatePct / 100 / $tpixPrice), 4);
    }

    /**
     * ค่าบริการที่ค้างจ่ายของกระเป๋านี้ (ทุกมาสเตอร์รวมกัน).
     *
     * @return array{count: int, usd: float, tpix: float} tpix นับเฉพาะรายการที่แปลงเป็น TPIX แล้ว
     */
    public function owed(string $wallet): array
    {
        $rows = AiCopyFee::where('follower_wallet', $wallet)->where('status', AiCopyFee::STATUS_ACCRUED)->get(['fee_usd', 'fee_tpix']);

        return [
            'count' => $rows->count(),
            'usd' => round((float) $rows->sum('fee_usd'), 2),
            'tpix' => round((float) $rows->sum('fee_tpix'), 4),
        ];
    }

    /**
     * สถานะคลังค่าบริการของกระเป๋านี้ — ใช้ทั้งด่านตอนกดตาม ด่านของบอทระหว่างตาม และคำเตือนบนจอ.
     *
     * @param  float|null  $required  ยอดที่ต้องมี (null = ใช้ยอดขั้นต่ำตายตัว)
     * @return array{collect: bool, ok: bool, balance: float, required: float, shortfall: float, owed: array{count: int, usd: float, tpix: float}}
     */
    public function gate(string $wallet, ?float $required = null): array
    {
        if (! $this->collecting()) {
            return ['collect' => false, 'ok' => true, 'balance' => 0.0, 'required' => 0.0, 'shortfall' => 0.0, 'owed' => ['count' => 0, 'usd' => 0.0, 'tpix' => 0.0]];
        }

        $balance = $this->balance($wallet);
        $owed = $this->owed($wallet);
        $required = round(($required ?? $this->minimumBalance()) + $owed['tpix'], 4);

        return [
            'collect' => true,
            'ok' => $owed['count'] === 0 && $balance >= $required,
            'balance' => round($balance, 4),
            'required' => $required,
            'shortfall' => round(max(0.0, $required - $balance), 4),
            'owed' => $owed,
        ];
    }

    /**
     * ไล่หักค่าบริการที่ค้างเก็บของกระเป๋านี้ เก่าสุดก่อน — ยอดไม่พอ = หยุด รายการที่เหลือค้างต่อ (เติมแล้วรอบหน้าเก็บเอง).
     *
     * หักทั้งรายการหรือไม่หักเลย: ไม่หักบางส่วน เพื่อให้ทุกแถวในคลังตรงกับค่าบริการหนึ่งรายการพอดี
     *
     * @return int จำนวนรายการที่เก็บสำเร็จรอบนี้
     */
    public function collect(string $wallet): int
    {
        if (! $this->collecting()) {
            return 0;
        }

        $ids = AiCopyFee::where('follower_wallet', $wallet)->where('status', AiCopyFee::STATUS_ACCRUED)->orderBy('id')->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $price = $this->tpixUsdPrice();
        $collected = 0;

        foreach ($ids as $id) {
            if (! $this->collectOne((int) $id, $price)) {
                break;
            }

            $collected++;
        }

        return $collected;
    }

    private function collectOne(int $feeId, ?float $price): bool
    {
        return DB::transaction(function () use ($feeId, $price) {
            $fee = AiCopyFee::whereKey($feeId)->lockForUpdate()->first();

            if (! $fee || $fee->status !== AiCopyFee::STATUS_ACCRUED) {
                return true;
            }

            // ตอนลงบัญชีไม่มีราคา TPIX — แปลงด้วยราคาตอนนี้ แล้วล็อกจำนวน TPIX ไว้กับรายการ (หนี้ไม่ลอยตามราคา)
            if ($fee->fee_tpix === null) {
                if ($price === null) {
                    return false;
                }

                $fee->update([
                    'tpix_usd_price' => $price,
                    'fee_tpix' => round($fee->fee_usd / $price, 8),
                    'master_tpix' => round($fee->master_usd / $price, 8),
                    'platform_tpix' => round($fee->platform_usd / $price, 8),
                ]);
            }

            $meta = ['source' => 'copy_trade', 'ai_copy_fee_id' => $fee->id, 'ai_copy_master_id' => $fee->ai_copy_master_id];

            if ($fee->fee_tpix > 0) {
                try {
                    $this->credits->record($fee->follower_wallet, TradingCredit::TYPE_CHARGE, -$fee->fee_tpix, "copyfee:{$fee->id}", $meta);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === TradingCreditService::ERR_INSUFFICIENT) {
                        return false;
                    }

                    throw $e;
                }
            }

            if ($fee->master_tpix > 0) {
                $this->credits->record($fee->master_wallet, TradingCredit::TYPE_BONUS, $fee->master_tpix, "copyincome:{$fee->id}", $meta);
            }

            $fee->update(['status' => AiCopyFee::STATUS_SETTLED, 'settled_at' => now()]);

            return true;
        });
    }
}
