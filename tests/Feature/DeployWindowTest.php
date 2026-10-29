<?php

namespace Tests\Feature;

use App\Support\DeployWindow;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — หน้าต่าง deploy: งานตามตารางต้องหยุดระหว่าง git reset → migrate → config:cache.
 *
 * สถานการณ์ที่ต้องรอด: deploy v1.1.263 (2026-10-29) เพิ่มคอลัมน์ให้ treasury_payouts
 * แต่ cron เรียก tpix:treasury-payouts ด้วยโค้ดใหม่ก่อน migrate → Unknown column 'nonce' สองรอบ
 *
 * Developed by Xman Studio.
 */
class DeployWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearFlag();
        (fn () => static::$staleWarned = false)->bindTo(null, DeployWindow::class)();
    }

    protected function tearDown(): void
    {
        $this->clearFlag();
        parent::tearDown();
    }

    private function clearFlag(): void
    {
        @unlink(DeployWindow::flagPath());
        @unlink(DeployWindow::skippedPath());
    }

    /**
     * @return array<int, Event>
     */
    private function events(): array
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThan(20, count($events), 'ตารางเวลาต้องโหลดจาก routes/console.php');

        return $events;
    }

    #[Test]
    public function ไม่มีธง_ทุกงานเดินตามปกติ(): void
    {
        foreach ($this->events() as $event) {
            $this->assertTrue($event->filtersPass($this->app), "{$event->description} ต้องไม่ถูกหยุดเมื่อไม่ได้ deploy");
        }
    }

    #[Test]
    public function มีธง_ทุกงานในตารางถูกหยุด(): void
    {
        touch(DeployWindow::flagPath());

        // งานไหนประกาศหลังลูป skip() ท้าย routes/console.php จะหลุดมาตรงนี้
        foreach ($this->events() as $event) {
            $this->assertFalse($event->filtersPass($this->app), "{$event->description} ต้องถูกหยุดระหว่าง deploy");
        }

        $payouts = collect($this->events())->first(fn (Event $e) => $e->description === 'treasury:payouts');
        $this->assertNotNull($payouts, 'ตารางเวลาต้องมี treasury:payouts');
        $this->assertFalse($payouts->filtersPass($this->app));
    }

    #[Test]
    public function ธงที่วางกลางรอบ_งานถัดไปในรอบเดียวกันเห็นทันที(): void
    {
        $events = $this->events();

        $this->assertTrue($events[0]->filtersPass($this->app));

        // schedule:run เช็กทีละงาน — stat cache ของ PHP ต้องไม่ทำให้มองไม่เห็นธงที่เพิ่งวาง
        touch(DeployWindow::flagPath());

        $this->assertFalse($events[1]->filtersPass($this->app));
    }

    #[Test]
    public function ธงค้างเกินอายุถูกมองข้ามและเตือนครั้งเดียว(): void
    {
        touch(DeployWindow::flagPath(), time() - DeployWindow::MAX_AGE_SECONDS - 60);

        Log::spy();

        foreach ($this->events() as $event) {
            $this->assertTrue($event->filtersPass($this->app), "{$event->description} ต้องเดินต่อเมื่อธงค้างจาก deploy ที่ตายไป");
        }

        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function งานที่รอบถัดไปอีกนานถูกจดให้_deploy_แจ้งเตือน(): void
    {
        // 00:15 = นาทีของ stabilizer:report (รายวัน) — งานรายนาที/ราย 5 นาทีไม่ต้องจด
        $this->travelTo(now()->setTime(0, 15));
        touch(DeployWindow::flagPath());

        foreach ($this->events() as $event) {
            $event->filtersPass($this->app);
        }

        $skipped = (string) @file_get_contents(DeployWindow::skippedPath());

        $this->assertStringContainsString('php artisan stabilizer:report', $skipped);
        $this->assertStringContainsString('php artisan aibot:analyze --scope=', $skipped);
        $this->assertStringNotContainsString('bridge:payout', $skipped);
        $this->assertStringNotContainsString('tpix:treasury-payouts', $skipped);
        $this->assertStringNotContainsString('dex:sync', $skipped);
    }
}
