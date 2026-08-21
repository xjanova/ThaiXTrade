<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — บันทึกกระเป๋ารับรายได้แล้วต้องเปิดการเทรดได้จริง.
 *
 * เกิดจริง 2026-08-21: เจ้าของกรอกกระเป๋ารับรายได้ครบทั้งสองเชนแล้วกดบันทึก
 * หน้าเว็บขึ้นว่าสำเร็จ แต่ `/api/v1/fees` ยังตอบ `swap.enabled = false`
 * เพราะด่านอ่าน `trading.fee_collector_wallet` ซึ่งเป็นคนละคีย์กับที่บันทึกไป
 * โค้ดที่โฆษณาว่า "sync" อยู่ฝั่งเบราว์เซอร์และไม่เคยส่งค่าขึ้นเซิร์ฟเวอร์เลย
 *
 * เทสต์ชุดนี้จึงยืนของจริงตั้งแต่การกดบันทึกถึงคีย์ที่ด่านอ่าน ไม่ใช่แค่ตัวกระจายค่า
 *
 * Developed by Xman Studio.
 */
class RevenueWalletPropagationTest extends TestCase
{
    use RefreshDatabase;

    private const TPIX_WALLET = '0x2112b98e3ec5A252b7b2A8f02d498B64a2186A7f';

    private const WTPIX_WALLET = '0x0B263D083969946fA2bB44Af2DeBA69d3D3D0220';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'admin');
    }

    private function saveRevenue(array $overrides = []): TestResponse
    {
        return $this->put('/admin/settings/revenue', array_merge([
            'tpix_wallet' => self::TPIX_WALLET,
            'tpix_chain_id' => 4289,
            'wtpix_wallet' => self::WTPIX_WALLET,
            'wtpix_chain_id' => 56,
            'auto_collect_enabled' => true,
        ], $overrides));
    }

    #[Test]
    public function บันทึกแท็บรายได้แล้วด่านเทรดต้องเปิดให้(): void
    {
        $this->assertSame('', (string) SiteSetting::get('trading', 'fee_collector_wallet', ''));

        $this->saveRevenue();

        // ค่าธรรมเนียมเทรดเกิดบนเชนที่ซื้อขาย wTPIX จึงต้องเป็นกระเป๋าใบนั้น
        $this->assertSame(
            self::WTPIX_WALLET,
            (string) SiteSetting::get('trading', 'fee_collector_wallet', ''),
        );
    }

    #[Test]
    public function ค่าสร้างเหรียญใช้กระเป๋าบนเชนtpix(): void
    {
        $this->saveRevenue();

        $this->assertSame(
            self::TPIX_WALLET,
            (string) SiteSetting::get('factory', 'fee_wallet', ''),
        );
    }

    /**
     * ยังไม่ได้ตั้งกระเป๋าบนเชนซื้อขาย ให้ตกมาใช้กระเป๋าบน TPIX Chain
     * ดีกว่าปล่อยให้การเทรดถูกปิดต่อไปทั้งที่เจ้าของกรอกกระเป๋ามาแล้ว.
     */
    #[Test]
    public function ไม่มีกระเป๋าบนเชนซื้อขายให้ตกมาใช้กระเป๋าtpix(): void
    {
        $this->saveRevenue(['wtpix_wallet' => '']);

        $this->assertSame(
            self::TPIX_WALLET,
            (string) SiteSetting::get('trading', 'fee_collector_wallet', ''),
        );
    }

    /**
     * กับดักที่ต้องกันไว้: กดบันทึกตอนช่องว่างต้องไม่ไปล้างกระเป๋าที่ตั้งไว้ดีอยู่แล้ว
     * ไม่งั้นการเผลอกดบันทึกครั้งเดียวปิดการเทรดทั้งเว็บโดยไม่มีใครรู้ตัว.
     */
    #[Test]
    public function บันทึกตอนช่องว่างต้องไม่ล้างกระเป๋าเดิม(): void
    {
        SiteSetting::set('trading', 'fee_collector_wallet', self::WTPIX_WALLET, 'string');
        SiteSetting::set('factory', 'fee_wallet', self::TPIX_WALLET, 'string');

        $this->saveRevenue(['tpix_wallet' => '', 'wtpix_wallet' => '']);

        $this->assertSame(
            self::WTPIX_WALLET,
            (string) SiteSetting::get('trading', 'fee_collector_wallet', ''),
        );
        $this->assertSame(
            self::TPIX_WALLET,
            (string) SiteSetting::get('factory', 'fee_wallet', ''),
        );
    }

    /**
     * แท็บอื่นต้องไม่ถูกลากไปด้วย — ตัวกระจายค่าผูกกับแท็บรายได้เท่านั้น.
     */
    #[Test]
    public function แท็บอื่นไม่กระจายค่า(): void
    {
        SiteSetting::set('revenue', 'wtpix_wallet', self::WTPIX_WALLET, 'string');

        $this->put('/admin/settings/trading', ['default_slippage' => '0.5']);

        $this->assertSame('', (string) SiteSetting::get('trading', 'fee_collector_wallet', ''));
    }
}
