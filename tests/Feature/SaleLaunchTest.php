<?php

namespace Tests\Feature;

use App\Models\SalePhase;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Services\SaleLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — Sale Launch Tests.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * เจ้าของกำหนด: "พร้อมจำหน่ายเมื่อไหร่ ก็เริ่มเฟสการขายใหม่แต่แรกวันนั้น"
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมวันของแต่ละเฟสตั้งตายตัวไว้ล่วงหน้า แล้วระบบไม่เคยพร้อมขายตามวันนั้น
 * เฟสแรกจึงหมดอายุไปเงียบ ๆ โดยยังไม่เคยขายได้เลยสักบาท (ค้างจริง 3 เดือน)
 *
 * เทสต์ชุดนี้กันสามเรื่อง:
 *   1. ด่านความพร้อมต้องบล็อกจริง — เปิดขายทั้งที่ไม่มีเหรียญให้จ่าย = รับเงินแล้วไม่ได้ของ
 *   2. ตารางที่คำนวณต้องต่อกันไม่ทับกัน — สองเฟสเปิดพร้อมกันแปลว่าราคาที่ลูกค้าได้ทายไม่ได้
 *   3. ตัวเปิดอัตโนมัติต้องไม่เปิดเอง เว้นแต่เจ้าของติดอาวุธไว้และผ่านครบทุกข้อ
 *
 * Developed by Xman Studio.
 */
class SaleLaunchTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        // SiteSetting::get() แคชค่า default ไว้ด้วย — ถ้าไม่ล้าง เทสต์ก่อนหน้าจะหลอกเทสต์ถัดไป
        Cache::flush();
    }

    // =========================================================================
    // ตัวช่วยสร้างข้อมูล
    // =========================================================================

    private function makeSale(): TokenSale
    {
        return TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
            // วันเก่าที่หมดอายุไปแล้ว — สภาพเดียวกับ production ตอนที่พบปัญหา
            'starts_at' => now()->subMonths(5),
            'ends_at' => now()->subMonths(1),
        ]);
    }

    private function makePhase(TokenSale $sale, int $order, array $attrs = []): SalePhase
    {
        return SalePhase::create(array_merge([
            'token_sale_id' => $sale->id,
            'name' => "Phase {$order}",
            'slug' => "phase-{$order}",
            'phase_order' => $order,
            'duration_days' => 30,
            'price_usd' => 0.05 * $order,
            'allocation' => 100000000,
            'sold' => 0,
            'min_purchase' => 100,
            'max_purchase' => 10000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'upcoming',
            'starts_at' => now()->subMonths(5),
            'ends_at' => now()->subMonths(3),
        ], $attrs));
    }

    /** ทำให้ทุกด่านความพร้อมผ่าน */
    private function makeEverythingReady(string $balanceHex = '0x152d02c7e14af6800000'): void
    {
        SiteSetting::set('sale', 'bank_account_no', '123-4-56789-0');
        SiteSetting::set('revenue', 'tpix_wallet', self::WALLET);
        config(['treasury.payouts_enabled' => true]);

        // 100,000 TPIX ในกระเป๋าจ่ายเหรียญ
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $balanceHex])]);
    }

    private function launcher(): SaleLaunchService
    {
        return app(SaleLaunchService::class);
    }

    // =========================================================================
    // 1) ด่านความพร้อม
    // =========================================================================

    public function test_readiness_blocks_when_nothing_is_configured(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $readiness = $this->launcher()->readiness($sale);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty($readiness['blocking']);
    }

    public function test_readiness_passes_when_everything_is_set(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady();

        $readiness = $this->launcher()->readiness($sale);

        $this->assertTrue($readiness['ready'], 'ยังบล็อก: '.implode(' · ', $readiness['blocking']));
    }

    /**
     * กระเป๋าจ่ายเหรียญว่าง = ห้ามเปิดขาย.
     *
     * นี่คือกรณีที่ทำร้ายลูกค้าที่สุด — จ่ายเงินแล้วไม่มีเหรียญให้จ่ายคืน
     */
    public function test_readiness_blocks_when_payout_wallet_is_empty(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady('0x0');

        $readiness = $this->launcher()->readiness($sale);

        $this->assertFalse($readiness['ready']);
        $this->assertContains('กระเป๋าจ่ายเหรียญมี TPIX', $readiness['blocking']);
    }

    /**
     * ถามยอดจากเชนไม่ได้ = ห้ามเปิดขาย (fail-closed).
     *
     * ยอมไม่เปิดขาย ดีกว่าเปิดขายทั้งที่ไม่รู้ว่ามีของ
     */
    public function test_readiness_blocks_when_chain_is_unreachable(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        SiteSetting::set('sale', 'bank_account_no', '123-4-56789-0');
        SiteSetting::set('revenue', 'tpix_wallet', self::WALLET);
        config(['treasury.payouts_enabled' => true]);
        Http::fake(['*' => Http::response(null, 500)]);

        $readiness = $this->launcher()->readiness($sale);

        $this->assertFalse($readiness['ready']);
        $this->assertContains('กระเป๋าจ่ายเหรียญมี TPIX', $readiness['blocking']);
    }

    public function test_readiness_blocks_when_no_payment_channel(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        SiteSetting::set('revenue', 'tpix_wallet', self::WALLET);
        config(['treasury.payouts_enabled' => true]);
        Http::fake(['*' => Http::response(['result' => '0x152d02c7e14af6800000'])]);

        $readiness = $this->launcher()->readiness($sale);

        $this->assertFalse($readiness['ready']);
        $this->assertContains('มีช่องทางรับเงินอย่างน้อย 1 ทาง', $readiness['blocking']);
    }

    // =========================================================================
    // 2) ตารางที่คำนวณได้
    // =========================================================================

    /**
     * เฟสแรกเริ่มวันที่กดเปิด ที่เหลือต่อกันเป็นทอด ๆ ตามความยาวของตัวเอง.
     */
    public function test_phases_are_chained_from_the_launch_day(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1, ['duration_days' => 30]);
        $this->makePhase($sale, 2, ['duration_days' => 45]);
        $this->makePhase($sale, 3, ['duration_days' => 60]);
        $this->makeEverythingReady();

        $start = now()->startOfDay();
        $result = $this->launcher()->launch($sale, $start);

        $this->assertTrue($result['ok'], $result['message'] ?? '');

        $phases = $sale->phases()->orderBy('phase_order')->get();

        $this->assertSame($start->toDateTimeString(), $phases[0]->starts_at->toDateTimeString());
        $this->assertSame($start->copy()->addDays(30)->toDateTimeString(), $phases[0]->ends_at->toDateTimeString());

        // เฟสถัดไปเริ่มตรงจุดที่เฟสก่อนหน้าจบพอดี — ไม่ทับกัน ไม่มีช่องว่าง
        $this->assertSame($phases[0]->ends_at->toDateTimeString(), $phases[1]->starts_at->toDateTimeString());
        $this->assertSame($start->copy()->addDays(75)->toDateTimeString(), $phases[1]->ends_at->toDateTimeString());
        $this->assertSame($phases[1]->ends_at->toDateTimeString(), $phases[2]->starts_at->toDateTimeString());
        $this->assertSame($start->copy()->addDays(135)->toDateTimeString(), $phases[2]->ends_at->toDateTimeString());
    }

    /**
     * เปิดแค่เฟสแรก ที่เหลือรอ sale:advance-phases เลื่อนให้ตามเวลา.
     *
     * ถ้าเปิดหมดพร้อมกัน getActivePhase() ที่ใช้ ->first() จะหยิบมาแบบทายไม่ได้
     * = ลูกค้าอาจได้ราคาที่เราไม่ได้ตั้งใจขาย
     */
    public function test_only_the_first_phase_becomes_active(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makePhase($sale, 2);
        $this->makeEverythingReady();

        $this->launcher()->launch($sale, now());

        $phases = $sale->phases()->orderBy('phase_order')->get();
        $this->assertSame('active', $phases[0]->status);
        $this->assertSame('upcoming', $phases[1]->status);
    }

    /**
     * เฟสที่ขายไปแล้วห้ามถูกเลื่อน — เท่ากับเปลี่ยนเงื่อนไขย้อนหลังกับคนที่จ่ายเงินมาแล้ว.
     */
    public function test_sold_phases_are_never_moved(): void
    {
        $sale = $this->makeSale();
        $sold = $this->makePhase($sale, 1, [
            'sold' => 5000,
            'status' => 'completed',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(10),
        ]);
        $this->makePhase($sale, 2);
        $this->makeEverythingReady();

        $originalStart = $sold->starts_at->toDateTimeString();
        $originalEnd = $sold->ends_at->toDateTimeString();

        $this->launcher()->launch($sale, now());

        $sold->refresh();
        $this->assertSame($originalStart, $sold->starts_at->toDateTimeString());
        $this->assertSame($originalEnd, $sold->ends_at->toDateTimeString());
        $this->assertSame('completed', $sold->status);
    }

    /**
     * เฟสใหม่ต้องไม่ทับช่วงของเฟสที่ขายไปแล้วซึ่งยังไม่จบ.
     *
     * ถ้าทับ จะมีสองเฟสเปิดขายพร้อมกันคนละราคา
     */
    public function test_new_phases_start_after_an_unfinished_sold_phase(): void
    {
        $sale = $this->makeSale();
        $sold = $this->makePhase($sale, 1, [
            'sold' => 5000,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(20),
        ]);
        $next = $this->makePhase($sale, 2);
        $this->makeEverythingReady();

        $this->launcher()->launch($sale, now());

        $next->refresh();
        $this->assertTrue(
            $next->starts_at->gte($sold->ends_at),
            'เฟสใหม่เริ่มก่อนเฟสที่ขายไปแล้วจะจบ → สองเฟสเปิดพร้อมกัน'
        );

        // และห้ามได้ป้าย active ล่วงหน้า — ป้ายที่ยังซื้อไม่ได้จริงคือกับดักเดิม
        $this->assertSame('upcoming', $next->status);
    }

    /**
     * ตั้งวันเปิดไว้ในอนาคต — เฟสแรกต้องเป็น upcoming ไม่ใช่ active.
     *
     * ป้าย active ที่ยังซื้อไม่ได้จริงคือสิ่งที่เคยทำให้ลูกค้าโอนเงินไปแล้วโดนปฏิเสธ
     * ให้ sale:advance-phases เป็นคนพลิกเป็น active ตอนถึงวันจริง
     */
    public function test_future_launch_date_leaves_the_first_phase_upcoming(): void
    {
        $sale = $this->makeSale();
        $phase = $this->makePhase($sale, 1);
        $this->makeEverythingReady();

        $future = now()->addDays(7)->startOfDay();
        $this->launcher()->launch($sale, $future);

        $phase->refresh();
        $this->assertSame('upcoming', $phase->status);
        $this->assertSame($future->toDateTimeString(), $phase->starts_at->toDateTimeString());
    }

    /**
     * ends_at ของรอบขายต้องครอบคลุมเฟสสุดท้ายเสมอ.
     *
     * ไม่งั้นได้สภาพขัดกันเอง: เฟสเปิดขายอยู่ แต่หน้าเว็บนับถอยหลังบอกว่ารอบขายจบแล้ว
     */
    public function test_sale_window_is_extended_to_cover_the_last_phase(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1, ['duration_days' => 30]);
        $this->makePhase($sale, 2, ['duration_days' => 30]);
        $this->makeEverythingReady();

        $this->launcher()->launch($sale, now());

        $sale->refresh();
        $last = $sale->phases()->orderByDesc('phase_order')->first();

        $this->assertTrue($sale->ends_at->gte($last->ends_at));
    }

    /** duration_days ที่ยังว่างต้องตกไปใช้ค่าเริ่มต้น ไม่ใช่ 0 วัน */
    public function test_missing_duration_falls_back_to_default(): void
    {
        $sale = $this->makeSale();
        $phase = $this->makePhase($sale, 1, ['duration_days' => null]);
        $this->makeEverythingReady();

        $start = now()->startOfDay();
        $this->launcher()->launch($sale, $start);

        $phase->refresh();
        $this->assertSame(SaleLaunchService::DEFAULT_DURATION_DAYS, (int) $phase->duration_days);
        $this->assertSame(
            $start->copy()->addDays(SaleLaunchService::DEFAULT_DURATION_DAYS)->toDateTimeString(),
            $phase->ends_at->toDateTimeString()
        );
    }

    // =========================================================================
    // 3) หมุดวันเปิดขาย
    // =========================================================================

    public function test_launch_stamps_the_launch_date_once_and_keeps_it(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady();

        $first = now()->startOfDay();
        $this->launcher()->launch($sale, $first);

        $sale->refresh();
        $stamped = $sale->launched_at->toDateTimeString();
        $this->assertSame($first->toDateTimeString(), $stamped);

        // ตั้งวันใหม่ทับ — หมุดวันเปิดครั้งแรกต้องไม่ขยับ เพราะเป็นประวัติ
        $this->launcher()->launch($sale->fresh(), $first->copy()->addDays(10));

        $sale->refresh();
        $this->assertSame($stamped, $sale->launched_at->toDateTimeString());
    }

    public function test_launch_is_refused_when_not_ready(): void
    {
        $sale = $this->makeSale();
        $phase = $this->makePhase($sale, 1);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $before = $phase->starts_at->toDateTimeString();
        $result = $this->launcher()->launch($sale, now());

        $this->assertFalse($result['ok']);
        $this->assertSame($before, $phase->fresh()->starts_at->toDateTimeString());
        $this->assertNull($sale->fresh()->launched_at);
    }

    public function test_force_opens_the_sale_even_when_not_ready(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $result = $this->launcher()->launch($sale, now(), null, true);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($sale->fresh()->launched_at);
    }

    // =========================================================================
    // 4) คำสั่งบรรทัดคำสั่ง
    // =========================================================================

    public function test_auto_does_nothing_when_not_armed(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady();

        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();

        $this->assertNull($sale->fresh()->launched_at);
    }

    public function test_auto_launches_when_armed_and_ready(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady();
        $this->launcher()->setAutoLaunch(true);

        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();

        $sale->refresh();
        $this->assertNotNull($sale->launched_at);
        $this->assertSame('active', $sale->phases()->orderBy('phase_order')->first()->status);
    }

    public function test_auto_does_not_launch_twice(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady();
        $this->launcher()->setAutoLaunch(true);

        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();
        $stamped = $sale->fresh()->launched_at->toDateTimeString();

        $this->travel(2)->days();
        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();

        $this->assertSame($stamped, $sale->fresh()->launched_at->toDateTimeString());
    }

    /** ตัวเปิดอัตโนมัติห้ามข้ามด่านความพร้อมได้ ไม่ว่าจะสั่งยังไง */
    public function test_auto_refuses_force(): void
    {
        $this->artisan('sale:launch', ['--auto' => true, '--force' => true])->assertFailed();
    }

    public function test_auto_waits_when_not_ready(): void
    {
        $sale = $this->makeSale();
        $this->makePhase($sale, 1);
        $this->makeEverythingReady('0x0');
        $this->launcher()->setAutoLaunch(true);

        // ยังไม่พร้อม ≠ ผิดพลาด — แค่รอรอบหน้า ไม่ควรทำให้ตารางเวลาแดง
        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();

        $this->assertNull($sale->fresh()->launched_at);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $sale = $this->makeSale();
        $phase = $this->makePhase($sale, 1);
        $this->makeEverythingReady();

        $before = $phase->starts_at->toDateTimeString();
        $this->artisan('sale:launch', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $phase->fresh()->starts_at->toDateTimeString());
        $this->assertNull($sale->fresh()->launched_at);
    }

    /** sale:reschedule ต้องยังทำงานได้เหมือนเดิม (ข้ามด่านความพร้อมเสมอ) */
    public function test_reschedule_still_works_and_skips_readiness(): void
    {
        $sale = $this->makeSale();
        $phase = $this->makePhase($sale, 1);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $this->artisan('sale:reschedule', ['--days' => 15])->assertSuccessful();

        $phase->refresh();
        $this->assertSame(15, (int) $phase->duration_days);
        $this->assertTrue($phase->starts_at->isToday());
    }
}
