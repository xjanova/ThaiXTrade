<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotPosition;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use App\Models\AiCopyFee;
use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\User;
use App\Services\AiBot\Copy\CopyTradeService;
use App\Services\Kyc\KycGate;
use App\Services\MarketDataService;
use App\Services\TpixDexService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — Master / Copy Trade ผ่าน API.
 *
 * สิ่งที่ชุดนี้ยึดไว้ (พังข้อไหน = คนเสียเงินหรือถูกหลอก):
 *   - ผลงานของมาสเตอร์มาจากไม้เงินจริงที่ปิดแล้วเท่านั้น — ไม้โหมดทดลองไม่ถูกนับ ไม่ถูกโชว์
 *   - มาสเตอร์ต้องมี KYC ที่อนุมัติแล้ว และอัตราต้องอยู่ในช่วงที่กำหนด
 *   - ผู้ตามเห็นผลงาน ไม่เห็นกฎของมาสเตอร์ · ก๊อปปี้ตัวเองไม่ได้ · ตามได้เฉพาะเงินจริง
 *   - ค่าบริการคิดจากกำไรที่ปิดแล้วแบบ high-water mark ครั้งเดียวต่อไม้ · ผู้ตามขาดทุนไม่เสีย
 *   - มาสเตอร์ขึ้นอัตรา ผู้ตามเดิมอยู่ที่อัตราที่ยอมรับไว้
 *   - ส่วนแบ่งแพลตฟอร์มซื้อได้แค่แถบ "แนะนำ" ไม่ได้ซื้ออันดับบนกระดาน
 *   - เลิกตามได้เสมอ และไม้ที่ถืออยู่ไม่ถูกบังคับขาย
 *
 * Developed by Xman Studio.
 */
class AiCopyTradeTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FOLLOWER = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const OTHER = '0xcccccccccccccccccccccccccccccccccccccccc';

    /** KYC ของผู้เรียกผ่านแล้วไหม (ตัวปลอมของ KycGate::satisfiesLevel) */
    public bool $kycApproved = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'aibot.copy_trade.enabled' => true,
            'aibot.copy_trade.fees.collect' => false,
            // เปิดเทรดเงินจริงให้ครบสามธง — ทุกอย่างในเทสต์นี้เป็นแถวในฐานข้อมูล ไม่มีธุรกรรมจริง
            'aibot.live_enabled' => true,
            'aibot.live.execution' => true,
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
            'aibot_risk.demo.fee_rate' => 0.1,
            'aibot_risk.demo.slippage_bps' => 8,
        ]);

        $this->seed(AiBotPlanSeeder::class);

        foreach ([self::MASTER, self::FOLLOWER, self::OTHER] as $wallet) {
            Cache::put('wallet_verified:'.$wallet, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));

            AiBotSubscription::create([
                'wallet_address' => $wallet,
                'ai_bot_plan_id' => AiBotPlan::where('code', 'vip')->firstOrFail()->id,
                'status' => 'active', 'days' => 30,
                'started_at' => now()->subDay(), 'expires_at' => now()->addDays(29),
            ]);
        }

        $this->partialMock(MarketDataService::class, fn ($mock) => $mock->shouldReceive('hasKlines')->andReturn(true));
        $this->partialMock(TpixDexService::class, fn ($mock) => $mock->shouldReceive('tpixUsdPrice')->andReturn(0.5));

        // KYC: ใช้ KycGate ตัวจริงทุกเมธอด ยกเว้น "คนนี้คือใคร" กับ "ใบที่อนุมัติแล้วถึงระดับไหม"
        $this->partialMock(KycGate::class, function ($mock) {
            $mock->shouldReceive('resolveUser')->andReturnUsing(fn () => new User());
            $mock->shouldReceive('satisfiesLevel')->andReturnUsing(fn () => $this->kycApproved);
        });
    }

    // ── ตัวช่วย ──────────────────────────────────────────────────────────────

    private function bot(string $wallet, array $attributes = []): AiBotConfig
    {
        return AiBotConfig::create($attributes + [
            'wallet_address' => $wallet,
            'ai_bot_subscription_id' => AiBotSubscription::where('wallet_address', $wallet)->value('id'),
            'name' => 'Trend BTC', 'pair' => 'BTC/USDT', 'strategy' => 'momentum', 'timeframe' => '4h',
            'params' => ['fast_ema' => 9, 'slow_ema' => 21, 'secret_sauce' => 'do-not-leak'],
            'risk' => ['max_position_usd' => 1000, 'stop_loss_pct' => 5, 'take_profit_pct' => 12, 'max_daily_loss_usd' => 100],
            'status' => 'running', 'mode' => 'live',
        ]);
    }

    /**
     * ไม้ซื้อ+ขายหนึ่งรอบต่อค่า pnl หนึ่งค่า กระจายเวลาตั้งแต่ $daysAgo วันก่อนถึงเมื่อวาน.
     *
     * @param  list<float>  $pnls
     */
    private function trades(AiBotConfig $bot, array $pnls, int $daysAgo = 60, string $mode = 'live'): void
    {
        $step = max(1, intdiv($daysAgo * 1440, max(1, count($pnls) * 2 + 1)));
        $at = now()->subDays($daysAgo);

        foreach ($pnls as $pnl) {
            foreach ([['buy', null], ['sell', $pnl]] as [$side, $realized]) {
                $trade = AiBotTrade::create([
                    'ai_bot_config_id' => $bot->id, 'wallet_address' => $bot->wallet_address, 'pair' => $bot->pair,
                    'mode' => $mode, 'side' => $side, 'price' => 100, 'quantity' => 1, 'gross_value' => 100,
                    'fee' => 0.1, 'slippage_cost' => 0.08, 'realized_pnl' => $realized, 'strategy' => $bot->strategy, 'reason' => 'test',
                ]);
                $trade->created_at = $at = $at->copy()->addMinutes($step);
                $trade->save();
            }
        }
    }

    /** บอทที่ผ่านเกณฑ์มาสเตอร์: 60 วัน · 24 ไม้ · +16% ของทุน · drawdown ต่ำ */
    private function eligibleBot(string $wallet = self::MASTER): AiBotConfig
    {
        $bot = $this->bot($wallet);
        $this->trades($bot, array_merge(array_fill(0, 20, 10.0), array_fill(0, 4, -10.0)));

        return $bot;
    }

    private function profile(array $overrides = []): array
    {
        return $overrides + [
            'wallet_address' => self::MASTER,
            'display_name' => 'Trend Rider',
            'bio' => 'ตามเทรนด์ 4 ชม.',
            'fee_rate_pct' => 20,
            'platform_share_pct' => 30,
        ];
    }

    private function registerMaster(array $overrides = [], ?AiBotConfig $bot = null): AiCopyMaster
    {
        $bot ??= $this->eligibleBot($overrides['wallet_address'] ?? self::MASTER);

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile($overrides) + ['bot_id' => $bot->id])->assertCreated();

        return AiCopyMaster::where('wallet_address', $bot->wallet_address)->firstOrFail();
    }

    private function follow(AiCopyMaster $master, string $wallet = self::FOLLOWER, array $overrides = [])
    {
        return $this->postJson('/api/v1/ai-bot/copy/follow', $overrides + [
            'wallet_address' => $wallet,
            'master_id' => $master->id,
            'accept_fee_rate_pct' => $master->fee_rate_pct,
            'risk' => ['max_position_usd' => 200, 'stop_loss_pct' => 5, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 50],
        ]);
    }

    /** ไม้ขายเงินจริงของบอทผู้ตาม (ที่ก๊อปปี้มา) */
    private function followerSell(AiCopyFollow $follow, float $pnl, string $mode = 'live'): AiBotTrade
    {
        return AiBotTrade::create([
            'ai_bot_config_id' => $follow->ai_bot_config_id, 'wallet_address' => $follow->follower_wallet, 'pair' => 'BTC/USDT',
            'mode' => $mode, 'side' => 'sell', 'price' => 100, 'quantity' => 1, 'gross_value' => 100,
            'fee' => 0.1, 'slippage_cost' => 0.08, 'realized_pnl' => $pnl, 'strategy' => 'copy', 'reason' => 'test',
        ]);
    }

    // ── ธงเปิด/ปิด ───────────────────────────────────────────────────────────

    #[Test]
    public function everything_is_closed_while_the_feature_flag_is_off(): void
    {
        $bot = $this->eligibleBot();
        config(['aibot.copy_trade.enabled' => false]);

        $this->getJson('/api/v1/ai-bot/copy/masters')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.masters', []);
        $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->assertOk()->assertJsonPath('data.invite', false);

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile() + ['bot_id' => $bot->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'COPY_TRADE_DISABLED');

        $this->assertDatabaseCount('ai_copy_masters', 0);
    }

    // ── เกณฑ์มาสเตอร์ ─────────────────────────────────────────────────────────

    #[Test]
    public function demo_results_never_qualify_and_never_show(): void
    {
        $bot = $this->bot(self::MASTER, ['mode' => 'demo']);
        $this->trades($bot, array_fill(0, 40, 25.0), 90, 'demo');

        $me = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->assertOk()->json('data');

        $this->assertFalse($me['invite']);
        $this->assertSame([], $me['candidates'], 'บอทที่มีแต่ไม้ทดลองไม่มีผลงานให้โชว์เลย');

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile() + ['bot_id' => $bot->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'COPY_NOT_ELIGIBLE');
    }

    #[Test]
    public function a_profitable_real_money_bot_gets_the_invitation_with_its_checks(): void
    {
        $this->eligibleBot();

        $me = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->assertOk()->json('data');

        $this->assertTrue($me['invite']);
        $this->assertNull($me['master']);
        $this->assertTrue($me['candidates'][0]['eligible']);
        $this->assertSame('live', $me['candidates'][0]['record']['mode']);
        $this->assertSame(24, $me['candidates'][0]['record']['closed']);
        $this->assertEqualsWithDelta(16.0, $me['candidates'][0]['record']['return_pct'], 1e-6);
        $this->assertSame(
            ['own_strategy', 'liquid_pair', 'min_days', 'min_closed_trades', 'min_return_pct', 'max_drawdown_pct'],
            array_column($me['candidates'][0]['checks'], 'code'),
        );
    }

    #[Test]
    public function each_threshold_blocks_on_its_own(): void
    {
        $service = app(CopyTradeService::class);
        $failing = fn (AiBotConfig $bot) => collect($service->eligibility($bot)['checks'])->where('ok', false)->pluck('code')->all();

        $young = $this->bot(self::MASTER, ['name' => 'young']);
        $this->trades($young, array_fill(0, 24, 10.0), 5);
        $this->assertSame(['min_days'], $failing($young));

        $few = $this->bot(self::MASTER, ['name' => 'few']);
        $this->trades($few, array_fill(0, 5, 50.0));
        $this->assertSame(['min_closed_trades'], $failing($few));

        $flat = $this->bot(self::MASTER, ['name' => 'flat']);
        $this->trades($flat, array_merge(array_fill(0, 12, 5.0), array_fill(0, 12, -4.0)));
        $this->assertSame(['min_return_pct'], $failing($flat));

        // กำไรรวมดี แต่ระหว่างทางเคยเสีย 30% ของทุน
        $wild = $this->bot(self::MASTER, ['name' => 'wild']);
        $this->trades($wild, array_merge(array_fill(0, 12, 40.0), array_fill(0, 6, -50.0), array_fill(0, 6, 10.0)));
        $this->assertSame(['max_drawdown_pct'], $failing($wild));

        $illiquid = $this->bot(self::MASTER, ['name' => 'illiquid', 'pair' => 'TPIX/USDT']);
        $this->trades($illiquid, array_merge(array_fill(0, 20, 10.0), array_fill(0, 4, -10.0)));
        $this->assertSame(['liquid_pair'], $failing($illiquid));
    }

    #[Test]
    public function shrinking_the_capital_setting_afterwards_does_not_inflate_the_return(): void
    {
        $bot = $this->eligibleBot();
        // ไม้ละ $100 ถูกลงจริง — ตั้งทุนสูงสุดเหลือ $10 ทีหลังต้องไม่ทำให้ผลตอบแทนกลายเป็น 1,600%
        $bot->update(['risk' => ['max_position_usd' => 10] + $bot->risk]);

        $record = app(CopyTradeService::class)->eligibility($bot->fresh())['record'];

        $this->assertEqualsWithDelta(100.0, $record['capital_usd'], 1e-6);
        $this->assertEqualsWithDelta(160.0, $record['return_pct'], 1e-6);
    }

    // ── เปิดตัวเป็นมาสเตอร์ ───────────────────────────────────────────────────

    #[Test]
    public function becoming_a_master_requires_an_approved_kyc(): void
    {
        $bot = $this->eligibleBot();
        $this->kycApproved = false;

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile() + ['bot_id' => $bot->id])
            ->assertStatus(403)->assertJsonPath('error.code', 'COPY_MASTER_KYC_REQUIRED');

        $this->assertDatabaseCount('ai_copy_masters', 0);

        $this->kycApproved = true;
        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile() + ['bot_id' => $bot->id])->assertCreated();
    }

    #[Test]
    public function the_fee_rate_and_platform_share_must_stay_inside_the_configured_range(): void
    {
        $bot = $this->eligibleBot();

        foreach ([['fee_rate_pct' => 4], ['fee_rate_pct' => 31], ['platform_share_pct' => 19], ['platform_share_pct' => 51], ['fee_rate_pct' => 'abc']] as $bad) {
            $this->postJson('/api/v1/ai-bot/copy/master', $this->profile($bad) + ['bot_id' => $bot->id])
                ->assertStatus(422)->assertJsonValidationErrors(array_key_first($bad));
        }

        $this->assertDatabaseCount('ai_copy_masters', 0);
    }

    #[Test]
    public function the_public_name_cannot_carry_markup_or_links(): void
    {
        $bot = $this->eligibleBot();

        foreach (['<script>alert(1)</script>', 'Best <b>ever</b>', 'http://evil.example/x', 'a"onmouseover="x', 'ab'] as $name) {
            $this->postJson('/api/v1/ai-bot/copy/master', $this->profile(['display_name' => $name]) + ['bot_id' => $bot->id])
                ->assertStatus(422)->assertJsonValidationErrors('display_name');
        }

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile(['display_name' => 'มาสเตอร์ เทรนด์ 4H_v2']) + ['bot_id' => $bot->id])->assertCreated();
    }

    #[Test]
    public function a_wallet_has_one_master_profile_and_cannot_use_someone_elses_bot(): void
    {
        $master = $this->registerMaster();
        $second = $this->eligibleBot();

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile() + ['bot_id' => $second->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'COPY_ALREADY_MASTER');

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile(['wallet_address' => self::OTHER]) + ['bot_id' => $master->ai_bot_config_id])
            ->assertStatus(404)->assertJsonPath('error.code', 'BOT_NOT_FOUND');

        $this->assertDatabaseCount('ai_copy_masters', 1);
    }

    // ── กระดาน ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_board_shows_real_money_results_and_never_the_masters_rules(): void
    {
        $this->registerMaster();

        $response = $this->getJson('/api/v1/ai-bot/copy/masters')->assertOk();
        $row = $response->json('data.masters.0');

        $this->assertSame('Trend Rider', $row['display_name']);
        $this->assertSame('live', $row['record']['mode']);
        $this->assertSame(24, $row['record']['closed']);
        $this->assertEqualsWithDelta(16.0, $row['record']['return_pct'], 1e-6);
        $this->assertArrayHasKey('max_drawdown_pct', $row['record']);
        $this->assertArrayHasKey('days', $row['record']);
        $this->assertSame(0, $row['followers']);
        $this->assertSame('BTC/USDT', $row['pair']);

        // ผู้ตามเห็นผลงาน ไม่เห็นกฎ — ไม่มีพารามิเตอร์ กลยุทธ์ รหัสบอท หรือที่อยู่กระเป๋าเต็มในคำตอบสาธารณะ
        $body = $response->getContent();
        foreach (['do-not-leak', 'fast_ema', 'momentum', self::MASTER, 'bot_id', 'earnings'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    #[Test]
    public function a_bigger_platform_share_buys_the_promoted_strip_but_not_the_ranking(): void
    {
        // A: ผลงานดีกว่า ให้แพลตฟอร์มขั้นต่ำ · B: ผลงานด้อยกว่า ให้แพลตฟอร์มมากสุด
        $botA = $this->bot(self::MASTER);
        $this->trades($botA, array_merge(array_fill(0, 22, 15.0), array_fill(0, 2, -10.0)));
        $a = $this->registerMaster(['display_name' => 'Alpha', 'platform_share_pct' => 20], $botA);

        $b = $this->registerMaster(['wallet_address' => self::OTHER, 'display_name' => 'Bravo', 'platform_share_pct' => 50]);

        $data = $this->getJson('/api/v1/ai-bot/copy/masters')->assertOk()->json('data');

        $this->assertSame(['Alpha', 'Bravo'], array_column($data['masters'], 'display_name'), 'กระดานหลักเรียงตามผลงานล้วน');
        $this->assertSame([$b->id], $data['promoted'], 'ส่วนแบ่งขั้นต่ำไม่ได้ขึ้นแถบแนะนำ');
        $this->assertFalse($data['masters'][0]['promoted']);
        $this->assertTrue($data['masters'][1]['promoted']);
        $this->assertNotNull($a);
    }

    #[Test]
    public function a_master_who_fell_below_the_floor_stays_listed_with_real_numbers_but_is_not_promoted(): void
    {
        $master = $this->registerMaster(['platform_share_pct' => 50]);
        $this->trades($master->bot, array_fill(0, 10, -40.0), 5);
        $master->update(['stats_at' => null]);

        $data = $this->getJson('/api/v1/ai-bot/copy/masters')->assertOk()->json('data');

        $this->assertSame([], $data['promoted']);
        $this->assertFalse($data['masters'][0]['meets_floor']);
        $this->assertLessThan(0, $data['masters'][0]['record']['return_pct']);
    }

    // ── การติดตาม ────────────────────────────────────────────────────────────

    #[Test]
    public function following_creates_a_real_money_copy_bot_at_the_accepted_rate(): void
    {
        $master = $this->registerMaster();

        $data = $this->follow($master)->assertCreated()->json('data');

        $follow = AiCopyFollow::firstOrFail();
        $bot = $follow->bot;

        $this->assertSame('copy', $bot->strategy);
        $this->assertSame('live', $bot->mode);
        $this->assertSame('paused', $bot->status, 'ผู้ตามเป็นคนกดเริ่มเอง');
        $this->assertSame('BTC/USDT', $bot->pair);
        $this->assertSame('4h', $bot->timeframe);
        $this->assertEqualsWithDelta(200.0, $bot->risk['max_position_usd'], 1e-9);
        $this->assertSame(self::FOLLOWER, $bot->wallet_address);
        $this->assertEqualsWithDelta(20.0, $follow->fee_rate_pct, 1e-9);
        $this->assertEqualsWithDelta(30.0, $follow->platform_share_pct, 1e-9);
        $this->assertFalse($data['rate_changed']);
        $this->assertArrayNotHasKey('secret_sauce', $bot->params);
    }

    #[Test]
    public function copying_is_real_money_only(): void
    {
        $master = $this->registerMaster();
        config(['aibot.live.execution' => false]);

        $this->follow($master)->assertStatus(409)->assertJsonPath('error.code', 'COPY_NEEDS_LIVE_TRADING');
        $this->assertDatabaseCount('ai_copy_follows', 0);
        $this->assertSame(1, AiBotConfig::count(), 'ไม่มีบอทผู้ตามถูกสร้างค้างไว้');
    }

    #[Test]
    public function nobody_can_copy_themselves_follow_twice_or_follow_a_paused_master(): void
    {
        $master = $this->registerMaster();

        $this->follow($master, self::MASTER)->assertStatus(422)->assertJsonPath('error.code', 'COPY_CANNOT_FOLLOW_SELF');

        $this->follow($master)->assertCreated();
        $this->follow($master)->assertStatus(422)->assertJsonPath('error.code', 'COPY_ALREADY_FOLLOWING');

        $this->putJson('/api/v1/ai-bot/copy/master', ['wallet_address' => self::MASTER, 'status' => 'paused'])->assertOk();
        $this->follow($master->fresh(), self::OTHER)->assertStatus(422)->assertJsonPath('error.code', 'COPY_MASTER_NOT_ACCEPTING');

        $this->assertSame(1, AiCopyFollow::count());
    }

    #[Test]
    public function a_follower_must_accept_the_rate_that_is_current(): void
    {
        $master = $this->registerMaster();

        $this->follow($master, self::FOLLOWER, ['accept_fee_rate_pct' => 10])
            ->assertStatus(422)->assertJsonPath('error.code', 'COPY_RATE_CHANGED');

        $this->assertDatabaseCount('ai_copy_follows', 0);
    }

    #[Test]
    public function a_rate_increase_does_not_reach_existing_followers_until_they_accept(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();

        $this->putJson('/api/v1/ai-bot/copy/master', ['wallet_address' => self::MASTER, 'fee_rate_pct' => 30])->assertOk();

        // ไม้ที่ปิดหลังมาสเตอร์ขึ้นอัตรายังคิดที่ 20% ที่ผู้ตามยอมรับไว้
        $this->followerSell($follow, 100.0);
        $mine = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::FOLLOWER)->assertOk()->json('data.follows.0');

        $this->assertTrue($mine['rate_changed']);
        $this->assertEqualsWithDelta(20.0, $mine['fee_rate_pct'], 1e-9);
        $this->assertEqualsWithDelta(20.0, $mine['fees_usd'], 1e-6);

        $this->postJson("/api/v1/ai-bot/copy/follow/{$follow->id}/accept", ['wallet_address' => self::FOLLOWER])
            ->assertOk()->assertJsonPath('data.rate_changed', false);

        $this->followerSell($follow, 100.0);
        app(CopyTradeService::class)->settleFees($follow->fresh());

        $this->assertEqualsWithDelta([20.0, 30.0], AiCopyFee::orderBy('id')->pluck('fee_usd')->all(), 1e-6);
    }

    // ── ค่าบริการ ─────────────────────────────────────────────────────────────

    #[Test]
    public function fees_follow_a_high_water_mark_and_are_split_with_the_platform(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $service = app(CopyTradeService::class);

        $this->followerSell($follow, 100.0);   // สะสม 100 → คิดจาก 100
        $this->followerSell($follow, -50.0);   // สะสม 50  → ขาดทุน ไม่คิด
        $this->followerSell($follow, 30.0);    // สะสม 80  → ยังไม่ถึงจุดสูงเดิม ไม่คิด
        $this->followerSell($follow, 40.0);    // สะสม 120 → คิดเฉพาะส่วนเกิน 20

        $this->assertSame(2, $service->settleFees($follow));
        $this->assertSame(0, $service->settleFees($follow->fresh()), 'ไล่ตรวจซ้ำต้องไม่คิดซ้ำ');

        $fees = AiCopyFee::orderBy('id')->get();

        $this->assertEqualsWithDelta([100.0, 20.0], $fees->pluck('profit_usd')->all(), 1e-6);
        $this->assertEqualsWithDelta([20.0, 4.0], $fees->pluck('fee_usd')->all(), 1e-6);
        // ส่วนแบ่งแพลตฟอร์ม 30% ของค่าบริการ ที่เหลือเป็นของมาสเตอร์
        $this->assertEqualsWithDelta([6.0, 1.2], $fees->pluck('platform_usd')->all(), 1e-6);
        $this->assertEqualsWithDelta([14.0, 2.8], $fees->pluck('master_usd')->all(), 1e-6);
        // ค่าบริการเป็น TPIX ตามราคาตอนคิด (0.5 USD ต่อ TPIX)
        $this->assertEqualsWithDelta([40.0, 8.0], $fees->pluck('fee_tpix')->all(), 1e-6);

        $follow->refresh();
        $this->assertEqualsWithDelta(120.0, $follow->realized_pnl, 1e-6);
        $this->assertEqualsWithDelta(120.0, $follow->high_water_mark, 1e-6);
        $this->assertEqualsWithDelta(24.0, $follow->fees_usd, 1e-6);
    }

    #[Test]
    public function a_follower_who_only_loses_pays_nothing(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();

        foreach ([-20.0, -35.0, 10.0] as $pnl) {
            $this->followerSell($follow, $pnl);
        }

        $this->assertSame(0, app(CopyTradeService::class)->settleFees($follow));
        $this->assertDatabaseCount('ai_copy_fees', 0);
        $this->assertEqualsWithDelta(-45.0, $follow->fresh()->realized_pnl, 1e-6);
    }

    #[Test]
    public function fees_are_only_recorded_until_collection_is_switched_on_and_demo_trades_never_count(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $service = app(CopyTradeService::class);

        $this->followerSell($follow, 500.0, 'demo');
        $this->assertSame(0, $service->settleFees($follow));

        $this->followerSell($follow, 50.0);
        $service->settleFees($follow->fresh());

        config(['aibot.copy_trade.fees.collect' => true]);
        $this->followerSell($follow, 50.0);
        $service->settleFees($follow->fresh());

        $this->assertSame([AiCopyFee::STATUS_RECORDED, AiCopyFee::STATUS_ACCRUED], AiCopyFee::orderBy('id')->pluck('status')->all());
    }

    #[Test]
    public function without_a_tpix_price_the_fee_is_kept_in_usd_instead_of_guessing(): void
    {
        $this->partialMock(TpixDexService::class, fn ($mock) => $mock->shouldReceive('tpixUsdPrice')->andReturn(null));

        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();

        $this->followerSell($follow, 100.0);
        app(CopyTradeService::class)->settleFees($follow);

        $fee = AiCopyFee::firstOrFail();
        $this->assertEqualsWithDelta(20.0, $fee->fee_usd, 1e-6);
        $this->assertNull($fee->fee_tpix);
        $this->assertNull($fee->tpix_usd_price);
    }

    #[Test]
    public function follower_counts_only_include_followers_who_actually_closed_a_copied_trade(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $this->follow($master, self::OTHER)->assertCreated();

        $service = app(CopyTradeService::class);
        $this->assertSame(0, $service->refreshStats($master)->stats['followers'], 'กดตามเฉยๆ ยังไม่นับ');

        $follow = AiCopyFollow::where('follower_wallet', self::FOLLOWER)->firstOrFail();
        $this->followerSell($follow, -5.0);
        $service->settleFees($follow);

        $this->assertSame(1, $service->refreshStats($master->fresh())->stats['followers']);
    }

    // ── เลิกตาม / ปิดโปรไฟล์ ─────────────────────────────────────────────────

    #[Test]
    public function a_follower_can_always_stop_and_keeps_an_open_position(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $follow->bot->update(['status' => 'running']);

        // ถือของอยู่: เลิกตามแล้วบอทต้องยังเดิน (ให้ stop ของผู้ตามทำงาน) และไม่มีไม้ขายถูกสร้าง
        AiBotPosition::create([
            'ai_bot_config_id' => $follow->ai_bot_config_id, 'pair' => 'BTC/USDT', 'mode' => 'live',
            'quantity' => 1, 'entry_price' => 100, 'cost_basis' => 100, 'entry_count' => 1, 'opened_at' => now(),
        ]);

        // ปิดธงทั้งระบบแล้วก็ยังเลิกตามได้
        config(['aibot.copy_trade.enabled' => false]);

        $this->postJson("/api/v1/ai-bot/copy/follow/{$follow->id}/stop", ['wallet_address' => self::FOLLOWER])
            ->assertOk()->assertJsonPath('data.status', 'stopped');

        $this->assertSame('running', $follow->bot->fresh()->status);
        $this->assertSame(0, AiBotTrade::where('ai_bot_config_id', $follow->ai_bot_config_id)->count());
        $this->assertSame('detached', app(CopyTradeService::class)->signalContext($follow->bot->fresh())['state']);
    }

    #[Test]
    public function stopping_with_no_position_pauses_the_copy_bot_and_others_cannot_stop_it(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $follow->bot->update(['status' => 'running']);

        $this->postJson("/api/v1/ai-bot/copy/follow/{$follow->id}/stop", ['wallet_address' => self::OTHER])
            ->assertStatus(404)->assertJsonPath('error.code', 'COPY_FOLLOW_NOT_FOUND');
        $this->assertSame('active', $follow->fresh()->status);

        $this->postJson("/api/v1/ai-bot/copy/follow/{$follow->id}/stop", ['wallet_address' => self::FOLLOWER])->assertOk();
        $this->assertSame('paused', $follow->bot->fresh()->status);
    }

    #[Test]
    public function closing_the_master_profile_releases_every_follower(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $this->follow($master, self::OTHER)->assertCreated();

        $this->postJson('/api/v1/ai-bot/copy/master/close', ['wallet_address' => self::MASTER])
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertSame(0, AiCopyFollow::active()->count());
        $this->assertSame([], $this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'));
    }

    // ── สิ่งที่เครื่องยนต์เห็น ───────────────────────────────────────────────

    #[Test]
    public function the_engine_context_carries_exposure_and_entry_but_no_rules(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $service = app(CopyTradeService::class);

        $flat = $service->signalContext($follow->bot);
        $this->assertSame('ok', $flat['state']);
        $this->assertFalse($flat['holding']);

        // มาสเตอร์ลง $400 จากเพดาน $1000 หลังผู้ตามเริ่มตาม
        $position = AiBotPosition::create([
            'ai_bot_config_id' => $master->ai_bot_config_id, 'pair' => 'BTC/USDT', 'mode' => 'live',
            'quantity' => 4, 'entry_price' => 100.18, 'cost_basis' => 400, 'entry_count' => 1, 'opened_at' => now()->addMinute(),
        ]);

        $context = $service->signalContext($follow->bot);

        $this->assertTrue($context['holding']);
        $this->assertEqualsWithDelta(0.4, $context['exposure'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $context['entry_market'], 0.01);
        $this->assertTrue($context['opened_after_follow']);
        $this->assertEqualsWithDelta(200.0, $context['follower_cap'], 1e-9);
        $this->assertStringNotContainsString('do-not-leak', json_encode($context));

        // ไม้ที่เปิดก่อนเริ่มตามไม่ถูกก๊อปปี้ · ไม้โหมดทดลองของมาสเตอร์ไม่ใช่ไม้
        $position->update(['opened_at' => now()->subDay()]);
        $this->assertFalse($service->signalContext($follow->bot)['opened_after_follow']);

        $position->update(['mode' => 'demo']);
        $this->assertFalse($service->signalContext($follow->bot)['holding']);
    }

    // ── ด่านของบอทที่ผูกกับ Copy Trade ───────────────────────────────────────

    #[Test]
    public function a_copy_bot_cannot_be_created_from_the_ordinary_bot_form(): void
    {
        $response = $this->postJson('/api/v1/ai-bot/bots', [
            'wallet_address' => self::FOLLOWER, 'name' => 'x', 'pair' => 'BTC/USDT', 'strategy' => 'copy', 'timeframe' => '4h',
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');

        $this->assertStringContainsString('Copy Trade', $response->json('errors.strategy.0'));
    }

    #[Test]
    public function bots_tied_to_copy_trade_are_locked_where_it_matters(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $copyBot = $follow->bot;

        $edit = fn (AiBotConfig $bot, string $wallet, array $changes) => $this->putJson("/api/v1/ai-bot/bots/{$bot->id}", $changes + [
            'wallet_address' => $wallet, 'name' => $bot->name, 'pair' => $bot->pair, 'strategy' => $bot->strategy, 'timeframe' => $bot->timeframe,
        ]);

        // ผู้ตาม: แก้ชื่อ/ความเสี่ยงได้ เปลี่ยนคู่ไม่ได้ สลับไปโหมดทดลองไม่ได้
        $edit($copyBot, self::FOLLOWER, ['name' => 'My copy', 'risk' => ['max_position_usd' => 150]])->assertOk();
        $edit($copyBot, self::FOLLOWER, ['pair' => 'ETH/USDT'])->assertStatus(422)->assertJsonPath('error.code', 'COPY_BOT_LOCKED');
        $this->postJson("/api/v1/ai-bot/bots/{$copyBot->id}/mode", ['wallet_address' => self::FOLLOWER, 'mode' => 'demo'])
            ->assertStatus(422)->assertJsonPath('error.code', 'COPY_LIVE_ONLY');

        // มาสเตอร์: ย้ายคู่/ลบบอทผลงาน/สลับไปโหมดทดลองไม่ได้ขณะเปิดโปรไฟล์
        $edit($master->bot, self::MASTER, ['pair' => 'ETH/USDT'])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MASTER_BOT');
        $this->deleteJson("/api/v1/ai-bot/bots/{$master->ai_bot_config_id}", ['wallet_address' => self::MASTER])
            ->assertStatus(422)->assertJsonPath('error.code', 'COPY_MASTER_BOT');
        $this->postJson("/api/v1/ai-bot/bots/{$master->ai_bot_config_id}/mode", ['wallet_address' => self::MASTER, 'mode' => 'demo'])
            ->assertStatus(422)->assertJsonPath('error.code', 'COPY_LIVE_ONLY');

        // ลบบอทผู้ตาม = เลิกตาม
        $this->deleteJson("/api/v1/ai-bot/bots/{$copyBot->id}", ['wallet_address' => self::FOLLOWER])->assertOk();
        $this->assertSame('stopped', $follow->fresh()->status);
    }
}
