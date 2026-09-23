<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\MacroTrendService;
use App\Services\MarketDataService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — แนวโน้มใหญ่สำหรับบอทที่เดินสด (ตัวดึง + แคช ของ MacroTrend).
 *
 * สิ่งที่ต้องยืน:
 *   1. แท่งของวันที่ยังไม่จบต้องไม่ถูกนับ — ราคาปิดของมันเปลี่ยนทุกวินาที
 *   2. ดึงไม่ได้ = "ไม่รู้" (ไม่กรอง) และลองใหม่เร็ว — ไม่ใช่หยุดเปิดไม้ทั้งระบบ
 *   3. แคชต้องไม่ข้ามรอยต่อวัน UTC — ไม่งั้นบอทเห็นแนวโน้มของเมื่อวานหลังเที่ยงคืน
 *
 * Developed by Xman Studio.
 */
class MacroTrendServiceTest extends TestCase
{
    /** @var list<array>|null แท่งรายวันที่ตลาดปลอมจะคืน (null = ตลาดล่ม) */
    private ?array $daily = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->app->bind(MarketDataService::class, fn () => new class($this) extends MarketDataService
        {
            public function __construct(private $test) {}

            public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
            {
                return $this->test->dailyForFake() ?? throw new \RuntimeException('ตลาดล่ม');
            }
        });
    }

    public function dailyForFake(): ?array
    {
        return $this->daily;
    }

    /** แท่งรายวันย้อนหลัง n วันจนถึง "วันนี้" (แท่งสุดท้ายคือวันที่กำลังวิ่ง) */
    private function days(int $n, callable $closeAt): array
    {
        $today = now('UTC')->startOfDay()->getTimestamp() * 1000;

        return array_map(fn ($i) => ['time' => $today - ($n - 1 - $i) * 86_400_000, 'close' => $closeAt($i)], range(0, $n - 1));
    }

    #[Test]
    public function แท่งของวันที่ยังไม่จบไม่ถูกนับ(): void
    {
        // ขึ้นมาตลอด 80 วัน แต่ "วันนี้" (ยังไม่จบ) ดิ่งหนัก → ต้องยังเห็นขาขึ้น
        $this->daily = $this->days(81, fn ($i) => $i === 80 ? 10.0 : 100.0 + $i);

        $trend = app(MacroTrendService::class)->current();

        $this->assertTrue($trend['up']);
        $this->assertSame(179.0, $trend['close'], 'ราคาปิดต้องเป็นของเมื่อวาน ไม่ใช่ราคาสดของวันนี้');
    }

    #[Test]
    public function ดึงไม่ได้คือไม่รู้_และลองใหม่ภายในหนึ่งนาที(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->daily = null;

        $this->assertNull(app(MacroTrendService::class)->current()['up'], 'ตลาดล่ม = ไม่กรอง ไม่ใช่ขาลง');

        $this->daily = $this->days(81, fn ($i) => 100.0 + $i);
        $this->assertNull(app(MacroTrendService::class)->current()['up'], 'ภายใน 60 วิยังใช้ผลเดิมจากแคช');

        $this->travel(61)->seconds();
        $this->assertTrue(app(MacroTrendService::class)->current()['up'], 'เกิน 60 วิต้องลองดึงใหม่');

        Carbon::setTestNow();
    }

    #[Test]
    public function แคชไม่ข้ามรอยต่อวัน_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 23:59:30', 'UTC'));
        $this->assertSame(30, MacroTrendService::ttlSeconds(true), 'เหลือ 30 วิถึงเที่ยงคืน = แคชได้แค่ 30 วิ');
        $this->assertSame(30, MacroTrendService::ttlSeconds(false));

        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->assertSame(1800, MacroTrendService::ttlSeconds(true));
        $this->assertSame(60, MacroTrendService::ttlSeconds(false));

        Carbon::setTestNow();
    }
}
