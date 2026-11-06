<?php

namespace Tests\Feature\Launchpad;

use App\Models\LaunchpadOrder;
use App\Models\LaunchpadReport;
use App\Models\LaunchpadSale;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\Support\FakesLaunchpad;
use Tests\TestCase;

/**
 * หน้าเว็บ Launchpad — /launchpad · /launchpad/{symbol} · ป้ายความเสี่ยงจากเชน · ปุ่มแจ้งเหรียญ.
 *
 * หลักที่ห้ามพัง:
 *  - หน้าขายที่ยังไม่อนุมัติ/ถูกซ่อน = 404 (ไม่ให้ใครส่งลิงก์หน้าขายที่แอดมินยังไม่ดูไปหลอกคน)
 *  - ป้ายมาจากเชนเท่านั้น และ "อ่านไม่ได้" ต้องเป็น unknown ไม่ใช่ ok
 *  - รายงานไม่ซ่อนหน้าขายเอง และกดซ้ำจาก IP เดิมไม่สร้างแถวใหม่
 */
class LaunchpadPagesTest extends TestCase
{
    use FakesBridgeChains;
    use FakesLaunchpad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->setUpLaunchpad();
        $this->registerSale();
    }

    private function badges(array $data): array
    {
        return collect($data['badges'])->mapWithKeys(fn ($b) => [$b['key'] => $b])->all();
    }

    #[Test]
    public function the_list_page_shows_only_approved_sales(): void
    {
        LaunchpadSale::firstOrFail()->replicate()->fill([
            'sale_address' => '0x'.str_repeat('7', 40), 'token_symbol' => 'FAKE', 'status' => LaunchpadSale::STATUS_PENDING,
        ])->save();

        $this->get('/launchpad')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Launchpad/Index')
            ->has('sales', 1)
            ->where('sales.0.token.symbol', 'IDTC')
            ->where('launchpad.enabled', true)
            ->where('launchpad.chains.0.chain_id', 56)
            ->where('launchpad.chains.0.usdt_address', strtolower($this->usdtBsc))
            ->where('launchpad.chains.0.usdt_decimals', 18));
    }

    #[Test]
    public function the_sale_page_is_found_by_symbol_in_any_case(): void
    {
        $this->get('/launchpad/idtc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Launchpad/Show')
            ->where('sale.token.symbol', 'IDTC')
            ->where('sale.price_usdt', '0.0000015')
            ->where('launchpad.order_ttl_minutes', 30));
    }

    #[Test]
    public function a_sale_that_is_not_approved_is_not_found(): void
    {
        foreach ([LaunchpadSale::STATUS_PENDING, LaunchpadSale::STATUS_HIDDEN, LaunchpadSale::STATUS_REJECTED] as $status) {
            LaunchpadSale::query()->update(['status' => $status]);
            $this->get('/launchpad/IDTC')->assertNotFound();
            $this->getJson('/api/v1/launchpad/sales/1/risk')->assertNotFound();
            $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam'])->assertNotFound();
        }

        $this->get('/launchpad/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/launchpad/'.rawurlencode('<script>'))->assertNotFound();
    }

    #[Test]
    public function the_page_says_when_the_launchpad_is_switched_off(): void
    {
        config(['launchpad.enabled' => false]);

        $this->get('/launchpad/IDTC')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('launchpad.enabled', false)
            ->where('launchpad.chains.0.enabled', false));
    }

    #[Test]
    public function a_clean_factory_token_gets_green_badges_read_from_chain(): void
    {
        $data = $this->getJson('/api/v1/launchpad/sales/1/risk')->assertOk()->json('data');
        $b = $this->badges($data);

        $this->assertTrue($data['available']);
        foreach (['factory', 'mint', 'pause', 'blacklist', 'autoburn', 'lp_lock', 'pool_share', 'creator_free', 'locked_supply'] as $key) {
            $this->assertSame('ok', $b[$key]['level'], $key);
        }
        $this->assertSame('info', $b['lp_held']['level']); // ยังไม่มีพูล
        $this->assertSame('100.00', $b['pool_share']['params']['pct']);
        $this->assertSame('4.00', $b['creator_free']['params']['pct']);   // 5B / 125B
        $this->assertSame('96.00', $b['locked_supply']['params']['pct']); // (100B ขาย + 20B สำรอง) / 125B
        $this->assertArrayNotHasKey('sale_paused', $b);
        $this->assertSame('0', $data['stats']['sold']);
        $this->assertSame('100000000000000000000000000000', $data['stats']['remaining']);
    }

    #[Test]
    public function risky_switches_and_an_unlocked_pool_show_up_as_danger(): void
    {
        $pair = '0x5700000000000000000000000000000000000057';
        $this->lp['flags']['mint'] = true;
        $this->lp['flags']['blacklist'] = true;
        $this->lp['paused'] = true;
        $this->lp['pair'] = $pair;
        $this->lp['lp_total'] = '1000';
        $this->chainState[4289]['balances'][$pair] = [$this->lpFeeder => '400'];
        // ผู้สร้างถือ 40B แบบไม่ล็อก (ย้ายมาจากสำรองของตัวป้อนพูล)
        $this->chainState[4289]['balances'][$this->lpToken][$this->lpCreator] = '40000000000000000000000000000';
        $this->chainState[4289]['balances'][$this->lpToken][$this->lpFeeder] = '0';

        $b = $this->badges($this->getJson('/api/v1/launchpad/sales/1/risk')->assertOk()->json('data'));

        $this->assertSame('danger', $b['mint']['level']);
        $this->assertSame('danger', $b['blacklist']['level']);
        $this->assertSame('ok', $b['pause']['level']);
        $this->assertSame('danger', $b['lp_held']['level']);
        $this->assertSame('40.00', $b['lp_held']['params']['pct']);
        $this->assertSame('danger', $b['creator_free']['level']);
        $this->assertSame('32.00', $b['creator_free']['params']['pct']);
        $this->assertSame('80.00', $b['locked_supply']['params']['pct']); // เหลือแค่ที่ยังขายอยู่ 100B / 125B
        $this->assertSame('ok', $b['locked_supply']['level']);            // 80% = เกณฑ์ขั้นต่ำของ LaunchpadFactory พอดี
        $this->assertSame('warn', $b['sale_paused']['level']);
    }

    #[Test]
    public function an_ended_lp_lock_is_a_warning(): void
    {
        LaunchpadSale::query()->update(['lp_unlock_at' => now()->subDay()]);

        $b = $this->badges($this->getJson('/api/v1/launchpad/sales/1/risk')->assertOk()->json('data'));

        $this->assertSame('warn', $b['lp_lock']['level']);
    }

    #[Test]
    public function when_the_chain_cannot_be_read_nothing_is_shown_as_safe(): void
    {
        $this->rpcFaults['rpc.tpix.test|eth_call'] = 'header not found';

        $data = $this->getJson('/api/v1/launchpad/sales/1/risk')->assertOk()->json('data');

        $this->assertFalse($data['available']);
        foreach ($data['badges'] as $badge) {
            if (in_array($badge['key'], ['lp_lock', 'pool_share'], true)) {
                continue; // สองข้อนี้มาจากค่าที่ลงทะเบียนไว้ ไม่ต้องอ่านเชน
            }
            $this->assertSame('unknown', $badge['level'], $badge['key']);
        }
    }

    #[Test]
    public function risk_facts_are_cached_for_a_minute(): void
    {
        $this->getJson('/api/v1/launchpad/sales/1/risk')->assertOk();
        $this->lp['flags']['mint'] = true;

        $cached = $this->badges($this->getJson('/api/v1/launchpad/sales/1/risk')->json('data'));
        $this->assertSame('ok', $cached['mint']['level']);

        Cache::forget('launchpad:risk:1');
        $fresh = $this->badges($this->getJson('/api/v1/launchpad/sales/1/risk')->json('data'));
        $this->assertSame('danger', $fresh['mint']['level']);
    }

    #[Test]
    public function a_report_is_stored_once_per_ip_and_does_not_hide_the_sale(): void
    {
        $this->postJson('/api/v1/launchpad/sales/1/report', [
            'reason' => 'impersonation', 'details' => '  copies <b>Bitcoin</b> logo  ', 'wallet' => '0x'.str_repeat('A', 40),
        ])->assertCreated()->assertJsonPath('data.received', true);

        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam'])->assertOk();

        $this->assertSame(1, LaunchpadReport::count());
        $report = LaunchpadReport::first();
        $this->assertSame('impersonation', $report->reason);
        $this->assertSame('copies <b>Bitcoin</b> logo', $report->details); // เก็บดิบ — หลังบ้านแสดงแบบ escape
        $this->assertSame('0x'.str_repeat('a', 40), $report->reporter_wallet);
        $this->assertSame(LaunchpadReport::STATUS_OPEN, $report->status);
        $this->assertSame(LaunchpadSale::STATUS_APPROVED, LaunchpadSale::first()->status);

        // พ้น 24 ชม. แจ้งใหม่ได้
        $this->travel(25)->hours();
        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam'])->assertCreated();
        $this->assertSame(2, LaunchpadReport::count());
    }

    #[Test]
    public function a_report_with_a_bad_reason_or_wallet_is_rejected(): void
    {
        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'drop table'])->assertUnprocessable();
        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam', 'wallet' => 'not-a-wallet'])->assertUnprocessable();
        $this->postJson('/api/v1/launchpad/sales/1/report', ['reason' => 'scam', 'details' => str_repeat('x', 1001)])->assertUnprocessable();

        $this->assertSame(0, LaunchpadReport::count());
    }

    #[Test]
    public function an_open_order_tells_the_page_where_to_pay_again_after_a_reload(): void
    {
        $order = $this->placeOrder('100')['response']->assertCreated()->json('data');

        $this->assertSame($this->vault, $order['payment']['vault']);
        $this->assertSame(strtolower($this->usdtBsc), $order['payment']['usdt_contract']);
        $this->assertSame(18, $order['payment']['usdt_decimals']);

        $again = $this->getJson("/api/v1/launchpad/orders/{$order['id']}")->assertOk()->json('data');
        $this->assertSame($order['payment'], $again['payment']);

        // ไม่ได้รอเงินแล้ว = ไม่บอกปลายทาง (กันคนโอนซ้ำเข้าคำสั่งที่ปิดแล้ว)
        LaunchpadOrder::query()->update(['status' => LaunchpadOrder::STATUS_CANCELLED]);
        $this->assertNull($this->getJson("/api/v1/launchpad/orders/{$order['id']}")->json('data.payment'));
    }
}
