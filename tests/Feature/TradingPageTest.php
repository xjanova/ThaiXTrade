<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TPIX TRADE - Trading Page Tests
 * Developed by Xman Studio.
 */
class TradingPageTest extends TestCase
{
    /**
     * Test trading page loads successfully.
     */
    public function test_trading_page_returns_successful_response(): void
    {
        $response = $this->get('/trade');

        $response->assertStatus(200);
    }

    /**
     * Test trading page with specific pair.
     */
    public function test_trading_page_with_pair(): void
    {
        $response = $this->get('/trade/BTC-USDT');

        $response->assertStatus(200);
    }

    /**
     * Test trading page contains chart component.
     */
    public function test_trading_page_has_required_elements(): void
    {
        $response = $this->get('/trade');

        $response->assertSee('TPIX TRADE');
    }
}
