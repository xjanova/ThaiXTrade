<?php

namespace Tests\Unit;

use App\Services\PriceFeedService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE - PriceFeed Service Tests
 * ทดสอบระบบดึงราคาและคำนวณ TPIX
 * Developed by Xman Studio.
 */
class PriceFeedServiceTest extends TestCase
{
    private PriceFeedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new PriceFeedService();
    }

    // =========================================================================
    // getPrice — ดึงราคา
    // =========================================================================

    public function test_stablecoins_return_one(): void
    {
        // Stablecoins ต้อง return $1.00 เสมอ
        $this->assertEquals(1.0, $this->service->getPrice('USDT'));
        $this->assertEquals(1.0, $this->service->getPrice('BUSD'));
        $this->assertEquals(1.0, $this->service->getPrice('USDC'));
        $this->assertEquals(1.0, $this->service->getPrice('DAI'));
    }

    public function test_stablecoin_case_insensitive(): void
    {
        $this->assertEquals(1.0, $this->service->getPrice('usdt'));
        $this->assertEquals(1.0, $this->service->getPrice('Busd'));
    }

    public function test_get_price_from_binance(): void
    {
        // Mock Binance API response
        Http::fake([
            'api.binance.com/*' => Http::response(['price' => '600.50'], 200),
        ]);

        $price = $this->service->getPrice('BNB');

        $this->assertEquals(600.50, $price);
    }

    public function test_get_price_returns_zero_on_api_failure(): void
    {
        Http::fake([
            'api.binance.com/*' => Http::response(null, 500),
        ]);

        $price = $this->service->getPrice('BNB');

        $this->assertEquals(0.0, $price);
    }

    // =========================================================================
    // convertToTpix — คำนวณ TPIX
    // =========================================================================

    public function test_convert_usdt_to_tpix(): void
    {
        // 100 USDT / $0.10 = 1000 TPIX
        $result = $this->service->convertToTpix(100, 'USDT', 0.10);

        $this->assertEquals(1000, $result['tpix_amount']);
        $this->assertEquals(100.0, $result['usd_value']);
        $this->assertEquals(1.0, $result['rate']);
    }

    public function test_convert_bnb_to_tpix(): void
    {
        Http::fake([
            'api.binance.com/*' => Http::response(['price' => '600.00'], 200),
        ]);

        // 1 BNB * $600 = $600 → $600 / $0.10 = 6000 TPIX
        $result = $this->service->convertToTpix(1, 'BNB', 0.10);

        $this->assertEquals(6000, $result['tpix_amount']);
        $this->assertEquals(600.0, $result['usd_value']);
        $this->assertEquals(600.0, $result['rate']);
    }

    public function test_convert_with_zero_tpix_price(): void
    {
        // ราคา TPIX = 0 → ไม่สามารถคำนวณได้
        $result = $this->service->convertToTpix(100, 'USDT', 0);

        $this->assertEquals(0, $result['tpix_amount']);
    }
}
