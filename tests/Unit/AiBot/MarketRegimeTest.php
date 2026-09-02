<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\MarketRegime;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ตัวจำแนกสภาพตลาดต้องอ่านเทรนด์ช้าๆ ว่า "มีทิศทาง" ไม่ใช่ "ออกข้าง".
 *
 * Developed by Xman Studio.
 */
class MarketRegimeTest extends TestCase
{
    /** @param list<float> $closes */
    private function candles(array $closes): array
    {
        return array_map(fn ($c) => ['close' => $c, 'high' => $c * 1.002, 'low' => $c * 0.998], $closes);
    }

    #[Test]
    public function ขาขึ้นช้าๆแต่ไม่ย้อนเลยต้องเป็น_trending_up(): void
    {
        // ขึ้นแท่งละ 0.1% ติดกัน 260 แท่ง — ความชันน้อยแต่ ER = 1
        $closes = [];
        for ($i = 0; $i < 260; $i++) {
            $closes[] = 100.0 * (1.001 ** $i);
        }

        $result = MarketRegime::assess($this->candles($closes));

        $this->assertSame(MarketRegime::TRENDING_UP, $result['regime']);
        $this->assertGreaterThan(0.9, $result['er']);
        $this->assertSame('up', $result['trend']);
        $this->assertGreaterThan(0, $result['above_ema_pct']);
    }

    #[Test]
    public function ขาลงต่อเนื่องต้องเป็น_trending_down(): void
    {
        $closes = [];
        for ($i = 0; $i < 260; $i++) {
            $closes[] = 200.0 * (0.998 ** $i);
        }

        $result = MarketRegime::assess($this->candles($closes));

        $this->assertSame(MarketRegime::TRENDING_DOWN, $result['regime']);
        $this->assertSame('down', $result['trend']);
    }

    #[Test]
    public function แกว่งไปมารอบราคาเดิมต้องเป็น_ranging(): void
    {
        $closes = [];
        for ($i = 0; $i < 260; $i++) {
            $closes[] = 100.0 + sin($i / 2) * 3.0;
        }

        $result = MarketRegime::assess($this->candles($closes));

        $this->assertSame(MarketRegime::RANGING, $result['regime']);
        $this->assertLessThan(MarketRegime::DEFAULT_ER_TRENDING, $result['er']);
    }

    #[Test]
    public function ข้อมูลไม่พอ_ema_200_ต้องถอยไปใช้คาบที่พอดี_ไม่ใช่ตอบไม่รู้(): void
    {
        $closes = [];
        for ($i = 0; $i < 120; $i++) {
            $closes[] = 100.0 + $i * 0.5;
        }

        $result = MarketRegime::assess($this->candles($closes));

        $this->assertNotNull($result['ema'], 'มี 120 แท่ง ต้องคำนวณทิศได้ด้วย EMA ที่สั้นลง');
        $this->assertSame(MarketRegime::TRENDING_UP, $result['regime']);
    }

    #[Test]
    public function ราคานิ่งสนิทไม่ใช่เทรนด์(): void
    {
        $result = MarketRegime::assess($this->candles(array_fill(0, 260, 100.0)));

        $this->assertSame(MarketRegime::RANGING, $result['regime']);
        $this->assertSame(0.0, $result['er']);
    }

    #[Test]
    public function efficiency_ratio_อยู่ในช่วง_0_ถึง_1_เสมอ(): void
    {
        $this->assertSame(0.0, MarketRegime::efficiencyRatio([1.0, 2.0], 20), 'ข้อมูลไม่พอ = 0');
        $this->assertSame(1.0, MarketRegime::efficiencyRatio(range(1, 30), 20));
        $this->assertLessThan(0.2, MarketRegime::efficiencyRatio(array_map(fn ($i) => 100 + ($i % 2), range(0, 40)), 20));
    }
}
