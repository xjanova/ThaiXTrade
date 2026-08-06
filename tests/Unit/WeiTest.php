<?php

namespace Tests\Unit;

use App\Support\Wei;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Wei — การแปลงค่า on-chain ต้องแม่นทุกหลัก
 *
 * เทสต์ชุดนี้มีไว้กันการถอยหลังกลับไปใช้ ext-gmp (ซึ่งไม่มีบนเซิร์ฟเวอร์)
 * และกันการเผลอแปลงเป็น int/float ระหว่างทาง — 1 TPIX = 1e18 wei
 * ดังนั้นแค่ 10 TPIX ก็เกิน PHP_INT_MAX แล้ว
 */
class WeiTest extends TestCase
{
    public function test_hex_to_int_handles_zero_and_empty_forms(): void
    {
        $this->assertSame('0', Wei::hexToInt(null));
        $this->assertSame('0', Wei::hexToInt(''));
        $this->assertSame('0', Wei::hexToInt('0x'));
        $this->assertSame('0', Wei::hexToInt('0x0'));
        $this->assertSame('0', Wei::hexToInt('0x0000'));
    }

    public function test_hex_to_int_small_values(): void
    {
        $this->assertSame('255', Wei::hexToInt('0xff'));
        $this->assertSame('255', Wei::hexToInt('0xFF'));
        $this->assertSame('255', Wei::hexToInt('ff'));      // ไม่มี prefix
        $this->assertSame('4095', Wei::hexToInt('0xfff'));  // ความยาวคี่
        $this->assertSame('4289', Wei::hexToInt('0x10c1')); // chainId ของ TPIX
    }

    public function test_hex_to_int_beyond_php_int_max(): void
    {
        // 1 TPIX = 1e18 wei
        $this->assertSame('1000000000000000000', Wei::hexToInt('0xde0b6b3a7640000'));

        // 1,710,000,000 TPIX (กระเป๋าคลังใบใหญ่สุด) — เกิน PHP_INT_MAX ไปมาก
        $expected = '1710000000000000000000000000';
        $hex = '0x'.self::decToHex($expected);
        $this->assertSame($expected, Wei::hexToInt($hex));

        // ยอดรวมทั้งเชน 7,000,000,000 TPIX
        $total = '7000000000000000000000000000';
        $this->assertSame($total, Wei::hexToInt('0x'.self::decToHex($total)));
    }

    public function test_hex_to_int_chunk_boundaries(): void
    {
        // hexToInt แบ่ง 7 หลักต่อรอบ — ทดสอบรอบต่อรอบว่าไม่หล่นหาย
        foreach ([6, 7, 8, 13, 14, 15, 63, 64] as $len) {
            $hex = str_repeat('f', $len);
            $expected = self::hexToIntReference($hex);
            $this->assertSame($expected, Wei::hexToInt('0x'.$hex), "ความยาว {$len} หลักไม่ตรง");
        }
    }

    public function test_hex_to_int_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Wei::hexToInt('0xzzzz');
    }

    public function test_format_trims_trailing_zeros(): void
    {
        $this->assertSame('1', Wei::format('1000000000000000000'));
        $this->assertSame('1.5', Wei::format('1500000000000000000'));
        $this->assertSame('0.000000000000000001', Wei::format('1'));
        $this->assertSame('0', Wei::format('0'));
        $this->assertSame('1710000000', Wei::format('1710000000000000000000000000'));
    }

    public function test_format_respects_custom_decimals(): void
    {
        $this->assertSame('1.5', Wei::format('150', 2));
        $this->assertSame('150', Wei::format('150', 0));
        $this->assertSame('1.234567', Wei::format('1234567', 6)); // USDT-style
    }

    public function test_format_handles_negative(): void
    {
        $this->assertSame('-1.5', Wei::format('-1500000000000000000'));
        $this->assertSame('0', Wei::format('-0'));
    }

    public function test_hex_to_whole_units_floors_like_gmp_div_q(): void
    {
        // 1.999... TPIX ต้องได้ '1' ไม่ใช่ '2' — ของเดิม gmp_div_q ปัดลง
        $almostTwo = bcsub('2000000000000000000', '1');
        $this->assertSame('1', Wei::hexToWholeUnits('0x'.self::decToHex($almostTwo)));

        $this->assertSame('0', Wei::hexToWholeUnits('0x0'));
        $this->assertSame('10000000', Wei::hexToWholeUnits('0x'.self::decToHex('10000000000000000000000000')));
    }

    public function test_to_wei_round_trip(): void
    {
        foreach (['0', '1', '1.5', '0.000000000000000001', '7000000000'] as $amount) {
            $this->assertSame($amount, Wei::format(Wei::toWei($amount)), "ไป-กลับไม่ตรงที่ {$amount}");
        }
    }

    public function test_to_wei_truncates_instead_of_rounding(): void
    {
        // ทศนิยมเกิน 18 หลักต้องถูกตัดทิ้ง ไม่ปัดขึ้น — กันสร้างเงินเพิ่มจากการปัด
        $this->assertSame('1000000000000000000', Wei::toWei('1.0000000000000000009'));
        $this->assertSame('1', Wei::toWei('0.0000000000000000019'));
    }

    public function test_to_wei_accepts_partial_forms(): void
    {
        $this->assertSame('1000000000000000000', Wei::toWei('1.'));
        $this->assertSame('500000000000000000', Wei::toWei('.5'));
        $this->assertSame('0', Wei::toWei('0.0'));
    }

    public function test_to_wei_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Wei::toWei('1.2.3');
    }

    public function test_compare_and_sum_stay_exact(): void
    {
        $a = '1000000000000000000';
        $b = '1000000000000000001';

        $this->assertSame(-1, Wei::compare($a, $b));
        $this->assertSame(0, Wei::compare($a, $a));
        $this->assertSame(1, Wei::compare($b, $a));

        // ผลรวมคลังทั้ง 6 ใบ + validator 4 ตัว = 7,000,000,000 TPIX พอดี
        $pools = [
            '1400000000000000000000000000',
            '1710000000000000000000000000',
            '700000000000000000000000000',
            '700000000000000000000000000',
            '1050000000000000000000000000',
            '1400000000000000000000000000',
        ];
        $validators = array_fill(0, 4, '10000000000000000000000000');

        $this->assertSame(
            '7000000000000000000000000000',
            Wei::sum(array_merge($pools, $validators)),
        );
    }

    /** อ้างอิงอิสระ: แปลงทีละหลัก ไม่แบ่ง chunk — ใช้ตรวจว่า chunk ไม่พลาด */
    private static function hexToIntReference(string $hex): string
    {
        $result = '0';
        foreach (str_split(strtolower($hex)) as $digit) {
            $result = bcadd(bcmul($result, '16', 0), (string) hexdec($digit), 0);
        }

        return $result;
    }

    private static function decToHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $remainder = bcmod($dec, '16');
            $hex = dechex((int) $remainder).$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }
}
