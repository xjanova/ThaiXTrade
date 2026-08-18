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

    /**
     * แหล่งราคาล่มและไม่มีราคาดีเก็บไว้ → ต้องโยน exception ไม่ใช่คืน 0
     *
     * ⚠️ เดิมเมธอดนี้คืน 0.0 เงียบๆ แล้วยังแคชไว้ 30 วินาที ซึ่งเป็นต้นเหตุ
     *    ให้ระบบขายคิดยอดเป็น 0 โดยไม่มีใครรู้ — ผู้เรียกต้องรู้ว่าราคาใช้ไม่ได้
     */
    public function test_get_price_throws_when_source_fails_and_no_cached_price(): void
    {
        Http::fake([
            'api.binance.com/*' => Http::response(null, 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->getPrice('BNB');
    }

    /**
     * แหล่งราคาล่ม แต่เคยได้ราคาดีมาก่อน → ใช้ราคาดีล่าสุดประคองไว้
     * (ไม่แคชความล้มเหลว จึงกลับมาใช้ของจริงได้ทันทีที่แหล่งราคาฟื้น)
     */
    public function test_get_price_falls_back_to_last_good_price(): void
    {
        Http::fake(['api.binance.com/*' => Http::response(['price' => '600.50'], 200)]);
        $this->assertEquals(600.50, $this->service->getPrice('BNB'));

        // ล้างแคชระยะสั้น เหลือแต่ค่าดีล่าสุด แล้วทำให้แหล่งราคาล่ม
        \Illuminate\Support\Facades\Cache::forget('price_feed:BNB_usd');
        Http::fake(['api.binance.com/*' => Http::response(null, 500)]);

        $this->assertEquals(600.50, $this->service->getPrice('BNB'));
    }

    /**
     * ราคาที่กระโดดผิดปกติจากค่าดีล่าสุด → ไม่เชื่อ ใช้ค่าดีล่าสุดแทน
     * (ฟีดเพี้ยนชั่วขณะเกิดได้จริง และที่นี่แปลว่าออกเหรียญผิดทันที)
     */
    public function test_absurd_price_spike_is_rejected(): void
    {
        Http::fake(['api.binance.com/*' => Http::response(['price' => '600'], 200)]);
        $this->service->getPrice('BNB');

        \Illuminate\Support\Facades\Cache::forget('price_feed:BNB_usd');
        Http::fake(['api.binance.com/*' => Http::response(['price' => '60000'], 200)]);

        $this->assertEquals(600.0, $this->service->getPrice('BNB'));
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

    /**
     * ราคา TPIX ของรอบขายตั้งเป็น 0 = ตั้งค่าผิด → ต้องหยุด ไม่ใช่ออกเหรียญ 0 เหรียญ
     * แล้วปล่อยให้ผู้ซื้อไปตกด่านอื่นด้วยข้อความที่ไม่เกี่ยวกัน
     */
    public function test_convert_rejects_zero_tpix_price(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->convertToTpix(100, 'USDT', 0);
    }
}
