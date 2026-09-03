<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — หน้ารายงานบั๊กในหลังบ้าน อ่านจากระบบกลาง xman studio.
 *
 * Developed by Xman Studio.
 */
class BugReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function centralRow(int $id, string $product, string $title, string $type = 'bug'): array
    {
        return [
            'id' => $id,
            'product_name' => $product,
            'product_version' => '1.1.157',
            'report_type' => $type,
            'title' => $title,
            'description' => "รายละเอียดของ {$title}",
            'metadata' => [
                'breadcrumbs' => ['12:00:00 app start', '12:00:05 deeplink connect'],
                'state' => ['wallet_kind' => 'linked', 'verified' => false],
                'build' => '478',
            ],
            'device_id' => 'abc123def456',
            'os_version' => 'android 14',
            'priority' => 'medium',
            'severity' => 'major',
            'status' => 'new',
            'created_at' => "2026-09-03T0{$id}:00:00Z",
        ];
    }

    #[Test]
    public function หน้าแสดงรายงานของทุกผลิตภัณฑ์_tpix_พร้อม_breadcrumb(): void
    {
        Http::fake(function ($request) {
            $product = $request['product_name'] ?? '';

            return match ($product) {
                'tpix-trade' => Http::response(['success' => true, 'data' => [$this->centralRow(1, 'tpix-trade', 'เซ็นแล้วเด้ง', 'crash')]]),
                'tpix-wallet' => Http::response(['success' => true, 'data' => [$this->centralRow(2, 'tpix-wallet', 'ปลดล็อกซ้ำ')]]),
                default => Http::response(['success' => true, 'data' => []]),
            };
        });

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/bug-reports')->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame('Admin/BugReports/Index', $response->viewData('page')['component']);
        $this->assertCount(2, $props['reports']);
        // เรียงใหม่ก่อน (id 2 สร้างทีหลัง)
        $this->assertSame('tpix-wallet', $props['reports'][0]['product_name']);
        $this->assertSame(['12:00:00 app start', '12:00:05 deeplink connect'], $props['reports'][1]['breadcrumbs']);
        $this->assertSame('linked', $props['reports'][1]['state']['wallet_kind']);
        $this->assertArrayNotHasKey('breadcrumbs', $props['reports'][1]['metadata']);
        $this->assertSame(1, $props['summary']['crashes']);
        $this->assertSame([], $props['fetchErrors']);

        // ดึงครั้งเดียวต่อผลิตภัณฑ์ (4 ผลิตภัณฑ์) แล้วแคช — เปิดซ้ำต้องไม่ยิงเพิ่ม
        $this->actingAs($this->admin, 'admin')->get('/admin/bug-reports')->assertOk();
        Http::assertSentCount(4);
    }

    #[Test]
    public function เลือกผลิตภัณฑ์เดียวดึงเฉพาะตัวนั้น_และระบบกลางล่มต้องบอกไม่ใช่หน้าพัง(): void
    {
        Http::fake(['xman4289.com/*' => Http::response('down', 503)]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/bug-reports?product=tpix-trade')->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame('tpix-trade', $props['selected']);
        $this->assertSame([], $props['reports']);
        $this->assertSame(['tpix-trade'], $props['fetchErrors']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function คนนอกเข้าหน้านี้ไม่ได้(): void
    {
        $this->get('/admin/bug-reports')->assertRedirect();
    }
}
