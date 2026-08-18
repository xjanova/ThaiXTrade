<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TPIX TRADE — Sale Privacy Tests
 *
 * ประวัติการซื้อและตาราง vesting ของกระเป๋าหนึ่งๆ ต้องพิสูจน์ตัวตนก่อนดู
 * ไม่งั้นใครก็ไล่ดูได้ว่ากระเป๋าไหนซื้อไปเท่าไร แล้วเล็งผู้ถือรายใหญ่ไปหลอกต่อ
 *
 * ⚠️ กับดักที่เคยพลาด: ย้าย route ไปหลัง middleware แล้วคิดว่าปิดแล้ว
 *    แต่ middleware อ่าน wallet_address จาก body/query เท่านั้น ส่วน route นี้
 *    ส่งมาเป็น route parameter → middleware มองไม่เห็น เลยปล่อยผ่านทุกครั้ง
 *    เทสต์นี้จึงต้องยิงผ่าน HTTP จริงเท่านั้น ไม่ใช่เรียก controller ตรง
 *
 * Developed by Xman Studio.
 */
class SalePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    public function test_purchases_of_a_wallet_require_verified_session(): void
    {
        $this->getJson('/api/v1/token-sale/purchases/'.self::WALLET)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
    }

    public function test_vesting_of_a_wallet_requires_verified_session(): void
    {
        $this->getJson('/api/v1/token-sale/vesting/'.self::WALLET)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
    }

    public function test_verified_wallet_can_read_its_own_history(): void
    {
        Cache::put('wallet_verified:'.strtolower(self::WALLET), [
            'verified_at' => now()->toIso8601String(),
            'ip' => '127.0.0.1',
        ], 3600);

        $this->getJson('/api/v1/token-sale/purchases/'.self::WALLET)
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * ยืนยันกระเป๋าตัวเองแล้ว ก็ยังดูของคนอื่นไม่ได้
     */
    public function test_verified_wallet_cannot_read_another_wallet(): void
    {
        Cache::put('wallet_verified:'.strtolower(self::WALLET), [
            'verified_at' => now()->toIso8601String(),
            'ip' => '127.0.0.1',
        ], 3600);

        $this->getJson('/api/v1/token-sale/purchases/0x2222222222222222222222222222222222222222')
            ->assertStatus(403);
    }
}
