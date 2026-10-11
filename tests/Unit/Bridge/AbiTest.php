<?php

namespace Tests\Unit\Bridge;

use App\Services\Bridge\Abi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ตัวเข้ารหัส ABI ของสะพาน — ค่าที่คาดหวังทุกตัวคำนวณด้วย ethers v6 (ไม่ได้คำนวณด้วยโค้ดที่กำลังทดสอบเอง).
 *
 * depositId ผิดหนึ่งบิต = สัญญาไม่รู้ว่าเคย mint แล้ว → ด่านกัน mint ซ้ำบนเชนไม่ทำงาน
 */
class AbiTest extends TestCase
{
    #[Test]
    public function deposit_id_matches_solidity_abi_encode(): void
    {
        // ethers: keccak256(abi.encode(uint256 56, bytes32 0x11…11, uint256 3))
        $this->assertSame(
            '0x81d5eb26e82634f1d4389d2d90b972963c3f96e37993582ecf6a5550ddfb443a',
            Abi::depositId(56, '0x'.str_repeat('11', 32), 3),
        );
    }

    #[Test]
    public function mint_calldata_matches_ethers(): void
    {
        $this->assertSame(
            '0x1e458bee000000000000000000000000a11ce00000000000000000000000000000000a11'
            .'000000000000000000000000000000000000000000000000000000000012d687'
            .str_repeat('22', 32),
            Abi::mintCalldata('0xa11ce00000000000000000000000000000000a11', '1234567', '0x'.str_repeat('22', 32)),
        );
        $this->assertSame('0x8456cb59', Abi::selector('pause()'));
    }

    #[Test]
    public function burned_topic_and_data_decode(): void
    {
        $this->assertSame('0xcebb26cae06a4ca4ef5d5c3a70761024f11664c019aa5df62461c72d11fdc9e5', Abi::topic(Abi::BURNED_EVENT));

        $data = '0x00000000000000000000000000000000000000000000000000000000002625a0'
            .'0000000000000000000000000000000000000000000000000000000000000040'
            .'000000000000000000000000000000000000000000000000000000000000002a'
            .'3078416243303030303030303030303030303030303030303030303030303030'
            .'3030303030303030303100000000000000000000000000000000000000000000';

        $this->assertSame(
            ['amount' => '2500000', 'target' => '0xAbC0000000000000000000000000000000000001'],
            Abi::decodeBurnData($data),
        );
        $this->assertNull(Abi::decodeBurnData('0x1234'));
    }

    #[Test]
    public function eighteen_decimals_are_truncated_to_six_and_the_dust_is_kept(): void
    {
        // 12.3456789 USDT บน BSC (18 ทศนิยม)
        $this->assertSame(['units' => '12345678', 'dust' => '900000000000'], Abi::toUnits6('12345678900000000000', 18));
        $this->assertSame(['units' => '5000000', 'dust' => '0'], Abi::toUnits6('5000000', 6));

        // ขากลับ: 6 → 18 คูณตรง ไม่มีเศษ
        $this->assertSame('12345678000000000000', Abi::fromUnits6('12345678', 18));
        $this->assertSame('12345678', Abi::fromUnits6('12345678', 6));
    }

    #[Test]
    public function addresses_are_validated_with_eip55_checksums(): void
    {
        $this->assertTrue(Abi::isValidAddress('0xf46131C82819d7621163F482b3fe88a228A7807c'));
        $this->assertTrue(Abi::isValidAddress('0xf46131c82819d7621163f482b3fe88a228a7807c'));
        // ตัวพิมพ์ผสมแต่ checksum ผิด = พิมพ์ผิด ห้ามจ่าย
        $this->assertFalse(Abi::isValidAddress('0xF46131C82819d7621163F482b3fe88a228A7807c'));
        $this->assertFalse(Abi::isValidAddress('0x0000000000000000000000000000000000000000'));
        $this->assertFalse(Abi::isValidAddress('TXYZ1234567890'));
        $this->assertSame('0xf46131C82819d7621163F482b3fe88a228A7807c', Abi::checksum('0xf46131c82819d7621163f482b3fe88a228a7807c'));
    }

    #[Test]
    public function uint_encoding_handles_values_beyond_php_int(): void
    {
        $this->assertSame(str_pad('de0b6b3a7640000', 64, '0', STR_PAD_LEFT), Abi::encUint('1000000000000000000'));
        $this->assertSame(str_repeat('0', 64), Abi::encUint('0'));
        $this->assertSame(str_pad('2b5e3af16b1880000', 64, '0', STR_PAD_LEFT), Abi::encUint('50000000000000000000'));
    }
}
