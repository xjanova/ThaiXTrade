<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TPIX TRADE - /trade/{pair} ต้องมี URL เดียวเป็นตัวพิมพ์ใหญ่
 *
 * Binance REST ปฏิเสธชื่อคู่ตัวพิมพ์เล็ก (-1121) — /trade/btc-usdt เคยเปิดได้
 * แต่กราฟ/สมุดคำสั่งว่างทั้งหน้า จึงส่งต่อแบบถาวรไปที่ตัวพิมพ์ใหญ่แทน
 *
 * Developed by Xman Studio.
 */
class TradeRouteCaseTest extends TestCase
{
    public function test_lowercase_pair_redirects_permanently_to_uppercase(): void
    {
        $this->get('/trade/btc-usdt')
            ->assertStatus(301)
            ->assertRedirect('/trade/BTC-USDT');
    }

    public function test_mixed_case_pair_redirects_and_keeps_the_query_string(): void
    {
        $this->get('/trade/Eth-usdt?ref=home')
            ->assertStatus(301)
            ->assertRedirect('/trade/ETH-USDT?ref=home');
    }

    public function test_uppercase_pair_renders_the_trade_page(): void
    {
        $this->get('/trade/BTC-USDT')->assertOk();
    }

    public function test_pair_pattern_still_rejects_garbage(): void
    {
        // regex เดิมต้องยังกันอยู่ — ไม่ใช่ทุกอย่างที่ส่งมาจะถูกพิมพ์ใหญ่แล้วส่งต่อ
        $this->get('/trade/btc_usdt')->assertNotFound();
    }
}
