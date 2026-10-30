<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\AdminAuth;
use App\Models\AdminUser;
use App\Models\AiAnalysis;
use App\Models\AiNews;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\ValidatorApplication;
use App\Services\AiTextService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — $request->user() บนเส้นทางหลังบ้านต้องได้แอดมินที่ล็อกอินอยู่.
 *
 * AdminAuth เดิมตรวจแค่ guard admin แล้วปล่อยผ่าน guard ปริยายจึงยังเป็น web (สมาชิก)
 * หน้าอนุมัติ validator ล้ม 500 และ reviewed_by / approved_by / requested_by / created_by
 * เก็บค่าว่าง หรือเก็บรหัสสมาชิกถ้าล็อกอินทั้งสองฝั่งในเบราว์เซอร์เดียวกัน
 *
 * ⚠️ ล็อกอินผ่าน session แบบเบราว์เซอร์จริงเท่านั้น ห้ามใช้ actingAs($admin, 'admin')
 *    เพราะมันสลับ guard ปริยายให้ก่อนคำขอจะเริ่ม เทสต์จะผ่านแม้ AdminAuth ไม่สลับเอง
 *
 * Developed by Xman Studio.
 */
class AdminRequestUserGuardTest extends TestCase
{
    private const WALLET = '0x2112b98e3ec5a252b7b2a8f02d498b64a2186a7f';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // หน้า validator อ่านชุด validator จากเชน — ไม่ให้ออกเน็ตจริง
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]));
    }

    private function admin(string $role = 'super_admin', bool $active = true): AdminUser
    {
        return AdminUser::create([
            'name' => "แอดมิน {$role}",
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function signIn(AdminUser $admin): AdminUser
    {
        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        // ด่านกันเทสต์ผ่านหลอก — ต้องเริ่มจาก web เหมือนคำขอจริง ไม่ใช่ถูกสลับมาก่อนแล้ว
        $this->assertSame('web', Auth::getDefaultDriver());

        return $admin;
    }

    private function signInMember(): User
    {
        $member = User::create([
            'wallet_address' => self::WALLET,
            'email' => 'member@tpix.test',
            'password' => 'secret-password',
        ]);
        $this->withSession([Auth::guard('web')->getName() => $member->id]);

        return $member;
    }

    private function application(): ValidatorApplication
    {
        return ValidatorApplication::create([
            'wallet_address' => self::WALLET,
            'tier' => 'validator',
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'latitude' => 13.7563,
            'longitude' => 100.5018,
        ]);
    }

    #[Test]
    public function อนุมัติใบสมัคร_validator_สำเร็จและระบุแอดมิน(): void
    {
        $admin = $this->signIn($this->admin());
        $application = $this->application();

        $this->postJson("/admin/validators/applications/{$application->id}/approve", ['admin_notes' => 'ผ่าน'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $application->refresh();
        $this->assertSame('approved', $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
    }

    #[Test]
    public function ปฏิเสธใบสมัคร_validator_สำเร็จและระบุแอดมิน(): void
    {
        $admin = $this->signIn($this->admin());
        $application = $this->application();

        $this->postJson("/admin/validators/applications/{$application->id}/reject", ['admin_notes' => 'ข้อมูลไม่ครบ'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $application->refresh();
        $this->assertSame('rejected', $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
    }

    #[Test]
    public function บันทึกความตั้งใจโหวต_validator_ระบุแอดมินที่เสนอ(): void
    {
        $admin = $this->signIn($this->admin());

        $this->postJson('/admin/validators/propose-vote', ['address' => self::WALLET, 'action' => 'remove'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $vote = json_decode((string) SiteSetting::get('validators', 'pending_vote_'.self::WALLET), true);

        $this->assertSame($admin->id, $vote['proposed_by']);
    }

    #[Test]
    public function งานจาก_ai_ระบุแอดมินที่สั่ง(): void
    {
        $this->mock(AiTextService::class, function (MockInterface $mock) {
            $reply = ['success' => true, 'content' => 'เนื้อหา', 'model' => 'test-model', 'tokens_used' => 1, 'processing_time_ms' => 1];
            $mock->shouldReceive('analyzeMarket')->andReturn($reply);
            $mock->shouldReceive('generateNews')->andReturn($reply);
        });
        $admin = $this->signIn($this->admin());

        $this->post('/admin/ai/analyze', ['symbol' => 'TPIX', 'type' => 'technical'])->assertRedirect();
        $this->post('/admin/ai/news/generate', ['topic' => 'TPIX', 'category' => 'analysis'])->assertRedirect();

        $this->assertSame($admin->id, AiAnalysis::sole()->created_by);
        $this->assertSame($admin->id, AiNews::sole()->created_by);
    }

    /**
     * รหัสสมาชิก #1 ตรงกับแอดมิน #1 ที่ไม่ได้ทำ — ผ่าน foreign key ได้เงียบๆ แล้วลงชื่อผิดคน
     * ต้องได้แอดมิน #2 ที่กดจริง.
     */
    #[Test]
    public function สมาชิกล็อกอินอยู่ในเบราว์เซอร์เดียวกันก็ยังบันทึกแอดมินที่กด(): void
    {
        $bystander = $this->admin('moderator');
        $member = $this->signInMember();
        $admin = $this->signIn($this->admin());
        $application = $this->application();

        $this->assertSame($bystander->id, $member->id);

        $this->postJson("/admin/validators/applications/{$application->id}/approve")->assertOk();

        $this->assertSame($admin->id, $application->fresh()->reviewed_by);
    }

    /**
     * HandleInertiaRequests รันก่อน AdminAuth — auth.user จึงยังเป็นสมาชิกเหมือนเดิม
     * ส่วน kyc คำนวณตอน render ซึ่งอยู่หลัง AdminAuth จึงได้ AdminUser และต้องไม่ล้ม.
     */
    #[Test]
    public function หน้าหลังบ้านเปิดได้และข้อมูลร่วมของ_inertia_ไม่เปลี่ยน(): void
    {
        $this->admin('moderator');
        $member = $this->signInMember();
        $this->signIn($this->admin());

        $this->get('/admin/trading-fees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.id', $member->id)
                ->where('kyc.approved_level', null));
    }

    #[Test]
    public function คนที่ไม่ผ่าน_admin_auth_ไม่ทำให้_guard_ปริยายเปลี่ยน(): void
    {
        $application = $this->application();

        $this->postJson("/admin/validators/applications/{$application->id}/approve")->assertRedirect();
        $this->assertSame('web', Auth::getDefaultDriver());

        $this->signInMember();
        $this->postJson("/admin/validators/applications/{$application->id}/approve")->assertRedirect();
        $this->assertSame('web', Auth::getDefaultDriver());

        $this->signIn($this->admin(active: false));
        $this->postJson("/admin/validators/applications/{$application->id}/approve")->assertRedirect();
        $this->assertSame('web', Auth::getDefaultDriver());

        $this->assertSame('pending', $application->fresh()->status);
    }

    /**
     * ThrottleRequests ใช้รหัสจาก $request->user() เป็นคีย์ และคีย์ไม่ได้แยกว่ามาจากตารางไหน
     * ถ้ามันรันหลัง AdminAuth แอดมิน #1 จะใช้โควตาก้อนเดียวกับสมาชิก #1
     * สมาชิกที่เรียก API รัวๆ ทำให้แอดมินโดน 429 ตอนกดอนุมัติได้.
     */
    #[Test]
    public function ตัวจำกัดอัตราของหลังบ้านรันก่อน_admin_auth_ทุกเส้นทาง(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = $this->app['router']->gatherRouteMiddleware($route);
            $adminAuth = array_search(AdminAuth::class, $middleware, true);

            if ($adminAuth === false) {
                continue;
            }

            foreach ($middleware as $position => $name) {
                if (is_string($name) && str_starts_with($name, ThrottleRequests::class)) {
                    $this->assertLessThan($adminAuth, $position, $route->uri());
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }
}
