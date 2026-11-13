<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\PositionSizer;
use App\Services\AiBot\Signal;
use App\Services\AiBot\Strategies\CopyStrategy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทผู้ตามต้องถือของ "ตามมาสเตอร์" และไม่ทำอะไรเกินกว่านั้น.
 *
 * กลยุทธ์นี้เห็นแค่สิ่งที่ engine ส่งมาทาง params['_copy'] (ถืออยู่ไหม · สัดส่วนทุน · ราคาเข้า)
 * ชุดนี้คุมว่า: เข้าเฉพาะไม้ใหม่ของมาสเตอร์ ไม่ไล่ราคา ปิดตามเมื่อมาสเตอร์ปิด และเมื่อเลิกตามแล้ว
 * ไม่เปิดไม้ใหม่เองอีกเลย
 *
 * Developed by Xman Studio.
 */
class CopyStrategyTest extends TestCase
{
    private CopyStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aibot.copy_trade.follow.max_entry_drift_pct' => 1.0, 'aibot.copy_trade.follow.min_add_usd' => 10]);

        $this->strategy = new CopyStrategy();
    }

    private function candles(float $close = 100.0, int $count = 40): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = ['time' => 1_700_000_000_000 + $i * 3_600_000, 'open' => $close, 'high' => $close * 1.002, 'low' => $close * 0.998, 'close' => $close, 'volume' => 1000.0];
        }

        return $out;
    }

    private function copy(array $overrides = []): array
    {
        return ['_copy' => $overrides + [
            'state' => 'ok', 'holding' => true, 'exposure' => 0.5, 'entry_market' => 100.0,
            'opened_after_follow' => true, 'follower_cap' => 200.0,
        ]];
    }

    #[Test]
    public function it_enters_a_new_master_trade_with_the_same_share_of_capital(): void
    {
        $signal = $this->strategy->decide($this->candles(100.2), $this->copy(['exposure' => 0.5]), null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertEqualsWithDelta(0.5, $signal->strength, 1e-9);
        // มาสเตอร์ใช้ทุนครึ่งหนึ่งของเพดานตัวเอง → ผู้ตามลงครึ่งหนึ่งของเพดานผู้ตาม ไม่ใช่จำนวนเงินเท่ามาสเตอร์
        $this->assertEqualsWithDelta(100.0, PositionSizer::budget('copy', ['max_position_usd' => 200], [], $signal->strength, 1.0), 1e-9);
    }

    #[Test]
    public function it_does_not_enter_a_trade_the_master_opened_before_the_follow_started(): void
    {
        $signal = $this->strategy->decide($this->candles(), $this->copy(['opened_after_follow' => false]), null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('ก่อนคุณเริ่มตาม', $signal->reason);
    }

    #[Test]
    public function it_does_not_chase_price_past_the_masters_entry(): void
    {
        $late = $this->strategy->decide($this->candles(102.0), $this->copy(), null);
        $near = $this->strategy->decide($this->candles(100.9), $this->copy(), null);
        // ราคาต่ำกว่าจุดเข้าของมาสเตอร์ = ได้ราคาดีกว่า เข้าได้เสมอ
        $better = $this->strategy->decide($this->candles(95.0), $this->copy(), null);

        $this->assertSame(Signal::HOLD, $late->action);
        $this->assertSame('ราคาวิ่งเกินจุดเข้าของมาสเตอร์ไปแล้ว 2.00% (ยอมได้ 1.00%) — ไม่ไล่ราคา', $late->reason);
        $this->assertSame(Signal::BUY, $near->action);
        $this->assertSame(Signal::BUY, $better->action);
    }

    #[Test]
    public function it_waits_while_the_master_is_flat(): void
    {
        $signal = $this->strategy->decide($this->candles(), $this->copy(['holding' => false, 'exposure' => 0.0, 'entry_market' => null]), null);

        $this->assertSame(Signal::HOLD, $signal->action);
    }

    #[Test]
    public function it_closes_when_the_master_closes(): void
    {
        $position = ['qty' => 1.0, 'entry' => 100.0];

        $closed = $this->strategy->decide($this->candles(), $this->copy(['holding' => false, 'exposure' => 0.0]), $position);
        $open = $this->strategy->decide($this->candles(), $this->copy(), $position);

        $this->assertSame(Signal::SELL, $closed->action);
        $this->assertSame('มาสเตอร์ปิดไม้แล้ว — ปิดตาม', $closed->reason);
        $this->assertSame(Signal::HOLD, $open->action);
    }

    #[Test]
    public function it_adds_only_when_the_master_has_clearly_added(): void
    {
        // ผู้ตามถือ $100 จากเพดาน $200 (50%)
        $position = ['qty' => 1.0, 'entry' => 100.0];

        $same = $this->strategy->decide($this->candles(), $this->copy(['exposure' => 0.5]), $position);
        $slightly = $this->strategy->decide($this->candles(), $this->copy(['exposure' => 0.55]), $position);
        $more = $this->strategy->decide($this->candles(), $this->copy(['exposure' => 0.9]), $position);

        $this->assertSame(Signal::HOLD, $same->action);
        $this->assertSame(Signal::HOLD, $slightly->action, 'ต่างกัน $10 จากเพดาน $200 ยังไม่ถึงขั้นเติม (ต้อง ≥ 10% ของเพดาน)');
        $this->assertSame(Signal::BUY, $more->action);
        // ช่องว่าง = 200 × 0.9 − 100 = $80 → งบของไม้เติม = $80
        $this->assertEqualsWithDelta(80.0, PositionSizer::budget('copy', ['max_position_usd' => 200], [], $more->strength, 1.0), 1e-6);
    }

    #[Test]
    public function it_never_reduces_the_position_just_because_the_master_trimmed(): void
    {
        $signal = $this->strategy->decide($this->candles(), $this->copy(['exposure' => 0.1]), ['qty' => 1.0, 'entry' => 100.0]);

        $this->assertSame(Signal::HOLD, $signal->action);
    }

    #[Test]
    public function a_detached_bot_never_opens_a_trade_and_never_force_sells(): void
    {
        foreach ([[], ['_copy' => ['state' => 'detached']], ['_copy' => ['state' => 'master_gone']], ['_copy' => 'x']] as $params) {
            $flat = $this->strategy->decide($this->candles(), $params, null);
            $holding = $this->strategy->decide($this->candles(), $params, ['qty' => 1.0, 'entry' => 100.0]);

            $this->assertSame(Signal::HOLD, $flat->action);
            // ไม้ที่ถืออยู่เป็นของผู้ตาม — เลิกตามแล้วไม่ถูกบังคับขาย (stop ของผู้ตามยังทำงานที่ engine)
            $this->assertSame(Signal::HOLD, $holding->action);
            $this->assertStringContainsString('เป็นของคุณ', $holding->reason);
        }
    }

    #[Test]
    public function the_ai_can_neither_loosen_nor_close_a_copied_trade(): void
    {
        $this->assertFalse($this->strategy->acceptsAiExit());
        $this->assertSame($this->copy(), $this->strategy->withAiRelief($this->copy(), 8.0));
    }
}
