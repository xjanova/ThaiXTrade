<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE - Sparkline API Tests.
 *
 * GET /api/v1/market/sparklines — เส้นกราฟย่อหลายคู่ในคำขอเดียว
 * ใช้ในรายการคู่เทรด (PairSelector) และหน้า Markets
 *
 * Developed by Xman Studio.
 */
class MarketSparklineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // แต่ละเทสต์ต้องเริ่มจาก cache เปล่า ไม่งั้นผลของเทสต์ก่อนรั่วข้ามมา
        Cache::flush();
    }

    /** สร้าง kline row ตามรูปแบบของ Binance (close อยู่ index 4) */
    private function klines(array $closes): array
    {
        return array_map(
            fn ($c) => [0, '0', '0', '0', (string) $c, '0', 0, '0', 0, '0', '0', '0'],
            $closes,
        );
    }

    public function test_returns_close_series_for_each_requested_symbol(): void
    {
        Http::fake([
            '*symbol=BTCUSDT*' => Http::response($this->klines([100, 110, 120])),
            '*symbol=ETHUSDT*' => Http::response($this->klines([10, 9, 11])),
        ]);

        $response = $this->getJson('/api/v1/market/sparklines?symbols=BTC-USDT,ETH-USDT');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'BTC-USDT' => [100.0, 110.0, 120.0],
                    'ETH-USDT' => [10.0, 9.0, 11.0],
                ],
            ]);
    }

    public function test_rejects_symbols_that_are_not_canonical_base_quote(): void
    {
        Http::fake();

        $response = $this->getJson('/api/v1/market/sparklines?symbols=../etc/passwd,BTC/USDT,,%20');

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'data' => []]);

        // ไม่มีอะไรถูกส่งต่อไปยัง Binance เลย
        Http::assertNothingSent();
    }

    public function test_returns_empty_series_when_upstream_has_no_market(): void
    {
        // TPIX ยังไม่ลิสต์บน Binance — ต้องได้ [] ไม่ใช่ error
        Http::fake(['*' => Http::response([], 400)]);

        $response = $this->getJson('/api/v1/market/sparklines?symbols=TPIX-USDT');

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'data' => ['TPIX-USDT' => []]]);
    }

    public function test_caps_the_number_of_symbols_per_request(): void
    {
        Http::fake(['*' => Http::response($this->klines([1, 2]))]);

        $symbols = collect(range(1, 60))->map(fn ($i) => "AA{$i}-USDT")->implode(',');

        $response = $this->getJson("/api/v1/market/sparklines?symbols={$symbols}");

        $response->assertStatus(200);
        $this->assertCount(40, $response->json('data'));
        Http::assertSentCount(40);
    }

    public function test_duplicate_symbols_are_fetched_once(): void
    {
        Http::fake(['*' => Http::response($this->klines([5, 6]))]);

        $response = $this->getJson('/api/v1/market/sparklines?symbols=BTC-USDT,btc-usdt,BTC-USDT');

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'data' => ['BTC-USDT' => [5.0, 6.0]]]);

        Http::assertSentCount(1);
    }

    public function test_falls_back_to_a_safe_interval_when_given_junk(): void
    {
        Http::fake(['*' => Http::response($this->klines([1, 2]))]);

        $this->getJson('/api/v1/market/sparklines?symbols=BTC-USDT&interval=99x&limit=9999')
            ->assertStatus(200);

        Http::assertSent(function ($request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['interval'] === '1h'      // interval ที่ไม่รู้จัก → default
                && (int) $query['limit'] === 96;    // limit ถูกจำกัดที่ 96
        });
    }
}
