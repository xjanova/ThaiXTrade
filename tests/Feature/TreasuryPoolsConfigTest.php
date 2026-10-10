<?php

namespace Tests\Feature;

use App\Support\Wei;
use Tests\TestCase;

/**
 * TPIX TRADE — รายชื่อกระเป๋าคลังต้องครบทั้ง master wallet และตรงกับ genesis.
 *
 * หน้า /admin/treasury ใช้รายการนี้ทั้งแสดงยอด กระทบยอด และปุ่มโอนออก
 * ถ้าหายไปสักใบ เงินในใบนั้นจะเคลื่อนได้โดยไม่มีใครเห็น ถ้าที่อยู่ผิดหนึ่งตัวอักษร
 * ปุ่ม "รับเข้า" จะโชว์ QR ของกระเป๋าที่ไม่มีใครถือคีย์
 *
 * Developed by Xman Studio.
 */
class TreasuryPoolsConfigTest extends TestCase
{
    public function test_every_master_wallet_path_is_listed_once(): void
    {
        $paths = array_column(config('treasury.pools'), 'path');

        $expected = array_map(fn (int $i) => "m/44'/60'/0'/0/{$i}", range(0, 10));

        $this->assertSame($expected, $paths, 'ต้องมีครบ path 0..10 ตามลำดับ ไม่ขาดไม่ซ้ำ');
    }

    public function test_genesis_of_all_pools_adds_up_to_the_total_supply(): void
    {
        $sumWei = '0';
        foreach (config('treasury.pools') as $pool) {
            $sumWei = bcadd($sumWei, Wei::toWei($pool['genesis']), 0);
        }

        $this->assertSame(
            Wei::toWei(config('treasury.total_supply')),
            $sumWei,
            'ยอด genesis รวมทุกใบต้องเท่ากับ supply ทั้งเชน (7,000,000,000) — ตัวกระทบยอดจึงครอบทั้งเชน',
        );
    }

    public function test_addresses_and_keys_are_unique_and_well_formed(): void
    {
        $pools = config('treasury.pools');

        $addresses = array_map(fn ($p) => strtolower($p['address']), $pools);
        $keys = array_column($pools, 'key');

        $this->assertCount(count($pools), array_unique($addresses), 'ที่อยู่ซ้ำกัน');
        $this->assertCount(count($pools), array_unique($keys), 'key ซ้ำกัน — สมุดบัญชีจะปนกัน');

        foreach ($pools as $pool) {
            $this->assertMatchesRegularExpression('/^0x[0-9a-fA-F]{40}$/', $pool['address'], "ที่อยู่ของ {$pool['key']} ผิดรูปแบบ");
            $this->assertNotEmpty($pool['role_th'], "{$pool['key']} ไม่มีชื่อภาษาไทย");
        }
    }

    public function test_hot_wallet_is_not_one_of_the_master_wallets(): void
    {
        // กระเป๋าร้อนต้องเป็นคีย์สุ่มอิสระ — ถ้า derive จาก mnemonic คลัง คีย์หลุดใบเดียวลามได้ทั้งชุด
        $addresses = array_map(fn ($p) => strtolower($p['address']), config('treasury.pools'));

        $this->assertNotContains(strtolower((string) config('treasury.hot_wallet.address')), $addresses);
    }
}
