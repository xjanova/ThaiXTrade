<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ศูนย์เฝ้าดูบอทเทรดในหลังบ้าน.
 *
 * เจ้าของสั่งว่าทีมงานต้องเห็นบอท "ทุกตัว ทั้งบนคลาวด์และบนหน้าเว็บแบบฟรี"
 * พร้อมสั่งหยุดและระงับได้ — เทสต์ชุดนี้ยืนสองเรื่องที่พังเงียบได้ง่ายที่สุด:
 *
 * 1. บอทของแพลนฟรี (เดินในเบราว์เซอร์) ต้องไม่หายไปจากหน้านี้
 *    เพราะ scope ที่ engine ใช้กรองเฉพาะบอทคลาวด์ — เผลอใช้ซ้ำแล้วมองไม่เห็นครึ่งระบบ
 * 2. การระงับต้องปิดได้ทั้งสองเส้นทาง ไม่ใช่แค่ฝั่งคลาวด์
 *
 * Developed by Xman Studio.
 */
class AiBotAdminTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'admin');
    }

    /** บอทหนึ่งตัวพร้อมแพลนตามที่ระบุ (cloud = เดินบนคลาวด์, browser = แพลนฟรี) */
    private function makeBot(string $execution, array $overrides = []): AiBotConfig
    {
        $plan = AiBotPlan::create([
            'code' => $execution.'-plan-'.uniqid(),
            'name' => 'แพลนทดสอบ',
            'name_th' => 'แพลนทดสอบ',
            'tier' => $execution === 'cloud' ? 'vip' : 'free',
            'execution' => $execution,
            'credits_per_day' => 0,
            'price_tpix_per_day' => 0,
            'max_bots' => 5,
            'is_active' => true,
        ]);

        $sub = AiBotSubscription::create([
            'wallet_address' => '0x'.str_repeat('a', 40),
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        return AiBotConfig::create(array_merge([
            'wallet_address' => $sub->wallet_address,
            'ai_bot_subscription_id' => $sub->id,
            'name' => 'บอททดสอบ '.$execution,
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'params' => [],
            'risk' => ['max_position_usd' => 100],
            'status' => 'running',
            'mode' => 'demo',
        ], $overrides));
    }

    #[Test]
    public function หน้าเฝ้าดูต้องเห็นบอททั้งคลาวด์และแพลนฟรี(): void
    {
        $cloud = $this->makeBot('cloud');
        $browser = $this->makeBot('browser');

        $this->get('/admin/ai-bots')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/AiBots/Index')
                    ->where('summary.bots_total', 2)
                    ->where('summary.cloud', 1)
                    ->where('summary.browser', 1)
                    ->where('bots.0.id', fn ($id) => in_array($id, [$cloud->id, $browser->id], true))
            );
    }

    #[Test]
    public function สรุปเงินทุนและกำไรมาจากไม้ที่ปิดแล้วเท่านั้น(): void
    {
        $bot = $this->makeBot('cloud');

        // ไม้ที่ปิดแล้ว — ต้องถูกนับ
        AiBotTrade::create([
            'ai_bot_config_id' => $bot->id, 'wallet_address' => $bot->wallet_address,
            'pair' => 'BTC/USDT', 'mode' => 'demo', 'side' => 'sell',
            'price' => 100, 'quantity' => 1, 'gross_value' => 100, 'fee' => 0.3,
            'realized_pnl' => 12.5, 'strategy' => 'grid', 'reason' => 'ถึงเป้า',
        ]);

        // ไม้ที่ยังไม่ปิด — ยังไม่รู้ผล ห้ามนับปนกับกำไรที่เกิดจริง
        AiBotTrade::create([
            'ai_bot_config_id' => $bot->id, 'wallet_address' => $bot->wallet_address,
            'pair' => 'BTC/USDT', 'mode' => 'demo', 'side' => 'buy',
            'price' => 100, 'quantity' => 1, 'gross_value' => 100, 'fee' => 0.3,
            'realized_pnl' => null, 'strategy' => 'grid', 'reason' => 'เข้าไม้',
        ]);

        $this->get('/admin/ai-bots')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('summary.realized_pnl', 12.5)
                    ->where('summary.closed_trades', 1)
                // JSON ย่อ 100.0 เหลือ 100 — เทียบแบบ strict จึงต้องเป็น int
                    ->where('summary.win_rate', 100)
            );
    }

    #[Test]
    public function ฟีดต้องมีทั้งรอบที่ลงมือและรอบที่ตัดสินใจไม่ทำอะไร(): void
    {
        $bot = $this->makeBot('cloud');

        foreach (['buy', 'hold'] as $action) {
            AiBotDecision::create([
                'ai_bot_config_id' => $bot->id, 'wallet_address' => $bot->wallet_address,
                'strategy' => 'grid', 'pair' => 'BTC/USDT', 'timeframe' => '1h', 'mode' => 'demo',
                'action' => $action, 'reason' => 'เหตุผล '.$action, 'risk_level' => 'calm',
                'price' => 100, 'budget' => 25, 'has_position' => false,
            ]);
        }

        $this->get('/admin/ai-bots')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('summary.ticks_24h', 2)
                    ->where('summary.buys_24h', 1)
                    ->where('summary.holds_24h', 1)
                    ->has('decisions', 2)
            );
    }

    #[Test]
    public function กราฟรายชั่วโมงต้องมีครบ24ช่องเสมอ(): void
    {
        $this->makeBot('cloud');

        // ไม่มีข้อมูลเลยก็ต้องได้ 24 ช่อง ไม่งั้นช่วงที่บอทเงียบจะมองไม่ออกบนกราฟ
        $this->get('/admin/ai-bots')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('hourly', 24));
    }

    #[Test]
    public function กดหยุดแล้วบอทต้องหยุดจริง(): void
    {
        $bot = $this->makeBot('cloud');

        $this->post("/admin/ai-bots/{$bot->id}/pause")->assertRedirect();

        $this->assertSame('paused', $bot->fresh()->status);
    }

    #[Test]
    public function ระงับแล้วเจ้าของกดเริ่มเองไม่ได้(): void
    {
        $bot = $this->makeBot('cloud');

        $this->post("/admin/ai-bots/{$bot->id}/ban", ['reason' => 'ตั้งค่าเสี่ยงเกินเพดาน'])
            ->assertRedirect();

        $bot->refresh();
        $this->assertNotNull($bot->banned_at);
        $this->assertSame('ตั้งค่าเสี่ยงเกินเพดาน', $bot->banned_reason);
        $this->assertSame($this->admin->email, $bot->banned_by);

        // engine ฝั่งคลาวด์ต้องไม่หยิบไปรันอีก
        $this->assertFalse(AiBotConfig::runnable()->where('id', $bot->id)->exists());
    }

    #[Test]
    public function ระงับต้องระบุเหตุผล(): void
    {
        $bot = $this->makeBot('cloud');

        $this->post("/admin/ai-bots/{$bot->id}/ban", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($bot->fresh()->banned_at);
    }

    /**
     * กับดักที่ต้องกันไว้: ปุ่ม "เดินต่อ" ต้องไม่กลายเป็นทางลัดข้ามการระงับ
     * ไม่งั้นทีมงานที่ไม่รู้เรื่องกดเดินต่อทีเดียว บอทที่ถูกระงับก็กลับมาเดินเฉยๆ.
     */
    #[Test]
    public function เดินต่อไม่ได้ถ้ายังถูกระงับอยู่(): void
    {
        $bot = $this->makeBot('cloud', ['status' => 'stopped', 'banned_at' => now(), 'banned_reason' => 'ทดสอบ']);

        $this->post("/admin/ai-bots/{$bot->id}/resume")->assertRedirect();

        $this->assertSame('stopped', $bot->fresh()->status);
    }

    #[Test]
    public function ปลดระงับแล้วคืนเป็นสถานะหยุดไม่ใช่เดินทันที(): void
    {
        $bot = $this->makeBot('cloud', ['status' => 'stopped', 'banned_at' => now(), 'banned_reason' => 'ทดสอบ']);

        $this->post("/admin/ai-bots/{$bot->id}/unban")->assertRedirect();

        $bot->refresh();
        $this->assertNull($bot->banned_at);
        $this->assertSame('paused', $bot->status);
    }

    #[Test]
    public function คนที่ไม่ได้ล็อกอินหลังบ้านเข้าไม่ได้(): void
    {
        $bot = $this->makeBot('cloud');

        auth('admin')->logout();

        $this->get('/admin/ai-bots')->assertRedirect('/admin/login');
        $this->post("/admin/ai-bots/{$bot->id}/ban", ['reason' => 'x'])->assertRedirect('/admin/login');

        $this->assertNull($bot->fresh()->banned_at);
    }
}
