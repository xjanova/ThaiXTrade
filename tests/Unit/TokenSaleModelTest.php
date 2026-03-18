<?php

namespace Tests\Unit;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE - Token Sale Model Tests
 * ทดสอบ Models: TokenSale, SalePhase, SaleTransaction
 * Developed by Xman Studio.
 */
class TokenSaleModelTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // TokenSale Model
    // =========================================================================

    public function test_token_sale_percent_sold_calculation(): void
    {
        $sale = TokenSale::create([
            'name' => 'Test Sale',
            'slug' => 'test-sale',
            'total_supply_for_sale' => '1000',
            'total_sold' => '250',
            'total_raised_usd' => '25',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $this->assertEquals(25.0, $sale->percent_sold);
    }

    public function test_token_sale_percent_sold_zero_supply(): void
    {
        $sale = TokenSale::create([
            'name' => 'Empty Sale',
            'slug' => 'empty-sale',
            'total_supply_for_sale' => '0',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $this->assertEquals(0, $sale->percent_sold);
    }

    public function test_token_sale_remaining(): void
    {
        $sale = TokenSale::create([
            'name' => 'Test Sale',
            'slug' => 'test-remaining',
            'total_supply_for_sale' => '1000',
            'total_sold' => '300',
            'total_raised_usd' => '30',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $this->assertEquals(700.0, $sale->remaining);
    }

    public function test_token_sale_active_scope(): void
    {
        TokenSale::create([
            'name' => 'Active Sale',
            'slug' => 'active-sale',
            'total_supply_for_sale' => '1000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        TokenSale::create([
            'name' => 'Ended Sale',
            'slug' => 'ended-sale',
            'total_supply_for_sale' => '1000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'completed',
        ]);

        $activeSales = TokenSale::active()->get();
        $this->assertCount(1, $activeSales);
        $this->assertEquals('Active Sale', $activeSales->first()->name);
    }

    // =========================================================================
    // SalePhase Model
    // =========================================================================

    public function test_phase_percent_sold(): void
    {
        $sale = TokenSale::create([
            'name' => 'Test Sale',
            'slug' => 'test-phase',
            'total_supply_for_sale' => '1000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public',
            'slug' => 'public',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '500',
            'sold' => '125',
            'min_purchase' => '10',
            'max_purchase' => '100000',
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
            'vesting_tge_percent' => '100.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);

        $this->assertEquals(25.0, $phase->percent_sold);
        $this->assertEquals(375.0, $phase->remaining_allocation);
        $this->assertFalse($phase->is_sold_out);
    }

    public function test_phase_sold_out(): void
    {
        $sale = TokenSale::create([
            'name' => 'Test Sale',
            'slug' => 'sold-out-test',
            'total_supply_for_sale' => '1000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Sold Out Phase',
            'slug' => 'sold-out',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '1000',
            'sold' => '1000',
            'min_purchase' => '10',
            'max_purchase' => '100000',
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
            'vesting_tge_percent' => '100.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);

        $this->assertTrue($phase->is_sold_out);
        $this->assertEquals(100.0, $phase->percent_sold);
        $this->assertEquals(0.0, $phase->remaining_allocation);
    }

    // =========================================================================
    // SaleTransaction Model — Vesting คำนวณ
    // =========================================================================

    public function test_transaction_uuid_auto_generated(): void
    {
        $sale = TokenSale::create([
            'name' => 'UUID Test',
            'slug' => 'uuid-test',
            'total_supply_for_sale' => '1000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Phase',
            'slug' => 'phase',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '1000',
            'sold' => '0',
            'min_purchase' => '10',
            'max_purchase' => '100000',
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
            'vesting_tge_percent' => '100.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);

        $tx = SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => '0xabcdef0123456789abcdef0123456789abcdef01',
            'payment_currency' => 'USDT',
            'payment_amount' => '100',
            'payment_usd_value' => '100.00',
            'tpix_amount' => '1000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('aa', 32),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        $this->assertNotEmpty($tx->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $tx->uuid
        );
    }

    public function test_vesting_full_tge_returns_all(): void
    {
        $sale = TokenSale::create([
            'name' => 'Vesting Test',
            'slug' => 'vesting-test',
            'total_supply_for_sale' => '10000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        // Phase ที่ TGE 100% → ได้ทันทีทั้งหมด
        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'No Vesting',
            'slug' => 'no-vesting',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '10000',
            'sold' => '0',
            'min_purchase' => '10',
            'max_purchase' => '100000',
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
            'vesting_tge_percent' => '100.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);

        $tx = SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => '0xabcdef0123456789abcdef0123456789abcdef01',
            'payment_currency' => 'USDT',
            'payment_amount' => '500',
            'payment_usd_value' => '500.00',
            'tpix_amount' => '5000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('bb', 32),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        // TGE 100% → claimable = ทั้งหมด
        $this->assertEquals(5000.0, $tx->claimable_amount);
    }

    public function test_vesting_with_cliff_returns_tge_only(): void
    {
        $sale = TokenSale::create([
            'name' => 'Cliff Test',
            'slug' => 'cliff-test',
            'total_supply_for_sale' => '10000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        // Phase ที่มี cliff 30 วัน, TGE 25%
        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Cliff Phase',
            'slug' => 'cliff-phase',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '10000',
            'sold' => '0',
            'min_purchase' => '10',
            'max_purchase' => '100000',
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'vesting_tge_percent' => '25.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);

        $tx = SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => '0xabcdef0123456789abcdef0123456789abcdef01',
            'payment_currency' => 'USDT',
            'payment_amount' => '1000',
            'payment_usd_value' => '1000.00',
            'tpix_amount' => '10000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('cc', 32),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        // ยังอยู่ใน cliff → ได้แค่ TGE 25% = 2500
        $this->assertEquals(2500.0, $tx->claimable_amount);
    }

    public function test_transaction_by_wallet_scope(): void
    {
        $sale = TokenSale::create([
            'name' => 'Scope Test',
            'slug' => 'scope-test',
            'total_supply_for_sale' => '10000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_chain_id' => 56,
            'status' => 'active',
        ]);

        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Phase',
            'slug' => 'scope-phase',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '10000',
            'sold' => '0',
            'min_purchase' => '10',
            'max_purchase' => '100000',
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
            'vesting_tge_percent' => '100.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);

        $wallet = '0xabcdef0123456789abcdef0123456789abcdef01';

        SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => $wallet,
            'payment_currency' => 'USDT',
            'payment_amount' => '100',
            'payment_usd_value' => '100.00',
            'tpix_amount' => '1000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('dd', 32),
            'status' => 'confirmed',
        ]);

        SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => '0x9999999999999999999999999999999999999999',
            'payment_currency' => 'BNB',
            'payment_amount' => '1',
            'payment_usd_value' => '600.00',
            'tpix_amount' => '6000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('ee', 32),
            'status' => 'confirmed',
        ]);

        // byWallet scope ต้องกรองเฉพาะ wallet ที่ระบุ
        $results = SaleTransaction::byWallet($wallet)->get();
        $this->assertCount(1, $results);
        $this->assertEquals($wallet, $results->first()->wallet_address);
    }
}
