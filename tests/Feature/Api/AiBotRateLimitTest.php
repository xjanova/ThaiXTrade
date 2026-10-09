<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โควตาของ AI TRADE ต้องเป็นของตัวเอง และนับคำขอละ "ครั้งเดียว".
 *
 * ⚠️ อาการเดิม: กลุ่ม /ai-bot ใช้ `throttle:30,1` แบบไม่มีชื่อ ซึ่งทุกตัวที่ไม่มีชื่อในเว็บ
 *    ใช้ตัวนับเดียวกัน (คีย์ = โดเมน|IP) — คำขอของบอทถูกนับสองรอบ (กลุ่ม + throttleApi ของทั้ง API)
 *    และนับรวมกับ API สาธารณะทุกตัวจาก IP นั้น หน้าคู่ TPIX/DEX ถามราคาถี่ๆ ก็กินโควตาหมด
 *    /status ได้ 429 → การ์ดพลิกเป็น "ยังไม่ได้เช่า" ทั้งที่ผู้ใช้เช่าอยู่
 *
 * Developed by Xman Studio.
 */
class AiBotRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x9999999999999999999999999999999999999999';

    private const OTHER = '0x9898989898989898989898989898989898989898';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        foreach ([self::WALLET, self::OTHER] as $wallet) {
            Cache::put('wallet_verified:'.$wallet, [
                'ip' => '127.0.0.1',
                'verified_at' => now()->toIso8601String(),
            ], now()->addHours(4));
        }
    }

    private function credits(string $wallet = self::WALLET): int
    {
        return $this->getJson('/api/v1/ai-bot/credits?wallet_address='.$wallet)->getStatusCode();
    }

    /** คีย์ของตัวนับมีชื่อ (ThrottleRequests ใช้ md5(ชื่อ + คีย์ของ Limit)) */
    private function namedAttempts(string $limiter, string $key): int
    {
        return RateLimiter::attempts(md5($limiter.$key));
    }

    /**
     * ⭐ ข้อที่ตรงกับบั๊กที่สุด — คำขอหนึ่งครั้ง นับหนึ่งครั้ง บนตัวนับของ ai-bot เท่านั้น.
     *
     * ตัวนับไม่มีชื่อที่ API สาธารณะใช้ร่วมกัน (sha1 ของ "โดเมน|IP") ต้องไม่ขยับเลย
     * และเพดาน 30/นาทีของกลุ่มที่ต้องยืนยันกระเป๋า (trading) ต้องไม่ซ้อนทับ
     */
    #[Test]
    public function an_ai_bot_call_is_counted_once_on_its_own_limiter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->credits());
        }

        $this->assertSame(3, $this->namedAttempts('ai-bot', self::WALLET.'|127.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts(sha1('|127.0.0.1')), 'ห้ามนับรวมกับ API สาธารณะ');
        $this->assertSame(0, $this->namedAttempts('trading', self::WALLET), 'ห้ามซ้อนเพดานของกลุ่ม trading');
    }

    /** กระเป๋าแบบ checksum (ตัวใหญ่ปน) คือใบเดียวกับตัวเล็ก — ต้องนับรวมกัน ไม่ใช่ได้โควตาสองเท่า */
    #[Test]
    public function a_checksum_cased_address_shares_the_same_counter(): void
    {
        $lower = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';
        Cache::put('wallet_verified:'.$lower, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHour());

        $this->getJson('/api/v1/ai-bot/credits?wallet_address=0xABCDEFabcdefABCDEFabcdefABCDEFabcdefABCD')->assertOk();
        $this->getJson('/api/v1/ai-bot/credits?wallet_address='.$lower)->assertOk();

        $this->assertSame(2, $this->namedAttempts('ai-bot', $lower.'|127.0.0.1'));
    }

    /** เดิมคำขอที่ 16 ก็โดนแล้ว (นับสองรอบ เพดาน 30) — ตอนนี้ใช้งานปกติเกิน 30 ครั้งต่อนาทีได้ */
    #[Test]
    public function more_than_thirty_calls_a_minute_go_through(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->assertSame(200, $this->credits(), "คำขอที่ {$i} ไม่ควรโดนจำกัด");
        }
    }

    /** API สาธารณะจาก IP เดียวกันใช้โควตาจนหมด — บอทยังต้องโหลดสถานะได้ */
    #[Test]
    public function busy_public_api_traffic_does_not_lock_the_bot_out(): void
    {
        $limited = false;

        for ($i = 0; $i < 80; $i++) {
            if ($this->getJson('/api/v1/ai-bot/catalog')->getStatusCode() === 429) {
                $limited = true;
                break;
            }
        }

        $this->assertTrue($limited, 'ตัวควบคุม: API สาธารณะต้องมีเพดานของมันเอง');

        $this->getJson('/api/v1/ai-bot/status?wallet_address='.self::WALLET)->assertOk();
    }

    /** เพดานยังต้องมีจริง — ไม่ใช่เปิดให้ยิงได้ไม่จำกัดเพราะกลัว 429 */
    #[Test]
    public function the_ai_bot_limit_still_exists(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->credits();
        }

        $this->assertSame(429, $this->credits());
    }

    /** กระเป๋าหนึ่งใช้โควตาหมด กระเป๋าอื่นต้องไม่ได้รับผลกระทบ */
    #[Test]
    public function one_wallet_cannot_use_up_another_wallets_quota(): void
    {
        for ($i = 0; $i < 61; $i++) {
            $this->credits();
        }

        $this->assertSame(429, $this->credits());
        $this->assertSame(200, $this->credits(self::OTHER));
    }

    /**
     * endpoint ที่หน้าเว็บเรียกเป็นระยะ (สั่งบอทฟรีเดิน · ไม้บนกราฟ) มีงบแยก — โควตาทั่วไปหมดก็ยังเดินได้.
     *
     * เดิม `throttle:60,1` รายเส้นทางไม่มีความหมาย เพราะใช้ตัวนับเดียวกับเพดาน 30 ของกลุ่ม
     */
    #[Test]
    public function the_polling_endpoints_have_their_own_budget(): void
    {
        $bot = AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => 'ฟรี', 'pair' => 'BTC/USDT',
            'strategy' => 'grid', 'timeframe' => '1h', 'status' => 'paused', 'mode' => 'demo',
        ]);

        for ($i = 0; $i < 61; $i++) {
            $this->credits();
        }
        $this->assertSame(429, $this->credits(), 'ตัวควบคุม: โควตาทั่วไปต้องหมดแล้ว');

        $this->getJson('/api/v1/ai-bot/trades?wallet_address='.self::WALLET.'&pair=BTC/USDT')->assertOk();

        // บอทยังไม่เปิด = 422 BOT_NOT_RUNNING (ไม่ใช่ 429) — แปลว่าผ่านด่านโควตามาแล้ว
        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => self::WALLET])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOT_NOT_RUNNING');
    }
}
