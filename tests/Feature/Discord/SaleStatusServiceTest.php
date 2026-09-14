<?php

namespace Tests\Feature\Discord;

use App\Models\SalePhase;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Services\SaleStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — ประกาศการขายเหรียญ "ตามจริง" (เจ้าของ: "ให้บอทจัดการว่าเปิดหรือปิดตามจริง วันเวลารอบต่าง ๆ").
 *
 * ห้ามหลุด:
 *   1. ป้าย status=active ในตารางอย่างเดียวไม่พอให้ประกาศ "เปิดขาย" — ต้องจ่ายเหรียญได้จริง
 *   2. ก่อนเปิดขายจริง ห้ามประกาศวันที่ (วันจะถูกคำนวณใหม่ตอนเปิด) — บอกเป็นจำนวนวันแทน
 *   3. เปิดขายแล้ว บอกวันที่จริงของแต่ละเฟส
 *
 * Developed by Xman Studio.
 */
class SaleStatusServiceTest extends TestCase
{
    private const WALLET = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function sale(array $attrs = []): TokenSale
    {
        return TokenSale::create(array_merge([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'accept_currencies' => ['BANK'],
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addMonths(2),
        ], $attrs));
    }

    private function phase(TokenSale $sale, int $order, array $attrs = []): SalePhase
    {
        return SalePhase::create(array_merge([
            'token_sale_id' => $sale->id,
            'name' => "Phase {$order}",
            'slug' => "phase-{$order}",
            'phase_order' => $order,
            'duration_days' => 30 + $order,
            'price_usd' => 0.05 * $order,
            'allocation' => 100000000,
            'sold' => 0,
            'min_purchase' => 100,
            'max_purchase' => 10000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'upcoming',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ], $attrs));
    }

    /** ทุกด่านความพร้อมผ่าน: เลขบัญชี + กระเป๋าจ่าย + สวิตช์จ่ายเหรียญ + มีเหรียญในกระเป๋า */
    private function everythingReady(): void
    {
        SiteSetting::set('sale', 'bank_account_no', '123-4-56789-0');
        SiteSetting::set('sale', 'bank_name', 'ธนาคารทดสอบ');
        SiteSetting::set('sale', 'bank_account_name', 'บริษัท ทดสอบ จำกัด');
        SiteSetting::set('revenue', 'tpix_wallet', self::WALLET);
        config(['treasury.payouts_enabled' => true]);
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x152d02c7e14af6800000'])]);
    }

    public function test_an_active_phase_is_not_announced_as_open_while_payouts_are_off(): void
    {
        // สภาพเดียวกับ production 2026-09-14: เฟส active + รับบัตร แต่ระบบจ่ายเหรียญปิด
        $sale = $this->sale();
        $this->phase($sale, 1, ['status' => 'active']);
        SiteSetting::set('sale', 'bank_account_no', '123-4-56789-0');
        config(['treasury.payouts_enabled' => false]);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $snapshot = app(SaleStatusService::class)->snapshot();

        $this->assertSame(SaleStatusService::PREPARING, $snapshot['state']);
        $this->assertStringContainsString('ยังไม่เปิดให้ซื้อ', $snapshot['headline']);
        $this->assertNull($snapshot['current_phase']);
    }

    public function test_before_launch_phases_show_their_length_not_placeholder_dates(): void
    {
        $sale = $this->sale(['launched_at' => null]);
        $this->phase($sale, 1, ['status' => 'active', 'duration_days' => 61]);
        $this->phase($sale, 2, ['duration_days' => 62]);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $phases = app(SaleStatusService::class)->snapshot()['phases'];

        $this->assertSame('ยาว 61 วัน · เริ่มนับเมื่อเปิดขายจริง', $phases[0]['window']);
        $this->assertSame('ยาว 62 วัน · เริ่มนับเมื่อเปิดขายจริง', $phases[1]['window']);
        $this->assertSame('รอเปิดขาย', $phases[0]['status_label']);
        $this->assertSame('$0.05', $phases[0]['price']);
    }

    public function test_when_everything_is_ready_the_sale_is_open_with_real_dates(): void
    {
        $this->everythingReady();
        $sale = $this->sale(['launched_at' => now()->subDays(10)]);
        $this->phase($sale, 1, ['status' => 'active', 'starts_at' => '2026-09-04 00:00:00', 'ends_at' => '2026-10-04 00:00:00']);
        $this->phase($sale, 2, ['starts_at' => '2026-10-04 00:00:00', 'ends_at' => '2026-11-04 00:00:00']);

        $snapshot = app(SaleStatusService::class)->snapshot();

        $this->assertSame(SaleStatusService::OPEN, $snapshot['state']);
        $this->assertSame('Phase 1', $snapshot['current_phase']);
        $this->assertStringContainsString('เปิดขายแล้ว — Phase 1 ราคา $0.05', $snapshot['headline']);
        $this->assertStringContainsString('โอนผ่านธนาคาร', $snapshot['detail']);
        $this->assertSame('🟢 เปิดขายอยู่', $snapshot['phases'][0]['status_label']);
        $this->assertStringContainsString('2026', $snapshot['phases'][0]['window']);
        $this->assertStringNotContainsString('เริ่มนับเมื่อเปิดขายจริง', $snapshot['phases'][0]['window']);
        $this->assertSame('รอเปิด', $snapshot['phases'][1]['status_label']);
    }

    public function test_no_active_sale_is_reported_as_closed(): void
    {
        $snapshot = app(SaleStatusService::class)->snapshot();

        $this->assertSame(SaleStatusService::CLOSED, $snapshot['state']);
        $this->assertSame([], $snapshot['phases']);
    }

    public function test_the_ai_facts_never_claim_the_sale_is_open_when_it_is_not(): void
    {
        $sale = $this->sale();
        $this->phase($sale, 1, ['status' => 'active']);
        Http::fake(['*' => Http::response(['result' => '0x0'])]);

        $text = app(SaleStatusService::class)->asText();

        $this->assertStringContainsString('เตรียมเปิดขาย', $text);
        $this->assertStringNotContainsString('เปิดขายแล้ว', $text);
        $this->assertStringContainsString('/token-sale', $text);
    }
}
