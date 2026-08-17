<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\StrategyAvailability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — กลยุทธ์ไหนลงมือได้จริง.
 *
 * เจ้าของสั่งว่า "ฟังก์ชันไหนยังใช้ไม่ได้ ก็ต้องทำให้ปุ่มไม่พร้อมใช้ไปก่อน
 * ถ้าเงื่อนไขการใช้ยังไม่ครบ"
 *
 * อาร์บิทราจเป็นเคสตัวอย่าง: ปลดล็อกที่ VIP แต่ต้องมีราคาสองแหล่งถึงจะมีส่วนต่าง
 * ให้จับ ตอนนี้พูล DEX ยังไม่ deploy จึงมีแค่ราคาเดียว
 *
 * Developed by Xman Studio.
 */
class StrategyAvailabilityTest extends TestCase
{
    private StrategyAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = new StrategyAvailability();
    }

    #[Test]
    public function อาร์บิทราจปิดอยู่ตราบใดที่_dex_ยังไม่_deploy(): void
    {
        // dexContracts.json ในรีโปยังเป็น zero address ทั้งหมด
        $this->assertFalse($this->availability->dexDeployed());
        $this->assertFalse($this->availability->isAvailable('arbitrage'));
    }

    #[Test]
    public function กลยุทธ์ที่ไม่พึ่ง_dex_ใช้ได้ตามปกติ(): void
    {
        foreach (['grid', 'dca', 'momentum', 'mean_reversion', 'breakout', 'scalping', 'ai_signal'] as $code) {
            $this->assertTrue($this->availability->isAvailable($code), "{$code} ควรใช้ได้");
        }
    }

    /** ปิดแล้วต้องบอกเหตุผลด้วย ไม่ใช่ปุ่มเทาเปล่าๆ ที่ผู้ใช้เดาเองว่าทำไม */
    #[Test]
    public function ปิดแล้วต้องมีเหตุผลทั้งไทยและอังกฤษ(): void
    {
        $status = $this->availability->check('arbitrage');

        $this->assertFalse($status['available']);
        $this->assertNotEmpty($status['reason']);
        $this->assertNotEmpty($status['reason_en']);
        $this->assertStringContainsString('DEX', $status['reason']);
    }

    #[Test]
    public function แคตตาล็อกแนบสถานะให้ทุกกลยุทธ์(): void
    {
        $decorated = $this->availability->decorate(config('aibot.strategies', []));

        $this->assertNotEmpty($decorated);

        foreach ($decorated as $strategy) {
            $this->assertArrayHasKey('available', $strategy);
            $this->assertArrayHasKey('unavailable_reason', $strategy);
        }

        $arbitrage = collect($decorated)->firstWhere('code', 'arbitrage');
        $this->assertFalse($arbitrage['available']);

        $grid = collect($decorated)->firstWhere('code', 'grid');
        $this->assertTrue($grid['available']);
        $this->assertNull($grid['unavailable_reason']);
    }

    #[Test]
    public function รายการอนุญาตไม่มีอาร์บิทราจแต่ยังมีตัวอื่นครบ(): void
    {
        $codes = $this->availability->availableCodes();

        $this->assertNotContains('arbitrage', $codes);
        $this->assertContains('grid', $codes);
        $this->assertCount(count(config('aibot.strategies')) - 1, $codes);
    }
}
