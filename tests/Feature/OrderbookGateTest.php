<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE - Orderbook Gate Tests
 * Internal order book ต้องปิดโดย default (กัน order ที่ match ใน DB
 * แต่ไม่ settle บนเชนจริง) จนกว่าจะเปิดผ่าน SiteSetting trading.orderbook_enabled
 * Developed by Xman Studio.
 */
class OrderbookGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payload ที่ผ่าน validation ของ createOrder
     */
    private function validOrderPayload(): array
    {
        return [
            'wallet_address' => '0x'.str_repeat('ab', 20),
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'type' => 'market',
            'amount' => 0.5,
            'total' => 100,
            'chain_id' => 56,
        ];
    }

    /**
     * ค่า default: order book ปิด → ตอบ 503 ORDERBOOK_COMING_SOON เสมอ
     * (แม้จะตั้ง fee_collector_wallet แล้วก็ตาม)
     */
    public function test_order_creation_is_blocked_while_orderbook_disabled(): void
    {
        SiteSetting::set('trading', 'fee_collector_wallet', '0x'.str_repeat('cd', 20));

        $response = $this->withoutMiddleware(VerifyWalletOwnership::class)
            ->postJson('/api/v1/trading/order', $this->validOrderPayload());

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ORDERBOOK_COMING_SOON');
    }

    /**
     * เปิด flag แล้ว gate ต้องปล่อยผ่านไปเจอเช็คตัวถัดไป
     * (ในเทสนี้ fee_collector ว่าง → ต้องเจอ PLATFORM_NOT_READY ไม่ใช่ COMING_SOON)
     */
    public function test_orderbook_flag_reopens_order_creation_path(): void
    {
        SiteSetting::set('trading', 'orderbook_enabled', '1');

        $response = $this->withoutMiddleware(VerifyWalletOwnership::class)
            ->postJson('/api/v1/trading/order', $this->validOrderPayload());

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'PLATFORM_NOT_READY');
    }
}
