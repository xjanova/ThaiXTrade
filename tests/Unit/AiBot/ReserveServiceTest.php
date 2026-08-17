<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotDemoAccount;
use App\Services\AiBot\ReserveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — คลังสองฝั่งของอาร์บิทราจ.
 *
 * เจ้าของสั่งว่าอาร์บิทราจ "ควรมีกระเป๋าแหล่งเงินคู่เทรดสำรองทั้งสองฝั่ง
 * เพื่อสั่งซื้อขายได้ทันทีตามส่วนต่างราคา"
 *
 * สิ่งที่ต้องคุมให้แน่น: ยอดฝั่งใดฝั่งหนึ่งห้ามติดลบ และห้ามลงมือเกินของที่มี
 * เพราะคลังที่หมดฝั่งหนึ่งคือคลังที่ทำอาร์บิทราจต่อไม่ได้อีกเลย
 *
 * Developed by Xman Studio.
 */
class ReserveServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private ReserveService $reserves;

    private AiBotConfig $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reserves = app(ReserveService::class);

        AiBotDemoAccount::create([
            'wallet_address' => self::WALLET,
            'balance' => 10000,
            'starting_balance' => 10000,
        ]);

        $this->bot = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอทอาร์บิทราจ',
            'pair' => 'BTC/USDT',
            'strategy' => 'arbitrage',
            'timeframe' => '1m',
            'status' => 'running',
            'mode' => 'demo',
        ]);
    }

    // ─────────────────────────── ตั้งคลัง ───────────────────────────

    /**
     * ⭐ ตั้งคลังแล้วต้องมีของทั้งสองฝั่ง ไม่ใช่เงินอย่างเดียว.
     */
    #[Test]
    public function funding_splits_capital_into_both_sides_of_the_pair(): void
    {
        $result = $this->reserves->fund($this->bot, 1000, 100);

        $this->assertTrue($result['ok']);

        $reserve = $result['reserve'];

        // ครึ่งหนึ่งกลายเป็นเหรียญ (500 ÷ 100 = 5) อีกครึ่งเก็บเป็นเงิน
        $this->assertEqualsWithDelta(5.0, (float) $reserve->base_qty, 0.00000001);
        $this->assertEqualsWithDelta(500.0, (float) $reserve->quote_amount, 0.00001);
        $this->assertEqualsWithDelta(1000.0, (float) $reserve->funded_quote, 0.00001);

        $this->assertTrue($reserve->canActBothWays(100), 'ต้องพร้อมลงมือได้ทั้งสองทิศ');
    }

    #[Test]
    public function funding_moves_the_money_out_of_the_free_balance(): void
    {
        $this->reserves->fund($this->bot, 2500, 100);

        $balance = (float) AiBotDemoAccount::where('wallet_address', self::WALLET)->value('balance');

        $this->assertEqualsWithDelta(7500.0, $balance, 0.00001);
    }

    #[Test]
    public function funding_is_refused_when_the_demo_balance_is_too_small(): void
    {
        $result = $this->reserves->fund($this->bot, 999999, 100);

        $this->assertFalse($result['ok']);
        $this->assertNull($this->reserves->find($this->bot));
    }

    #[Test]
    public function funding_needs_a_real_price_to_split_against(): void
    {
        $this->assertFalse($this->reserves->fund($this->bot, 1000, 0)['ok']);
    }

    /**
     * โหมดจริงต้องให้ผู้ใช้โอนเอง — ระบบไม่ถือกุญแจของใคร.
     */
    #[Test]
    public function live_mode_cannot_be_funded_by_the_system(): void
    {
        $this->bot->update(['mode' => 'live']);

        $this->assertFalse($this->reserves->fund($this->bot, 1000, 100)['ok']);
    }

    // ─────────────────────────── ลงมือจับส่วนต่าง ───────────────────────────

    /**
     * ⭐ ขายเหรียญออก: เหรียญลด เงินเพิ่ม (หักค่าธรรมเนียม).
     */
    #[Test]
    public function selling_base_moves_value_from_coins_into_cash(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        $result = $this->reserves->execute($this->bot, 'sell_base', 100, 200, 0.001);

        $this->assertTrue($result['ok']);

        $reserve = $this->reserves->find($this->bot);

        // ขาย 200 ที่ราคา 100 = 2 เหรียญ เหลือ 3
        $this->assertEqualsWithDelta(3.0, (float) $reserve->base_qty, 0.00000001);
        // ได้เงิน 200 หักค่าธรรมเนียม 0.2 → 500 + 199.8
        $this->assertEqualsWithDelta(699.8, (float) $reserve->quote_amount, 0.00001);
    }

    /**
     * ⭐ ซื้อเหรียญเข้า: เงินลด เหรียญเพิ่ม.
     */
    #[Test]
    public function buying_base_moves_value_from_cash_into_coins(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        $this->assertTrue($this->reserves->execute($this->bot, 'buy_base', 100, 200, 0.001)['ok']);

        $reserve = $this->reserves->find($this->bot);

        $this->assertEqualsWithDelta(300.0, (float) $reserve->quote_amount, 0.00001);
        // จ่าย 200 หักค่าธรรมเนียม 0.2 → ได้เหรียญ 199.8/100 = 1.998
        $this->assertEqualsWithDelta(6.998, (float) $reserve->base_qty, 0.00000001);
    }

    /**
     * ⭐ ห้ามขายเกินเหรียญที่มี — คลังติดลบคือคลังที่พังถาวร.
     */
    #[Test]
    public function it_refuses_to_sell_more_coins_than_the_reserve_holds(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);   // มีเหรียญ 5

        $result = $this->reserves->execute($this->bot, 'sell_base', 100, 100000, 0.001);

        $this->assertFalse($result['ok']);

        $reserve = $this->reserves->find($this->bot);
        $this->assertEqualsWithDelta(5.0, (float) $reserve->base_qty, 0.00000001, 'ยอดต้องไม่ถูกแตะเลย');
    }

    /**
     * ⭐ ห้ามใช้เงินเกินที่มีเช่นกัน.
     */
    #[Test]
    public function it_refuses_to_spend_more_cash_than_the_reserve_holds(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);   // มีเงิน 500

        $this->assertFalse($this->reserves->execute($this->bot, 'buy_base', 100, 99999, 0.001)['ok']);

        $this->assertEqualsWithDelta(500.0, (float) $this->reserves->find($this->bot)->quote_amount, 0.00001);
    }

    #[Test]
    public function an_unknown_direction_is_rejected(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        $this->assertFalse($this->reserves->execute($this->bot, 'ทิศทางมั่ว', 100, 100)['ok']);
    }

    #[Test]
    public function executing_without_a_reserve_is_refused(): void
    {
        $this->assertFalse($this->reserves->execute($this->bot, 'sell_base', 100, 100)['ok']);
    }

    #[Test]
    public function each_execution_counts_as_a_round_trip(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        $this->reserves->execute($this->bot, 'sell_base', 100, 100);
        $this->reserves->execute($this->bot, 'buy_base', 99, 100);

        $this->assertSame(2, $this->reserves->find($this->bot)->round_trips);
    }

    // ─────────────────────────── มูลค่าและกำไร ───────────────────────────

    /**
     * ⭐ ซื้อถูกขายแพงครบรอบแล้วมูลค่าคลังต้องโตขึ้น.
     */
    #[Test]
    public function capturing_a_real_spread_grows_the_reserve(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        // ขายตอนแพง (105) แล้วซื้อคืนตอนถูก (95) ด้วยขนาดเท่ากัน
        $this->reserves->execute($this->bot, 'sell_base', 105, 210, 0);
        $this->reserves->execute($this->bot, 'buy_base', 95, 210, 0);

        $reserve = $this->reserves->find($this->bot);

        // เหรียญกลับมามากกว่าเดิมเพราะซื้อคืนได้ถูกลง
        $this->assertGreaterThan(5.0, (float) $reserve->base_qty);
        $this->assertGreaterThan(0, (float) $reserve->realized_pnl);
    }

    #[Test]
    public function total_value_counts_both_sides_at_the_current_price(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        $reserve = $this->reserves->find($this->bot);

        // เหรียญ 5 ตัวที่ราคา 120 = 600 บวกเงินสด 500
        $this->assertEqualsWithDelta(1100.0, $reserve->totalValue(120), 0.00001);
    }

    // ─────────────────────────── คืนทุน ───────────────────────────

    #[Test]
    public function releasing_returns_both_sides_back_into_the_demo_balance(): void
    {
        $this->reserves->fund($this->bot, 1000, 100);

        $result = $this->reserves->release($this->bot, 100);

        $this->assertTrue($result['ok']);
        $this->assertEqualsWithDelta(1000.0, $result['returned'], 0.00001);
        $this->assertNull($this->reserves->find($this->bot));

        $balance = (float) AiBotDemoAccount::where('wallet_address', self::WALLET)->value('balance');
        $this->assertEqualsWithDelta(10000.0, $balance, 0.00001);
    }

    #[Test]
    public function releasing_a_reserve_that_does_not_exist_is_refused(): void
    {
        $this->assertFalse($this->reserves->release($this->bot, 100)['ok']);
    }

    /**
     * คลังของบอทคนละตัวต้องแยกกันเด็ดขาด.
     */
    #[Test]
    public function reserves_are_isolated_per_bot(): void
    {
        $other = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอทที่สอง',
            'pair' => 'ETH/USDT',
            'strategy' => 'arbitrage',
            'timeframe' => '1m',
            'status' => 'running',
            'mode' => 'demo',
        ]);

        $this->reserves->fund($this->bot, 1000, 100);

        $this->assertNotNull($this->reserves->find($this->bot));
        $this->assertNull($this->reserves->find($other));
    }
}
