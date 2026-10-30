<?php

namespace App\Services\Validator;

use InvalidArgumentException;

/**
 * Rlp — ตัวถอด RLP แบบเล็กที่สุดที่ใช้อ่าน extraData ของ IBFT.
 *
 * คืนค่าเป็น "ไบต์ดิบ" (string) สำหรับ item และ array สำหรับ list
 * ไม่มีตัวเข้ารหัส — ฝั่งเว็บไม่เคยต้องสร้าง RLP เอง
 *
 * Developed by Xman Studio
 */
final class Rlp
{
    /**
     * ถอดทั้งก้อน — ต้องกินไบต์พอดี ไม่เหลือเศษ (เศษ = ข้อมูลเพี้ยน ไม่ใช่ของที่ควรเดาต่อ).
     *
     * @return string|array<int,mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $bytes): string|array
    {
        [$value, $used] = self::decodeAt($bytes, 0);
        if ($used !== strlen($bytes)) {
            throw new InvalidArgumentException('RLP: มีไบต์เหลือหลังจบข้อมูล');
        }

        return $value;
    }

    /**
     * @return array{0:string|array<int,mixed>,1:int} [ค่า, จำนวนไบต์ที่ใช้]
     */
    private static function decodeAt(string $b, int $p): array
    {
        $len = strlen($b);
        if ($p >= $len) {
            throw new InvalidArgumentException('RLP: อ่านเกินท้ายข้อมูล');
        }

        $x = ord($b[$p]);

        if ($x <= 0x7F) {
            return [$b[$p], 1];
        }

        if ($x <= 0xBF) {
            [$ds, $dl] = self::payload($b, $p, $x, 0x80, 0xB7);

            return [substr($b, $ds, $dl), $ds - $p + $dl];
        }

        [$ds, $dl] = self::payload($b, $p, $x, 0xC0, 0xF7);
        $items = [];
        $q = $ds;
        while ($q < $ds + $dl) {
            [$item, $used] = self::decodeAt($b, $q);
            $items[] = $item;
            $q += $used;
        }
        if ($q !== $ds + $dl) {
            throw new InvalidArgumentException('RLP: ความยาวลิสต์ไม่ตรงกับสมาชิก');
        }

        return [$items, $ds - $p + $dl];
    }

    /**
     * @return array{0:int,1:int} [จุดเริ่มข้อมูล, ความยาวข้อมูล]
     */
    private static function payload(string $b, int $p, int $x, int $shortBase, int $longBase): array
    {
        if ($x <= $longBase) {
            $ds = $p + 1;
            $dl = $x - $shortBase;
        } else {
            $n = $x - $longBase;
            if ($n > 4) {
                throw new InvalidArgumentException('RLP: ความยาวใหญ่เกินจริง');
            }
            $dl = 0;
            for ($i = 1; $i <= $n; $i++) {
                if ($p + $i >= strlen($b)) {
                    throw new InvalidArgumentException('RLP: หัวข้อมูลขาด');
                }
                $dl = ($dl << 8) | ord($b[$p + $i]);
            }
            $ds = $p + 1 + $n;
        }

        if ($ds + $dl > strlen($b)) {
            throw new InvalidArgumentException('RLP: ข้อมูลสั้นกว่าที่หัวบอก');
        }

        return [$ds, $dl];
    }
}
