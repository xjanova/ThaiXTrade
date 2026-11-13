<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\StrategyAvailability;
use App\Services\ContractRegistry;
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
        foreach (['grid', 'dca', 'momentum', 'mean_reversion', 'breakout', 'ai_signal'] as $code) {
            $this->assertTrue($this->availability->isAvailable($code), "{$code} ควรใช้ได้");
        }
    }

    /**
     * ⭐ สแกลป์ถูกถอดออกจากการขาย 2 ก.ย. 2026 — วัดจริง 608 ไม้แล้วแพ้โดยโครงสร้าง.
     *
     * ต้อง "ไม่พร้อมใช้" พร้อมเหตุผลที่บอกตัวเลขจริง ไม่ใช่หายไปจากแคตตาล็อกเฉยๆ
     * (บอทเก่ายังต้องอ่านชื่อและประวัติของตัวเองได้)
     */
    #[Test]
    public function กลยุทธ์ที่ถูกถอดออกจากการขายต้องไม่พร้อมใช้พร้อมเหตุผล(): void
    {
        $status = $this->availability->check('scalping');

        $this->assertFalse($status['available']);
        $this->assertStringContainsString('ถอด', $status['reason']);
        $this->assertStringContainsString('608', $status['reason'], 'เหตุผลต้องอ้างการวัดจริง ไม่ใช่ความเห็น');
        $this->assertNotEmpty($status['reason_en']);

        $this->assertNotContains('scalping', $this->availability->availableCodes());
    }

    /** ปิดแล้วต้องบอกเหตุผลด้วย ไม่ใช่ปุ่มเทาเปล่าๆ ที่ผู้ใช้เดาเองว่าทำไม */
    #[Test]
    public function ปิดแล้วต้องมีเหตุผลทั้งไทยและอังกฤษ(): void
    {
        $status = $this->availability->check('arbitrage');

        $this->assertFalse($status['available']);
        $this->assertNotEmpty($status['reason']);
        $this->assertNotEmpty($status['reason_en']);
        $this->assertStringContainsString('เร็วๆ นี้', $status['reason']);
        $this->assertStringContainsString('Coming soon', $status['reason_en']);
        $this->assertTrue($status['coming_soon']);
    }

    /**
     * ⭐ DEX พร้อมอย่างเดียวต้องไม่เปิดอาร์บิทราจ — ArbitrageStrategy ยังคืน "ถือ" ทุกรอบ.
     *
     * เดิมพอพูล DEX ขึ้น production แคตตาล็อกก็รายงาน available=true ทันที ผู้ใช้ VIP
     * สร้างบอทที่ไม่มีวันเข้าไม้ได้ ต้องรอธง implemented ที่คนตั้งเองเมื่อทำเสร็จจริง
     */
    #[Test]
    public function dex_พร้อมแล้วอาร์บิทราจก็ยังเป็นเร็วๆนี้จนกว่าจะตั้งธงว่าทำเสร็จ(): void
    {
        $this->mock(ContractRegistry::class, fn ($mock) => $mock->shouldReceive('dexReady')->andReturn(true));
        $availability = new StrategyAvailability();

        $status = $availability->check('arbitrage');
        $this->assertFalse($status['available']);
        $this->assertTrue($status['coming_soon']);
        $this->assertNotContains('arbitrage', $availability->availableCodes());

        // ตั้งธงว่าทำเสร็จแล้ว + DEX พร้อม = เปิดได้ (ธงเป็นตัวเปิด ไม่ใช่ DEX)
        config(['aibot.strategies' => collect(config('aibot.strategies'))
            ->map(fn (array $s) => $s['code'] === 'arbitrage' ? ['implemented' => true] + $s : $s)
            ->all()]);

        $this->assertTrue((new StrategyAvailability())->isAvailable('arbitrage'));
    }

    /** ถอดออกจากการขาย ≠ เร็วๆ นี้ — หน้าจอติดป้ายคนละแบบ */
    #[Test]
    public function กลยุทธ์ที่ถอดแล้วไม่ถูกติดป้ายเร็วๆนี้(): void
    {
        $this->assertFalse($this->availability->check('scalping')['coming_soon']);
        $this->assertFalse($this->availability->check('grid')['coming_soon']);
    }

    #[Test]
    public function แคตตาล็อกแนบสถานะให้ทุกกลยุทธ์(): void
    {
        $decorated = $this->availability->decorate(config('aibot.strategies', []));

        $this->assertNotEmpty($decorated);

        foreach ($decorated as $strategy) {
            $this->assertArrayHasKey('available', $strategy);
            $this->assertArrayHasKey('unavailable_reason', $strategy);
            $this->assertArrayHasKey('coming_soon', $strategy);
        }

        $arbitrage = collect($decorated)->firstWhere('code', 'arbitrage');
        $this->assertFalse($arbitrage['available']);
        $this->assertTrue($arbitrage['coming_soon']);

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

        // หายไปสองตัว: อาร์บิทราจ (เร็วๆ นี้) + สแกลป์ (ถอดออกจากการขาย)
        $retired = collect(config('aibot.strategies'))->where('retired', true)->count();
        $this->assertCount(count(config('aibot.strategies')) - 1 - $retired, $codes);
    }
}
