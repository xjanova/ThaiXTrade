<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\AiBot\BotRunner;
use App\Services\AiBotService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เส้นทางที่เบราว์เซอร์สั่งบอทแพลนฟรีเดิน (POST /ai-bot/bots/{id}/tick).
 *
 * สองเรื่องที่เคยพัง:
 *  1) รอบเดินถูกเทียบกับ "เวลาจบ" ของรอบก่อน + ไม่มีล็อก — หน้าเว็บสั่งทุก 30 วิแต่บอทเดินจริง
 *     ทุก 60 วิ และสองแท็บเดินบอทซ้อนกันได้ (ซื้อซ้ำสองไม้)
 *  2) แพลนไม่ให้บอทเดินแล้ว (VIP หมดอายุ → แพลนฟรี) ตอบ 403 เฉยๆ บอทค้าง running ตลอดกาล
 *     หน้าจอโชว์ "ระบบกำลังตรวจสอบ" แทนที่จะบอกว่าต้องต่ออายุแพลน
 *
 * Developed by Xman Studio.
 */
class AiBotBrowserTickTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x7171717171717171717171717171717171717171';

    /** จำนวนครั้งที่ BotRunner ถูกสั่งเดินจริง */
    private int $runs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.self::WALLET, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));

        config(['aibot.browser_tick_min_seconds' => 30]);

        // ตัวเดินบอทปลอม — ไม่ยิงตลาดจริง เขียน last_run_at ตอนจบรอบแบบเดียวกับของจริง (record())
        $this->mock(BotRunner::class, function ($mock) {
            $mock->shouldReceive('tick')->andReturnUsing(function (AiBotConfig $bot) {
                $this->runs++;
                $bot->update(['last_run_at' => now()]);

                return ['action' => 'hold', 'reason' => 'ทดสอบ', 'risk' => 'calm'];
            });
        });
    }

    private function subscribeTo(string $planCode, ?Carbon $expiresAt = null): AiBotSubscription
    {
        return AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => AiBotPlan::where('code', $planCode)->firstOrFail()->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDays(30),
            'expires_at' => $expiresAt ?? now()->addDays(30),
        ]);
    }

    private function makeBot(array $attributes = []): AiBotConfig
    {
        return AiBotConfig::create(array_merge([
            'wallet_address' => self::WALLET,
            'name' => 'บอทฟรี',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
            'params' => [],
            'risk' => ['max_position_usd' => 100],
        ], $attributes));
    }

    private function tick(AiBotConfig $bot): TestResponse
    {
        return $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => self::WALLET]);
    }

    // ───────────────────────── รอบเดิน + ล็อก ─────────────────────────

    /**
     * ⭐ นับรอบจาก "เวลาเริ่ม" — รอบก่อนใช้เวลา 20 วิ คำขอถัดไปที่ 30 วิหลังเริ่มต้องได้เดิน.
     *
     * เดิมเทียบกับ last_run_at (เวลาจบ) → เห็นว่าผ่านไปแค่ 10 วิ → ข้าม → บอทเดินทุก 60 วิ
     */
    #[Test]
    public function the_cadence_counts_from_when_the_last_run_started(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot();
        $startedAt = now()->startOfSecond();

        $this->travelTo($startedAt);
        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', false);

        // รอบนั้นจบช้า (ตลาดตอบช้า) — BotRunner เขียน last_run_at เป็นเวลาจบ
        $bot->update(['last_run_at' => $startedAt->copy()->addSeconds(20)]);

        $this->travelTo($startedAt->copy()->addSeconds(30));
        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', false);

        $this->assertSame(2, $this->runs);
    }

    /** ตัวจับเวลาของเบราว์เซอร์มาถึงก่อนเวลาไม่กี่ร้อยมิลลิวินาที — ต้องไม่ถูกข้ามจนรอบกลายเป็นสองเท่า */
    #[Test]
    public function a_tick_arriving_slightly_early_still_runs(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot();
        $startedAt = now()->startOfSecond();

        $this->travelTo($startedAt);
        $this->tick($bot)->assertOk();

        $this->travelTo($startedAt->copy()->addSeconds(29));
        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', false);

        $this->assertSame(2, $this->runs);
    }

    /** สั่งถี่เกินไปยังต้องถูกข้าม — ไม่ใช่เปิดให้หน้าเว็บเดินบอทรัวๆ */
    #[Test]
    public function a_second_tick_inside_the_window_is_skipped(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot();

        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', false);

        $this->travel(5)->seconds();
        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', true);

        $this->assertSame(1, $this->runs);
    }

    /**
     * ⭐ สองแท็บสั่งพร้อมกัน — ใบที่สองต้องไม่เดินบอทซ้อน (กันซื้อซ้ำสองไม้).
     *
     * จำลอง "อีกแท็บกำลังเดินอยู่" ด้วยการถือล็อกของบอทตัวนี้ไว้ก่อน
     */
    #[Test]
    public function a_tick_while_another_tab_is_running_the_bot_is_skipped(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot();

        $otherTab = Cache::lock('aibot:browser-tick:'.$bot->id, 120);
        $this->assertTrue($otherTab->get());

        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', true);
        $this->assertSame(0, $this->runs);

        $otherTab->release();

        $this->tick($bot)->assertOk()->assertJsonPath('data.skipped', false);
        $this->assertSame(1, $this->runs);
    }

    // ───────────────────────── แพลนไม่ให้เดินแล้ว ─────────────────────────

    /**
     * ⭐ VIP หมดอายุ → ระบบลงแพลนฟรีให้ → กลยุทธ์ VIP ถูกล็อก: ต้องพักบอทพร้อมเหตุผล.
     *
     * เดิมตอบ 403 แล้วบอทค้าง running ตลอดกาล — หน้าเว็บสั่งเดินทุก 30 วิแล้วโดนปฏิเสธทุกรอบ
     */
    #[Test]
    public function a_bot_the_current_plan_no_longer_covers_is_paused_with_the_reason(): void
    {
        $this->subscribeTo('vip', now()->subDay());   // VIP หมดอายุไปแล้ว
        $bot = $this->makeBot(['strategy' => 'ai_signal']);

        $this->tick($bot)
            ->assertStatus(403)
            ->assertJsonPath('error.code', AiBotService::ERR_STRATEGY_LOCKED);

        $bot->refresh();
        $this->assertSame('paused', $bot->status);
        $this->assertSame(AiBotConfig::PAUSE_PLAN_LOCKED, $bot->pauseReason());
        $this->assertSame(0, $this->runs);

        // หน้าจอเห็นเหตุผลเป็นรหัส แปลภาษาได้ — ไม่ใช่แค่ไฟสีเหลือง
        $this->getJson('/api/v1/ai-bot/status?wallet_address='.self::WALLET)
            ->assertOk()
            ->assertJsonPath('data.bots.0.pause_reason', AiBotConfig::PAUSE_PLAN_LOCKED);
    }

    /** ยังเปิดอยู่แต่แพลนไม่ให้เดิน (ยังไม่มีใครสั่งเดิน) = ออฟไลน์ด้วยเหตุผลของแพลน ไม่ใช่ "กำลังตรวจสอบ" */
    #[Test]
    public function a_running_bot_outside_the_plan_is_reported_as_a_plan_problem(): void
    {
        $this->subscribeTo('vip', now()->subDay());
        $this->makeBot(['strategy' => 'ai_signal']);

        $this->getJson('/api/v1/ai-bot/status?wallet_address='.self::WALLET)
            ->assertOk()
            ->assertJsonPath('data.bots.0.status', 'running')
            ->assertJsonPath('data.bots.0.online', false)
            ->assertJsonPath('data.bots.0.offline_reason', AiBotConfig::PAUSE_PLAN_LOCKED);
    }

    /** กดเริ่มใหม่ = เหตุผลที่ระบบเคยพักไว้ไม่จริงแล้ว ต้องหายไปด้วย */
    #[Test]
    public function starting_the_bot_again_clears_the_old_pause_reason(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot(['status' => 'paused']);
        $bot->pauseBecause(AiBotConfig::PAUSE_PLAN_EXPIRED, 'แพลนหมดอายุ');

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/state", ['wallet_address' => self::WALLET, 'action' => 'start'])
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.pause_reason', null);
    }
}
