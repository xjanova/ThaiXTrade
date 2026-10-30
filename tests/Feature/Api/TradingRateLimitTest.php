<?php

namespace Tests\Feature\Api;

use App\Services\WalletSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โควตาของกลุ่มที่ต้องยืนยันกระเป๋า (throttle:trading) เป็นของ "เจ้าของที่พิสูจน์แล้ว" เท่านั้น.
 *
 * ช่องโหว่ที่ปิด: เดิมตัวนับ trading นับ by(wallet_address ที่ส่งมา) ทุกคำขอ และรัน "ก่อน" VerifyWalletOwnership
 * เลขกระเป๋าเห็นได้บนเชน ใครก็ยิง 30 ครั้ง/นาทีโดยใส่เลขกระเป๋าของเหยื่อ (โดน 403 ทุกครั้ง แต่ถูกนับ)
 * เจ้าของตัวจริงโดน 429 ทั้งสั่งซื้อขาย swap ซื้อเหรียญ stake KYC ค่าธรรมเนียม — คนยิงไม่ต้องพิสูจน์อะไรเลย
 *
 * Developed by Xman Studio.
 */
class TradingRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** มีตัวอักษร a–f — ใช้ทดสอบที่อยู่แบบ checksum (ตัวใหญ่ปน) ได้ */
    private const OWNER = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const OWNER_CHECKSUM = '0xABCDEFabcdefABCDEFabcdefABCDEFabcdefABCD';

    /** IP ของเจ้าของ — และของทุกคนที่ออกเน็ตด้วย IP เดียวกัน (CGNAT ของค่ายมือถือ, Wi-Fi ร้านกาแฟ) */
    private const OWNER_IP = '203.0.113.10';

    /** คนแปลกหน้าที่อยู่คนละที่ */
    private const STRANGER_IP = '198.51.100.77';

    /** เพดานของกลุ่ม — ตัวเลขเดียวกับ AppServiceProvider::tradingLimit() */
    private const LIMIT = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** อ่านแต่ฐานข้อมูล ไม่ยิงเชน — ตอบ 200 ทั้งตอนมีและไม่มีกระเป๋า */
    private function orders(string $wallet = self::OWNER): string
    {
        return '/api/v1/trading/orders?wallet_address='.$wallet;
    }

    /** เจ้าของเซ็นผ่าน verify-signature จาก IP นี้ — แถวเดียวกับที่ WalletController::verifySignature เขียน */
    private function ownerSignedFrom(string $ip): void
    {
        Cache::put('wallet_verified:'.self::OWNER, [
            'chain_id' => 4289,
            'ip' => $ip,
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], 14400);
    }

    /** แอปของเจ้าของ — ส่งโทเคน X-Wallet-Session ทุกคำขอ ไม่ผูก IP */
    private function ownerApp(): array
    {
        return [WalletSessionService::HEADER => app(WalletSessionService::class)->issue(self::OWNER, 4289, self::OWNER_IP)['token']];
    }

    /** คีย์ของตัวนับมีชื่อ (ThrottleRequests ใช้ md5(ชื่อ + คีย์ของ Limit)) */
    private function attempts(string $key): int
    {
        return RateLimiter::attempts(md5('trading'.$key));
    }

    /** ยิงใส่เลขกระเป๋าของเจ้าของโดยไม่มีหลักฐานอะไรเลย — คืนรหัสสถานะของทุกคำขอ */
    private function spamOwnerAddressFrom(string $ip, int $times): array
    {
        $statuses = [];

        for ($i = 0; $i < $times; $i++) {
            $statuses[] = $this->fromIp($ip)->getJson($this->orders())->getStatusCode();
        }

        return $statuses;
    }

    // ── คนแปลกหน้ากินโควตาของเจ้าของไม่ได้ ─────────────────────────────

    /**
     * ⭐ ข้อที่ตรงกับช่องโหว่ที่สุด — ยิงเกินเพดานด้วยเลขกระเป๋าของเหยื่อ แล้วเจ้าของต้องยังใช้งานได้.
     */
    #[Test]
    public function คนแปลกหน้ายิงใส่เลขกระเป๋าของเหยื่อ_เจ้าของไม่โดน_429(): void
    {
        $this->ownerSignedFrom(self::OWNER_IP);

        $statuses = $this->spamOwnerAddressFrom(self::STRANGER_IP, self::LIMIT + 5);

        // คนแปลกหน้าผ่านด่านไม่ได้ และโดนเพดานของถังตัวเอง ("กระเป๋า + IP" ของเขา)
        $this->assertSame([...array_fill(0, self::LIMIT, 403), ...array_fill(0, 5, 429)], $statuses);
        $this->assertSame(self::LIMIT, $this->attempts(self::OWNER.'|'.self::STRANGER_IP));
        $this->assertSame(0, $this->attempts(self::OWNER), 'คำขอที่พิสูจน์ไม่ได้ห้ามนับในถังของเจ้าของ');

        $this->fromIp(self::OWNER_IP)->getJson($this->orders())->assertOk();
    }

    /**
     * ออกเน็ต IP เดียวกับเจ้าของก็ต้องกินโควตาของเจ้าของไม่ได้.
     *
     * นับ "กระเป๋า + IP" อย่างเดียวยังไม่พอ — คนที่อยู่หลัง IP เดียวกันจะยิงลงถังเดียวกับเจ้าของได้อีก
     */
    #[Test]
    public function คนที่ใช้_ip_เดียวกันก็กินโควตาของเจ้าของไม่ได้(): void
    {
        $app = $this->ownerApp();

        $statuses = $this->spamOwnerAddressFrom(self::OWNER_IP, self::LIMIT + 5);
        $this->assertNotContains(200, $statuses, 'ตัวควบคุม: ไม่มีโทเคนต้องผ่านด่านกระเป๋าไม่ได้');

        $this->fromIp(self::OWNER_IP)->getJson($this->orders(), $app)->assertOk();
    }

    /**
     * คนแปลกหน้าต้องไม่เห็นว่าเจ้าของใช้โควตาไปเท่าไร.
     *
     * X-RateLimit-Remaining มาจากถังที่ตัวนับเลือก — ถ้าคำขอของคนแปลกหน้าถูกเทียบกับถังของเจ้าของ
     * ใครก็เฝ้าดูได้ว่ากระเป๋านี้กำลังเปิดหน้าเทรดอยู่หรือไม่ (เลขกระเป๋าเห็นได้บนเชน)
     */
    #[Test]
    public function คนแปลกหน้าไม่เห็นโควตาที่เจ้าของใช้ไป(): void
    {
        $this->ownerSignedFrom(self::OWNER_IP);

        for ($i = 0; $i < 20; $i++) {
            $this->fromIp(self::OWNER_IP)->getJson($this->orders())->assertOk();
        }

        $this->fromIp(self::STRANGER_IP)
            ->getJson($this->orders())
            ->assertStatus(403)
            ->assertHeader('X-RateLimit-Remaining', self::LIMIT - 1);
    }

    // ── เจ้าของยังโดนเพดานต่อกระเป๋า ───────────────────────────────────

    /** เพดานยังต้องมีจริง — ไม่ใช่เปิดให้ยิงได้ไม่จำกัดเพราะกลัวคนอื่นกินโควตา */
    #[Test]
    public function เจ้าของยังโดนเพดาน_30_ครั้งต่อนาที(): void
    {
        $this->ownerSignedFrom(self::OWNER_IP);

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->fromIp(self::OWNER_IP)->getJson($this->orders())->assertOk();
        }

        $this->fromIp(self::OWNER_IP)->getJson($this->orders())->assertStatus(429);
    }

    /**
     * เพดานเป็นของกระเป๋า ไม่ใช่ของ IP — สลับ Wi-Fi/4G หรือหมุน proxy ไม่ได้โควตาใหม่.
     *
     * ถ้านับเจ้าของแบบ "กระเป๋า + IP" ข้อนี้จะแดง
     */
    #[Test]
    public function เจ้าของเปลี่ยน_ip_ไม่ได้โควตาใหม่(): void
    {
        $app = $this->ownerApp();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->fromIp(self::OWNER_IP)->getJson($this->orders(), $app)->assertOk();
        }

        $this->fromIp('192.0.2.55')->getJson($this->orders(), $app)->assertStatus(429);
    }

    /** ที่อยู่แบบ checksum (ตัวใหญ่ปน) คือกระเป๋าใบเดียวกับตัวเล็ก — ต้องนับรวมกัน ไม่ใช่ได้โควตาสองเท่า */
    #[Test]
    public function ที่อยู่แบบ_checksum_นับถังเดียวกับตัวเล็ก(): void
    {
        $this->ownerSignedFrom(self::OWNER_IP);

        $this->fromIp(self::OWNER_IP)->getJson($this->orders(self::OWNER_CHECKSUM))->assertOk();
        $this->fromIp(self::OWNER_IP)->getJson($this->orders())->assertOk();

        $this->assertSame(2, $this->attempts(self::OWNER));
    }

    /** ตัวนับหาเลขกระเป๋าตามลำดับเดียวกับด่าน — เลขใน path ก็นับในถังของเจ้าของ */
    #[Test]
    public function เลขกระเป๋าใน_path_นับถังเดียวกับ_query(): void
    {
        $this->ownerSignedFrom(self::OWNER_IP);

        $this->fromIp(self::OWNER_IP)->getJson('/api/v1/carbon-credits/my-credits/'.self::OWNER)->assertOk();
        $this->fromIp(self::OWNER_IP)->getJson($this->orders())->assertOk();

        $this->assertSame(2, $this->attempts(self::OWNER));
    }

    /**
     * path กับ query คนละใบ — ตัวนับต้องเลือกกระเป๋าใน path แบบเดียวกับด่าน.
     *
     * ถ้าตัวนับยังเลือก query ก่อน มันจะนับเข้าถังของกระเป๋าที่คนยิงพิสูจน์ได้ ขณะที่ด่านตรวจกระเป๋าใน path
     * = สองตัวตัดสินคนละใบ (กฎความเป็นเจ้าของต้องมีที่เดียว ดู VerifyWalletOwnership::proves)
     */
    #[Test]
    public function path_กับ_query_คนละใบนับถังของกระเป๋าใน_path(): void
    {
        $stranger = '0x2222222222222222222222222222222222222222';

        $this->ownerSignedFrom(self::OWNER_IP);
        Cache::put('wallet_verified:'.$stranger, [
            'chain_id' => 4289,
            'ip' => self::STRANGER_IP,
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], 14400);

        $this->fromIp(self::STRANGER_IP)
            ->getJson('/api/v1/carbon-credits/my-credits/'.self::OWNER.'?wallet_address='.$stranger)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');

        $this->assertSame(1, $this->attempts(self::OWNER.'|'.self::STRANGER_IP));
        $this->assertSame(0, $this->attempts(self::OWNER), 'คนแปลกหน้าต้องไม่กินโควตาของเจ้าของ');
        $this->assertSame(0, $this->attempts($stranger));
    }

    // ── คำขอที่ไม่ระบุกระเป๋า ──────────────────────────────────────────

    /** ไม่ระบุกระเป๋า = นับต่อ IP เหมือนเดิม (ด่านกระเป๋าปล่อยให้ controller จัดการ) */
    #[Test]
    public function คำขอที่ไม่ระบุกระเป๋ายังนับต่อ_ip(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->fromIp(self::OWNER_IP)->getJson('/api/v1/trading/orders')->assertOk();
        }

        $this->fromIp(self::OWNER_IP)->getJson('/api/v1/trading/orders')->assertStatus(429);
        $this->fromIp(self::STRANGER_IP)->getJson('/api/v1/trading/orders')->assertOk();
    }

    /** ?wallet_address[]=x ส่งมาเป็น array — ตัวนับห้าม cast ตรงๆ (จะกลายเป็น 500) ต้องได้ 422 จากด่านกระเป๋า */
    #[Test]
    public function wallet_address_เป็น_array_ไม่ทำให้ตัวนับพัง(): void
    {
        $this->fromIp(self::OWNER_IP)
            ->getJson('/api/v1/trading/orders?wallet_address[]='.self::OWNER)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_WALLET');
    }
}
