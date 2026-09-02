<?php

namespace Tests\Feature;

use App\Services\AiBot\Backtest\KlineArchive;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — `aibot:backtest` ต้องรันจบและรายงานสิ่งที่ตัดสินใจต่อได้ โดยไม่แตะเน็ตในเทสต์.
 *
 * Developed by Xman Studio.
 */
class AiBotBacktestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // คลังปลอม: นิ่ง 400 แท่ง → ขึ้น 60 → ลง 60 → นิ่ง 100 (มีรอบให้ momentum เข้า-ออก)
        $this->app->instance(KlineArchive::class, new class extends KlineArchive
        {
            public function __construct() {}

            public function range(string $symbol, string $interval, int $fromMs, int $toMs, bool $offline = false): array
            {
                $closes = array_fill(0, 400, 100.0);
                for ($i = 1; $i <= 60; $i++) {
                    $closes[] = 100.0 + $i * 0.5;
                }
                for ($i = 1; $i <= 60; $i++) {
                    $closes[] = 130.0 - $i * 0.5;
                }
                $closes = array_merge($closes, array_fill(0, 100, 100.0));

                $out = [];
                $start = $toMs - count($closes) * 3_600_000;
                foreach ($closes as $i => $close) {
                    $out[] = ['time' => $start + $i * 3_600_000, 'open' => $close, 'high' => $close * 1.002, 'low' => $close * 0.998, 'close' => $close, 'volume' => 1000.0];
                }

                return $out;
            }
        });
    }

    #[Test]
    public function รันกลยุทธ์เดียวแล้วรายงานตัวชี้วัดครบ(): void
    {
        $this->assertSame(0, Artisan::call('aibot:backtest', ['strategy' => 'momentum', '--days' => 10]));
        $output = Artisan::output();

        foreach (['momentum', 'edge/ไม้', 'ต้นทุนไป-กลับ', 'max drawdown', 'ถือเฉยๆ'] as $expected) {
            $this->assertStringContainsString($expected, $output, "ไม่พบ '{$expected}':\n".$output);
        }
    }

    #[Test]
    public function โหมด_json_ให้โครงสร้างที่เครื่องอ่านต่อได้(): void
    {
        Artisan::call('aibot:backtest', ['strategy' => 'momentum', '--days' => 10, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame('momentum', $payload['strategy']);
        $this->assertArrayHasKey('edge_bps', $payload['summary']);
        $this->assertArrayHasKey('cost_bps', $payload['summary']);
        $this->assertIsArray($payload['trades']);
    }

    #[Test]
    public function จูนหลายค่าแล้วจัดอันดับ(): void
    {
        $code = Artisan::call('aibot:backtest', [
            'strategy' => 'momentum', '--days' => 10,
            '--sweep' => ['fast_ema=8,12', 'slow_ema=21,26'],
        ]);

        $this->assertSame(0, $code);
        $output = Artisan::output();
        $this->assertStringContainsString('4 ชุด', $output);
        $this->assertStringContainsString('walk-forward', $output);
    }

    #[Test]
    public function walk_forward_แยกช่วงจูนกับช่วงทดสอบ(): void
    {
        $code = Artisan::call('aibot:backtest', [
            'strategy' => 'momentum', '--days' => 10, '--walk-forward' => true,
            '--sweep' => ['fast_ema=8,12'],
        ]);

        $this->assertSame(0, $code);
        $output = Artisan::output();
        $this->assertStringContainsString('จูน (train)', $output);
        $this->assertStringContainsString('ทดสอบ (test)', $output);
    }

    #[Test]
    public function กลยุทธ์ที่ไม่รู้จักต้องล้มพร้อมบอก(): void
    {
        $this->assertSame(1, Artisan::call('aibot:backtest', ['strategy' => 'ไม่มี']));
        $this->assertStringContainsString('ไม่รู้จัก', Artisan::output());
    }

    #[Test]
    public function params_ที่ไม่ใช่_json_ต้องถูกปฏิเสธ(): void
    {
        $this->assertSame(1, Artisan::call('aibot:backtest', ['strategy' => 'momentum', '--params' => 'not-json']));
    }
}
