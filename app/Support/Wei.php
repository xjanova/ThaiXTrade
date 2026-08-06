<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Wei — big-integer conversions for on-chain values, built on BCMath only.
 *
 * ทำไมไม่ใช้ GMP:
 * โค้ดเดิมเรียก gmp_init()/gmp_strval()/gmp_div_q() กระจายอยู่ 7 ไฟล์ แต่
 * **เซิร์ฟเวอร์โปรดักชันไม่ได้ติดตั้ง ext-gmp** (ตรวจแล้วทั้ง PHP 8.2 และ 8.3
 * เมื่อ 6 ส.ค. 2026 — มีแต่ bcmath) การเรียก gmp_* จึงโยน \Error ซึ่ง
 * `catch (\Exception)` ที่ล้อมอยู่ **จับไม่ได้** (Error กับ Exception เป็นพี่น้องกัน
 * ใต้ Throwable) กลายเป็น fatal กลางคัน
 *
 * บั๊กนี้ซ่อนตัวได้นานเพราะ hexToDecimal() เดิม return '0' ออกไปก่อนถ้า hex เป็น
 * '0x' หรือ '0x0' — ยอดศูนย์จึงดู "ทำงานได้" ส่วนยอดที่ไม่ใช่ศูนย์เท่านั้นที่ระเบิด
 * และเชน TPIX เก่าว่างเปล่ามาตลอดจึงไม่เคยมีใครเจอ
 *
 * ทุกเมธอดรับ-คืนเป็น **string** เสมอ ห้ามแปลงเป็น int/float ระหว่างทาง
 * เพราะ 1 TPIX = 1e18 wei ซึ่งเกิน PHP_INT_MAX (~9.2e18) ตั้งแต่ 10 TPIX ขึ้นไป
 */
final class Wei
{
    /** จำนวนทศนิยมมาตรฐานของ EVM native token */
    public const DECIMALS = 18;

    /** 10^18 ในรูป string — ใช้บ่อยพอที่จะ hardcode ไว้ */
    private const ONE_ETHER = '1000000000000000000';

    /**
     * Convert a hex quantity (e.g. eth_getBalance result) to a decimal integer string.
     *
     * รับได้ทั้งมี/ไม่มี prefix 0x, ตัวพิมพ์เล็ก/ใหญ่, ความยาวคี่, และ '0x' เปล่า
     */
    public static function hexToInt(?string $hex): string
    {
        $hex = strtolower(trim((string) $hex));

        if ($hex === '' || $hex === '0x') {
            return '0';
        }

        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        $hex = ltrim($hex, '0');

        if ($hex === '') {
            return '0';
        }

        if (! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Wei::hexToInt() ได้ค่าที่ไม่ใช่เลขฐานสิบหก');
        }

        // แปลงทีละ 7 หลัก (16^7 = 268435456 ยังปลอดภัยใน 32-bit int)
        // เพื่อลดจำนวนรอบ bcmath ลง ~7 เท่าเทียบกับไล่ทีละหลัก
        $result = '0';
        foreach (str_split($hex, 7) as $chunk) {
            $shift = bcpow('16', (string) strlen($chunk), 0);
            $result = bcadd(bcmul($result, $shift, 0), (string) hexdec($chunk), 0);
        }

        return $result;
    }

    /**
     * Format a wei integer string as a human-readable decimal string.
     *
     * ตัดศูนย์ท้ายทศนิยมออก ('1.500' -> '1.5', '1.000' -> '1')
     * ไม่ปัดเศษ ไม่มีคอมมาคั่นหลัก — เอาไว้แสดงผล/เทียบค่าแบบตรงตัว
     */
    public static function format(string $wei, int $decimals = self::DECIMALS): string
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('Wei::format() decimals ติดลบไม่ได้');
        }

        $wei = trim($wei);
        $negative = str_starts_with($wei, '-');
        $digits = ltrim($wei, '+-');
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return '0';
        }

        if (! ctype_digit($digits)) {
            throw new InvalidArgumentException('Wei::format() ได้ค่าที่ไม่ใช่จำนวนเต็ม');
        }

        if ($decimals === 0) {
            return ($negative ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        $intPart = substr($digits, 0, -$decimals);
        $fracPart = rtrim(substr($digits, -$decimals), '0');

        $out = $fracPart === '' ? $intPart : $intPart.'.'.$fracPart;

        return ($negative ? '-' : '').$out;
    }

    /**
     * Convert a hex quantity straight to a human-readable decimal string.
     */
    public static function hexToDecimal(?string $hex, int $decimals = self::DECIMALS): string
    {
        return self::format(self::hexToInt($hex), $decimals);
    }

    /**
     * Convert a hex quantity to whole units, discarding the fraction.
     *
     * เทียบเท่าของเดิม `gmp_div_q(gmp_init($hex,16), gmp_init('1e18'))`
     * คือ **หารปัดลง** ไม่ใช่ปัดใกล้สุด — 1.999 TPIX ได้ '1'
     */
    public static function hexToWholeUnits(?string $hex, int $decimals = self::DECIMALS): string
    {
        $divisor = $decimals === self::DECIMALS
            ? self::ONE_ETHER
            : bcpow('10', (string) $decimals, 0);

        return bcdiv(self::hexToInt($hex), $divisor, 0);
    }

    /**
     * Convert a human-readable amount ('1.5') to a wei integer string.
     *
     * ทศนิยมที่เกิน $decimals จะถูก **ตัดทิ้ง** ไม่ปัด — เพื่อไม่ให้เผลอสร้างเงิน
     * เพิ่มจากการปัดขึ้นตอนคำนวณยอดโอน
     */
    public static function toWei(string $amount, int $decimals = self::DECIMALS): string
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');

        // ต้องมีตัวเลขอย่างน้อยหนึ่งตัว — '.' เปล่า ๆ หรือสตริงว่างไม่ใช่จำนวนเงิน
        if (! preg_match('/^(\d+(\.\d*)?|\.\d+)$/', $amount)) {
            throw new InvalidArgumentException('Wei::toWei() ได้ค่าที่ไม่ใช่ตัวเลข');
        }

        [$intPart, $fracPart] = array_pad(explode('.', $amount, 2), 2, '');

        $fracPart = substr(str_pad($fracPart, $decimals, '0'), 0, $decimals);
        $digits = ltrim($intPart.$fracPart, '0');

        if ($digits === '') {
            return '0';
        }

        return ($negative ? '-' : '').$digits;
    }

    /**
     * Compare two wei integer strings. Returns -1, 0 or 1.
     */
    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }

    /**
     * Sum a list of wei integer strings without ever touching float.
     */
    public static function sum(array $weiValues): string
    {
        $total = '0';
        foreach ($weiValues as $value) {
            $total = bcadd($total, (string) $value, 0);
        }

        return $total;
    }
}
