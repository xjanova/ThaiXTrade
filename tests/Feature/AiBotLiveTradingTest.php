<?php

namespace Tests\Feature;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotPosition;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\BotRunner;
use App\Services\AiBot\Live\LiveBroker;
use App\Services\AiBot\MacroTrendService;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\Wallet\BotWalletSigner;
use App\Services\MarketDataService;
use App\Services\Web3BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทเทรดเงินจริงจากกระเป๋าบอท (LiveBroker + aibot:wallet-transfers).
 *
 * สิ่งที่ชุดนี้ยึดไว้ (พังข้อไหน = เงินจริงของผู้ใช้เสียหาย):
 *   - บัญชีไม้ถูกเขียนจากใบเสร็จบนเชนเท่านั้น — คำสั่งที่ยังไม่ยืนยัน/ไม่ลงบล็อกไม่แตะบัญชี
 *   - ทุกด่านที่ไม่ผ่าน (ราคาเบี่ยง · หมดอายุ · แถวถูกแก้ · ระบบปิด) ปิดคำสั่งก่อนแตะกุญแจ
 *   - เหรียญ/router/ผู้รับ มาจาก config และกระเป๋าเอง ไม่ใช่จากแถวคำสั่ง
 *   - กระเป๋าหนึ่งใบมีธุรกรรมกำลังส่งได้ทีละรายการ (ถอน · approve · สวอป นับรวมกัน)
 *   - บอทที่ยังมีเงินจริงค้างอยู่ ลบ/สลับโหมด/ย้ายคู่ไม่ได้ และเจ้าของสั่งปิดไม้เองได้เสมอ
 *
 * เชนทั้งหมดเป็นของปลอม (FakeBotChain) — ไม่มีธุรกรรมจริง ไม่มีการเรียก RPC
 *
 * Developed by Xman Studio.
 */
class AiBotLiveTradingTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x4242424242424242424242424242424242424242';

    private const USDT = '0x55d398326f99059ff775485246999027b3197955';

    private const BTCB = '0x7130d2a12b9bcbfae4f2634d864a1ee1ce3ead9c';

    private const POOL = '0x00000000000000000000000000000000000000aa';

    private const V2_ROUTER = '0x10ed43c718714eb63d5aa57b78b54704e256024e';

    private const V3_ROUTER = '0x13f4ea83d0bd40e75c8222255bc855a974568dd4';

    private FakeBotChain $chain;

    /** @var list<array<string, float|int>> แท่งเทียนที่ตลาดปลอมคืน */
    private array $candles = [];

    /** ราคาตลาดล่าสุดที่ตลาดปลอมคืน — null = ดึงราคาไม่ได้ (ตัวส่งข้ามด่านราคาอ้างอิงเก่า) */
    private ?float $tickerPrice = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'aibot.live_enabled' => true,
            'aibot.live.execution' => true,
            'aibot.live.entries_paused' => false,
            'aibot.live.slippage_bps' => 50,
            'aibot.live.entry_max_deviation_bps' => 120,
            'aibot.live.exit_max_deviation_bps' => 300,
            'aibot.live.min_order_usd' => 10,
            'aibot.live.max_order_usd' => 500,
            'aibot.live.order_ttl_minutes' => 10,
            'aibot.live.retry_backoff_minutes' => 5,
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
            'aibot.bot_wallet.confirmations' => 2,
        ]);

        $this->partialMock(Web3BalanceService::class, function ($mock) {
            $mock->shouldReceive('getNativeBalance')->andReturnUsing(fn () => $this->chain->nativeHuman);
            $mock->shouldReceive('getTokenBalance')->andReturn('1000');
        });

        $this->chain = new FakeBotChain();
        $this->app->instance(BotWalletSigner::class, $this->chain);

        // ตลาดปลอม — ไม่ยิงเน็ตจริง
        $this->app->bind(MarketDataService::class, function () {
            return new class($this) extends MarketDataService
            {
                public function __construct(private $test) {}

                public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
                {
                    return $this->test->candlesForFake();
                }

                public function getTokenPrice(string $symbol): ?array
                {
                    return ['price' => $this->test->tickerForFake()];
                }
            };
        });

        $this->app->instance(MacroTrendService::class, new class() extends MacroTrendService
        {
            public function __construct() {}

            public function current(?int $period = null): array
            {
                return ['up' => null, 'close' => null, 'ema' => null, 'above_pct' => null, 'symbol' => 'BTC/USDT', 'period' => 50];
            }
        });

        Cache::put('wallet_verified:'.self::OWNER, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    public function candlesForFake(): array
    {
        return $this->candles;
    }

    public function tickerForFake(): ?float
    {
        return $this->tickerPrice;
    }

    // ─────────────────────────── ตัวช่วยสร้างฉาก ───────────────────────────

    private function wallet(): AiBotWallet
    {
        return app(BotWalletService::class)->ensure(self::OWNER);
    }

    private function bot(array $attributes = []): AiBotConfig
    {
        $plan = AiBotPlan::firstOrCreate(['code' => 'pro'], [
            'name' => 'Pro', 'name_th' => 'โปร',
            'credits_per_day' => 50, 'max_bots' => 5, 'max_position_usd' => 5000,
            'features' => ['a'], 'features_th' => ['ก'], 'sort_order' => 1, 'is_active' => true,
        ]);

        AiBotSubscription::firstOrCreate(['wallet_address' => self::OWNER], [
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        $this->wallet();

        return AiBotConfig::create(array_merge([
            'wallet_address' => self::OWNER,
            'name' => 'บอทเงินจริง',
            'pair' => 'BTC/USDT',
            'strategy' => 'momentum',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'live',
            'params' => [],
            'risk' => ['max_position_usd' => 1000],
        ], $attributes));
    }

    private function live(): LiveBroker
    {
        return app(LiveBroker::class);
    }

    private function buy(AiBotConfig $bot, float $price = 68000.0, float $budget = 100.0): array
    {
        return $this->live()->buy($bot, $price, $budget, ['reason' => 'EMA ตัดขึ้น', 'meta' => ['ema' => 1], 'risk_level' => 'calm']);
    }

    private function hold(AiBotConfig $bot, string $qty = '0.001465', float $cost = 100.15): AiBotPosition
    {
        return AiBotPosition::create([
            'ai_bot_config_id' => $bot->id,
            'pair' => $bot->pair,
            'mode' => 'live',
            'quantity' => $qty,
            'entry_price' => $cost / (float) $qty,
            'cost_basis' => $cost,
            'entry_count' => 1,
            'opened_at' => now()->subHours(5),
        ]);
    }

    private function runSender(): void
    {
        $this->artisan('aibot:wallet-transfers')->assertSuccessful();
    }

    /** ใบเสร็จของสวอป: เหรียญขาเข้าออกจากกระเป๋าบอท เหรียญขาออกเข้ากระเป๋าบอท */
    private function swapReceipt(string $tokenIn, string $spentWei, string $tokenOut, string $receivedWei, string $status = '0x1'): array
    {
        $wallet = strtolower($this->wallet()->address);

        return [
            'status' => $status,
            'blockNumber' => '0x64',
            'gasUsed' => '0x30d40',             // 200,000
            'effectiveGasPrice' => '0x3b9aca00', // 1 gwei → 0.0002 BNB
            'logs' => $status === '0x1' ? [
                FakeBotChain::transferLog($tokenIn, $wallet, self::POOL, $spentWei),
                FakeBotChain::transferLog($tokenOut, self::POOL, $wallet, $receivedWei),
            ] : [],
        ];
    }

    private function approveReceipt(): array
    {
        return ['status' => '0x1', 'blockNumber' => '0x64', 'gasUsed' => '0xc350', 'effectiveGasPrice' => '0x3b9aca00', 'logs' => []];
    }

    private function order(AiBotConfig $bot): AiBotWalletTransfer
    {
        return AiBotWalletTransfer::swaps()->where('ai_bot_config_id', $bot->id)->latest('id')->firstOrFail();
    }

    private function risingCandles(int $flat = 60): array
    {
        $closes = array_fill(0, $flat, 100.0);
        $closes[] = 102.0;
        $closes[] = 102.0;   // แท่งที่ยังวิ่งอยู่ — BotRunner ตัดทิ้ง

        $candles = [];

        foreach ($closes as $i => $close) {
            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $close * 0.999, 'high' => $close * 1.002, 'low' => $close * 0.998,
                'close' => $close, 'volume' => 1000.0,
            ];
        }

        return $candles;
    }

    // ───────────────────────── 1) เข้าคิว ≠ ได้ของ ─────────────────────────

    #[Test]
    public function a_buy_is_only_queued_and_touches_no_ledger(): void
    {
        $bot = $this->bot();

        $result = $this->buy($bot);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['queued']);

        $order = $this->order($bot);
        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $order->status);
        $this->assertSame('USDT', $order->asset);
        $this->assertSame(self::USDT, $order->token_address);
        $this->assertSame('100000000000000000000', $order->amount_wei);
        $this->assertSame(self::BTCB, $order->metaValue('token_out'));
        $this->assertSame('buy', $order->metaValue('side'));
        $this->assertSame(strtolower($this->wallet()->address), strtolower($order->to_address), 'ผู้รับต้องเป็นกระเป๋าบอทเอง');
        $this->assertNotNull($order->expires_at);

        $this->assertSame(0, AiBotTrade::count(), 'ยังไม่มีธุรกรรมบนเชน = ยังไม่มีไม้');
        $this->assertSame(0, AiBotPosition::count());
        $this->assertSame([], $this->chain->calls, 'ฝั่งเข้าคิวต้องไม่แตะตัวเซ็น');
    }

    #[Test]
    public function a_second_order_is_refused_while_one_is_in_flight(): void
    {
        $bot = $this->bot();
        $this->buy($bot);

        $again = $this->buy($bot);

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('รอผลคำสั่งก่อนหน้า', $again['reason']);
        $this->assertSame(1, AiBotWalletTransfer::swaps()->count());
    }

    #[Test]
    public function orders_below_the_minimum_or_beyond_the_wallet_are_refused(): void
    {
        $bot = $this->bot();

        $small = $this->buy($bot, 68000.0, 5.0);
        $this->assertFalse($small['ok']);
        $this->assertStringContainsString('ต่ำกว่าขั้นต่ำ', $small['reason']);

        // เพดานต่อไม้ของระบบชนะทุนที่บอทขอ
        $big = $this->buy($bot, 68000.0, 5000.0);
        $this->assertTrue($big['ok']);
        $this->assertSame(500.0, (float) $this->order($bot)->amount);

        // บอทอีกตัวของกระเป๋าเดียวกัน: USDT 1000 − 500 ที่จองไว้ = เหลือ 500
        $other = $this->bot(['name' => 'ตัวที่สอง', 'pair' => 'ETH/USDT']);
        config(['aibot.live.max_order_usd' => 900]);
        $this->assertTrue($this->buy($other, 3500.0, 900.0)['ok']);
        $this->assertSame(500.0, (float) $this->order($other)->amount, 'USDT ที่คำสั่งอื่นจองไว้ใช้ซ้ำไม่ได้');
    }

    #[Test]
    public function no_gas_means_no_order(): void
    {
        $bot = $this->bot();
        $this->chain->nativeHuman = '0.0001';

        $result = $this->buy($bot);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ค่าแก๊ส', $result['reason']);
        $this->assertSame(0, AiBotWalletTransfer::count());
    }

    #[Test]
    public function an_unsupported_pair_cannot_trade_real_money(): void
    {
        $bot = $this->bot(['pair' => 'TPIX/USDT']);

        $result = $this->buy($bot, 0.02, 100.0);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ยังเทรดเงินจริงไม่ได้', $result['reason']);
    }

    #[Test]
    public function pausing_entries_blocks_buys_but_not_exits(): void
    {
        config(['aibot.live.entries_paused' => true]);
        $bot = $this->bot();

        $this->assertFalse($this->buy($bot)['ok']);

        $this->hold($bot);
        $this->assertTrue($this->live()->sell($bot, 70000.0, ['reason' => 'ตัดขาดทุน'])['ok']);
    }

    // ───────────────────── 2) ซื้อครบวงจร: approve → สวอป → ลงบัญชี ─────────────────────

    #[Test]
    public function a_buy_approves_the_exact_amount_then_swaps_and_books_from_the_receipt(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $order = $this->order($bot);
        $wallet = $this->wallet();

        // รอบ 1: สิทธิ์ยังไม่มี → ส่ง approve เท่าจำนวนไม้ ให้ router ของเส้นทางที่เลือก แล้วรอ
        $this->runSender();

        $approve = AiBotWalletTransfer::where('parent_id', $order->id)->firstOrFail();
        $this->assertSame(AiBotWalletTransfer::DIRECTION_APPROVE, $approve->direction);
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $approve->status);
        $this->assertSame('100000000000000000000', $approve->amount_wei, 'approve เท่าจำนวนของไม้ ไม่ใช่ไม่จำกัด');
        $this->assertSame(self::V3_ROUTER, strtolower($approve->to_address));
        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $order->fresh()->status, 'สวอปต้องรอ approve ลงบล็อกก่อน');
        $this->assertSame('v3', $order->fresh()->metaValue('router_pin'));
        $this->assertSame(['plan', 'sign-approve', 'send'], array_column($this->chain->calls, 'action'));

        // รอบ 2: approve ยืนยันแล้ว → เซ็นสวอปด้วย minOut = ใบเสนอราคา − slippage
        $this->chain->receipts[$approve->tx_hash] = $this->approveReceipt();
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_CONFIRMED, $approve->fresh()->status);
        $order->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $order->status);
        $this->assertNotNull($order->tx_hash);
        $this->assertNotNull($order->raw_tx);

        $signed = $this->chain->last('sign-swap');
        $this->assertSame(strtolower($wallet->address), strtolower($signed['address']));
        $this->assertSame(self::USDT, strtolower($signed['tokenIn']));
        $this->assertSame(self::BTCB, strtolower($signed['tokenOut']));
        $this->assertSame('100000000000000000000', $signed['amountIn']);
        $this->assertSame('1458670000000000', $signed['minOut'], 'minOut = 1466000000000000 × 0.995');
        $this->assertSame('v3', $signed['route']['kind']);
        $this->assertSame('v3', $this->chain->last('plan')['pin'], 'approve แล้วต้องใช้ router เดิม');
        $this->assertSame(0, AiBotTrade::count(), 'ส่งแล้วแต่ยังไม่ยืนยัน = ยังไม่มีไม้');

        // รอบ 3: สวอปยืนยัน → ไม้และของที่ถือเขียนจากใบเสร็จ (ได้ 0.001465 ไม่ใช่ 0.001466 ตามใบเสนอราคา)
        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::USDT, '100000000000000000000', self::BTCB, '1465000000000000');
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_CONFIRMED, $order->fresh()->status);

        $trade = AiBotTrade::firstOrFail();
        $this->assertSame('live', $trade->mode);
        $this->assertSame('buy', $trade->side);
        $this->assertSame($order->tx_hash, $trade->tx_hash);
        $this->assertEqualsWithDelta(0.001465, (float) $trade->quantity, 1e-12);
        $this->assertEqualsWithDelta(100.0, (float) $trade->gross_value, 1e-8);
        $this->assertEqualsWithDelta(100 / 0.001465, (float) $trade->price, 1e-4);
        // ค่าแก๊ส: สวอป 0.0002 BNB + approve 0.00005 BNB = 0.00025 BNB × $600
        $this->assertEqualsWithDelta(0.15, (float) $trade->fee, 1e-8);
        $this->assertSame('EMA ตัดขึ้น', $trade->reason);

        $position = AiBotPosition::firstOrFail();
        $this->assertSame('live', $position->mode);
        $this->assertEqualsWithDelta(0.001465, (float) $position->quantity, 1e-12);
        $this->assertEqualsWithDelta(100.15, (float) $position->cost_basis, 1e-8, 'ต้นทุน = USDT ที่จ่าย + ค่าแก๊ส');
    }

    #[Test]
    public function settling_the_same_transaction_twice_books_one_trade(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();

        $order = $this->order($bot);
        $receipt = $this->swapReceipt(self::USDT, '100000000000000000000', self::BTCB, '1465000000000000');
        $this->chain->receipts[$order->tx_hash] = $receipt;
        $this->runSender();

        $this->live()->settle($order->fresh(), $receipt);
        $this->runSender();

        $this->assertSame(1, AiBotTrade::count());
        $this->assertEqualsWithDelta(0.001465, (float) AiBotPosition::firstOrFail()->quantity, 1e-12);
    }

    // ───────────────────── 3) ด่านที่ปิดคำสั่งก่อนแตะกุญแจ ─────────────────────

    #[Test]
    public function a_quote_too_far_from_the_market_price_is_never_signed(): void
    {
        $bot = $this->bot();
        $this->buy($bot);

        // ควรได้ ~0.0014706 BTC แต่ DEX ให้ 0.00144 (แย่กว่า ~208 bps > เพดาน 120)
        $this->chain->quoteOut = '1440000000000000';
        $this->runSender();

        $order = $this->order($bot);
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $order->status);
        $this->assertSame(AiBotWalletTransfer::FAIL_PRICE_MOVED, $order->failure_code);
        $this->assertSame(['plan'], array_column($this->chain->calls, 'action'), 'ไม่ approve ไม่เซ็น ไม่ส่ง');
        $this->assertSame(0, AiBotTrade::count());
    }

    #[Test]
    public function an_order_that_waited_too_long_expires_unsigned(): void
    {
        $bot = $this->bot();
        $this->buy($bot);

        $this->travel(11)->minutes();
        $this->runSender();

        $order = $this->order($bot);
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $order->status);
        $this->assertSame(AiBotWalletTransfer::FAIL_EXPIRED, $order->failure_code);
        $this->assertSame([], $this->chain->calls);
    }

    #[Test]
    public function a_tampered_order_row_is_refused_before_any_key_is_opened(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $order = $this->order($bot);

        // แถวถูกแก้ให้สวอปไปเหรียญอื่น
        $order->mergeMeta(['token_out' => '0x000000000000000000000000000000000000dead'])->save();
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::FAIL_PAIR, $order->fresh()->failure_code);
        $this->assertSame([], $this->chain->calls);

        // แถวถูกแก้ผู้รับ
        $this->travel(6)->minutes();
        $this->buy($bot);
        $second = $this->order($bot);
        AiBotWalletTransfer::whereKey($second->id)->update(['to_address' => '0x000000000000000000000000000000000000dead']);
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::FAIL_PAIR, $second->fresh()->failure_code);
        $this->assertSame([], $this->chain->calls);
    }

    #[Test]
    public function a_tampered_deviation_cap_cannot_exceed_the_system_ceiling(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $order = $this->order($bot);
        $order->mergeMeta(['max_deviation_bps' => 9000])->save();

        // แย่กว่าราคาตลาด ~10% — เพดานระบบ (500 bps) ต้องชนะค่าที่ถูกแก้ในแถว
        $this->chain->quoteOut = '1320000000000000';
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::FAIL_PRICE_MOVED, $order->fresh()->failure_code);
    }

    /**
     * ⭐ ราคาอ้างอิงในแถว (ตอนบอทตัดสินใจ) ห่างจากราคาตลาดตอนจะเซ็นเกินเพดาน = สัญญาณเก่า/แถวถูกบิด ไม่เซ็น.
     *
     * ด่าน DEX อย่างเดียวไม่พอ: แถวที่ถูกแก้ตั้งราคาอ้างอิงให้ตรงกับพูลที่ถูกบิดราคาได้
     */
    #[Test]
    public function a_reference_price_far_from_the_current_market_is_never_signed(): void
    {
        $bot = $this->bot();
        $this->buy($bot, 68000.0);

        $this->tickerPrice = 71000.0;   // ตลาดวิ่งไปแล้ว ~4.4% (> 300 bps)
        $this->runSender();

        $order = $this->order($bot);
        $this->assertSame(AiBotWalletTransfer::FAIL_PRICE_MOVED, $order->failure_code);
        $this->assertNull($this->chain->last('sign-approve'));
        $this->assertNull($this->chain->last('sign-swap'));

        // ราคาตลาดใกล้ราคาอ้างอิง = ผ่านด่านนี้ตามปกติ
        $this->travel(6)->minutes();
        $this->buy($bot, 68000.0);
        $this->tickerPrice = 68300.0;
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $this->order($bot)->status);
    }

    #[Test]
    public function turning_execution_off_drops_queued_orders_unsigned(): void
    {
        $bot = $this->bot();
        $this->buy($bot);

        config(['aibot.live.execution' => false]);
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::FAIL_TRADING_OFF, $this->order($bot)->failure_code);
        $this->assertSame([], $this->chain->calls);
    }

    #[Test]
    public function no_liquidity_closes_the_order_without_spending_gas(): void
    {
        $bot = $this->bot();
        $this->buy($bot);

        $this->chain->quoteOut = null;
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::FAIL_NO_ROUTE, $this->order($bot)->failure_code);
        $this->assertSame(['plan'], array_column($this->chain->calls, 'action'));
    }

    #[Test]
    public function a_chain_read_failure_keeps_the_order_queued_for_the_next_round(): void
    {
        $bot = $this->bot();
        $this->buy($bot);

        $this->chain->planFails = true;
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $this->order($bot)->status);
    }

    #[Test]
    public function repeated_approve_failures_close_the_order_instead_of_burning_gas(): void
    {
        config(['aibot.live.max_approve_attempts' => 2]);
        $bot = $this->bot();
        $this->buy($bot);
        $order = $this->order($bot);

        // approve สองครั้งลงบล็อกแบบ revert — สิทธิ์ไม่เคยขึ้น
        for ($i = 0; $i < 2; $i++) {
            $this->runSender();
            $approve = AiBotWalletTransfer::where('parent_id', $order->id)->latest('id')->firstOrFail();
            $this->chain->receipts[$approve->tx_hash] = ['status' => '0x0', 'blockNumber' => '0x64', 'gasUsed' => '0xc350', 'effectiveGasPrice' => '0x3b9aca00', 'logs' => []];
        }

        $this->runSender();

        $this->assertSame(2, AiBotWalletTransfer::where('parent_id', $order->id)->count());
        $this->assertSame(AiBotWalletTransfer::FAIL_APPROVE, $order->fresh()->failure_code);
        $this->assertNull($this->chain->last('sign-swap'));
    }

    // ───────────────────── 4) ส่งแล้วไม่สำเร็จ = บัญชีไม่ขยับ ─────────────────────

    #[Test]
    public function a_reverted_swap_books_nothing_and_backs_the_bot_off(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();

        $order = $this->order($bot);
        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::USDT, '0', self::BTCB, '0', '0x0');
        $this->runSender();

        $order->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $order->status);
        $this->assertSame(AiBotWalletTransfer::FAIL_REVERTED, $order->failure_code);
        $this->assertSame('0.000200000000000000', $order->metaValue('gas_bnb'), 'ไม้ที่ revert ก็เสียค่าแก๊ส — ต้องบันทึกไว้');
        $this->assertSame(0, AiBotTrade::count());
        $this->assertSame(0, AiBotPosition::count());

        // เพิ่งล้ม = พักก่อนสั่งใหม่ (ไม่วนเผาแก๊ส)
        $retry = $this->buy($bot);
        $this->assertFalse($retry['ok']);
        $this->assertStringContainsString('พักสักครู่', $retry['reason']);

        $this->travel(6)->minutes();
        $this->assertTrue($this->buy($bot)['ok']);
    }

    #[Test]
    public function a_confirmed_swap_with_an_unreadable_receipt_stays_open_and_books_nothing(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();

        $order = $this->order($bot);
        $this->chain->receipts[$order->tx_hash] = ['status' => '0x1', 'blockNumber' => '0x64', 'gasUsed' => '0x30d40', 'effectiveGasPrice' => '0x3b9aca00', 'logs' => []];
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $order->fresh()->status, 'ลงบัญชีไม่ได้ = ห้ามปิดคำสั่ง');
        $this->assertSame(0, AiBotTrade::count());

        // กระเป๋านี้ยังติดรายการอยู่ — คำสั่งใหม่ของบอทถูกปฏิเสธ
        $this->assertFalse($this->buy($bot)['ok']);
    }

    #[Test]
    public function a_swap_written_off_as_dropped_is_booked_when_it_lands_late(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();

        $order = $this->order($bot);
        AiBotWalletTransfer::whereKey($order->id)->update([
            'status' => AiBotWalletTransfer::STATUS_FAILED,
            'failure_code' => AiBotWalletTransfer::FAIL_DROPPED,
            'failure_reason' => 'x',
        ]);

        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::USDT, '100000000000000000000', self::BTCB, '1465000000000000');
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertNull($order->fresh()->failure_code);
        $this->assertSame(1, AiBotTrade::where('tx_hash', $order->tx_hash)->count());
        $this->assertSame(1, AiBotPosition::where('mode', 'live')->count());
    }

    // ───────────────────────────── 5) ขาย ─────────────────────────────

    #[Test]
    public function a_sell_closes_the_position_with_profit_from_the_receipt(): void
    {
        $bot = $this->bot();
        $this->hold($bot);

        $this->assertTrue($this->live()->sell($bot, 70000.0, ['reason' => 'ถึงเป้าทำกำไร'])['ok']);

        $order = $this->order($bot);
        $this->assertSame('BTC', $order->asset);
        $this->assertSame(self::BTCB, $order->token_address);
        $this->assertSame('1465000000000000', $order->amount_wei);
        $this->assertSame(300, $order->metaValue('max_deviation_bps'));

        $this->chain->balanceIn = '1465000000000000';
        $this->chain->allowance['v3'] = '1465000000000000';
        $this->chain->quoteOut = '102200000000000000000';   // ควรได้ 102.55 → แย่กว่า ~34 bps
        $this->runSender();

        $signed = $this->chain->last('sign-swap');
        $this->assertSame(self::BTCB, strtolower($signed['tokenIn']));
        $this->assertSame(self::USDT, strtolower($signed['tokenOut']));
        $this->assertSame('1465000000000000', $signed['amountIn']);

        $order->refresh();
        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::BTCB, '1465000000000000', self::USDT, '102100000000000000000');
        $this->runSender();

        $trade = AiBotTrade::firstOrFail();
        $this->assertSame('sell', $trade->side);
        $this->assertEqualsWithDelta(102.1, (float) $trade->gross_value, 1e-8);
        $this->assertEqualsWithDelta(0.12, (float) $trade->fee, 1e-8);
        // 102.10 − ค่าแก๊ส 0.12 − ต้นทุน 100.15
        $this->assertEqualsWithDelta(1.83, (float) $trade->realized_pnl, 1e-8);
        $this->assertSame(0, AiBotPosition::count());
    }

    #[Test]
    public function selling_with_fewer_coins_on_chain_closes_only_what_was_sold(): void
    {
        $bot = $this->bot();
        $this->hold($bot, '0.002000', 140.0);
        $this->live()->sell($bot, 70000.0, ['reason' => 'ตัดขาดทุน']);

        // ในกระเป๋าเหลือครึ่งเดียว — ขายเท่าที่มี
        $this->chain->balanceIn = '1000000000000000';
        $this->chain->allowance['v3'] = '2000000000000000';
        $this->chain->quoteOut = '69800000000000000000';
        $this->runSender();

        $this->assertSame('1000000000000000', $this->chain->last('sign-swap')['amountIn']);

        $order = $this->order($bot);
        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::BTCB, '1000000000000000', self::USDT, '69700000000000000000');
        $this->runSender();

        $position = AiBotPosition::firstOrFail();
        $this->assertEqualsWithDelta(0.001, (float) $position->quantity, 1e-12);
        $this->assertEqualsWithDelta(70.0, (float) $position->cost_basis, 1e-8);
        // 69.70 − 0.12 − ต้นทุนครึ่งหนึ่ง 70
        $this->assertEqualsWithDelta(-0.42, (float) AiBotTrade::firstOrFail()->realized_pnl, 1e-8);
    }

    /**
     * ⭐ ขาย = เหรียญของ "ไม้ที่ถืออยู่" เสมอ แม้คู่ของบอทจะไม่ตรงกับไม้แล้ว
     *    (ขายตามคู่ปัจจุบันของบอท = เอาจำนวนของเหรียญหนึ่งไปขายเป็นอีกเหรียญ).
     */
    #[Test]
    public function a_sell_uses_the_pair_of_the_position_not_the_bots_current_pair(): void
    {
        $bot = $this->bot();
        $this->hold($bot);
        $bot->update(['pair' => 'ETH/USDT']);

        $this->assertTrue($this->live()->sell($bot->fresh(), 70000.0, ['reason' => 'ตัดขาดทุน'])['ok']);

        $order = $this->order($bot);
        $this->assertSame(self::BTCB, $order->token_address);
        $this->assertSame('BTC/USDT', $order->metaValue('pair'));
    }

    /**
     * การปิดไม้ไม่ถูกหน่วงด้วยช่วงพัก เมื่อคำสั่งก่อนหน้าถูกปิดก่อนเซ็น (ไม่เสียแก๊ส) —
     * แต่ถ้าคำสั่งก่อนหน้า revert บนเชน (เสียแก๊สแล้ว) ต้องพักเหมือนขาซื้อ.
     */
    #[Test]
    public function exits_retry_immediately_after_a_free_failure_but_back_off_after_a_revert(): void
    {
        $bot = $this->bot();
        $this->hold($bot);
        $this->chain->balanceIn = '1465000000000000';
        $this->chain->allowance['v3'] = '1465000000000000';

        // ครั้งที่ 1: ราคาเบี่ยงเกินเพดาน — ปิดก่อนเซ็น
        $this->live()->sell($bot, 70000.0, ['reason' => 'ตัดขาดทุน']);
        $this->chain->quoteOut = '90000000000000000000';
        $this->runSender();
        $this->assertSame(AiBotWalletTransfer::FAIL_PRICE_MOVED, $this->order($bot)->failure_code);

        // สั่งปิดใหม่ได้ทันที
        $this->assertTrue($this->live()->sell($bot, 70000.0, ['reason' => 'ตัดขาดทุน'])['ok']);

        // ครั้งที่ 2: ส่งแล้ว revert — เสียค่าแก๊ส
        $this->chain->quoteOut = '102200000000000000000';
        $this->runSender();
        $order = $this->order($bot);
        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::BTCB, '0', self::USDT, '0', '0x0');
        $this->runSender();
        $this->assertSame(AiBotWalletTransfer::FAIL_REVERTED, $order->fresh()->failure_code);

        $again = $this->live()->sell($bot, 70000.0, ['reason' => 'ตัดขาดทุน']);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('พักสักครู่', $again['reason']);

        // เจ้าของกดปิดเองไม่ติดช่วงพัก
        $this->assertTrue($this->live()->sell($bot, 70000.0, ['reason' => 'เจ้าของสั่งปิดไม้เอง'], true)['ok']);
        $this->assertSame(1, AiBotPosition::count(), 'คำสั่งที่ไม่ลงบล็อกไม่แตะไม้ที่ถือ');
    }

    #[Test]
    public function a_sell_with_nothing_held_is_refused(): void
    {
        $bot = $this->bot();

        $result = $this->live()->sell($bot, 70000.0, ['reason' => 'x']);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, AiBotWalletTransfer::count());
    }

    // ─────────────────── 6) กระเป๋าหนึ่งใบ ธุรกรรมกำลังส่งทีละรายการ ───────────────────

    #[Test]
    public function a_withdrawal_waits_while_a_swap_of_the_same_wallet_is_in_flight(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $this->chain->allowance['v3'] = '100000000000000000000';
        $this->runSender();   // สวอปออกไปแล้ว รอใบเสร็จ

        $withdraw = app(BotWalletService::class)->requestWithdraw(self::OWNER, 'USDT', 25.0);
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $withdraw->fresh()->status, 'nonce ชุดเดียว — ถอนต้องรอสวอปจบ');
        $this->assertNull($this->chain->last('sign'));

        // สวอปจบ → รอบถัดไปถอนถึงได้เดิน
        $order = $this->order($bot);
        $this->chain->receipts[$order->tx_hash] = $this->swapReceipt(self::USDT, '100000000000000000000', self::BTCB, '1465000000000000');
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $withdraw->fresh()->status);
        $this->assertSame(strtolower(self::OWNER), strtolower($this->chain->last('sign')['to']), 'ถอนไปหาเจ้าของเท่านั้น');
    }

    #[Test]
    public function bot_orders_do_not_appear_as_withdrawals_and_cannot_be_cancelled_as_one(): void
    {
        $bot = $this->bot();
        $this->buy($bot);
        $order = $this->order($bot);

        $this->getJson('/api/v1/ai-bot/wallet?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonPath('data.wallet.has_pending_withdraw', false)
            ->assertJsonCount(0, 'data.transfers');

        $this->postJson("/api/v1/ai-bot/wallet/withdraw/{$order->id}/cancel", ['wallet_address' => self::OWNER])
            ->assertStatus(422);

        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $order->fresh()->status);
    }

    /**
     * ทางออกฉุกเฉิน: ขายคืนเป็น USDT ไม่ได้เลย → ทีมงานส่งเหรียญกลับหาเจ้าของ (ปลายทางเดียวที่เป็นไปได้).
     */
    #[Test]
    public function support_can_return_stuck_coins_to_the_owner_and_nowhere_else(): void
    {
        $bot = $this->bot();
        $this->hold($bot);

        // ไม่ใส่ --confirm = ดูอย่างเดียว
        $this->artisan('aibot:wallet-rescue', ['owner' => self::OWNER, 'token' => 'BTC'])->assertSuccessful();
        $this->assertSame(0, AiBotWalletTransfer::count());
        $this->assertSame(1, AiBotPosition::count());

        // เหรียญที่ระบบไม่รู้จัก / เหรียญที่ถอนจากหน้าเว็บได้อยู่แล้ว = ไม่ทำ
        $this->artisan('aibot:wallet-rescue', ['owner' => self::OWNER, 'token' => 'NOPE', '--confirm' => true])->assertFailed();
        $this->artisan('aibot:wallet-rescue', ['owner' => self::OWNER, 'token' => 'USDT', '--confirm' => true])->assertFailed();

        $this->artisan('aibot:wallet-rescue', ['owner' => self::OWNER, 'token' => 'BTC', '--confirm' => true])->assertSuccessful();

        $transfer = AiBotWalletTransfer::firstOrFail();
        $this->assertSame(AiBotWalletTransfer::DIRECTION_WITHDRAW, $transfer->direction);
        $this->assertSame(self::BTCB, $transfer->token_address);
        $this->assertSame(strtolower(self::OWNER), strtolower($transfer->to_address));
        $this->assertSame(0, AiBotPosition::count(), 'เหรียญออกจากกระเป๋าแล้ว ไม้ในบัญชีต้องไม่ค้าง');

        $this->runSender();

        $signed = $this->chain->last('sign');
        $this->assertSame(strtolower(self::OWNER), strtolower($signed['to']));
        $this->assertSame(self::BTCB, strtolower($signed['token']));
    }

    // ───────────────────────── 7) รอบคิดของบอท (BotRunner) ─────────────────────────

    #[Test]
    public function the_runner_queues_a_real_order_instead_of_a_pending_signal(): void
    {
        $bot = $this->bot();
        $this->candles = $this->risingCandles();

        $result = app(BotRunner::class)->tick($bot);

        $this->assertSame('buy', $result['action']);
        $order = $this->order($bot);
        $this->assertSame('buy', $order->metaValue('side'));
        $this->assertEqualsWithDelta(102.0, (float) $order->metaValue('ref_price'), 1e-9);
        $this->assertStringContainsString('EMA', (string) $order->metaValue('reason'));
        $this->assertLessThanOrEqual(500.0, (float) $order->amount);
        $this->assertSame(0, AiBotTrade::count(), 'เข้าคิวแล้วยังไม่ใช่ไม้');
        $this->assertStringNotContainsString('[รอยืนยัน]', (string) $bot->fresh()->last_reason);
    }

    #[Test]
    public function the_runner_waits_instead_of_deciding_again_while_an_order_is_pending(): void
    {
        $bot = $this->bot();
        $this->candles = $this->risingCandles();
        $runner = app(BotRunner::class);

        $runner->tick($bot);
        $second = $runner->tick($bot->fresh());

        $this->assertSame('hold', $second['action']);
        $this->assertStringContainsString('รอผลคำสั่งเงินจริง', $second['reason']);
        $this->assertSame(1, AiBotWalletTransfer::swaps()->count());
    }

    #[Test]
    public function a_stop_loss_queues_the_exit_and_keeps_the_position_until_the_chain_confirms(): void
    {
        $bot = $this->bot(['risk' => ['max_position_usd' => 1000, 'stop_loss_pct' => 5]]);
        $this->candles = $this->risingCandles();
        $this->hold($bot, '0.5', 61.2);   // ต้นทุน 122.4 ต่อหน่วย ราคาตอนนี้ 102 → ขาดทุนเกิน 5%

        $result = app(BotRunner::class)->tick($bot);

        $this->assertSame('sell', $result['action']);
        $this->assertStringContainsString('ตัดขาดทุน', $result['reason']);
        $this->assertSame('sell', $this->order($bot)->metaValue('side'));
        $this->assertSame(1, AiBotPosition::count(), 'ยังไม่ยืนยันบนเชน = ยังถือของอยู่');
    }

    #[Test]
    public function an_exit_that_cannot_be_queued_is_reported_as_still_holding(): void
    {
        $bot = $this->bot(['risk' => ['max_position_usd' => 1000, 'stop_loss_pct' => 5, 'max_daily_loss_usd' => 1]]);
        $this->candles = $this->risingCandles();
        $this->hold($bot, '0.5', 61.2);
        $this->chain->nativeHuman = '0.0001';   // ค่าแก๊สหมด

        $result = app(BotRunner::class)->tick($bot);

        $this->assertSame('hold', $result['action']);
        $this->assertStringContainsString('ยังปิดไม้ไม่ได้', $result['reason']);
        $this->assertStringContainsString('ค่าแก๊ส', $result['reason']);
        $this->assertSame('running', $bot->fresh()->status, 'ยังถือของอยู่ต้องไม่ถูกพัก — รอบหน้าต้องสั่งปิดใหม่');
    }

    /**
     * ⭐ ด่านขาดทุนต่อวันสั่งปิดไม้แล้วพักบอท — ถ้าคำสั่งปิดนั้นไม่ลงบล็อก บอทต้องถูกปลุกกลับมาสั่งปิดใหม่
     *    ไม่ใช่ทิ้งไม้ไว้กับบอทที่ไม่เดินแล้ว.
     */
    #[Test]
    public function a_paused_bot_is_woken_when_its_closing_order_never_lands(): void
    {
        $bot = $this->bot(['risk' => ['max_position_usd' => 1000, 'max_daily_loss_usd' => 1]]);
        $this->candles = $this->risingCandles();
        $this->hold($bot, '0.5', 61.2);   // ขาดทุนลอย ~10.2 > เพดานวันละ 1

        $result = app(BotRunner::class)->tick($bot);

        $this->assertSame('sell', $result['action']);
        $this->assertSame('paused', $bot->fresh()->status);
        $order = $this->order($bot);
        $this->assertTrue($order->metaValue('resume_bot_on_failure'));

        // คำสั่งค้างคิวจนหมดอายุ (เชนล่ม) — ไม่ได้ขาย
        $this->travel(11)->minutes();
        $this->runSender();

        $this->assertSame(AiBotWalletTransfer::FAIL_EXPIRED, $order->fresh()->failure_code);
        $this->assertSame('running', $bot->fresh()->status, 'ยังถือของอยู่ — บอทต้องกลับมาเดินเพื่อสั่งปิดใหม่');
        $this->assertSame(1, AiBotPosition::count());
    }

    #[Test]
    public function without_the_execution_flag_live_mode_is_still_a_pending_signal(): void
    {
        config(['aibot.live.execution' => false]);
        $bot = $this->bot();
        $this->candles = $this->risingCandles();

        $result = app(BotRunner::class)->tick($bot);

        $this->assertSame('signal', $result['action']);
        $this->assertSame(0, AiBotWalletTransfer::count());
    }

    // ─────────────────────────────── 8) API ───────────────────────────────

    #[Test]
    public function a_bot_with_real_money_at_stake_cannot_be_deleted_switched_or_moved(): void
    {
        $bot = $this->bot();
        $this->hold($bot);
        $payload = ['wallet_address' => self::OWNER];

        $this->deleteJson("/api/v1/ai-bot/bots/{$bot->id}", $payload)
            ->assertStatus(422)->assertJsonPath('error.code', 'LIVE_POSITION_OPEN');

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", $payload + ['mode' => 'demo'])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIVE_POSITION_OPEN');

        $this->putJson("/api/v1/ai-bot/bots/{$bot->id}", $payload + [
            'name' => 'ย้ายคู่', 'pair' => 'ETH/USDT', 'strategy' => 'momentum', 'timeframe' => '1h',
        ])->assertStatus(422)->assertJsonPath('error.code', 'LIVE_POSITION_OPEN');

        $this->assertNotNull($bot->fresh());
        $this->assertSame('live', $bot->fresh()->mode);
        $this->assertSame('BTC/USDT', $bot->fresh()->pair);

        // คำสั่งค้างอยู่ก็นับ
        AiBotPosition::query()->delete();
        $this->buy($bot);
        $this->deleteJson("/api/v1/ai-bot/bots/{$bot->id}", $payload)->assertStatus(422);
    }

    #[Test]
    public function the_owner_can_close_a_position_even_after_the_rental_ended(): void
    {
        $bot = $this->bot(['status' => 'paused']);
        $this->hold($bot);
        AiBotSubscription::query()->update(['expires_at' => now()->subDay()]);
        $this->tickerPrice = 70000.0;

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/close", ['wallet_address' => self::OWNER])
            ->assertOk()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.order.side', 'sell')
            ->assertJsonPath('data.order.manual', true)
            ->assertJsonPath('data.bot.pending_order.side', 'sell');

        $order = $this->order($bot);
        $this->assertSame(500, $order->metaValue('max_deviation_bps'), 'เจ้าของสั่งเอง — ยอมราคาเบี่ยงได้มากกว่าบอท');

        // กดซ้ำระหว่างรอ = ไม่มีคำสั่งที่สอง
        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/close", ['wallet_address' => self::OWNER])
            ->assertStatus(422)->assertJsonPath('error.code', 'CLOSE_FAILED');
        $this->assertSame(1, AiBotWalletTransfer::swaps()->count());
    }

    #[Test]
    public function the_live_portfolio_shows_positions_and_orders_of_the_caller_only(): void
    {
        $bot = $this->bot();
        $this->hold($bot);
        $this->tickerPrice = 70000.0;
        $this->live()->sell($bot, 70000.0, ['reason' => 'ถึงเป้า']);

        $this->getJson('/api/v1/ai-bot/live?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonPath('data.execution', true)
            ->assertJsonPath('data.positions.0.pair', 'BTC/USDT')
            ->assertJsonPath('data.positions.0.bot_id', $bot->id)
            ->assertJsonPath('data.orders.0.side', 'sell')
            ->assertJsonPath('data.orders.0.status', 'queued')
            ->assertJsonMissingPath('data.orders.0.raw_tx');

        $stranger = '0x9999999999999999999999999999999999999999';
        Cache::put('wallet_verified:'.$stranger, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHour());

        $this->getJson('/api/v1/ai-bot/live?wallet_address='.$stranger)
            ->assertOk()
            ->assertJsonCount(0, 'data.positions')
            ->assertJsonCount(0, 'data.orders');

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/close", ['wallet_address' => $stranger])->assertStatus(404);
    }

    #[Test]
    public function switching_to_live_requires_a_tradable_pair_once_execution_is_on(): void
    {
        $bot = $this->bot(['mode' => 'demo', 'pair' => 'TPIX/USDT']);
        app(BotWalletService::class)->refreshBalances($this->wallet());   // กระเป๋าบอทมีทุนแล้ว — เหลือด่านคู่เหรียญ

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", ['wallet_address' => self::OWNER, 'mode' => 'live'])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIVE_PAIR_UNSUPPORTED');

        $this->getJson('/api/v1/ai-bot/catalog')->assertOk()->assertJsonPath('data.features.live_execution', true);

        config(['aibot.live.execution' => false]);
        $this->getJson('/api/v1/ai-bot/catalog')->assertOk()->assertJsonPath('data.features.live_execution', false);
    }
}

/**
 * เชนปลอมของกระเป๋าบอท — แทน BotWalletSigner ทั้งตัว (ไม่มี node ไม่มี RPC ไม่มีธุรกรรมจริง).
 */
class FakeBotChain extends BotWalletSigner
{
    /** @var list<array<string, mixed>> ทุกครั้งที่ตัวส่งเรียกตัวเซ็น ตามลำดับ */
    public array $calls = [];

    /** @var array<string, array> hash → ใบเสร็จ */
    public array $receipts = [];

    public array $allowance = ['v2' => '0', 'v3' => '0'];

    public string $balanceIn = '1000000000000000000000';

    public string $nativeWei = '50000000000000000';

    public string $nativeHuman = '0.05';

    /** ใบเสนอราคาของ DEX (หน่วยเล็กสุดของเหรียญขาออก) — null = ไม่มีเส้นทาง */
    public ?string $quoteOut = '1466000000000000';

    public bool $planFails = false;

    private int $sequence = 0;

    public function isAvailable(): bool
    {
        return true;
    }

    public function planSwap(string $owner, string $tokenIn, string $tokenOut, string $amountInWei, ?string $routerPin = null): array
    {
        $this->calls[] = ['action' => 'plan', 'owner' => $owner, 'tokenIn' => $tokenIn, 'tokenOut' => $tokenOut, 'amountIn' => $amountInWei, 'pin' => $routerPin];

        if ($this->planFails) {
            return ['ok' => false, 'code' => 'rpc', 'error' => 'timeout'];
        }

        return [
            'ok' => true,
            'balanceIn' => $this->balanceIn,
            'nativeBalance' => $this->nativeWei,
            'decimalsIn' => 18,
            'decimalsOut' => 18,
            'allowance' => $this->allowance,
            'best' => $this->quoteOut === null ? null : [
                'kind' => 'v3',
                'router' => '0x000000000000000000000000000000000000beef',   // ตัวส่งต้องไม่เชื่อช่องนี้
                'tokens' => [$tokenIn, $tokenOut],
                'fees' => [100],
                'amountOut' => $this->quoteOut,
            ],
            'bnbQuote' => '600000000000000000000',
            'gasPrice' => '1000000000',
        ];
    }

    public function signApprove(string $privateKeyHex, string $expectAddress, string $token, string $spender, string $amountWei): array
    {
        return $this->signed(['action' => 'sign-approve', 'address' => $expectAddress, 'token' => $token, 'spender' => $spender, 'amount' => $amountWei]);
    }

    public function signSwap(string $privateKeyHex, string $expectAddress, string $tokenIn, string $tokenOut, string $amountInWei, string $minOutWei, int $deadline, array $route): array
    {
        return $this->signed([
            'action' => 'sign-swap', 'address' => $expectAddress, 'tokenIn' => $tokenIn, 'tokenOut' => $tokenOut,
            'amountIn' => $amountInWei, 'minOut' => $minOutWei, 'deadline' => $deadline, 'route' => $route,
        ]);
    }

    public function sign(string $privateKeyHex, string $expectAddress, string $to, string $valueWei, ?string $tokenAddress = null, ?int $nonce = null): array
    {
        return $this->signed(['action' => 'sign', 'address' => $expectAddress, 'to' => $to, 'value' => $valueWei, 'token' => $tokenAddress]);
    }

    public function send(string $rawTx): array
    {
        $this->calls[] = ['action' => 'send', 'raw' => $rawTx];

        return ['ok' => true, 'txHash' => $rawTx, 'alreadyKnown' => false];
    }

    public function receipt(string $txHash): ?array
    {
        return $this->receipts[$txHash] ?? null;
    }

    public function blockNumber(): ?int
    {
        return 1000;
    }

    public function transactionKnown(string $txHash): ?bool
    {
        return true;
    }

    public function nonceOf(string $address, string $tag = 'latest'): ?int
    {
        return 0;
    }

    /** การเรียกครั้งล่าสุดของ action นั้น (null = ไม่เคยถูกเรียก) */
    public function last(string $action): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['action'] === $action) {
                return $call;
            }
        }

        return null;
    }

    private function signed(array $call): array
    {
        $this->calls[] = $call;
        $hash = '0x'.str_pad(dechex(++$this->sequence), 64, '0', STR_PAD_LEFT);

        return ['ok' => true, 'address' => $call['address'], 'nonce' => $this->sequence, 'txHash' => $hash, 'raw' => $hash];
    }

    public static function transferLog(string $token, string $from, string $to, string $valueWei): array
    {
        $pad = fn (string $address) => '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);

        return [
            'address' => $token,
            'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', $pad($from), $pad($to)],
            'data' => '0x'.self::decToHex($valueWei),
        ];
    }

    private static function decToHex(string $dec): string
    {
        $hex = '';

        while (bccomp($dec, '0', 0) > 0) {
            $hex = dechex((int) bcmod($dec, '16')).$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }
}
