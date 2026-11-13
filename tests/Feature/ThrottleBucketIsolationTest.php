<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — throttle ทุกเส้นต้องมีถังของตัวเอง ห้ามใช้ตัวนับไม่มีชื่อร่วมกับ API สาธารณะ (ออดิท 2026-11-13).
 *
 * `throttle:N,M` แบบไม่มีชื่อถัง (อาร์กิวเมนต์ที่สาม) ใช้คีย์เดียวกันทั้งระบบ:
 *   ยังไม่ล็อกอิน → sha1("โดเมน|IP")   ·   ล็อกอินแล้ว → sha1(รหัสบัญชี)
 * API สาธารณะทุกตัวนับลงคีย์นี้ผ่าน throttle:60,1 ของทั้ง API หน้าเว็บที่ถามราคาถี่ๆ จึงทำให้
 *   - ล็อกอิน/สมัครสมาชิก (5 และ 3 ครั้ง/นาที) โดน 429 ตั้งแต่กดครั้งแรก
 *   - heartbeat ของมาสเตอร์โหนด · ผูกกุญแจ validator · รายงานบั๊ก · ซื้อ/เคลมเหรียญ โดน 429 ทั้งที่ยังไม่เคยเรียก
 *   - ปุ่มหลังบ้าน (เช่น สวิตช์กองทุนพยุงราคา 10 ครั้ง/นาที) กดไม่ได้ถ้าเปิดหน้าเทรดไว้อีกแท็บ
 *   - เส้นที่นับเป็นชั่วโมง (throttle:5,60) เป็นคนเปิดหน้าต่างนับ → ตัวนับรวมค้าง 1 ชั่วโมง API ใช้ได้ 60 ครั้ง/ชั่วโมง
 *
 * Developed by Xman Studio.
 */
class ThrottleBucketIsolationTest extends TestCase
{
    private const WALLET = '0x5555555555555555555555555555555555555555';

    /** เส้นสาธารณะที่อ่านแต่ค่าตั้ง — ใช้แทน "หน้าเว็บที่ยิง API สาธารณะถี่ๆ" */
    private const PUBLIC_ENDPOINT = '/api/v1/trading-fee/tiers';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(['*' => Http::response(['result' => '0x0'])]);
    }

    private function publicCalls(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->getJson(self::PUBLIC_ENDPOINT)->assertOk();
        }
    }

    /** ตัวนับไม่มีชื่อของผู้ที่ยังไม่ล็อกอิน (ThrottleRequests: sha1 ของ "โดเมน|IP") */
    private function publicAttempts(): int
    {
        return RateLimiter::attempts(sha1('|127.0.0.1'));
    }

    /**
     * เส้นที่เพดานต่ำกว่าปริมาณ API สาธารณะของหน้าเว็บปกติ → [จำนวนคำขอสาธารณะก่อนหน้า, method, url, body].
     * คำขอไม่ต้องสำเร็จ (ส่วนใหญ่ได้ 4xx เพราะไม่ส่งข้อมูล/ลายเซ็น) — ขอแค่ไม่ใช่ 429.
     */
    public static function lowLimitRoutes(): array
    {
        return [
            'ล็อกอิน (5/นาที)' => [8, 'POST', '/login', ['email' => 'nobody@tpix.test', 'password' => 'wrong-password']],
            'สมัครสมาชิก (3/นาที)' => [5, 'POST', '/register', []],
            'heartbeat มาสเตอร์โหนด (10/นาที)' => [12, 'POST', '/api/v1/node/heartbeat', []],
            'ผูกกุญแจ validator (10/นาที)' => [12, 'POST', '/api/v1/validators/key-binding', []],
            'รายงานบั๊ก (20/นาที)' => [22, 'POST', '/api/v1/bug-reports', []],
            'CI แจ้งรุ่นใหม่ (10/นาที)' => [12, 'POST', '/api/v1/app/notify-release', []],
            'ตรวจก่อนซื้อเหรียญ (10/นาที)' => [12, 'POST', '/api/v1/token-sale/precheck', ['wallet_address' => self::WALLET]],
            'เคลมเหรียญ (10/นาที)' => [12, 'POST', '/api/v1/token-sale/claim', ['wallet_address' => self::WALLET]],
            'watchdog ของเชน (30/นาที)' => [32, 'POST', '/api/infra/heartbeat', []],
            'IoT ส่งข้อมูลเป็นชุด (30/นาที)' => [32, 'POST', '/api/v1/food-passport/iot/batch-ingest', []],
        ];
    }

    // ── 1. API สาธารณะต้องไม่กินโควตาของเส้นอื่น ─────────────────────────

    #[Test]
    #[DataProvider('lowLimitRoutes')]
    public function public_api_traffic_does_not_use_up_another_routes_limit(int $publicCalls, string $method, string $url, array $body): void
    {
        $this->publicCalls($publicCalls);

        $status = $this->json($method, $url, $body)->getStatusCode();

        $this->assertNotSame(429, $status, "{$method} {$url} โดน 429 ทั้งที่ยังไม่เคยถูกเรียกเลย");
        $this->assertSame($publicCalls + ($this->countsOnPublicBucket($url) ? 1 : 0), $this->publicAttempts(), 'คำขอหนึ่งครั้งต้องไม่ถูกนับสองรอบบนถังรวม');
    }

    /** เส้นใต้ /api/ ยังถูกนับ "หนึ่งครั้ง" บนถังรวมของทั้ง API · เส้นหน้าเว็บไม่ถูกนับเลย */
    private function countsOnPublicBucket(string $url): bool
    {
        return str_starts_with($url, '/api/');
    }

    /** เพดานของแต่ละเส้นยังต้องมีจริง — ไม่ใช่ตั้งชื่อถังแล้วยิงได้ไม่จำกัด */
    #[Test]
    public function login_is_still_limited_to_five_attempts_a_minute(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotSame(429, $this->post('/login', ['email' => 'nobody@tpix.test', 'password' => 'wrong'])->getStatusCode(), "ครั้งที่ {$i}");
        }

        $this->post('/login', ['email' => 'nobody@tpix.test', 'password' => 'wrong'])->assertStatus(429);
        $this->assertSame(0, $this->publicAttempts(), 'ล็อกอินผิดต้องไม่กินโควตา API สาธารณะ');
    }

    /** เครื่อง IoT ได้เพดาน 120/นาทีตามที่ประกาศจริง — เดิมได้แค่ 30 เพราะนับสองรอบบนถัง 60 */
    #[Test]
    public function iot_ingest_gets_the_declared_hundred_and_twenty_a_minute(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/v1/food-passport/iot/ingest', [])->getStatusCode(), "ครั้งที่ {$i}");
        }

        $this->postJson('/api/v1/food-passport/iot/ingest', [])->assertStatus(429);
        $this->getJson(self::PUBLIC_ENDPOINT)->assertOk();
    }

    // ── 2. เส้นที่นับเป็นชั่วโมงต้องไม่ทำให้ตัวนับรวมค้าง 1 ชั่วโมง ──────────

    /**
     * อาการเดิม: POST /admin/setup (throttle:5,60) เป็นคำขอแรกของหน้าต่างนับ → ตัวนับรวมอายุ 60 นาที
     * API สาธารณะจาก IP นั้นจึงใช้ได้ 60 ครั้ง "ต่อชั่วโมง" แทนต่อนาที.
     */
    #[Test]
    public function an_hourly_limited_route_does_not_stretch_the_public_window_to_an_hour(): void
    {
        $this->post('/admin/setup', []);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::PUBLIC_ENDPOINT);
        }
        $this->getJson(self::PUBLIC_ENDPOINT)->assertStatus(429);

        $this->travel(61)->seconds();

        $this->getJson(self::PUBLIC_ENDPOINT)->assertOk();
    }

    // ── 3. หลังบ้าน ─────────────────────────────────────────────────────

    /** แอดมินเปิดหน้าเทรดไว้อีกแท็บ (API สาธารณะวิ่งอยู่) แล้วกดสวิตช์กองทุนพยุงราคา — ต้องไม่โดน 429 */
    #[Test]
    public function an_admin_action_is_not_blocked_by_public_api_calls_from_the_same_browser(): void
    {
        $admin = AdminUser::create([
            'name' => 'แอดมิน',
            'email' => 'super_admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->publicCalls(12);

        $status = $this->withSession([Auth::guard('admin')->getName() => $admin->id])
            ->post('/admin/stabilizer/switch', [])
            ->getStatusCode();

        $this->assertNotSame(429, $status);
    }

    // ── 4. ด่านกันถอยหลัง ───────────────────────────────────────────────

    /**
     * throttle ไม่มีชื่อที่ยอมให้เหลือมีตัวเดียว: throttle:60,1 ของทั้ง API (bootstrap/app.php + กลุ่ม v1).
     * เพิ่ม throttle:N,M ที่ไหนก็ตามโดยไม่ใส่ชื่อถัง เทสต์นี้จะบอกชื่อเส้นที่ต้องแก้.
     */
    #[Test]
    public function every_throttle_has_its_own_bucket(): void
    {
        $router = app('router');

        $offenders = collect($router->getRoutes()->getRoutes())
            ->flatMap(fn (Route $route) => collect($router->gatherRouteMiddleware($route))
                ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, ThrottleRequests::class.':'))
                ->map(fn (string $middleware) => substr($middleware, strlen(ThrottleRequests::class) + 1))
                ->reject(fn (string $args) => $this->hasOwnBucket($args)
                    || ($args === '60,1' && ($route->uri() === 'api' || str_starts_with($route->uri(), 'api/'))))
                ->map(fn (string $args) => implode('|', $route->methods()).' '.$route->uri().' → throttle:'.$args))
            ->values()
            ->all();

        $this->assertSame([], $offenders, 'throttle แบบไม่มีชื่อถังใช้ตัวนับเดียวกับ API สาธารณะ — เติมอาร์กิวเมนต์ที่สาม เช่น throttle:10,1,my-route');
    }

    /** มีชื่อถัง (อาร์กิวเมนต์ที่สาม) หรือเป็นตัวนับที่ลงทะเบียนไว้ใน AppServiceProvider (RateLimiter::for) */
    private function hasOwnBucket(string $args): bool
    {
        $parts = explode(',', $args);

        if (count($parts) === 1) {
            return RateLimiter::limiter($parts[0]) !== null;
        }

        return trim($parts[2] ?? '') !== '';
    }
}
