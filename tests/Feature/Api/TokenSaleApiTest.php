<?php

namespace Tests\Feature\Api;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TPIX TRADE - Token Sale API Tests
 * ทดสอบ API endpoints สำหรับระบบขายเหรียญ TPIX
 * Developed by Xman Studio.
 */
class TokenSaleApiTest extends TestCase
{
    use RefreshDatabase;

    private TokenSale $sale;

    private SalePhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // สร้างข้อมูล Token Sale สำหรับทดสอบ
        $this->sale = TokenSale::create([
            'name' => 'TPIX Token Sale',
            'slug' => 'tpix-token-sale',
            'description' => 'TPIX Token Sale Round 1',
            'total_supply_for_sale' => '700000000',
            'total_sold' => '0',
            'total_raised_usd' => '0',
            'accept_currencies' => ['BNB', 'USDT'],
            'accept_chain_id' => 56,
            'sale_wallet_address' => '0x1234567890123456789012345678901234567890',
            'status' => 'active',
        ]);

        // สร้าง phase สำหรับทดสอบ
        $this->phase = SalePhase::create([
            'token_sale_id' => $this->sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 1,
            'price_usd' => '0.10',
            'allocation' => '350000000',
            'sold' => '0',
            'min_purchase' => '100',
            'max_purchase' => '1000000',
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 180,
            'vesting_tge_percent' => '25.00',
            'whitelist_only' => false,
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // GET /api/v1/token-sale — ดึงข้อมูลรอบขาย
    // =========================================================================

    public function test_get_active_token_sale(): void
    {
        $response = $this->getJson('/api/v1/token-sale');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'TPIX Token Sale')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id', 'name', 'description', 'status',
                    'total_supply', 'total_sold', 'total_raised_usd',
                    'percent_sold', 'accept_currencies',
                    'phases' => [
                        '*' => [
                            'id', 'name', 'price_usd', 'allocation',
                            'sold', 'percent_sold', 'remaining',
                            'status', 'vesting_tge_percent',
                        ],
                    ],
                ],
            ]);
    }

    public function test_get_token_sale_when_none_active(): void
    {
        // ปิด sale ที่มีอยู่
        $this->sale->update(['status' => 'completed']);
        Cache::flush();

        $response = $this->getJson('/api/v1/token-sale');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    // =========================================================================
    // GET /api/v1/token-sale/stats — สถิติรอบขาย
    // =========================================================================

    public function test_get_sale_stats(): void
    {
        $response = $this->getJson('/api/v1/token-sale/stats');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_supply', 'total_sold', 'total_raised_usd',
                    'percent_sold', 'buyers_count', 'phases',
                ],
            ]);
    }

    public function test_stats_reflect_purchases(): void
    {
        // สร้างรายการซื้อ
        SaleTransaction::create([
            'token_sale_id' => $this->sale->id,
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => '0xabcdef0123456789abcdef0123456789abcdef01',
            'payment_currency' => 'USDT',
            'payment_amount' => '1000',
            'payment_usd_value' => '1000.00',
            'tpix_amount' => '10000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('ab', 32),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        $this->sale->update(['total_sold' => 10000, 'total_raised_usd' => 1000]);
        Cache::flush();

        $response = $this->getJson('/api/v1/token-sale/stats');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(10000, $data['total_sold']);
        $this->assertEquals(1000, $data['total_raised_usd']);
        $this->assertEquals(1, $data['buyers_count']);
    }

    // =========================================================================
    // POST /api/v1/token-sale/preview — คำนวณ preview
    // =========================================================================

    public function test_preview_with_usdt(): void
    {
        $response = $this->postJson('/api/v1/token-sale/preview', [
            'phase_id' => $this->phase->id,
            'currency' => 'USDT',
            'amount' => 100,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'phase', 'price_per_tpix', 'payment_amount',
                    'payment_currency', 'payment_usd_value',
                    'tpix_amount', 'currency_rate', 'remaining_in_phase',
                ],
            ]);

        // USDT = $1, ราคา $0.10 ต่อ TPIX → 100 USDT = 1000 TPIX
        $this->assertEquals(1000, $response->json('data.tpix_amount'));
    }

    public function test_preview_validation_fails(): void
    {
        $response = $this->postJson('/api/v1/token-sale/preview', [
            'phase_id' => 9999,
            'currency' => 'INVALID',
            'amount' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // =========================================================================
    // GET /api/v1/token-sale/purchases/{wallet} — ดึงรายการซื้อ
    // =========================================================================

    public function test_get_purchases_for_wallet(): void
    {
        $wallet = '0xabcdef0123456789abcdef0123456789abcdef01';

        SaleTransaction::create([
            'token_sale_id' => $this->sale->id,
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => $wallet,
            'payment_currency' => 'USDT',
            'payment_amount' => '500',
            'payment_usd_value' => '500.00',
            'tpix_amount' => '5000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('cd', 32),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/token-sale/purchases/{$wallet}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_purchases_invalid_wallet_returns_422(): void
    {
        $response = $this->getJson('/api/v1/token-sale/purchases/not-a-wallet');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_ADDRESS');
    }

    // =========================================================================
    // GET /api/v1/token-sale/vesting/{wallet} — Vesting Schedule
    // =========================================================================

    public function test_get_vesting_schedule(): void
    {
        $wallet = '0xabcdef0123456789abcdef0123456789abcdef01';

        SaleTransaction::create([
            'token_sale_id' => $this->sale->id,
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => $wallet,
            'payment_currency' => 'USDT',
            'payment_amount' => '1000',
            'payment_usd_value' => '1000.00',
            'tpix_amount' => '10000',
            'price_per_tpix' => '0.10',
            'tx_hash' => '0x'.str_repeat('ef', 32),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/token-sale/vesting/{$wallet}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_purchased', 'total_claimable',
                    'total_claimed', 'total_locked',
                ],
            ]);

        $this->assertEquals(10000, $response->json('data.total_purchased'));
    }

    public function test_vesting_invalid_wallet_returns_422(): void
    {
        $response = $this->getJson('/api/v1/token-sale/vesting/invalid');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // =========================================================================
    // Token Sale Page — ทดสอบหน้าเว็บ
    // =========================================================================

    public function test_token_sale_page_loads(): void
    {
        $response = $this->get('/token-sale');

        $response->assertStatus(200);
    }

    public function test_whitepaper_page_loads(): void
    {
        $response = $this->get('/whitepaper');

        $response->assertStatus(200);
    }

    public function test_explorer_page_loads(): void
    {
        $response = $this->get('/explorer');

        $response->assertStatus(200);
    }

    public function test_bridge_page_loads(): void
    {
        $response = $this->get('/bridge');

        $response->assertStatus(200);
    }

    public function test_staking_page_redirects_to_masternode(): void
    {
        $response = $this->get('/staking');

        $response->assertRedirect('/masternode');
    }
}
