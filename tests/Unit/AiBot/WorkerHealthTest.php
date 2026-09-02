<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Services\AiBot\WorkerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — สัญญาณชีพของวอร์กเกอร์ AI TRADE.
 *
 * "ออนไลน์" ต้องไม่ใช่แค่สถานะ running ในฐานข้อมูล — เซิร์ฟเวอร์ดับแล้วสถานะนั้น
 * ยังอยู่ แต่ไม่มีใครเดินบอทให้ ชุดนี้ตรึงว่าการตัดสินมองครบสามชั้น
 * (สถานะ · วอร์กเกอร์เต้น · บอทได้รอบคิด)
 *
 * Developed by Xman Studio.
 */
class WorkerHealthTest extends TestCase
{
    use RefreshDatabase;

    private WorkerHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['aibot.health.stale_minutes' => 5, 'aibot.tick_interval_minutes.vip' => 1]);
        $this->health = new WorkerHealth;
    }

    private function bot(array $overrides = []): AiBotConfig
    {
        return AiBotConfig::create(array_merge([
            'wallet_address' => '0x1111111111111111111111111111111111111111',
            'name' => 'heartbeat', 'pair' => 'BTC/USDT', 'strategy' => 'momentum',
            'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
            'last_run_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function ไม่เคยเต้นเลยถือว่าไม่มีชีวิต(): void
    {
        $this->assertNull($this->health->lastBeat('momentum'));
        $this->assertNull($this->health->beatAgeMinutes('momentum'));
        $this->assertFalse($this->health->isAlive('momentum'));
    }

    #[Test]
    public function เต้นแล้วทั้งกลยุทธ์และภาพรวมต้องรู้(): void
    {
        $this->health->beat('momentum');

        $this->assertTrue($this->health->isAlive('momentum'));
        $this->assertTrue($this->health->isAlive());
        $this->assertFalse($this->health->isAlive('grid'), 'กลยุทธ์อื่นไม่ได้เต้นด้วย');
        $this->assertSame(0, $this->health->beatAgeMinutes('momentum'));
    }

    #[Test]
    public function เงียบเกินเกณฑ์แล้วต้องตาย(): void
    {
        $this->health->beat('momentum');
        $this->travel(6)->minutes();

        $this->assertFalse($this->health->isAlive('momentum'));
        $this->assertTrue($this->health->isAlive('momentum', 10), 'เกณฑ์ที่ยาวกว่ายังผ่าน');
    }

    #[Test]
    public function บอท_running_ที่วอร์กเกอร์เงียบต้องออฟไลน์พร้อมเหตุผล(): void
    {
        $bot = $this->bot();

        $status = $this->health->botStatus($bot, 1);

        $this->assertFalse($status['online']);
        $this->assertSame('worker_silent', $status['reason']);
    }

    #[Test]
    public function บอทที่วอร์กเกอร์เต้นและเพิ่งได้รอบคิดต้องออนไลน์(): void
    {
        $this->health->beat('momentum');
        $bot = $this->bot();

        $status = $this->health->botStatus($bot, 1);

        $this->assertTrue($status['online']);
        $this->assertNull($status['reason']);
        $this->assertNotNull($status['worker_last_beat_at']);
    }

    #[Test]
    public function บอทที่ไม่ได้รอบคิดนานเกิน_3_เท่าของรอบต้องออฟไลน์แม้วอร์กเกอร์เต้น(): void
    {
        $bot = $this->bot(['last_run_at' => now()->subMinutes(30)]);
        $this->health->beat('momentum');

        $status = $this->health->botStatus($bot, 5);   // 5×3 + 5 = 20 นาที

        $this->assertFalse($status['online']);
        $this->assertSame('bot_stale', $status['reason']);
    }

    #[Test]
    public function บอทใหม่ที่ยังไม่เคยเดินได้เวลาผ่อนผันเท่าหนึ่งรอบ(): void
    {
        $this->health->beat('momentum');
        $bot = $this->bot(['last_run_at' => null]);

        $this->assertTrue($this->health->botStatus($bot, 5)['online']);
    }

    #[Test]
    public function บอทที่พักหรือถูกแบนไม่ใช่ออฟไลน์แบบผิดปกติ(): void
    {
        $this->health->beat('momentum');

        $paused = $this->bot(['status' => 'paused']);
        $banned = $this->bot(['banned_at' => now(), 'banned_reason' => 'x']);

        $this->assertSame('not_running', $this->health->botStatus($paused, 1)['reason']);
        $this->assertSame('banned', $this->health->botStatus($banned, 1)['reason']);
    }

    #[Test]
    public function บันทึกช่วงดับแจ้งครั้งเดียวและวัดเวลาที่หายไปตอนฟื้น(): void
    {
        $this->assertTrue($this->health->markOutage('momentum', now()->subMinutes(12)));
        $this->assertFalse($this->health->markOutage('momentum'), 'ครั้งที่สองต้องไม่แจ้งซ้ำ');

        $down = $this->health->clearOutage('momentum');

        $this->assertSame(12, $down);
        $this->assertNull($this->health->outageSince('momentum'));
        $this->assertNull($this->health->clearOutage('momentum'), 'ไม่ได้ดับอยู่แล้วต้องคืน null');
    }

    #[Test]
    public function สรุปสุขภาพเก็บเวลาที่ตรวจไว้ให้_api(): void
    {
        $this->assertNull($this->health->summary());

        $this->health->storeSummary(['alive' => true, 'strategies' => []]);

        $summary = $this->health->summary();
        $this->assertTrue($summary['alive']);
        $this->assertArrayHasKey('checked_at', $summary);
    }
}
