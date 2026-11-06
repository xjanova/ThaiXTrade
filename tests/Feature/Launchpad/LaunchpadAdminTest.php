<?php

namespace Tests\Feature\Launchpad;

use App\Models\AdminUser;
use App\Models\LaunchpadReport;
use App\Models\LaunchpadSale;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\Support\FakesLaunchpad;
use Tests\TestCase;

/**
 * หลังบ้าน /admin/launchpad — super_admin เท่านั้น · ลงทะเบียนอ่านจากเชน · อนุมัติกันชื่อซ้ำ · กดซ้ำได้ 409.
 * ลงชื่อเข้าด้วย session ของ guard admin (ไม่ใช้ actingAs — มันสลับ guard หลักจนเทสต์ผ่านบนโค้ดที่พังจริง).
 */
class LaunchpadAdminTest extends TestCase
{
    use FakesBridgeChains;
    use FakesLaunchpad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->setUpLaunchpad();
    }

    private function signIn(string $role = 'super_admin'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => "แอดมิน {$role}",
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    #[Test]
    public function only_a_super_admin_can_open_or_change_the_launchpad(): void
    {
        $this->get('/admin/launchpad')->assertRedirect();
        $this->postJson('/admin/launchpad/register', ['sale_address' => $this->lpSale])->assertRedirect();

        $this->signIn('admin');
        $this->get('/admin/launchpad')->assertForbidden();
        $this->postJson('/admin/launchpad/register', ['sale_address' => $this->lpSale])->assertForbidden();
        $this->assertSame(0, LaunchpadSale::count());
    }

    #[Test]
    public function a_sale_is_registered_from_chain_as_pending_then_approved(): void
    {
        $this->signIn();

        $this->postJson('/admin/launchpad/register', ['sale_address' => $this->lpSale])
            ->assertCreated()
            ->assertJsonPath('data.symbol', 'IDTC')
            ->assertJsonPath('data.status', 'pending');

        // ยังไม่อนุมัติ = หน้าเว็บไม่เห็น
        $this->get('/launchpad/IDTC')->assertNotFound();

        $this->postJson('/admin/launchpad/sales/1/status', ['action' => 'approve', 'note' => 'ตรวจแล้ว'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $sale = LaunchpadSale::first();
        $this->assertSame('แอดมิน super_admin', $sale->reviewed_by);
        $this->assertSame('ตรวจแล้ว', $sale->status_note);
        $this->get('/launchpad/IDTC')->assertOk();

        // กดซ้ำ = 409 ไม่ใช่ทำซ้ำ
        $this->postJson('/admin/launchpad/sales/1/status', ['action' => 'approve'])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $this->postJson('/admin/launchpad/sales/1/status', ['action' => 'hide'])->assertOk()->assertJsonPath('data.status', 'hidden');
        $this->get('/launchpad/IDTC')->assertNotFound();
    }

    #[Test]
    public function a_sale_not_made_by_our_factory_is_refused_with_a_code(): void
    {
        $this->signIn();

        $this->postJson('/admin/launchpad/register', ['sale_address' => '0x'.str_repeat('9', 40)])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOT_FACTORY_SALE');
        $this->postJson('/admin/launchpad/register', ['sale_address' => 'nope'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ADDRESS');

        $this->lp['token_check'] = '2';
        $this->postJson('/admin/launchpad/register', ['sale_address' => $this->lpSale])
            ->assertStatus(422)->assertJsonPath('error.code', 'RISKY_TOKEN');

        $this->assertSame(0, LaunchpadSale::count());
    }

    #[Test]
    public function approving_a_copycat_symbol_is_blocked(): void
    {
        $this->signIn();
        $this->registerSale(); // IDTC #1 อนุมัติแล้ว

        $copy = LaunchpadSale::firstOrFail()->replicate()->fill([
            'sale_address' => '0x'.str_repeat('7', 40),
            'token_address' => '0x'.str_repeat('8', 40),
            'token_symbol' => 'idtc',
            'status' => LaunchpadSale::STATUS_PENDING,
        ]);
        $copy->save();

        $this->postJson("/admin/launchpad/sales/{$copy->id}/status", ['action' => 'approve'])
            ->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_TOKEN');
        $this->assertSame(LaunchpadSale::STATUS_PENDING, $copy->fresh()->status);

        $this->postJson('/admin/launchpad/sales/999/status', ['action' => 'approve'])->assertNotFound();
        $this->postJson("/admin/launchpad/sales/{$copy->id}/status", ['action' => 'delete'])->assertUnprocessable();
    }

    #[Test]
    public function the_dashboard_lists_sales_reports_and_orders_that_need_a_person(): void
    {
        $this->registerSale();
        $this->placeOrder('100')['response']->assertCreated();
        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam', 'details' => '<script>alert(1)</script>'])->assertCreated();
        $this->signIn();

        $this->get('/admin/launchpad?tab=reports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Launchpad/Index')
            ->where('tab', 'reports')
            ->where('state.enabled', true)
            ->where('state.agent_address', $this->agentAddr)
            ->has('sales', 1)
            ->where('sales.0.orders_count', 1)
            ->where('sales.0.open_orders_count', 1)
            ->where('sales.0.open_reports', 1)
            ->where('counts.open_reports', 1)
            ->has('reports.data', 1)
            ->where('reports.data.0.details', '<script>alert(1)</script>')
            // ค่าเริ่มต้นแสดงเฉพาะที่ต้องให้คนดู — คำสั่งที่เพิ่งสร้างยังไม่เข้าข่าย
            ->where('filters.status', 'attention')
            ->has('orders.data', 0));

        $this->get('/admin/launchpad?tab=orders&status=all')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.status', 'awaiting_payment')
            ->missing('orders.data.0.signature'));
    }

    #[Test]
    public function a_report_is_closed_once(): void
    {
        $this->registerSale();
        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam'])->assertCreated();
        $this->signIn();
        $id = LaunchpadReport::firstOrFail()->id;

        $this->postJson("/admin/launchpad/reports/{$id}", ['status' => 'reviewed'])->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->postJson("/admin/launchpad/reports/{$id}", ['status' => 'dismissed'])->assertStatus(409);
        $this->postJson("/admin/launchpad/reports/{$id}", ['status' => 'open'])->assertUnprocessable();

        $this->assertSame('แอดมิน super_admin', LaunchpadReport::first()->reviewed_by);
    }
}
