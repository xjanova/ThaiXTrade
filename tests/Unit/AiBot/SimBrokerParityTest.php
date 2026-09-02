<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotTrade;
use App\Services\AiBot\Backtest\SimBroker;
use App\Services\AiBot\PaperBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โบรกเกอร์ของ backtest ต้องคิดเงินเท่าโบรกเกอร์ของโหมดทดลองเป๊ะ.
 *
 * นี่คือเทสต์ที่ทำให้ผล backtest "เชื่อได้": ถ้าสองตัวนี้คิดค่าธรรมเนียม slippage
 * หรือต้นทุนเฉลี่ยต่างกันแม้ทศนิยมเดียว กลยุทธ์ที่ชนะบนกระดาษจะแพ้ของจริง
 * โดยไม่มีใครรู้ว่าเพราะอะไร — ยิงตัวเลขเดียวกันใส่ทั้งคู่แล้วเทียบถึงทศนิยมที่ 8
 *
 * Developed by Xman Studio.
 */
class SimBrokerParityTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x5555555555555555555555555555555555555555';

    private PaperBroker $paper;

    private AiBotConfig $bot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aibot_risk.demo.starting_balance' => 10000.0,
            'aibot_risk.demo.fee_rate' => 0.1,
            'aibot_risk.demo.slippage_bps' => 8,
        ]);

        $this->paper = app(PaperBroker::class);
        $this->bot = AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => 'parity', 'pair' => 'BTC/USDT',
            'strategy' => 'dca', 'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
        ]);
    }

    private function sim(): SimBroker
    {
        return new SimBroker(10000.0, 0.1 / 100, 8 / 10000);
    }

    #[Test]
    public function ซื้อครั้งเดียวแล้วขาย_ตัวเลขทุกช่องต้องเท่ากัน(): void
    {
        $ctx = ['reason' => 'parity', 'meta' => [], 'risk_level' => 'calm'];

        $this->paper->buy($this->bot, 100.0, 250.0, $ctx);
        $this->paper->sell($this->bot, 113.37, $ctx);

        $sim = $this->sim();
        $sim->buy(0, 0, 100.0, 250.0, 'parity');
        $sim->sell(1, 1, 113.37, 'parity');

        [$paperBuy, $paperSell] = AiBotTrade::orderBy('id')->get()->all();
        [$simBuy, $simSell] = $sim->trades;

        foreach (['price', 'quantity', 'gross_value', 'fee', 'slippage_cost'] as $field) {
            $this->assertEqualsWithDelta((float) $paperBuy->{$field}, $simBuy[$field], 1e-8, "buy.{$field}");
            $this->assertEqualsWithDelta((float) $paperSell->{$field}, $simSell[$field], 1e-8, "sell.{$field}");
        }

        $this->assertEqualsWithDelta((float) $paperSell->realized_pnl, $simSell['realized_pnl'], 1e-8, 'realized_pnl');

        $paperBalance = (float) $this->paper->account(self::WALLET, 'dca')->balance;
        $this->assertEqualsWithDelta($paperBalance, $sim->cash, 1e-6, 'เงินสดหลังปิดไม้');
    }

    #[Test]
    public function เติมไม้สองรอบแล้วต้นทุนเฉลี่ยต้องเท่ากัน(): void
    {
        $ctx = ['reason' => 'parity', 'meta' => [], 'risk_level' => 'calm'];

        $this->paper->buy($this->bot, 100.0, 40.0, $ctx);
        $this->paper->buy($this->bot, 90.0, 60.0, $ctx);

        $sim = $this->sim();
        $sim->buy(0, 0, 100.0, 40.0, 'a');
        $sim->buy(1, 1, 90.0, 60.0, 'b');

        $position = $this->bot->positions()->first();

        $this->assertEqualsWithDelta((float) $position->entry_price, $sim->entryPrice(), 1e-8, 'ต้นทุนเฉลี่ยต่อหน่วย');
        $this->assertEqualsWithDelta((float) $position->cost_basis, $sim->position['cost'], 1e-8, 'เงินที่จ่ายไปทั้งหมด');
        $this->assertEqualsWithDelta((float) $position->quantity, $sim->position['qty'], 1e-8, 'จำนวนที่ถือ');

        $this->paper->sell($this->bot, 95.0, $ctx);
        $pnl = $sim->sell(2, 2, 95.0, 'c');

        $this->assertEqualsWithDelta((float) AiBotTrade::where('side', 'sell')->first()->realized_pnl, $pnl, 1e-8);
    }

    #[Test]
    public function งบเกินเงินสดต้องถูกตัดเหลือเท่าที่มี_เหมือนกันทั้งคู่(): void
    {
        $ctx = ['reason' => 'parity', 'meta' => [], 'risk_level' => 'calm'];

        $this->paper->buy($this->bot, 100.0, 50000.0, $ctx);   // มีแค่ 10,000

        $sim = $this->sim();
        $sim->buy(0, 0, 100.0, 50000.0, 'a');

        $this->assertEqualsWithDelta((float) AiBotTrade::first()->quantity, $sim->position['qty'], 1e-8);
        $this->assertEqualsWithDelta(0.0, $sim->cash, 1e-8);
    }
}
