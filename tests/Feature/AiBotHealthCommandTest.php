<?php

namespace Tests\Feature;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\AiBot\WorkerHealth;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ยามเฝ้าวอร์กเกอร์: ปลดล็อกค้าง + บันทึกดับ/ฟื้น.
 *
 * สถานการณ์ที่ต้องรอด: เซิร์ฟเวอร์ดับกลางรอบ → ล็อกกันซ้อนค้างในแคช → cron กลับมา
 * แล้วเห็นล็อกเลยข้ามทุกรอบ บอททั้งกลยุทธ์นิ่ง 10 นาทีโดยไม่มีใครรู้
 *
 * Developed by Xman Studio.
 */
class AiBotHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['aibot.health.stale_minutes' => 5]);
        $this->seed(AiBotPlanSeeder::class);
    }

    /** บอทคลาวด์ที่กำลังทำงาน — ต้องมีการเช่าแพลนคลาวด์จริง ไม่งั้น scope cloudExecuted ไม่นับ */
    private function cloudBot(string $strategy = 'momentum'): AiBotConfig
    {
        $plan = AiBotPlan::where('execution', 'cloud')->firstOrFail();

        AiBotSubscription::firstOrCreate(
            ['wallet_address' => self::WALLET, 'ai_bot_plan_id' => $plan->id],
            ['status' => 'active', 'started_at' => now(), 'expires_at' => now()->addDays(30)],
        );

        return AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => "r-{$strategy}", 'pair' => 'BTC/USDT',
            'strategy' => $strategy, 'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
        ]);
    }

    private function tickEvent(string $strategy): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => $e->description === "aibot:tick:{$strategy}");

        $this->assertNotNull($event, "ตารางเวลาต้องมี aibot:tick:{$strategy}");

        return $event;
    }

    #[Test]
    public function ไม่มีบอทคลาวด์ก็ไม่มีอะไรให้เฝ้า(): void
    {
        $this->artisan('aibot:health')
            ->expectsOutputToContain('ไม่มีบอทคลาวด์')
            ->assertSuccessful();
    }

    #[Test]
    public function วอร์กเกอร์เงียบพร้อมล็อกค้างต้องถูกปลดและบันทึกว่าดับ(): void
    {
        $this->cloudBot('momentum');
        app(WorkerHealth::class)->beat('momentum');
        $this->travel(8)->minutes();

        $event = $this->tickEvent('momentum');
        $event->mutex->create($event);
        $this->assertTrue($event->mutex->exists($event));

        Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'released stale'));
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'worker silent'));
        Log::shouldReceive('info')->never();

        $this->artisan('aibot:health')->assertFailed();

        $this->assertFalse($event->mutex->exists($event), 'ล็อกค้างต้องถูกปลดเพื่อให้ cron รอบถัดไปเดินได้');

        $health = app(WorkerHealth::class);
        $this->assertNotNull($health->outageSince('momentum'));

        $summary = $health->summary();
        $this->assertFalse($summary['alive']);
        $this->assertTrue($summary['strategies']['momentum']['lock_released']);
        $this->assertSame(8, $summary['strategies']['momentum']['beat_age_minutes']);
    }

    #[Test]
    public function เงียบซ้ำต้องไม่แจ้งเตือนซ้ำ(): void
    {
        $this->cloudBot('grid');
        app(WorkerHealth::class)->markOutage('grid', now()->subMinutes(20));

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->never();

        $this->artisan('aibot:health')->assertFailed();
    }

    #[Test]
    public function กลับมาเต้นแล้วต้องบันทึกการฟื้นพร้อมนาทีที่หายไป(): void
    {
        $this->cloudBot('momentum');
        $health = app(WorkerHealth::class);
        $health->markOutage('momentum', now()->subMinutes(14));
        $health->beat('momentum');

        Log::shouldReceive('info')->once()->withArgs(
            fn ($msg, $ctx) => str_contains($msg, 'recovered') && $ctx['down_minutes'] === 14
        );

        $this->artisan('aibot:health')->assertSuccessful();

        $this->assertNull($health->outageSince('momentum'));
        $this->assertTrue($health->summary()['alive']);
        $this->assertSame(14, $health->summary()['strategies']['momentum']['recovered_after_minutes']);
    }

    #[Test]
    public function กลยุทธ์ที่ไม่มีบอทรออยู่ไม่ถูกนับว่าดับ(): void
    {
        $this->cloudBot('momentum');
        app(WorkerHealth::class)->beat('momentum');

        // grid ไม่เคยเต้น แต่ไม่มีบอท grid สักตัว → ต้องไม่โผล่ในรายงาน
        $this->artisan('aibot:health', ['--json' => true])->assertSuccessful();

        $summary = app(WorkerHealth::class)->summary();
        $this->assertArrayHasKey('momentum', $summary['strategies']);
        $this->assertArrayNotHasKey('grid', $summary['strategies']);
    }

    #[Test]
    public function ตารางเวลาต้องมียามและยามต้องไม่ล็อกตัวเอง(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => $e->description === 'aibot:health');

        $this->assertNotNull($event);
        $this->assertSame('*/2 * * * *', $event->expression);
        $this->assertFalse($event->withoutOverlapping, 'ยามค้างล็อกเองแล้วจะไม่มีใครมาปลดให้');
    }
}
