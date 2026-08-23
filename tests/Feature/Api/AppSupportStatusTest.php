<?php

namespace Tests\Feature\Api;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TPIX TRADE — ด่านเวอร์ชันขั้นต่ำของแอป.
 *
 * GET /api/v1/app/support-status?product=&version=
 *
 * ใช้บังคับให้ผู้ใช้ย้ายไปรุ่นใหม่ — แอปที่ได้ supported=false ต้องขึ้นจอปิดกั้น
 * แล้วพาไปโหลดใหม่ ไม่ปล่อยให้ใช้งานต่อ
 *
 * Developed by Xman Studio.
 */
class AppSupportStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * ค่าเริ่มต้นต้องปล่อยผ่านทุกรุ่น — ตั้งใจให้เป็นแบบนี้
     * ถ้าเผลอตั้งค่าเริ่มต้นเป็นตัวเลขจริง จะตัดผู้ใช้ทิ้งทันทีที่ deploy โดยไม่มีใครสั่ง
     */
    public function test_everything_is_supported_until_a_minimum_is_set(): void
    {
        $this->getJson('/api/v1/app/support-status?product=trade&version=0.0.1')
            ->assertOk()
            ->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.min_version', '0.0.0')
            ->assertJsonPath('data.download_url', null);
    }

    public function test_blocks_versions_below_the_minimum(): void
    {
        SiteSetting::set('app_release', 'min_supported_trade', '2.0.0');

        $response = $this->getJson('/api/v1/app/support-status?product=trade&version=1.9.9');

        $response->assertOk();
        $response->assertJsonPath('data.supported', false);
        $response->assertJsonPath('data.min_version', '2.0.0');
        // ต้องบอกทางไปต่อด้วย ไม่ใช่ปิดประตูเฉย ๆ
        $this->assertStringContainsString('/download', $response->json('data.download_url'));
        $this->assertNotEmpty($response->json('data.message'));
    }

    public function test_allows_the_exact_minimum_version(): void
    {
        SiteSetting::set('app_release', 'min_supported_trade', '2.0.0');

        $this->getJson('/api/v1/app/support-status?product=trade&version=2.0.0')
            ->assertOk()
            ->assertJsonPath('data.supported', true);
    }

    /** แต่ละแอปตั้งขั้นต่ำแยกกันได้ ไม่ใช่ตั้งทีเดียวโดนหมด */
    public function test_each_product_has_its_own_minimum(): void
    {
        SiteSetting::set('app_release', 'min_supported_masternode', '2.0.0');

        $this->getJson('/api/v1/app/support-status?product=masternode&version=1.13.13')
            ->assertOk()
            ->assertJsonPath('data.supported', false);

        // วอลเล็ตยังไม่ได้ตั้ง ต้องไม่โดนหางเลข
        $this->getJson('/api/v1/app/support-status?product=wallet&version=1.13.13')
            ->assertOk()
            ->assertJsonPath('data.supported', true);
    }

    /** product แปลก ๆ ต้องไม่ทำให้พัง — ถือเป็น trade ตามค่าเริ่มต้น */
    public function test_unknown_product_falls_back_to_trade(): void
    {
        SiteSetting::set('app_release', 'min_supported_trade', '2.0.0');

        $this->getJson('/api/v1/app/support-status?product=%3Cscript%3E&version=1.0.0')
            ->assertOk()
            ->assertJsonPath('data.supported', false);
    }

    /** ไม่ส่งเวอร์ชันมา = ถือว่าเก่าสุด ต้องโดนกั้นเมื่อมีการตั้งขั้นต่ำไว้ */
    public function test_missing_version_is_treated_as_oldest(): void
    {
        SiteSetting::set('app_release', 'min_supported_trade', '1.0.0');

        $this->getJson('/api/v1/app/support-status?product=trade')
            ->assertOk()
            ->assertJsonPath('data.supported', false);
    }
}
