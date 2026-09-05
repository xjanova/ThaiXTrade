<?php

namespace Tests\Feature\Api;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ที่อยู่กระเป๋ารับค่าธรรมเนียมต้องออกจาก API เป็นตัวพิมพ์เล็กเสมอ.
 *
 * ค่าที่แอดมินพิมพ์ไว้บน prod มี checksum (EIP-55) ไม่ตรง — ethers v6 ฝั่งเบราว์เซอร์
 * โยน "bad address checksum" ตอนโอนค่าธรรมเนียม ทำให้สวอปสำเร็จแต่เก็บค่าธรรมเนียมไม่ได้
 * เลยสักครั้ง โดยหน้าเว็บแค่ warn เงียบ ๆ — ตัวพิมพ์เล็กล้วนไม่ถูกตรวจ checksum
 */
class FeeCollectorAddressTest extends TestCase
{
    public function test_fee_info_returns_the_collector_in_lowercase(): void
    {
        Cache::flush();
        SiteSetting::set('trading', 'fee_collector_wallet', '0x0B263D083969946fA2bB44Af2DeBA69d3D3D0220');

        $this->getJson('/api/v1/trading/fee-info?chain_id=56')
            ->assertOk()
            ->assertJsonPath('data.fee_collector', '0x0b263d083969946fa2bb44af2deba69d3d3d0220');
    }
}
