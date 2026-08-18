<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — อ่าน IP จริงของผู้ใช้จากหลัง Cloudflare.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ พลาดตรงนี้แล้วทั้งเว็บใช้โควตา rate limit ถังเดียวกัน
 * ═══════════════════════════════════════════════════════════════════════════
 * ไม่เชื่อพร็อกซี = $request->ip() คืน IP ของ Cloudflare เหมือนกันหมดทุกคน
 * `throttle:5,1` ที่หน้าล็อกอินจึงกลายเป็น "ทั้งเว็บล็อกอินได้ 5 ครั้งต่อนาที"
 * ผู้ใช้เจอ 429 ทั้งที่เพิ่งกดครั้งแรก — เกิดจริงบนโปรดักชัน 2026-08-18
 *
 * และการแบน IP ใช้ไม่ได้เลย เพราะทุกคนเป็น IP เดียวกันในสายตาแอป
 *
 * ⚠️ อีกด้าน: เชื่อ '*' ทั้งที่ origin เปิดรับตรง = ใครก็ปลอม X-Forwarded-For
 *    หลบ rate limit และหลบแบนได้ทันที ชุดนี้จึงคุมทั้งสองด้าน
 *
 * Developed by Xman Studio.
 */
class TrustedProxyTest extends TestCase
{
    /** IP ของ Cloudflare edge (อยู่ในช่วง 172.64.0.0/13 ที่ประกาศไว้) */
    private const CF_EDGE = '172.71.0.1';

    private const REAL_USER = '203.0.113.45';

    private function requestThrough(string $proxyIp, string $clientIp): Request
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $proxyIp,
            'HTTP_X_FORWARDED_FOR' => $clientIp,
        ]);

        // ให้ผ่าน middleware จริง ไม่ใช่ตั้ง trusted proxies เองในเทสต์
        app(TrustProxies::class)->handle($request, fn ($r) => response('ok'));

        return $request;
    }

    #[Test]
    public function เห็น_ip_จริงของผู้ใช้เมื่อมาผ่าน_cloudflare(): void
    {
        $request = $this->requestThrough(self::CF_EDGE, self::REAL_USER);

        $this->assertSame(self::REAL_USER, $request->ip());
    }

    /**
     * ⚠️ ข้อที่กันการหลบ rate limit และหลบแบน.
     *
     * คนที่ยิงเข้า origin ตรงๆ (ไม่ผ่าน Cloudflare) แล้วปลอม X-Forwarded-For
     * ต้องถูกนับด้วย IP จริงของตัวเอง ไม่ใช่ IP ที่เขาปลอมมา
     */
    #[Test]
    public function ปลอม_x_forwarded_for_จาก_ip_ที่ไม่ใช่พร็อกซีไม่ได้(): void
    {
        $attacker = '198.51.100.99';

        $request = $this->requestThrough($attacker, 'ปลอมเป็นคนอื่น');

        $this->assertSame($attacker, $request->ip());
    }

    #[Test]
    public function คำขอที่ไม่ผ่านพร็อกซีเลยยังอ่าน_ip_ได้ถูก(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => self::REAL_USER]);
        app(TrustProxies::class)->handle($request, fn ($r) => response('ok'));

        $this->assertSame(self::REAL_USER, $request->ip());
    }

    /** ช่วง IP ของ Cloudflare ต้องมีครบทั้ง v4 และ v6 ไม่ใช่ตกหล่นครึ่งเดียว */
    #[Test]
    public function รายการพร็อกซีครอบคลุมทั้ง_ipv4_และ_ipv6(): void
    {
        $proxies = config('trustedproxy.proxies');

        $this->assertIsArray($proxies);
        $this->assertNotEmpty(array_filter($proxies, fn ($p) => str_contains($p, '.')), 'ขาดช่วง IPv4');
        $this->assertNotEmpty(array_filter($proxies, fn ($p) => str_contains($p, ':')), 'ขาดช่วง IPv6');
    }

    /**
     * X-Forwarded-Proto ต้องถูกอ่านด้วย.
     *
     * Cloudflare คุยกับ origin ด้วย http ได้ — ถ้าไม่อ่าน header นี้ Laravel จะคิดว่า
     * ทั้งเว็บเป็น http แล้วสร้างลิงก์/redirect เป็น http ทั้งหมด กลายเป็น
     * mixed content และลูปเปลี่ยนเส้นทางบนเบราว์เซอร์ที่บังคับ https
     */
    #[Test]
    public function อ่าน_https_จากพร็อกซีได้(): void
    {
        $request = Request::create('http://tpix.online/', 'GET', [], [], [], [
            'REMOTE_ADDR' => self::CF_EDGE,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        app(TrustProxies::class)->handle($request, fn ($r) => response('ok'));

        $this->assertTrue($request->isSecure());
    }
}
