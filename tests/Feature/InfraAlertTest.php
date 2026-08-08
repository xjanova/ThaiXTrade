<?php

namespace Tests\Feature;

use App\Models\InfraHeartbeat;
use App\Models\SystemAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ระบบคาดแดงหลังบ้าน — รับ heartbeat/alert จาก watchdog เซิร์ฟเวอร์เชน
 *
 * เน้นสามเรื่อง:
 *   1. ประตูต้องล็อกจริง — ไม่มี token / token ผิด / ยังไม่ตั้ง token ต้องเข้าไม่ได้
 *   2. เหตุซ้ำต้องรวมแถว ไม่ใช่ท่วมตาราง (watchdog ยิงทุกนาที)
 *   3. วงจรชีวิตเหตุ: ยก → heartbeat กลับมา → ปิดอัตโนมัติ / เงียบนาน → ยกเอง
 */
class InfraAlertTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-infra-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.infra_alerts.token' => $this->token]);
    }

    private function authed(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    // =========================================================================
    // ประตูทางเข้า
    // =========================================================================

    public function test_heartbeat_rejects_missing_or_wrong_token(): void
    {
        $this->postJson('/api/infra/heartbeat', ['node' => 'chain-1'])
            ->assertStatus(401);

        $this->postJson('/api/infra/heartbeat', ['node' => 'chain-1'], ['Authorization' => 'Bearer wrong-token'])
            ->assertStatus(401);
    }

    public function test_endpoints_are_disabled_when_token_not_configured(): void
    {
        config(['services.infra_alerts.token' => '']);

        // default deny — ยังไม่ตั้ง token = ปิดระบบ ไม่ใช่เปิดโล่ง
        $this->postJson('/api/infra/heartbeat', ['node' => 'chain-1'], $this->authed())
            ->assertStatus(503);
    }

    public function test_alert_validates_payload(): void
    {
        $this->postJson('/api/infra/alert', [
            'node' => 'chain-1',
            'key' => 'INVALID KEY!',
            'severity' => 'critical',
            'message' => 'x',
        ], $this->authed())->assertStatus(422);

        $this->postJson('/api/infra/alert', [
            'node' => 'chain-1',
            'key' => 'chain_stalled',
            'severity' => 'catastrophic',
            'message' => 'x',
        ], $this->authed())->assertStatus(422);
    }

    // =========================================================================
    // Heartbeat
    // =========================================================================

    public function test_heartbeat_records_node_and_block(): void
    {
        $this->postJson('/api/infra/heartbeat', ['node' => 'chain-1', 'block' => 13696], $this->authed())
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('infra_heartbeats', ['node' => 'chain-1', 'last_block' => 13696]);

        // ยิงซ้ำ = อัปเดตแถวเดิม ไม่สร้างใหม่
        $this->postJson('/api/infra/heartbeat', ['node' => 'chain-1', 'block' => 13700], $this->authed())
            ->assertOk();

        $this->assertSame(1, InfraHeartbeat::count());
        $this->assertSame(13700, InfraHeartbeat::first()->last_block);
    }

    public function test_heartbeat_auto_resolves_chain_alerts(): void
    {
        SystemAlert::raise('chain-1', 'chain_stalled', 'critical', 'เชนสะดุด');
        SystemAlert::raise('chain-1', 'chain_restarted', 'warning', 'กู้แล้ว');
        SystemAlert::raise('chain-2', 'chain_stalled', 'critical', 'อีกเครื่อง');

        $this->postJson('/api/infra/heartbeat', ['node' => 'chain-1', 'block' => 100], $this->authed())
            ->assertOk();

        // chain_stalled ของ chain-1 ปิดอัตโนมัติ
        $this->assertSame('resolved', SystemAlert::where('node', 'chain-1')->where('alert_key', 'chain_stalled')->first()->status);
        // chain_restarted (warning) ค้างไว้ให้แอดมินกดรับทราบเอง
        $this->assertSame('active', SystemAlert::where('alert_key', 'chain_restarted')->first()->status);
        // เหตุของเครื่องอื่นต้องไม่ถูกปิดข้ามเครื่อง
        $this->assertSame('active', SystemAlert::where('node', 'chain-2')->first()->status);
    }

    // =========================================================================
    // Alert lifecycle
    // =========================================================================

    public function test_duplicate_alert_merges_into_one_row(): void
    {
        $this->postJson('/api/infra/alert', [
            'node' => 'chain-1',
            'key' => 'chain_stalled',
            'severity' => 'critical',
            'message' => 'ครั้งแรก',
        ], $this->authed())->assertOk();

        $this->postJson('/api/infra/alert', [
            'node' => 'chain-1',
            'key' => 'chain_stalled',
            'severity' => 'critical',
            'message' => 'ครั้งสอง',
        ], $this->authed())->assertOk()->assertJson(['occurrences' => 2]);

        $this->assertSame(1, SystemAlert::count());
        $alert = SystemAlert::first();
        $this->assertSame('ครั้งสอง', $alert->message);
        $this->assertSame(2, $alert->occurrences);
        $this->assertSame('active', $alert->status);
    }

    public function test_warning_escalates_to_critical_without_new_row(): void
    {
        SystemAlert::raise('chain-1', 'disk_low', 'warning', 'ดิสก์เหลือน้อย');
        SystemAlert::raise('chain-1', 'disk_low', 'critical', 'ดิสก์เต็มแล้ว');

        $this->assertSame(1, SystemAlert::count());
        $this->assertSame('critical', SystemAlert::first()->severity);

        // ทางกลับ (critical → warning) ต้องไม่ลดระดับเอง
        SystemAlert::raise('chain-1', 'disk_low', 'warning', 'อัปเดต');
        $this->assertSame('critical', SystemAlert::first()->severity);
    }

    public function test_stale_heartbeat_raises_critical_alert(): void
    {
        InfraHeartbeat::create(['node' => 'chain-1', 'last_block' => 500, 'last_seen_at' => now()->subMinutes(10)]);
        InfraHeartbeat::create(['node' => 'chain-2', 'last_block' => 600, 'last_seen_at' => now()->subSeconds(30)]);

        $this->artisan('infra:check-heartbeats')->assertSuccessful();

        // เครื่องเงียบ 10 นาที → คาดแดง; เครื่องปกติ → เงียบ
        $this->assertSame('active', SystemAlert::where('node', 'chain-1')->where('alert_key', 'heartbeat_missing')->first()?->status);
        $this->assertNull(SystemAlert::where('node', 'chain-2')->first());

        // รันซ้ำต้องรวมแถวเดิม ไม่งอกใหม่ทุกนาที
        $this->artisan('infra:check-heartbeats')->assertSuccessful();
        $this->assertSame(1, SystemAlert::where('node', 'chain-1')->count());
    }

    // =========================================================================
    // ฝั่ง admin
    // =========================================================================

    public function test_admin_endpoints_require_admin_login(): void
    {
        $this->get('/admin/system-alerts/active')->assertRedirect();

        $alert = SystemAlert::raise('chain-1', 'chain_stalled', 'critical', 'ทดสอบ');
        $this->post("/admin/system-alerts/{$alert->id}/resolve")->assertRedirect();
        $this->assertSame('active', $alert->fresh()->status);
    }
}
