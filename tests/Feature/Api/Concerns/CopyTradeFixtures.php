<?php

namespace Tests\Feature\Api\Concerns;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\User;
use App\Services\Kyc\KycGate;
use App\Services\MarketDataService;
use App\Services\TpixDexService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — ฉากร่วมของเทสต์ Master / Copy Trade.
 *
 * เปิดธง Copy Trade + เทรดเงินจริงครบสามธง (ทุกอย่างเป็นแถวในฐานข้อมูล ไม่มีธุรกรรมจริง)
 * กระเป๋าสามใบที่ยืนยันแล้วและเช่าแพลน VIP · ราคา TPIX ปลอม 0.5 USD · KYC สลับผ่าน/ไม่ผ่านได้
 *
 * Developed by Xman Studio.
 */
trait CopyTradeFixtures
{
    protected const MASTER = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected const FOLLOWER = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected const OTHER = '0xcccccccccccccccccccccccccccccccccccccccc';

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

    protected function bot(string $wallet, array $attributes = []): AiBotConfig
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
    protected function trades(AiBotConfig $bot, array $pnls, int $daysAgo = 60, string $mode = 'live'): void
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
    protected function eligibleBot(string $wallet = self::MASTER): AiBotConfig
    {
        $bot = $this->bot($wallet);
        $this->trades($bot, array_merge(array_fill(0, 20, 10.0), array_fill(0, 4, -10.0)));

        return $bot;
    }

    protected function profile(array $overrides = []): array
    {
        return $overrides + [
            'wallet_address' => self::MASTER,
            'display_name' => 'Trend Rider',
            'bio' => 'ตามเทรนด์ 4 ชม.',
            'fee_rate_pct' => 20,
            'platform_share_pct' => 30,
        ];
    }

    protected function registerMaster(array $overrides = [], ?AiBotConfig $bot = null): AiCopyMaster
    {
        $bot ??= $this->eligibleBot($overrides['wallet_address'] ?? self::MASTER);

        $this->postJson('/api/v1/ai-bot/copy/master', $this->profile($overrides) + ['bot_id' => $bot->id])->assertCreated();

        return AiCopyMaster::where('wallet_address', $bot->wallet_address)->firstOrFail();
    }

    protected function follow(AiCopyMaster $master, string $wallet = self::FOLLOWER, array $overrides = [])
    {
        return $this->postJson('/api/v1/ai-bot/copy/follow', $overrides + [
            'wallet_address' => $wallet,
            'master_id' => $master->id,
            'accept_fee_rate_pct' => $master->fee_rate_pct,
            'risk' => ['max_position_usd' => 200, 'stop_loss_pct' => 5, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 50],
        ]);
    }

    /** ไม้ขายเงินจริงของบอทผู้ตาม (ที่ก๊อปปี้มา) */
    protected function followerSell(AiCopyFollow $follow, float $pnl, string $mode = 'live'): AiBotTrade
    {
        return AiBotTrade::create([
            'ai_bot_config_id' => $follow->ai_bot_config_id, 'wallet_address' => $follow->follower_wallet, 'pair' => 'BTC/USDT',
            'mode' => $mode, 'side' => 'sell', 'price' => 100, 'quantity' => 1, 'gross_value' => 100,
            'fee' => 0.1, 'slippage_cost' => 0.08, 'realized_pnl' => $pnl, 'strategy' => 'copy', 'reason' => 'test',
        ]);
    }
}
