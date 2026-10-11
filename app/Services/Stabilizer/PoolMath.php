<?php

namespace App\Services\Stabilizer;

use App\Services\TpixDexService;

/**
 * คณิตของพูล UniV2 (x·y=k ค่าธรรมเนียม 0.3%) สำหรับคำนวณขนาดไม้ของ Stability Reserve.
 *
 * หลักคิด: บอทไม่ได้ "ตั้งราคา" — มันแค่คำนวณว่าต้องใส่เงินเท่าไรเพื่อดันราคากลับเข้าหา
 * ขอบกรอบ โดยไม่เกินเพดานผลกระทบต่อราคาต่อไม้ (max impact) แล้วตรวจซ้ำด้วยเลขจำนวนเต็ม
 *
 * หน่วย: reserve/จำนวนเป็น wei สตริง (TPIX 18 ทศนิยม, USDT ตาม decimals ของสัญญา)
 *        ราคา = USDT ต่อ 1 TPIX เป็นสตริงทศนิยม 18 ตำแหน่ง
 *
 * Developed by Xman Studio
 */
final class PoolMath
{
    private const SCALE = 18;

    /** ราคากลางของพูล — null ถ้าพูลว่าง */
    public static function price(string $reserveTpix, string $reserveUsdt, int $usdtDecimals): ?string
    {
        return TpixDexService::midPrice($reserveTpix, $reserveUsdt, 18, $usdtDecimals);
    }

    /**
     * ผลของการซื้อ TPIX ด้วย USDT จำนวน $usdtIn (wei).
     *
     * @return array{out: string, price_after: ?string}
     */
    public static function quoteBuy(string $usdtIn, string $reserveTpix, string $reserveUsdt, int $usdtDecimals): array
    {
        $out = TpixDexService::amountOut($usdtIn, $reserveUsdt, $reserveTpix);
        $newTpix = bcsub($reserveTpix, $out, 0);
        $newUsdt = bcadd($reserveUsdt, $usdtIn, 0);

        return ['out' => $out, 'price_after' => self::price($newTpix, $newUsdt, $usdtDecimals)];
    }

    /**
     * ผลของการขาย TPIX จำนวน $tpixIn (wei) ได้ USDT.
     *
     * @return array{out: string, price_after: ?string}
     */
    public static function quoteSell(string $tpixIn, string $reserveTpix, string $reserveUsdt, int $usdtDecimals): array
    {
        $out = TpixDexService::amountOut($tpixIn, $reserveTpix, $reserveUsdt);
        $newTpix = bcadd($reserveTpix, $tpixIn, 0);
        $newUsdt = bcsub($reserveUsdt, $out, 0);

        return ['out' => $out, 'price_after' => self::price($newTpix, $newUsdt, $usdtDecimals)];
    }

    /**
     * USDT ที่ต้องใส่เพื่อให้ราคาขึ้นไป "ไม่เกิน" $targetPrice.
     *
     * แก้สมการ (y+Δ)(y+0.997Δ) = r·y² หา Δ/y แบบปิด แล้วตรวจซ้ำด้วยเลขจำนวนเต็ม —
     * ถ้าปัดเศษแล้วเกินเป้าแม้นิดเดียว ลดลงทีละ 0.5% จนผ่าน (ห้ามเกินขอบกรอบ)
     */
    public static function sizeBuy(string $reserveTpix, string $reserveUsdt, int $usdtDecimals, string $targetPrice): string
    {
        $price = self::price($reserveTpix, $reserveUsdt, $usdtDecimals);
        if ($price === null || bccomp($targetPrice, $price, self::SCALE) <= 0) {
            return '0';
        }

        $ratio = (float) bcdiv($targetPrice, $price, self::SCALE);
        $fraction = self::solveFraction($ratio);
        $amount = self::fractionOf($reserveUsdt, $fraction);

        return self::shrinkUntil($amount, fn (string $a) => ($q = self::quoteBuy($a, $reserveTpix, $reserveUsdt, $usdtDecimals))['price_after'] !== null
            && bccomp($q['price_after'], $targetPrice, self::SCALE) <= 0);
    }

    /**
     * TPIX ที่ต้องขายเพื่อให้ราคาลงมา "ไม่ต่ำกว่า" $targetPrice.
     *
     * ฝั่งขาย: ราคาใหม่/ราคาเดิม = x² / ((x+Δ)(x+0.997Δ)) → สมการเดียวกันกับฝั่งซื้อด้วย R = 1/r
     */
    public static function sizeSell(string $reserveTpix, string $reserveUsdt, int $usdtDecimals, string $targetPrice): string
    {
        $price = self::price($reserveTpix, $reserveUsdt, $usdtDecimals);
        if ($price === null || bccomp($targetPrice, '0', self::SCALE) <= 0 || bccomp($targetPrice, $price, self::SCALE) >= 0) {
            return '0';
        }

        $ratio = (float) bcdiv($price, $targetPrice, self::SCALE);
        $fraction = self::solveFraction($ratio);
        $amount = self::fractionOf($reserveTpix, $fraction);

        return self::shrinkUntil($amount, fn (string $a) => ($q = self::quoteSell($a, $reserveTpix, $reserveUsdt, $usdtDecimals))['price_after'] !== null
            && bccomp($q['price_after'], $targetPrice, self::SCALE) >= 0);
    }

    /** เปอร์เซ็นต์การเปลี่ยนราคา (บวก = ขึ้น) */
    public static function changePct(string $from, string $to): float
    {
        if (bccomp($from, '0', self::SCALE) <= 0) {
            return 0.0;
        }

        return (float) bcmul(bcsub(bcdiv($to, $from, self::SCALE), '1', self::SCALE), '100', 6);
    }

    /** ตัวเลขทศนิยม (float/int จาก config) → สตริงสำหรับ bcmath โดยไม่มีรูป 1.0E-5 */
    public static function dec(int|float|string $value, int $places = 8): string
    {
        if (is_string($value)) {
            return $value;
        }

        $text = rtrim(rtrim(sprintf('%.'.$places.'F', $value), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /** ราคา × (1 ± pct/100) */
    public static function shift(string $price, float $pct): string
    {
        return bcmul($price, bcadd('1', bcdiv(self::dec($pct), '100', self::SCALE), self::SCALE), self::SCALE);
    }

    // ── ภายใน ────────────────────────────────────────────────────────────

    /** 0.997f² + 1.997f + (1 − ratio) = 0 → รากบวก */
    private static function solveFraction(float $ratio): float
    {
        if ($ratio <= 1.0) {
            return 0.0;
        }

        $a = 0.997;
        $b = 1.997;
        $c = 1.0 - $ratio;
        $disc = $b * $b - 4 * $a * $c;

        return $disc <= 0 ? 0.0 : max(0.0, (-$b + sqrt($disc)) / (2 * $a));
    }

    private static function fractionOf(string $reserve, float $fraction): string
    {
        if ($fraction <= 0) {
            return '0';
        }

        return bcmul($reserve, sprintf('%.15F', $fraction), 0);
    }

    /** ลดทีละ 0.5% จนผ่านเงื่อนไข (สูงสุด 200 รอบ) — ไม่ผ่านเลยคืน 0 */
    private static function shrinkUntil(string $amount, callable $passes): string
    {
        for ($i = 0; $i < 200 && bccomp($amount, '0', 0) > 0; $i++) {
            if ($passes($amount)) {
                return $amount;
            }
            $amount = bcdiv(bcmul($amount, '995', 0), '1000', 0);
        }

        return '0';
    }
}
