<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotCredit;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\AiBotService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เส้นทางเงินของการ "เช่าบอท": สิ่งที่จ่ายต้องตรงกับสิ่งที่ได้.
 *
 * สามเรื่องที่ตรวจเจอก่อนเปิดขาย:
 *  1) แพลนฟรีกับแพลนเสียเงินถูกสร้างพร้อมกันได้ (คนละล็อก) → ลูกค้าจ่ายแล้วระบบเห็นว่าเป็นแพลนฟรี
 *  2) เพดานทุนของแพลนเสียเงินค้างอยู่กับบอทหลังแพลนหมดอายุ → เดินบนแพลนฟรีด้วยขนาดไม้ของ VIP
 *  3) ขอจำนวนวันนอกรายการที่ขาย → ถูกตัดเงินและได้แพลน 1 วันโดยไม่มีอะไรบอก
 *
 * Developed by Xman Studio.
 */
class AiBotRentalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x5151515151515151515151515151515151515151';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.self::WALLET, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));

        config(['aibot.sales_open' => true]);
    }

    private function service(): AiBotService
    {
        return app(AiBotService::class);
    }

    private function plan(string $code): AiBotPlan
    {
        return AiBotPlan::where('code', $code)->firstOrFail();
    }

    private function liveRows(): int
    {
        return AiBotSubscription::where('wallet_address', self::WALLET)->live()->count();
    }

    // ───────────────────── 1) แพลนฟรี vs แพลนเสียเงิน ─────────────────────

    /**
     * ⭐ ระหว่างที่กำลังเช่า (ถือล็อกของกระเป๋าอยู่) การถามสถานะต้องไม่สร้างแถวแพลนฟรีแทรก.
     */
    #[Test]
    public function a_status_check_during_a_rental_creates_no_free_row(): void
    {
        $lock = Cache::lock('aibot:subscribe:'.self::WALLET, 15);
        $this->assertTrue($lock->get());

        try {
            $this->assertNull($this->service()->ensureFreeSubscription(self::WALLET));
            $this->assertSame(0, AiBotSubscription::count(), 'ห้ามมีแถวแพลนฟรีเกิดระหว่างที่การเช่ากำลังเดิน');
        } finally {
            $lock->release();
        }

        // ล็อกว่างแล้วถึงสร้างได้ตามปกติ
        $this->assertSame('free', $this->service()->ensureFreeSubscription(self::WALLET)?->plan->code);
    }

    #[Test]
    public function renting_over_a_free_plan_leaves_exactly_one_live_rental(): void
    {
        $this->service()->ensureFreeSubscription(self::WALLET);
        $this->service()->record(self::WALLET, 'topup', 5000, 'test:seed');

        $subscription = $this->service()->subscribe(self::WALLET, $this->plan('starter'), 30, 'key-1');

        $this->assertSame('starter', $subscription->plan->code);
        $this->assertSame(1, $this->liveRows());
        $this->assertSame('starter', $this->service()->activeSubscription(self::WALLET)->plan->code, 'จ่ายแล้วต้องเห็นเป็นแพลนที่จ่าย');
        // ถามสถานะซ้ำหลังเช่า = ได้ใบเดิม ไม่มีแถวฟรีงอก
        $this->assertSame('starter', $this->service()->ensureFreeSubscription(self::WALLET)->plan->code);
        $this->assertSame(1, $this->liveRows());
    }

    // ───────────────────── 2) เพดานทุนหลังแพลนหมดอายุ ─────────────────────

    /**
     * ⭐ แพลนเสียเงินหมดอายุ → ตกมาแพลนฟรี → บอทต้องกลับมาอยู่ใต้เพดานทุนของแพลนฟรี
     *    (และจำค่าที่ผู้ใช้เคยขอไว้ เพื่อขยายคืนเมื่อเช่าใหม่).
     */
    #[Test]
    public function falling_back_to_the_free_plan_puts_bots_back_under_its_capital_cap(): void
    {
        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => $this->plan('vip')->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $bot = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอทของ VIP',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
            'params' => [],
            'risk' => $this->service()->sanitizeRisk(['max_position_usd' => 50000], $this->plan('vip')),
        ]);

        $this->assertEqualsWithDelta(50000.0, (float) $bot->risk['max_position_usd'], 0.001);

        $this->assertSame('free', $this->service()->ensureFreeSubscription(self::WALLET)->plan->code);

        $risk = $bot->fresh()->risk;
        $this->assertEqualsWithDelta(100.0, (float) $risk['max_position_usd'], 0.001, 'เพดานทุนของแพลนฟรีคือ $100');
        $this->assertEqualsWithDelta(50000.0, (float) $risk['max_position_usd_requested'], 0.001, 'ค่าที่ผู้ใช้ขอไว้ต้องไม่หาย');

        // เช่าใหม่ → ขยายคืนตามที่เคยขอ
        $this->service()->record(self::WALLET, 'topup', 50000, 'test:seed');
        $this->service()->subscribe(self::WALLET, $this->plan('vip'), 30, 'key-2');

        $this->assertEqualsWithDelta(50000.0, (float) $bot->fresh()->risk['max_position_usd'], 0.001);
    }

    // ───────────────────── 3) จำนวนวันที่ขายจริงเท่านั้น ─────────────────────

    #[Test]
    public function days_outside_the_sold_list_are_rejected_instead_of_silently_charged_for_one_day(): void
    {
        $this->service()->record(self::WALLET, 'topup', 5000, 'test:seed');

        $this->postJson('/api/v1/ai-bot/subscribe', [
            'wallet_address' => self::WALLET,
            'plan_code' => 'starter',
            'days' => 14,
            'idempotency_key' => 'rental-test-key-14-days',
        ])->assertStatus(422);

        $this->assertSame(0, AiBotCredit::where('wallet_address', self::WALLET)->where('type', 'charge')->count(), 'ต้องไม่มีการตัดเครดิต');
        $this->assertSame(5000.0, (float) $this->service()->balanceFor(self::WALLET));
    }

    #[Test]
    public function a_sold_duration_charges_exactly_credits_per_day_times_days(): void
    {
        $this->service()->record(self::WALLET, 'topup', 5000, 'test:seed');
        $starter = $this->plan('starter');

        $this->postJson('/api/v1/ai-bot/subscribe', [
            'wallet_address' => self::WALLET,
            'plan_code' => 'starter',
            'days' => 30,
            'idempotency_key' => 'rental-test-key-30-days',
        ])->assertOk();

        $expected = (float) $starter->credits_per_day * 30;
        $this->assertEqualsWithDelta(5000.0 - $expected, (float) $this->service()->balanceFor(self::WALLET), 0.0001);
        $this->assertEqualsWithDelta(30, now()->diffInDays($this->service()->activeSubscription(self::WALLET)->expires_at), 0.01);
    }
}
