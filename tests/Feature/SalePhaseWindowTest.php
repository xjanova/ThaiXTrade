<?php

namespace Tests\Feature;

use App\Models\SalePhase;
use App\Models\TokenSale;
use App\Services\TokenSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — Sale Phase Window Tests
 *
 * เฟสที่ยังไม่เริ่มหรือปิดไปแล้ว ต้องซื้อไม่ได้ "ทั้งทางหน้าเว็บและทาง API ตรง"
 * เดิม processPurchase เช็คแค่ status ทำให้ยิง API พร้อม phase_id ของรอบที่ปิด
 * ไปแล้วยังจ่ายเงินเข้ามาได้ (หน้าเว็บซ่อนเฟสนั้นไปแล้วก็จริง แต่ backend ยังรับ)
 *
 * Developed by Xman Studio.
 */
class SalePhaseWindowTest extends TestCase
{
    use RefreshDatabase;

    private function makePhase(?string $startsAt, ?string $endsAt): SalePhase
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
        ]);

        return SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Private Sale',
            'slug' => 'private-sale',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 200000000,
            'min_purchase' => 100,
            'max_purchase' => 10000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    private function buy(SalePhase $phase): void
    {
        app(TokenSaleService::class)->processPurchase(
            '0x'.str_repeat('cd', 20),
            $phase->id,
            'USDT',
            100.0,
            '0x'.str_repeat('11', 32),
        );
    }

    /**
     * เฟสที่หมดอายุแล้ว — ห้ามรับเงิน แม้ status จะยังเป็น active.
     */
    public function test_purchase_is_rejected_after_phase_ended(): void
    {
        $phase = $this->makePhase(now()->subMonths(5)->toDateTimeString(), now()->subMonths(3)->toDateTimeString());

        $this->expectExceptionMessage('This phase has already ended.');
        $this->buy($phase);
    }

    /**
     * เฟสที่ยังไม่ถึงกำหนดเปิด — ห้ามรับเงินล่วงหน้า.
     */
    public function test_purchase_is_rejected_before_phase_starts(): void
    {
        $phase = $this->makePhase(now()->addDays(7)->toDateTimeString(), now()->addDays(67)->toDateTimeString());

        $this->expectExceptionMessage('This phase has not started yet.');
        $this->buy($phase);
    }

    /**
     * เฟสที่อยู่ในช่วงเวลา — ต้องผ่านด่านเวลาไปเจอด่านถัดไป (ไม่ใช่ถูกปัดตกเพราะวันที่)
     * เราปล่อยให้มันไปตายที่ด่านตรวจ tx บนเชน ซึ่งพิสูจน์ว่าด่านเวลาไม่ได้ขวางผิดตัว
     */
    public function test_purchase_passes_date_gate_inside_window(): void
    {
        Http::fake(['*' => Http::response(['result' => null], 200)]);
        $phase = $this->makePhase(now()->subDay()->toDateTimeString(), now()->addDays(59)->toDateTimeString());

        try {
            $this->buy($phase);
            $this->assertTrue(true, 'ผ่านด่านเวลาไปได้');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('phase has', $e->getMessage(),
                'ต้องไม่ถูกปัดตกด้วยเหตุผลเรื่องช่วงเวลา');
        }
    }

    /**
     * เฟสที่ไม่กำหนดวัน (null ทั้งคู่) — ต้องซื้อได้ตามปกติ ไม่ใช่ถูกบล็อกทิ้ง.
     */
    public function test_phase_without_dates_is_not_blocked(): void
    {
        Http::fake(['*' => Http::response(['result' => null], 200)]);
        $phase = $this->makePhase(null, null);

        try {
            $this->buy($phase);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('phase has', $e->getMessage());
        }
    }
}
