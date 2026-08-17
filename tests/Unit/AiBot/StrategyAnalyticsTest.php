<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotTrade;
use App\Services\AiBot\StrategyAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — สถิติที่ใช้ตัดสินใจต่อได้ ต้องคำนวณถูก.
 *
 * ตัวเลขชุดนี้คือสิ่งที่ผู้ใช้จะใช้ตัดสินว่าจะเช่าต่อหรือเลิก และจะปรับกลยุทธ์ไปทางไหน
 * คำนวณผิด = ผู้ใช้ตัดสินใจบนข้อมูลผิด ซึ่งแย่กว่าไม่มีตัวเลขให้ดูเลย
 *
 * Developed by Xman Studio.
 */
class StrategyAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private StrategyAnalytics $analytics;

    private AiBotConfig $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(StrategyAnalytics::class);

        $this->bot = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอททดสอบ',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
        ]);
    }

    /** บันทึกไม้หนึ่งไม้ (pnl = null คือไม้ซื้อที่ยังไม่ปิด) */
    private function trade(?float $pnl, array $attributes = []): AiBotTrade
    {
        static $n = 0;
        $n++;

        return AiBotTrade::create(array_merge([
            'ai_bot_config_id' => $this->bot->id,
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT',
            'mode' => 'demo',
            'side' => $pnl === null ? 'buy' : 'sell',
            'price' => 100 + $n,
            'quantity' => 1,
            'gross_value' => 100,
            'fee' => 0.1,
            'slippage_cost' => 0.08,
            'realized_pnl' => $pnl,
            'strategy' => 'grid',
            'reason' => 'ทดสอบ',
            'risk_level' => 'calm',
            'created_at' => now()->addMinutes($n),
        ], $attributes));
    }

    // ─────────────────────────── ตัวเลขพื้นฐาน ───────────────────────────

    #[Test]
    public function it_returns_empty_shape_when_there_are_no_trades(): void
    {
        $result = $this->analytics->forWallet(self::WALLET);

        $this->assertSame(0, $result['overall']['trades']);
        $this->assertNull($result['overall']['win_rate']);
        $this->assertNull($result['overall']['profit_factor']);
        $this->assertNull($result['overall']['max_drawdown']);
        $this->assertSame([], $result['by_strategy']);
    }

    /**
     * ไม้ที่ยังไม่ปิดต้องไม่ถูกนับเป็นผลงาน — กำไรลอยยังไม่ใช่เงิน.
     */
    #[Test]
    public function open_trades_are_counted_but_do_not_affect_performance(): void
    {
        $this->trade(null);
        $this->trade(null);

        $overall = $this->analytics->forWallet(self::WALLET)['overall'];

        $this->assertSame(2, $overall['trades']);
        $this->assertSame(0, $overall['closed']);
        $this->assertNull($overall['win_rate']);
        $this->assertSame(0.0, $overall['realized_pnl']);
    }

    #[Test]
    public function it_counts_wins_losses_and_win_rate(): void
    {
        $this->trade(10);
        $this->trade(20);
        $this->trade(-5);
        $this->trade(-5);

        $overall = $this->analytics->forWallet(self::WALLET)['overall'];

        $this->assertSame(4, $overall['closed']);
        $this->assertSame(2, $overall['wins']);
        $this->assertSame(2, $overall['losses']);
        $this->assertSame(50.0, $overall['win_rate']);
        $this->assertSame(20.0, $overall['realized_pnl']);
        $this->assertSame(20.0, $overall['best_trade']);
        $this->assertSame(-5.0, $overall['worst_trade']);
    }

    // ─────────────────────────── profit factor ───────────────────────────

    /**
     * profit factor = กำไรรวม ÷ ขาดทุนรวม.
     */
    #[Test]
    public function profit_factor_divides_gross_win_by_gross_loss(): void
    {
        $this->trade(30);
        $this->trade(20);   // กำไรรวม 50
        $this->trade(-25);  // ขาดทุนรวม 25

        $overall = $this->analytics->forWallet(self::WALLET)['overall'];

        $this->assertSame(2.0, $overall['profit_factor']);
    }

    /**
     * ⭐ ยังไม่เคยขาดทุนเลย = ยังตัดสินไม่ได้ ไม่ใช่ "ดีเลิศ".
     *
     * ถ้าคืนค่าอนันต์หรือเลขสูงๆ ผู้ใช้จะเข้าใจว่ากลยุทธ์นี้ไร้ที่ติ
     * ทั้งที่จริงแค่ยังเทรดไม่พอจะเจอไม้เสีย
     */
    #[Test]
    public function profit_factor_is_unknown_rather_than_infinite_when_nothing_lost_yet(): void
    {
        $this->trade(10);
        $this->trade(5);

        $this->assertNull($this->analytics->forWallet(self::WALLET)['overall']['profit_factor']);
    }

    // ─────────────────────────── expectancy ───────────────────────────

    /**
     * expectancy = (อัตราชนะ × กำไรเฉลี่ยตอนชนะ) − (อัตราแพ้ × ขาดทุนเฉลี่ยตอนแพ้).
     */
    #[Test]
    public function expectancy_reflects_average_outcome_per_trade(): void
    {
        // ชนะ 2 ไม้ ไม้ละ 10 · แพ้ 2 ไม้ ไม้ละ 4
        // = (0.5 × 10) − (0.5 × 4) = 5 − 2 = 3
        $this->trade(10);
        $this->trade(10);
        $this->trade(-4);
        $this->trade(-4);

        $this->assertSame(3.0, $this->analytics->forWallet(self::WALLET)['overall']['expectancy']);
    }

    /**
     * ⭐ ชนะบ่อยแต่ขาดทุนหนักตอนแพ้ = expectancy ติดลบ.
     *
     * นี่คือเหตุผลที่ต้องมี expectancy คู่กับอัตราชนะเสมอ — อัตราชนะ 75%
     * ดูดีมากจนหลอกคนได้ ทั้งที่กลยุทธ์นี้ขาดทุน
     */
    #[Test]
    public function a_high_win_rate_can_still_have_negative_expectancy(): void
    {
        $this->trade(2);
        $this->trade(2);
        $this->trade(2);
        $this->trade(-30);

        $overall = $this->analytics->forWallet(self::WALLET)['overall'];

        $this->assertSame(75.0, $overall['win_rate'], 'ดูเผินๆ เหมือนกลยุทธ์ที่ดี');
        $this->assertLessThan(0, $overall['expectancy'], 'แต่จริงๆ ขาดทุน');
        $this->assertLessThan(0, $overall['realized_pnl']);
    }

    // ─────────────────────────── ขาดทุนสูงสุด ───────────────────────────

    /**
     * วัดจากยอดสูงสุดที่เคยทำได้ลงมาถึงจุดต่ำสุดหลังจากนั้น.
     */
    #[Test]
    public function max_drawdown_measures_the_worst_fall_from_a_peak(): void
    {
        // สะสม: +50 → +30 → +10 → +60
        // ยอดสูงสุดก่อนร่วง = 50 · จุดต่ำสุดหลังจากนั้น = 10 → ร่วงลึกสุด 40
        $this->trade(50);
        $this->trade(-20);
        $this->trade(-20);
        $this->trade(50);

        $this->assertSame(40.0, $this->analytics->forWallet(self::WALLET)['overall']['max_drawdown']);
    }

    #[Test]
    public function max_drawdown_is_zero_when_the_curve_only_goes_up(): void
    {
        $this->trade(10);
        $this->trade(10);

        $this->assertSame(0.0, $this->analytics->forWallet(self::WALLET)['overall']['max_drawdown']);
    }

    // ─────────────────────────── ต้นทุนที่มองไม่เห็น ───────────────────────────

    /**
     * ⭐ ค่าธรรมเนียมกับ slippage ต้องรวมและโชว์เสมอ.
     */
    #[Test]
    public function it_totals_the_hidden_costs_of_trading(): void
    {
        $this->trade(5);
        $this->trade(5);
        $this->trade(5);

        $overall = $this->analytics->forWallet(self::WALLET)['overall'];

        $this->assertEqualsWithDelta(0.3, $overall['total_fees'], 0.0001);
        $this->assertEqualsWithDelta(0.24, $overall['total_slippage'], 0.0001);
        $this->assertEqualsWithDelta(0.54, $overall['total_cost'], 0.0001);
    }

    // ─────────────────────────── การจัดกลุ่ม ───────────────────────────

    #[Test]
    public function it_breaks_results_down_per_strategy(): void
    {
        $this->trade(30, ['strategy' => 'grid']);
        $this->trade(-10, ['strategy' => 'dca']);

        $byStrategy = collect($this->analytics->forWallet(self::WALLET)['by_strategy'])
            ->keyBy('key');

        $this->assertSame(30.0, $byStrategy['grid']['realized_pnl']);
        $this->assertSame(-10.0, $byStrategy['dca']['realized_pnl']);
    }

    #[Test]
    public function strategies_are_ranked_by_profit(): void
    {
        $this->trade(-40, ['strategy' => 'dca']);
        $this->trade(80, ['strategy' => 'grid']);
        $this->trade(10, ['strategy' => 'momentum']);

        $order = array_column($this->analytics->forWallet(self::WALLET)['by_strategy'], 'key');

        $this->assertSame(['grid', 'momentum', 'dca'], $order);
    }

    /**
     * ⭐ แยกผลตามระดับความเสี่ยงของตลาดตอนเข้าไม้.
     *
     * ตอบคำถามที่ใช้ปรับระบบได้จริง: เทรดตอนตลาดผันผวนแล้วได้หรือเสีย
     */
    #[Test]
    public function it_breaks_results_down_by_market_risk_level(): void
    {
        $this->trade(20, ['risk_level' => 'calm']);
        $this->trade(15, ['risk_level' => 'calm']);
        $this->trade(-35, ['risk_level' => 'elevated']);

        $byRisk = collect($this->analytics->forWallet(self::WALLET)['by_risk'])->keyBy('key');

        $this->assertSame(35.0, $byRisk['calm']['realized_pnl']);
        $this->assertSame(-35.0, $byRisk['elevated']['realized_pnl'], 'เทรดตอนเสี่ยงสูงแล้วขาดทุน');
    }

    #[Test]
    public function it_breaks_results_down_per_pair(): void
    {
        $this->trade(12, ['pair' => 'BTC/USDT']);
        $this->trade(-3, ['pair' => 'ETH/USDT']);

        $byPair = collect($this->analytics->forWallet(self::WALLET)['by_pair'])->keyBy('key');

        $this->assertSame(12.0, $byPair['BTC/USDT']['realized_pnl']);
        $this->assertSame(-3.0, $byPair['ETH/USDT']['realized_pnl']);
    }

    // ─────────────────────────── การแยกข้อมูล ───────────────────────────

    #[Test]
    public function it_never_mixes_in_another_wallets_trades(): void
    {
        $this->trade(100);
        $this->trade(999, ['wallet_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);

        $this->assertSame(100.0, $this->analytics->forWallet(self::WALLET)['overall']['realized_pnl']);
    }

    #[Test]
    public function demo_and_live_results_are_kept_apart(): void
    {
        $this->trade(50, ['mode' => 'demo']);
        $this->trade(70, ['mode' => 'live']);

        $this->assertSame(50.0, $this->analytics->forWallet(self::WALLET, 'demo')['overall']['realized_pnl']);
        $this->assertSame(70.0, $this->analytics->forWallet(self::WALLET, 'live')['overall']['realized_pnl']);
    }
}
