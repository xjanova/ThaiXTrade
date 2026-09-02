<?php

namespace Tests\Feature;

use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use App\Models\AiBotTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — `aibot:harvest` ต้องสรุปได้โดยไม่โหลดทุกแถวขึ้นหน่วยความจำ.
 *
 * คำสั่งนี้ตายบน prod 2 ก.ย. 2026 ตอนตารางถึง 81k แถว (memory limit 128M) —
 * เทสต์ชุดนี้ยืนยันว่าตัวเลขยังถูกหลังย้ายไปรวมในฐานข้อมูล และนับ repeat_count
 * เป็น "รอบ" ไม่ใช่นับแถว
 *
 * Developed by Xman Studio.
 */
class AiBotHarvestCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x4444444444444444444444444444444444444444';

    private function seedHarvestData(): AiBotConfig
    {
        $bot = AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => 'harvest', 'pair' => 'BTC/USDT',
            'strategy' => 'momentum', 'timeframe' => '1h', 'status' => 'paused', 'mode' => 'demo',
            'params' => [], 'risk' => [],
        ]);

        $decision = fn (string $action, string $reason, int $repeat = 1) => AiBotDecision::create([
            'ai_bot_config_id' => $bot->id, 'wallet_address' => self::WALLET, 'strategy' => 'momentum',
            'pair' => 'BTC/USDT', 'timeframe' => '1h', 'mode' => 'demo', 'action' => $action,
            'reason' => $reason, 'risk_level' => 'calm', 'price' => 100, 'has_position' => false,
            'repeat_count' => $repeat, 'last_seen_at' => now(),
        ]);

        // สภาพ "รอสัญญาณ" คงอยู่ 40 รอบ = แถวเดียว repeat 40 · เข้าไม้ 1 · ถือต่อ 5
        $decision('hold', 'ยังไม่มีสัญญาณตัดขึ้นของ EMA', 40);
        $decision('buy', 'EMA เร็วตัดขึ้น');
        $decision('hold', 'ถือต่อ เทรนด์ยังไม่กลับตัว', 5);

        foreach ([['buy', null], ['sell', 1.5], ['buy', null], ['sell', -0.5]] as [$side, $pnl]) {
            AiBotTrade::create([
                'ai_bot_config_id' => $bot->id, 'wallet_address' => self::WALLET, 'pair' => 'BTC/USDT',
                'mode' => 'demo', 'side' => $side, 'price' => 100, 'quantity' => 0.1, 'gross_value' => 10,
                'fee' => 0.01, 'slippage_cost' => 0.008, 'realized_pnl' => $pnl, 'strategy' => 'momentum',
                'reason' => 'ทดสอบ', 'risk_level' => 'calm',
            ]);
        }

        return $bot;
    }

    #[Test]
    public function มันสรุปรายกลยุทธ์ได้ถูกต้องโดยนับ_repeat_count_เป็นรอบ(): void
    {
        $this->seedHarvestData();

        $this->assertSame(0, Artisan::call('aibot:harvest'));
        $output = Artisan::output();

        // ตัวเลขที่ตารางต้องแสดง: รอบคิด 46 (40 + 1 + 5) · ซื้อ 1 · ถือ 45 · ปิดไม้ 2 · ชนะ 50%
        foreach (['momentum', '46', '50%', 'ยังไม่มีสัญญาณตัดขึ้นของ EMA'] as $expected) {
            $this->assertStringContainsString($expected, $output, "ไม่พบ '{$expected}' ในผลลัพธ์:\n".$output);
        }
    }

    #[Test]
    public function มัน_export_ข้อมูลดิบเป็น_json_ที่อ่านได้(): void
    {
        $this->seedHarvestData();
        $path = storage_path('app/testing/harvest-'.uniqid().'.json');

        $this->artisan('aibot:harvest', ['--export' => $path])->assertExitCode(0);

        $payload = json_decode(File::get($path), true);
        File::delete($path);

        $this->assertIsArray($payload, 'ไฟล์ที่สตรีมออกมาต้องเป็น JSON ที่ถูกต้อง');
        $this->assertCount(3, $payload['decisions']);
        $this->assertSame(46, $payload['by_strategy']['momentum']['ticks']);
        $this->assertSame(2, $payload['by_strategy']['momentum']['closed']);
    }

    #[Test]
    public function ไม่มีข้อมูลก็ต้องบอกตรงๆ_ไม่ใช่พัง(): void
    {
        $this->artisan('aibot:harvest')
            ->expectsOutputToContain('ยังไม่มีข้อมูล')
            ->assertExitCode(0);
    }
}
