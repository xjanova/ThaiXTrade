<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Backtest\BacktestEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — backtester ต้องให้ผลที่ "อธิบายได้" บนตลาดจำลองที่รู้คำตอบ.
 *
 * ผล backtest ที่ผิดอันตรายกว่าไม่มี backtest — มันให้ความมั่นใจปลอมก่อนเอาเงินไปเสี่ยง
 * ชุดนี้จึงยึดที่ความสอดคล้องของตัวเลข (realized = gross − ต้นทุน) และพฤติกรรม
 * ที่ต้องเกิดแน่ (ตลาดนิ่งไม่เทรด · stop ทำงาน · DCA เฉลี่ยต้นทุน)
 *
 * Developed by Xman Studio.
 */
class BacktestEngineTest extends TestCase
{
    private BacktestEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aibot_risk.demo.starting_balance' => 10000.0,
            'aibot_risk.demo.fee_rate' => 0.1,
            'aibot_risk.demo.slippage_bps' => 8,
        ]);

        $this->engine = app(BacktestEngine::class);
    }

    /** @param list<float> $closes */
    private function candles(array $closes, int $stepMinutes = 60): array
    {
        $out = [];

        foreach ($closes as $i => $close) {
            $out[] = [
                'time' => 1_700_000_000_000 + $i * $stepMinutes * 60_000,
                'open' => $close * 0.999,
                'high' => $close * 1.002,
                'low' => $close * 0.998,
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        return $out;
    }

    /** นิ่ง → ขึ้นชัดเจน → ลงชัดเจน: momentum ต้องเข้าตอนตัดขึ้นและออกตอนตัดลง */
    private function trendRoundTrip(): array
    {
        $closes = array_fill(0, 200, 100.0);
        for ($i = 1; $i <= 60; $i++) {
            $closes[] = 100.0 + $i * 0.5;
        }
        for ($i = 1; $i <= 60; $i++) {
            $closes[] = 130.0 - $i * 0.5;
        }
        $closes = array_merge($closes, array_fill(0, 40, 100.0));

        return $this->candles($closes);
    }

    #[Test]
    public function ตลาดนิ่งต้องไม่มีไม้เลย(): void
    {
        $result = $this->engine->run('momentum', $this->candles(array_fill(0, 400, 100.0)), '1h');

        $this->assertSame(0, $result['summary']['trades']);
        $this->assertSame(0.0, $result['summary']['realized_pnl']);
        $this->assertSame(10000.0, $result['summary']['final_equity']);
        $this->assertCount($result['bars'], $result['equity'], 'เส้นมูลค่าพอร์ตต้องมีจุดครบทุกแท่งที่ทดสอบ');
    }

    #[Test]
    public function momentum_เข้าและออกครบรอบบนเทรนด์ที่ชัดเจน_และตัวเลขสอดคล้องกัน(): void
    {
        $result = $this->engine->run('momentum', $this->trendRoundTrip(), '1h', [], ['max_position_usd' => 1000, 'stop_loss_pct' => 50, 'take_profit_pct' => 200]);
        $s = $result['summary'];

        $this->assertGreaterThanOrEqual(1, $s['closed'], 'ต้องมีไม้ที่ปิดอย่างน้อยหนึ่งไม้');
        $this->assertGreaterThan(0, $s['gross_pnl'], 'ขึ้น 30% แล้วออกตอนตัดลง gross ต้องบวก');

        // เอกลักษณ์ที่ห้ามผิด: realized = gross − ต้นทุน
        $this->assertEqualsWithDelta($s['gross_pnl'] - $s['costs'], $s['realized_pnl'], 0.011);

        // ทุกไม้ขายต้องมี cost_basis และ held_bars ให้คำนวณ edge/hold ได้
        foreach ($result['trades'] as $trade) {
            if ($trade['side'] === 'sell') {
                $this->assertArrayHasKey('cost_basis', $trade);
                $this->assertGreaterThan(0, $trade['held_bars']);
            }
        }

        $this->assertNotNull($s['edge_bps']);
        $this->assertSame(36.0, $s['cost_bps']);
        $this->assertGreaterThan(0, $s['exposure_pct']);
        $this->assertLessThan(100, $s['exposure_pct']);
    }

    #[Test]
    public function stop_loss_ของผู้ใช้ทำงานเมื่อราคาดิ่ง(): void
    {
        // DCA ซื้อทันทีที่รอบครบ (interval 1h บน 1h = ทุกแท่ง) แล้วตลาดพังลง 10%
        $closes = array_fill(0, 160, 100.0);
        for ($i = 1; $i <= 30; $i++) {
            $closes[] = 100.0 - $i * 0.5;
        }

        $result = $this->engine->run('dca', $this->candles($closes), '1h',
            ['interval_hours' => 1, 'budget_usd' => 50, 'dip_boost_pct' => 3],
            ['max_position_usd' => 100, 'stop_loss_pct' => 3, 'take_profit_pct' => 200, 'max_daily_loss_usd' => 100000],
            ['risk_gate' => false],
        );

        $stopped = array_filter($result['trades'], fn ($t) => $t['side'] === 'sell' && str_contains($t['reason'], 'ตัดขาดทุน'));

        $this->assertNotEmpty($stopped, 'ราคาลง 10% ต้องชน stop 3% อย่างน้อยหนึ่งครั้ง');
    }

    #[Test]
    public function dca_เติมไม้แล้วเฉลี่ยต้นทุน(): void
    {
        $closes = array_fill(0, 160, 100.0);

        $result = $this->engine->run('dca', $this->candles($closes), '1h',
            ['interval_hours' => 1, 'budget_usd' => 20, 'dip_boost_pct' => 3],
            ['max_position_usd' => 100, 'stop_loss_pct' => 50, 'take_profit_pct' => 200, 'max_daily_loss_usd' => 100000],
            ['risk_gate' => false],
        );

        $buys = array_filter($result['trades'], fn ($t) => $t['side'] === 'buy');

        $this->assertGreaterThan(1, count($buys), 'DCA ต้องซื้อหลายรอบ');
        $this->assertSame(0, $result['summary']['closed'], 'ตลาดนิ่ง DCA ไม่มีจุดขายเอง');
        $this->assertGreaterThan(0, $result['summary']['exposure_pct']);

        // ⭐ เพดานทุน $100 ต้องคุมยอดรวม: งบ $20 → เติมได้ 5 ไม้แล้วหยุด ไม่ใช่เติมไปเรื่อยๆ
        $this->assertCount(5, $buys, 'ต้องหยุดเติมเมื่อต้นทุนสะสมชนเพดานทุน');
        $this->assertLessThanOrEqual(100.0 + 1e-6, $result['summary']['max_deployed']);
        $this->assertGreaterThan(0, $result['summary']['decisions']['blocked'], 'รอบที่ชนเพดานต้องถูกนับว่าถูกกัน');
    }

    #[Test]
    public function edge_ถ่วงด้วยเงิน_เท่ากับกำไรก่อนหักต้นทุนของไม้ที่ปิดหารเงินที่ลง(): void
    {
        $result = $this->engine->run('momentum', $this->trendRoundTrip(), '1h', [], ['max_position_usd' => 1000, 'stop_loss_pct' => 50, 'take_profit_pct' => 200]);

        $sells = array_values(array_filter($result['trades'], fn ($t) => $t['side'] === 'sell'));
        $this->assertNotEmpty($sells);

        $deployed = array_sum(array_column($sells, 'cost_basis'));
        $gross = array_sum(array_map(fn ($t) => $t['realized_pnl'] + $t['fee'] + $t['slippage_cost'] + $t['buy_costs'], $sells));

        $this->assertEqualsWithDelta($gross / $deployed * 10000, $result['summary']['edge_bps'], 0.06);
        $this->assertGreaterThan(0, $result['summary']['capital_return_pct']);
    }

    #[Test]
    public function ปิดด่านความเสี่ยงแล้วต้องได้ไม้ไม่น้อยกว่าเปิด(): void
    {
        $market = $this->trendRoundTrip();
        $risk = ['max_position_usd' => 1000, 'stop_loss_pct' => 50, 'take_profit_pct' => 200];

        $gated = $this->engine->run('momentum', $market, '1h', [], $risk, ['risk_gate' => true])['summary'];
        $raw = $this->engine->run('momentum', $market, '1h', [], $risk, ['risk_gate' => false])['summary'];

        $this->assertGreaterThanOrEqual($gated['trades'], $raw['trades']);
    }

    #[Test]
    public function กลยุทธ์ที่ไม่รู้จักต้องล้มดัง_ไม่ใช่คืนศูนย์เงียบๆ(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->run('ไม่มี', $this->candles(array_fill(0, 200, 100.0)), '1h');
    }
}
