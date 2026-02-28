<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * TPIX TRADE - Base Test Case
 * Developed by Xman Studio.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup if needed
    }

    /**
     * Create a mock wallet address.
     */
    protected function mockWalletAddress(): string
    {
        return '0x'.bin2hex(random_bytes(20));
    }

    /**
     * Create mock trading data.
     */
    protected function mockTradingPair(): array
    {
        return [
            'symbol' => 'BTC/USDT',
            'base' => 'BTC',
            'quote' => 'USDT',
            'price' => '67234.50',
            'change_24h' => '+2.45%',
            'volume_24h' => '45600000000',
        ];
    }
}
