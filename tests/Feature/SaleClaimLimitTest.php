<?php

namespace Tests\Feature;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE — Sale Claim Limit Tests
 *
 * เคยมีช่องโหว่: getClaimableAmountAttribute() มีทางออก 4 ทาง แต่มีทางเดียว
 * ที่หัก claimed_amount → กด claim ซ้ำจากการซื้อครั้งเดียวได้ไม่จำกัด
 * ชุดนี้ล็อกพฤติกรรมทั้ง 4 เส้นทางไว้ ไม่ให้พลาดซ้ำ
 *
 * Developed by Xman Studio.
 */
class SaleClaimLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransaction(array $phaseAttrs, float $tpix = 1000): SaleTransaction
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale-'.uniqid(),
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
        ]);

        $phase = SalePhase::create(array_merge([
            'token_sale_id' => $sale->id,
            'name' => 'Phase',
            'slug' => 'phase-'.uniqid(),
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 200000000,
            'min_purchase' => 1,
            'max_purchase' => 10000000,
            'status' => 'active',
        ], $phaseAttrs));

        return SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => '0x'.str_repeat('cd', 20),
            'payment_currency' => 'USDT',
            'payment_amount' => 50,
            'payment_usd_value' => 50,
            'tpix_amount' => $tpix,
            'price_per_tpix' => 0.05,
            'tx_hash' => '0x'.str_repeat('1', 63).uniqid(),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);
    }

    /**
     * เฟสที่ไม่มี vesting (ค่าเริ่มต้นของ migration) — เคลมได้ครั้งเดียวเท่านั้น
     */
    public function test_phase_without_vesting_can_only_be_claimed_once(): void
    {
        $tx = $this->makeTransaction([
            'vesting_tge_percent' => 100,
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
        ]);

        $this->assertSame(1000.0, $tx->claimable_amount);

        // เคลมเต็มจำนวน
        $tx->update(['claimed_amount' => 1000]);

        $this->assertSame(0.0, $tx->fresh()->claimable_amount,
            'เคลมครบแล้วต้องเหลือ 0 ไม่ใช่กลับมาเต็มจำนวนอีก');
    }

    /**
     * ระหว่างช่วง cliff — เคลมได้แค่ส่วน TGE ครั้งเดียว ไม่ใช่ทุกครั้งที่กด
     */
    public function test_tge_portion_during_cliff_can_only_be_claimed_once(): void
    {
        $tx = $this->makeTransaction([
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
        ]);

        // อยู่ในช่วง cliff → ปลดล็อกแค่ 20% = 200
        $this->assertSame(200.0, $tx->claimable_amount);

        $tx->update(['claimed_amount' => 200]);

        $this->assertSame(0.0, $tx->fresh()->claimable_amount,
            'ช่วง cliff เคลม TGE ไปแล้วต้องไม่ได้อีก');
    }

    /**
     * เฟสถูกลบไปแล้ว — ก็ยังต้องหักยอดที่เคลมไปแล้วเช่นกัน
     */
    public function test_transaction_without_phase_still_subtracts_claimed(): void
    {
        $tx = $this->makeTransaction([
            'vesting_tge_percent' => 100,
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
        ]);

        // จำลองเฟสถูกลบ (soft delete) — ความสัมพันธ์จะคืน null
        // sale_phase_id เป็น NOT NULL จึงตัดค่าตรงๆ ไม่ได้
        $tx->phase()->delete();
        $tx->update(['claimed_amount' => 400]);
        $tx->unsetRelation('phase');

        $this->assertSame(600.0, $tx->fresh()->claimable_amount);
    }

    /**
     * หลังพ้น cliff — ทยอยปลดตามเวลา และหักส่วนที่เคลมไปแล้วเสมอ
     */
    public function test_linear_vesting_subtracts_claimed_amount(): void
    {
        $tx = $this->makeTransaction([
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
        ]);

        // ย้อนวันเริ่มไป 120 วัน = พ้น cliff 30 วันแล้ว เดินมา 90 วันจาก 180
        $tx->update(['vesting_start_at' => now()->subDays(120)]);

        $unlocked = $tx->fresh()->claimable_amount;
        // TGE 200 + ครึ่งหนึ่งของ 800 = 600
        $this->assertEqualsWithDelta(600.0, $unlocked, 10.0);

        $tx->update(['claimed_amount' => 500]);
        $this->assertEqualsWithDelta($unlocked - 500, $tx->fresh()->claimable_amount, 10.0);
    }

    /**
     * ยอดที่เคลมได้ต้องไม่เกินยอดที่ซื้อไว้ แม้ข้อมูล vesting จะเพี้ยน
     */
    public function test_claimable_never_exceeds_purchased_amount(): void
    {
        $tx = $this->makeTransaction([
            'vesting_tge_percent' => 150, // ค่าเพี้ยน
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 180,
        ]);

        $this->assertLessThanOrEqual(1000.0, $tx->claimable_amount);
    }
}
