<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotPosition;
use App\Models\AiBotTrade;
use App\Services\AiBot\PaperBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โบรกเกอร์จำลองต้องไม่โกหกผู้ใช้.
 *
 * ข้อตกลงสำคัญที่สุดของโหมดทดลอง: ผลที่เห็นต้อง "แย่กว่าหรือเท่ากับ" ของจริงเสมอ
 * ถ้าโหมดทดลองให้ผลสวยเกินจริง ผู้ใช้จะเช่าแล้วผิดหวัง — เสียลูกค้าถาวร
 * แพงกว่าการไม่ได้ลูกค้ามาแต่แรกมาก
 *
 * Developed by Xman Studio.
 */
class PaperBrokerTest extends TestCase
{
    use RefreshDatabase;

    private PaperBroker $broker;

    private AiBotConfig $bot;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aibot_risk.demo.starting_balance' => 10000.0,
            'aibot_risk.demo.fee_rate' => 0.1,     // 0.1% ต่อไม้
            'aibot_risk.demo.slippage_bps' => 8,   // 0.08%
            'aibot_risk.demo.max_resets_per_day' => 3,
        ]);

        $this->broker = app(PaperBroker::class);
        $this->bot = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอททดสอบ',
            'pair' => 'BTC/USDT',
            'strategy' => 'momentum',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
        ]);
    }

    // ─────────────────────────── ราคาที่ได้จริง ───────────────────────────

    /**
     * slippage ต้องเข้าข้างตลาดเสมอ ไม่ใช่เข้าข้างผู้ใช้.
     */
    #[Test]
    public function fill_price_is_always_worse_than_market(): void
    {
        $market = 100.0;

        $this->assertGreaterThan($market, $this->broker->fillPrice($market, 'buy'), 'ซื้อต้องได้ราคาแพงกว่าตลาด');
        $this->assertLessThan($market, $this->broker->fillPrice($market, 'sell'), 'ขายต้องได้ราคาถูกกว่าตลาด');

        $this->assertEqualsWithDelta(100.08, $this->broker->fillPrice($market, 'buy'), 0.0001);
        $this->assertEqualsWithDelta(99.92, $this->broker->fillPrice($market, 'sell'), 0.0001);
    }

    // ─────────────────────────── ไม้ซื้อ ───────────────────────────

    /**
     * ค่าธรรมเนียมหักจากงบก่อน ที่เหลือถึงแปลงเป็นเหรียญ.
     */
    #[Test]
    public function buy_deducts_fee_before_converting_to_coins(): void
    {
        $result = $this->broker->buy($this->bot, 100.0, 1000.0, ['reason' => 'ทดสอบ']);

        $this->assertTrue($result['ok']);

        $fee = 1000.0 * 0.001;              // 1 USDT
        $spend = 1000.0 - $fee;             // 999 USDT
        $expectedQty = $spend / 100.08;     // แปลงที่ราคาหลัง slippage

        $trade = $result['trade'];
        $this->assertEqualsWithDelta($fee, (float) $trade->fee, 0.00001);
        $this->assertEqualsWithDelta($expectedQty, (float) $trade->quantity, 0.00000001);

        // เงินออกจากกระเป๋าเต็มงบ (ทั้งค่าเหรียญและค่าธรรมเนียม)
        $this->assertEqualsWithDelta(9000.0, (float) $this->broker->account(self::WALLET)->balance, 0.00001);
    }

    /**
     * งบต้องถูกจำกัดด้วยเงินที่มีจริง — ห้ามติดลบ.
     */
    #[Test]
    public function buy_never_spends_more_than_the_balance(): void
    {
        $this->broker->buy($this->bot, 100.0, 999999.0, ['reason' => 'ขอเกินตัว']);

        $balance = (float) $this->broker->account(self::WALLET)->balance;

        $this->assertGreaterThanOrEqual(0.0, $balance);
        $this->assertEqualsWithDelta(0.0, $balance, 0.00001);
    }

    /**
     * เงินไม่พอต้องปฏิเสธอย่างสุภาพ ไม่ใช่สร้างไม้ผี.
     */
    #[Test]
    public function buy_is_rejected_when_credits_run_out(): void
    {
        AiBotDemoAccount::create([
            'wallet_address' => self::WALLET,
            'balance' => 0.5,
            'starting_balance' => 10000.0,
        ]);

        $result = $this->broker->buy($this->bot, 100.0, 500.0, ['reason' => 'เงินหมด']);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, AiBotPosition::count());
        $this->assertSame(0, AiBotTrade::count());
    }

    /**
     * ราคาตลาดเป็นศูนย์ = ข้อมูลเสีย ต้องไม่หารด้วยศูนย์.
     */
    #[Test]
    public function buy_refuses_a_zero_price(): void
    {
        $result = $this->broker->buy($this->bot, 0.0, 500.0, ['reason' => 'ราคาเสีย']);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, AiBotPosition::count());
    }

    /**
     * เติมไม้ (DCA) ต้องเฉลี่ยต้นทุนใหม่จากเงินที่จ่ายไปทั้งหมด.
     */
    #[Test]
    public function pyramiding_recalculates_the_average_cost(): void
    {
        $this->broker->buy($this->bot, 100.0, 1000.0, ['reason' => 'ไม้แรก']);
        $this->broker->buy($this->bot, 50.0, 1000.0, ['reason' => 'ไม้สอง ราคาถูกลง']);

        $position = AiBotPosition::first();

        $this->assertSame(2, $position->entry_count);
        $this->assertEqualsWithDelta(2000.0, (float) $position->cost_basis, 0.00001);

        // ต้นทุนเฉลี่ย = เงินที่จ่ายทั้งหมด ÷ จำนวนเหรียญทั้งหมด
        $expected = 2000.0 / (float) $position->quantity;
        $this->assertEqualsWithDelta($expected, (float) $position->entry_price, 0.00000001);

        // ซื้อไม้สองที่ราคาครึ่งเดียว ต้นทุนเฉลี่ยต้องอยู่ระหว่างสองราคา
        $this->assertGreaterThan(50.0, (float) $position->entry_price);
        $this->assertLessThan(100.0, (float) $position->entry_price);
    }

    // ─────────────────────────── ไม้ขาย ───────────────────────────

    /**
     * ขายแล้วต้องได้เงินคืน ปิดไม้ และบันทึกกำไรขาดทุนจริง.
     */
    #[Test]
    public function sell_closes_the_position_and_credits_the_proceeds(): void
    {
        $this->broker->buy($this->bot, 100.0, 1000.0, ['reason' => 'เข้าไม้']);
        $qty = (float) AiBotPosition::first()->quantity;

        $result = $this->broker->sell($this->bot, 120.0, ['reason' => 'ทำกำไร']);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, AiBotPosition::count(), 'ขายแล้วต้องไม่เหลือไม้ค้าง');

        $gross = $qty * 119.904;            // 120 หัก slippage 8 bps
        $proceeds = $gross - ($gross * 0.001);

        $this->assertEqualsWithDelta($proceeds, 9000.0 + $proceeds - 9000.0, 0.00001);
        $this->assertEqualsWithDelta(9000.0 + $proceeds, (float) $this->broker->account(self::WALLET)->balance, 0.0001);
        $this->assertEqualsWithDelta($proceeds - 1000.0, $result['pnl'], 0.0001);
        $this->assertGreaterThan(0.0, $result['pnl'], 'ขายที่ราคาสูงกว่าต้นทุน 20% ต้องได้กำไร');
    }

    /**
     * ไม่มีของก็ต้องขายไม่ได้ — กันการสร้างสถานะติดลบ (short) ที่ระบบไม่รองรับ.
     */
    #[Test]
    public function sell_is_rejected_when_there_is_nothing_to_sell(): void
    {
        $result = $this->broker->sell($this->bot, 100.0, ['reason' => 'ขายลม']);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, AiBotTrade::count());
    }

    // ─────────────────────── ข้อตกลงหลักของโหมดทดลอง ───────────────────────

    /**
     * ⭐ ซื้อแล้วขายทันทีที่ราคาเดิม ต้อง "ขาดทุน" เสมอ.
     *
     * นี่คือหลักประกันว่าโหมดทดลองไม่ได้ให้ผลสวยเกินจริง — ถ้าเทสต์นี้แดง
     * แปลว่ามีใครลืมคิดค่าธรรมเนียมหรือ slippage แล้วผู้ใช้จะถูกหลอก
     */
    #[Test]
    public function a_flat_round_trip_always_loses_money(): void
    {
        $start = (float) $this->broker->account(self::WALLET)->balance;

        $this->broker->buy($this->bot, 100.0, 1000.0, ['reason' => 'เข้า']);
        $result = $this->broker->sell($this->bot, 100.0, ['reason' => 'ออกที่ราคาเดิม']);

        $end = (float) $this->broker->account(self::WALLET)->balance;

        $this->assertLessThan(0.0, $result['pnl'], 'เข้าออกที่ราคาเดิมต้องขาดทุนจากค่าธรรมเนียม + slippage');
        $this->assertLessThan($start, $end, 'ยอดเงินต้องลดลง');

        // ต้นทุนรวมประมาณ 0.1%×2 (ค่าธรรมเนียม) + 0.08%×2 (slippage) ≈ 0.36%
        $this->assertEqualsWithDelta(0.36, (($start - $end) / 1000.0) * 100, 0.05);
    }

    /**
     * ทุกไม้ต้องบันทึกเหตุผลไว้ — ผู้ใช้ต้องย้อนดูได้ว่าบอทคิดอะไร.
     */
    #[Test]
    public function every_trade_records_why_the_bot_acted(): void
    {
        $this->broker->buy($this->bot, 100.0, 500.0, [
            'reason' => 'EMA ตัดขึ้น + RSI ยังไม่ overbought',
            'risk_level' => 'caution',
            'meta' => ['rsi' => 58.2],
        ]);

        $trade = AiBotTrade::first();

        $this->assertSame('EMA ตัดขึ้น + RSI ยังไม่ overbought', $trade->reason);
        $this->assertSame('caution', $trade->risk_level);
        $this->assertSame(['rsi' => 58.2], $trade->signal_meta);
        $this->assertSame('demo', $trade->mode, 'ไม้ทดลองต้องไม่ปนกับไม้จริงเด็ดขาด');
    }

    // ─────────────────────────── ล้างพอร์ต ───────────────────────────

    /**
     * ล้างพอร์ตต้องคืนทุนตั้งต้นและเก็บกวาดให้หมด.
     */
    #[Test]
    public function reset_restores_the_starting_balance_and_clears_history(): void
    {
        $this->broker->buy($this->bot, 100.0, 3000.0, ['reason' => 'เข้าไม้']);

        $this->assertSame(1, AiBotPosition::count());

        $result = $this->broker->reset(self::WALLET);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, AiBotPosition::count());
        $this->assertSame(0, AiBotTrade::count());
        $this->assertEqualsWithDelta(10000.0, (float) $this->broker->account(self::WALLET)->balance, 0.00001);
    }

    /**
     * ล้างรัวๆ เพื่อไล่หาผลลัพธ์สวยๆ ต้องถูกจำกัด.
     */
    #[Test]
    public function reset_is_capped_per_day(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->broker->reset(self::WALLET)['ok'], "ครั้งที่ {$i} ควรผ่าน");
        }

        $blocked = $this->broker->reset(self::WALLET);

        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('3', $blocked['reason']);
    }

    /**
     * โควตาล้างพอร์ตต้องรีเซ็ตเมื่อขึ้นวันใหม่.
     */
    #[Test]
    public function the_reset_quota_refills_the_next_day(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->broker->reset(self::WALLET);
        }

        $this->travel(1)->days();

        $this->assertTrue($this->broker->reset(self::WALLET)['ok'], 'วันใหม่ต้องล้างได้อีก');
    }

    /**
     * กระเป๋าทดลองของแต่ละ wallet ต้องแยกจากกัน.
     */
    #[Test]
    public function demo_accounts_are_isolated_per_wallet(): void
    {
        $other = '0x2222222222222222222222222222222222222222';

        $this->broker->buy($this->bot, 100.0, 5000.0, ['reason' => 'เข้าไม้']);

        $this->assertEqualsWithDelta(5000.0, (float) $this->broker->account(self::WALLET)->balance, 0.00001);
        $this->assertEqualsWithDelta(10000.0, (float) $this->broker->account($other)->balance, 0.00001);
    }
}
