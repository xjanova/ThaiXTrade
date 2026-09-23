<?php

namespace Tests\Feature;

use App\Console\Commands\AiBotHarvest;
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

    /**
     * ⭐ แยกผลรายบอทได้ และเทียบคู่ทดลอง ai_gate เปิด/ปิด ในคำสั่งเดียว.
     *
     * ออดิท R3: พอร์ตทดลองผูกกับ (กระเป๋า, กลยุทธ์) บอทคู่ +AI/−AI จึงรวมอยู่แถวเดียวกัน
     * ในตารางรายกลยุทธ์ — ต้องดึงข้อมูลดิบออกไปแยกเองนอกระบบถึงจะตอบได้ว่า AI ช่วยไหม
     */
    #[Test]
    public function มันแยกผลรายบอทและเทียบคู่ทดลอง_ai_gate(): void
    {
        $old = $this->seedHarvestData();   // บอทกองเก่า — ต้องถูกตัดออกด้วย --from-bot

        $bot = fn (bool $gate) => AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => 'ab', 'pair' => 'BTC/USDT',
            'strategy' => 'dca', 'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
            'params' => ['ai_gate' => $gate], 'risk' => ['max_position_usd' => 100],
        ]);
        $trade = fn (AiBotConfig $b, string $side, ?float $pnl) => AiBotTrade::create([
            'ai_bot_config_id' => $b->id, 'wallet_address' => self::WALLET, 'pair' => 'BTC/USDT',
            'mode' => 'demo', 'side' => $side, 'price' => 100, 'quantity' => 0.25, 'gross_value' => 24.975,
            'fee' => 0.025, 'slippage_cost' => 0.02, 'realized_pnl' => $pnl, 'strategy' => 'dca',
            'reason' => 'ทดสอบ', 'risk_level' => 'calm',
        ]);

        $on = $bot(true);
        $off = $bot(false);
        $trade($on, 'buy', null);
        $trade($on, 'sell', 1.25);
        $trade($off, 'buy', null);
        $trade($off, 'sell', 2.00);

        $this->assertSame(0, Artisan::call('aibot:harvest', ['--by-bot' => true, '--from-bot' => $on->id]));
        $output = Artisan::output();

        $this->assertStringContainsString("#{$on->id}", $output);
        $this->assertStringContainsString('+AI', $output);
        $this->assertStringContainsString('−AI', $output);
        $this->assertStringContainsString('-0.75', $output, 'AI ทำให้เสีย 0.75 เทียบกับกลุ่มควบคุม');
        $this->assertStringNotContainsString("#{$old->id} ", $output, 'บอทกองเก่าต้องไม่ปน');
    }

    /** รอบ DCA หลายไม้ = รอบเดียว — edge คิดจากเงินที่ลงทั้งรอบ ไม่ใช่เฉลี่ยรายไม้ */
    #[Test]
    public function รอบหลายไม้ถูกรวมเป็นรอบเดียวและ_edge_ถ่วงด้วยเงิน(): void
    {
        $buy = ['side' => 'buy', 'pair' => 'BTC/USDT', 'gross_value' => 24.975, 'fee' => 0.025, 'slippage_cost' => 0.02, 'realized_pnl' => null];

        $r = AiBotHarvest::summariseRounds([
            $buy, $buy, $buy, $buy,
            ['side' => 'sell', 'pair' => 'BTC/USDT', 'gross_value' => 110.2, 'fee' => 0.11, 'slippage_cost' => 0.09, 'realized_pnl' => 10.09],
            $buy,   // รอบใหม่ที่ยังไม่ปิด
        ]);

        $this->assertSame(1, $r['closed']);
        $this->assertSame(1, $r['wins']);
        $this->assertSame(10.09, $r['realized']);
        // ต้นทุนของรอบที่ปิด = ขาซื้อ 4 × 0.045 + ขาขาย 0.2 = 0.38 · ลงเงิน 4 × 25 = 100
        $this->assertSame(0.38, $r['costs']);
        $this->assertEqualsWithDelta(1047.0, $r['edge_bps'], 0.1);
        $this->assertSame(25.0, $r['open_cost'], 'ไม้ของรอบที่ยังไม่ปิดต้องไม่ถูกนับใน edge');
    }
}
