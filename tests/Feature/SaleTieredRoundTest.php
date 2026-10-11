<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Services\BankTransferSaleService;
use App\Services\SaleLaunchService;
use App\Services\SaleStatusService;
use App\Services\TokenSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — รอบขายแบบขั้นบันได (ล็อกวันจบรอบ).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * เจ้าของกำหนด (11 ต.ค. 2026)
 * ═══════════════════════════════════════════════════════════════════════════
 * "เหรียญในเฟสทั้งหมดที่ยังไม่ได้ขาย เอามาขายรอบนี้เลย ทั้งหมด ยาวไปถึงสิ้นปี"
 * ราคาขั้นบันได 0.05 → 0.08 → 0.10 · เปิดเองเมื่อระบบจ่ายเหรียญพร้อม
 *
 * เทสต์ชุดนี้คุมสามเรื่อง:
 *   1. ทุกขั้นใช้ช่วงเวลาเดียวกันจนถึงวันจบรอบ — ไม่ว่าจะเปิดช้าไปกี่วัน
 *   2. ขั้นแรกขายหมดเมื่อไหร่ ขั้นถัดไปต้องเปิดทันที ทุกทางที่รับเงิน
 *      (เดิมทางบัตร/โอนเงินไม่ปิดเฟสเลย ส่วนทางคริปโตปิดทั้งรอบขายทิ้ง)
 *   3. เศษโควตาที่ต่ำกว่ายอดซื้อขั้นต่ำต้องนับว่าหมด ไม่งั้นรอบค้างที่ขั้นนั้นจนสิ้นปี
 *
 * Developed by Xman Studio.
 */
class SaleTieredRoundTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    protected function setUp(): void
    {
        parent::setUp();

        // SiteSetting::get() แคชค่า default ไว้ด้วย — ถ้าไม่ล้าง เทสต์ก่อนหน้าจะหลอกเทสต์ถัดไป
        Cache::flush();
    }

    // =========================================================================
    // ตัวช่วยสร้างข้อมูล — สภาพเดียวกับ production วันที่ 11 ต.ค. (ขายไป 0 ทุกเฟส)
    // =========================================================================

    private function makeSale(): TokenSale
    {
        return TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 3000,
            'accept_currencies' => ['CARD', 'BANK'],
            'accept_chain_id' => 4289,
            'starts_at' => now()->subMonths(6),
            'ends_at' => now()->subWeeks(2),
        ]);
    }

    /** สามขั้นราคา โควตาขั้นละ 1,000 TPIX (ซื้อ $100 ที่ $0.10 = เหมาหมดขั้นได้ในคำสั่งเดียว) */
    private function makeTiers(TokenSale $sale): array
    {
        $tiers = [];
        foreach ([1 => 0.05, 2 => 0.08, 3 => 0.10] as $order => $price) {
            $tiers[$order] = SalePhase::create([
                'token_sale_id' => $sale->id,
                'name' => "Tier {$order}",
                'slug' => "tier-{$order}",
                'phase_order' => $order,
                'duration_days' => 61,
                'price_usd' => $price,
                'allocation' => 1000,
                'sold' => 0,
                'min_purchase' => 100,
                'max_purchase' => 1000000,
                'vesting_tge_percent' => 20,
                'vesting_cliff_days' => 30,
                'vesting_duration_days' => 180,
                'status' => 'completed',
                'starts_at' => now()->subMonths(6),
                'ends_at' => now()->subWeeks(2),
            ]);
        }

        return $tiers;
    }

    private function makeEverythingReady(): void
    {
        SiteSetting::set('sale', 'bank_name', 'ธนาคารกสิกรไทย');
        SiteSetting::set('sale', 'bank_account_name', 'Xman Studio');
        SiteSetting::set('sale', 'bank_account_no', '123-4-56789-0');
        SiteSetting::set('revenue', 'tpix_wallet', self::WALLET);
        config(['treasury.payouts_enabled' => true]);

        // 100,000 TPIX ในกระเป๋าจ่ายเหรียญ
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x152d02c7e14af6800000'])]);
    }

    private function launcher(): SaleLaunchService
    {
        return app(SaleLaunchService::class);
    }

    private function yearEnd(): Carbon
    {
        return now()->addDays(80)->endOfDay();
    }

    /** เปิดรอบขั้นบันไดจบ yearEnd() แล้วคืนเฟสสดจากฐานข้อมูล */
    private function launchTiered(TokenSale $sale): array
    {
        $this->makeEverythingReady();
        $this->launcher()->setRoundEndsAt($this->yearEnd());

        $result = $this->launcher()->launch($sale);
        $this->assertTrue($result['ok'], (string) $result['message']);

        return $sale->phases()->orderBy('phase_order')->get()->keyBy('phase_order')->all();
    }

    // =========================================================================
    // 1) ตารางขั้นบันได
    // =========================================================================

    public function test_round_end_puts_every_tier_in_one_window(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);

        $tiers = $this->launchTiered($sale);

        foreach ($tiers as $tier) {
            $this->assertTrue($tier->starts_at->lte(now()), "{$tier->name} ต้องเริ่มวันนี้");
            $this->assertSame($this->yearEnd()->toDateTimeString(), $tier->ends_at->toDateTimeString(), "{$tier->name} ต้องจบวันจบรอบ");
        }

        // เปิดทีละขั้น — สองราคาเปิดพร้อมกันไม่ได้
        $this->assertSame('active', $tiers[1]->status);
        $this->assertSame('upcoming', $tiers[2]->status);
        $this->assertSame('upcoming', $tiers[3]->status);

        // หน้าเว็บนับถอยหลังจาก token_sales.ends_at — ต้องขยายถึงวันจบรอบ
        $this->assertSame($this->yearEnd()->toDateTimeString(), $sale->fresh()->ends_at->toDateTimeString());
        $this->assertSame(1, app(TokenSaleService::class)->getActivePhase($sale->fresh())?->phase_order);
    }

    /** duration_days คือความยาวของแบบต่อกันตามเวลา — ขั้นบันไดห้ามเขียนทับ */
    public function test_tiered_launch_keeps_phase_durations(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);

        $tiers = $this->launchTiered($sale);

        foreach ($tiers as $tier) {
            $this->assertSame(61, (int) $tier->duration_days);
        }
    }

    /** เปิดช้าไปกี่วันก็ต้องจบวันเดิม — นี่คือเหตุผลที่ต้องล็อกวันจบไว้ ไม่ใช่นับจำนวนวัน */
    public function test_auto_launch_ends_on_the_locked_date_even_when_ready_late(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $end = $this->yearEnd();
        $this->launcher()->setRoundEndsAt($end);
        $this->launcher()->setAutoLaunch(true);

        // ยังไม่พร้อม (กระเป๋าจ่ายเหรียญว่าง) → ไม่เปิด
        Http::fake(['*' => Http::response(['result' => '0x0'])]);
        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();
        $this->assertNull($sale->fresh()->launched_at);

        // พร้อมเอาอีก 9 วันให้หลัง
        $this->travel(9)->days();
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->makeEverythingReady();
        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();

        $tier1 = $sale->phases()->where('phase_order', 1)->first();
        $this->assertNotNull($sale->fresh()->launched_at);
        $this->assertSame('active', $tier1->status);
        $this->assertSame($end->toDateTimeString(), $tier1->ends_at->toDateTimeString());
    }

    /** วันจบรอบผ่านไปแล้ว = ห้ามเปิด (เปิดไปก็ปิดทันที) และตัวอัตโนมัติไม่ทำให้ตารางเวลาแดง */
    public function test_round_end_in_the_past_refuses_to_launch(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->makeEverythingReady();
        $this->launcher()->setRoundEndsAt(now()->subDay());
        $this->launcher()->setAutoLaunch(true);

        $result = $this->launcher()->launch($sale);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('วันจบรอบ', (string) $result['message']);

        $this->artisan('sale:launch', ['--auto' => true])->assertSuccessful();
        $this->assertNull($sale->fresh()->launched_at);
        $this->assertSame('completed', $sale->phases()->where('phase_order', 1)->first()->status);
    }

    public function test_cli_until_option_runs_a_tiered_round(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->makeEverythingReady();
        $until = now()->addDays(30)->format('Y-m-d');

        $this->artisan('sale:launch', ['--until' => $until])->assertSuccessful();

        foreach ($sale->phases as $tier) {
            // ใส่แค่วันที่ = จบตอนสิ้นวันนั้น ไม่ใช่เที่ยงคืนต้นวัน
            $this->assertSame("{$until} 23:59:59", $tier->ends_at->toDateTimeString());
        }
    }

    /** ไม่ล็อกวันจบ = พฤติกรรมเดิม (เฟสต่อกันตามเวลา) ต้องไม่เปลี่ยน */
    public function test_without_round_end_phases_are_still_chained(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->makeEverythingReady();

        $this->launcher()->launch($sale);

        $tiers = $sale->phases()->orderBy('phase_order')->get();
        $this->assertSame($tiers[0]->ends_at->toDateTimeString(), $tiers[1]->starts_at->toDateTimeString());
        $this->assertSame($tiers[1]->ends_at->toDateTimeString(), $tiers[2]->starts_at->toDateTimeString());
    }

    // =========================================================================
    // 2) ขายหมดขั้น → ขั้นถัดไปเปิดทันที
    // =========================================================================

    /** ทางโอนเงิน: ทีมงานกดยืนยันแล้วขั้นแรกหมด → ขั้นสองต้องเปิดทันที ไม่รอรอบชั่วโมง */
    public function test_selling_out_a_tier_opens_the_next_one_immediately(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->launchTiered($sale);

        $bank = app(BankTransferSaleService::class);
        // $50 ที่ $0.05 = 1,000 TPIX = เหมาขั้นแรกหมด
        $order = $bank->createOrder(self::BUYER, $sale->phases()->where('phase_order', 1)->value('id'), 50.0);
        $bank->confirm(SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail(), 'admin@tpix.test');

        $tiers = $sale->phases()->orderBy('phase_order')->get();
        $this->assertSame('completed', $tiers[0]->status);
        $this->assertSame('active', $tiers[1]->status);
        $this->assertSame('upcoming', $tiers[2]->status);
        $this->assertSame('active', $sale->fresh()->status, 'ขั้นแรกหมดไม่ได้แปลว่ารอบขายจบ');

        // ผู้ซื้อคนถัดไปได้ราคาขั้นสอง
        $this->assertSame(0.08, (float) app(TokenSaleService::class)->getActivePhase($sale->fresh())->price_usd);
    }

    /** เหลือ 30 TPIX แต่ซื้อขั้นต่ำ 100 — ไม่มีใครซื้อเศษนี้ได้ ต้องนับว่าหมดแล้วเปิดขั้นถัดไป */
    public function test_dust_below_minimum_purchase_counts_as_sold_out(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $tiers = $this->launchTiered($sale);

        $tiers[1]->update(['sold' => 970]);

        $changes = DB::transaction(fn () => app(TokenSaleService::class)->advancePhases($sale->fresh()));

        $this->assertNotEmpty($changes);
        $this->assertSame('completed', $tiers[1]->fresh()->status);
        $this->assertSame('active', $tiers[2]->fresh()->status);
    }

    /** ทางสำรองรายชั่วโมงต้องเลื่อนขั้นได้เองด้วย ถ้าการเลื่อนหลังขายล้มไป */
    public function test_hourly_command_rolls_over_a_sold_out_tier(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $tiers = $this->launchTiered($sale);

        $tiers[1]->update(['sold' => 1000]);

        $this->artisan('sale:advance-phases')->assertSuccessful();

        $this->assertSame('completed', $tiers[1]->fresh()->status);
        $this->assertSame('active', $tiers[2]->fresh()->status);
        $this->assertSame('upcoming', $tiers[3]->fresh()->status);
    }

    /**
     * แบบต่อกันตามเวลา: เฟสแรกขายหมดก่อนกำหนด รอบขายต้องยังไม่จบ
     *
     * เดิมทางคริปโตตั้งรอบขายเป็น completed ทันทีที่ไม่มีเฟส active เหลือ
     * เฟสถัดไป (ซึ่งยังไม่ถึงวันเริ่ม) จึงไม่มีวันได้เปิด
     */
    public function test_selling_out_the_first_phase_keeps_the_sale_alive(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->makeEverythingReady();
        $this->launcher()->launch($sale); // ไม่ล็อกวันจบ = ต่อกันตามเวลา

        $first = $sale->phases()->where('phase_order', 1)->first();
        $first->update(['sold' => 1000]);
        app(TokenSaleService::class)->rollOverIfExhausted($first);

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('upcoming', $sale->phases()->where('phase_order', 2)->value('status'), 'เฟสสองยังไม่ถึงวันเริ่ม');
        $this->assertSame('active', $sale->fresh()->status);

        // ถึงวันเริ่มของเฟสสอง → คำสั่งรายชั่วโมงเปิดให้
        $this->travelTo($sale->phases()->where('phase_order', 2)->first()->starts_at->copy()->addMinute());
        $this->artisan('sale:advance-phases')->assertSuccessful();
        $this->assertSame('active', $sale->phases()->where('phase_order', 2)->value('status'));
    }

    public function test_selling_out_every_tier_completes_the_sale(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->launchTiered($sale);

        $sale->phases()->update(['sold' => 1000]);
        app(TokenSaleService::class)->rollOverIfExhausted($sale->phases()->where('phase_order', 1)->first());

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(0, $sale->phases()->where('status', 'active')->count());
    }

    // =========================================================================
    // 3) หลังบ้าน + ข้อความสถานะ
    // =========================================================================

    private function actingAsAdmin(): void
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');
    }

    public function test_admin_can_lock_and_clear_the_round_end(): void
    {
        $this->actingAsAdmin();
        $date = now()->addDays(60)->format('Y-m-d');

        $this->post('/admin/token-sales/round-end', ['round_ends_at' => $date])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame("{$date} 23:59:59", $this->launcher()->roundEndsAt()?->toDateTimeString());

        $this->post('/admin/token-sales/round-end', ['round_ends_at' => ''])->assertSessionHas('success');
        $this->assertNull($this->launcher()->roundEndsAt());
    }

    public function test_admin_cannot_lock_a_round_end_in_the_past(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/token-sales/round-end', ['round_ends_at' => now()->subDay()->format('Y-m-d')])
            ->assertSessionHasErrors('round_ends_at');

        $this->assertNull($this->launcher()->roundEndsAt());
    }

    /** ก่อนเปิด บอท/ผู้ช่วย AI ต้องบอกวันจบรอบจริง ไม่ใช่ "ยาว 61 วัน" */
    public function test_status_text_shows_the_round_end_before_launch(): void
    {
        $sale = $this->makeSale();
        $this->makeTiers($sale);
        $this->launcher()->setRoundEndsAt($this->yearEnd());

        $snapshot = app(SaleStatusService::class)->snapshot();

        $this->assertStringContainsString('ขายถึง', $snapshot['phases'][0]['window']);
        $this->assertNotNull($snapshot['phases'][0]['round_ends_at']);
    }
}
