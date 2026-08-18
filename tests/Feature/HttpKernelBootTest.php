<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — แอปบูตผ่านทาง HTTP ได้จริง.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ เทสต์ปกติจับข้อผิดพลาดตรงนี้ไม่ได้ — นี่คือเหตุผลที่ไฟล์นี้ต้องมี
 * ═══════════════════════════════════════════════════════════════════════════
 * ตอนรันเทสต์ Laravel สร้างแอปด้วยลำดับที่โหลด config เสร็จก่อน resolve HTTP Kernel
 * แต่ตอนรับคำขอจริง Kernel ถูก resolve ก่อน config service จะถูกผูก
 *
 * โค้ดใน `withMiddleware()` ที่เรียก `config()` จึงผ่านเทสต์ทุกข้อแต่ทำให้เว็บ
 * ล่มทั้งเว็บทันทีที่ขึ้นโปรดักชัน — เกิดจริง 2026-08-18 (500 ทุกหน้ารวม /up
 * ทั้งที่ deploy เขียวและ `config:cache` สำเร็จ)
 *
 * ไฟล์นี้จึงบูตแอปใหม่จากศูนย์แล้วส่งคำขอผ่าน Kernel เหมือน production
 * ไม่ใช้ TestCase ที่บูตไว้ให้แล้ว
 *
 * Developed by Xman Studio.
 */
class HttpKernelBootTest extends TestCase
{
    /**
     * บูตแอปใหม่แบบเดียวกับตอนรับคำขอจริง แล้วยิงคำขอเข้าไป.
     *
     * @return array{status: int, ip: string}
     */
    private function bootAndHandle(array $server = []): array
    {
        // require ใหม่ทุกครั้ง — ไม่ใช้อินสแตนซ์ที่ TestCase บูตไว้ให้
        $app = require base_path('bootstrap/app.php');
        $kernel = $app->make(Kernel::class);

        $request = Request::create('/up', 'GET', [], [], [], $server);
        $response = $kernel->handle($request);

        return ['status' => $response->getStatusCode(), 'ip' => $request->ip()];
    }

    /**
     * ⚠️ ข้อที่กันเว็บล่มทั้งเว็บ.
     *
     * แดงเมื่อไหร่แปลว่ามีโค้ดใน bootstrap/app.php เรียกของที่ยังไม่พร้อมตอนนั้น
     * (config() · Cache · DB · facade อื่นๆ) — อย่าปล่อยขึ้นโปรดักชันเด็ดขาด
     */
    #[Test]
    public function บูตผ่าน_http_kernel_ได้โดยไม่พัง(): void
    {
        $result = $this->bootAndHandle(['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertLessThan(500, $result['status'], 'บูตผ่าน HTTP ไม่สำเร็จ — ดูโค้ดใน bootstrap/app.php');
    }

    /** และการตั้งพร็อกซีต้องมีผลจริงตอนบูตแบบนี้ ไม่ใช่แค่ตอนเทสต์ */
    #[Test]
    public function อ่าน_ip_จริงได้ตั้งแต่ตอนบูตแบบ_production(): void
    {
        $result = $this->bootAndHandle([
            'REMOTE_ADDR' => '172.71.0.1',            // Cloudflare edge
            'HTTP_X_FORWARDED_FOR' => '203.0.113.45', // ผู้ใช้จริง
        ]);

        $this->assertLessThan(500, $result['status']);
        $this->assertSame('203.0.113.45', $result['ip']);
    }

    /** ปลอมจาก IP ที่ไม่ใช่พร็อกซีไม่ได้ แม้ในเส้นทางบูตจริง */
    #[Test]
    public function ปลอม_ip_จากนอกพร็อกซีไม่ได้ในเส้นทางบูตจริง(): void
    {
        $result = $this->bootAndHandle([
            'REMOTE_ADDR' => '198.51.100.99',
            'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
        ]);

        $this->assertSame('198.51.100.99', $result['ip']);
    }
}
