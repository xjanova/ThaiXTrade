<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Analyst\CoinSnapshot;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ภาพราคาที่ AI ได้เห็นต้องมาจากแท่งจริง และหายไปอย่างซื่อสัตย์เมื่อไม่มีข้อมูล.
 *
 * Developed by Xman Studio.
 */
class CoinSnapshotTest extends TestCase
{
    /** แท่ง 4 ชม. n แท่ง ขึ้น 0.3% ต่อแท่ง + แท่งที่ยังวิ่งอยู่ปิดท้าย */
    private function bindMarket(int $bars, ?float $funding = 0.01, bool $throw = false): void
    {
        $this->app->bind(MarketDataService::class, fn () => new class($bars, $funding, $throw) extends MarketDataService
        {
            public function __construct(private int $bars, private ?float $funding, private bool $throw) {}

            public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('ตลาดล่ม');
                }

                $out = [];
                for ($i = 0; $i < $this->bars; $i++) {
                    $close = 100.0 * (1.003 ** $i);
                    $out[] = ['time' => 1_700_000_000_000 + $i * 14_400_000, 'open' => $close, 'high' => $close * 1.004, 'low' => $close * 0.996, 'close' => $close, 'volume' => 10];
                }

                return $out;
            }

            public function getFundingRate(string $symbol): ?float
            {
                return $this->funding;
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function ขาขึ้นให้ตัวเลขที่อ่านแล้วเข้าใจตรงกัน(): void
    {
        $this->bindMarket(200);

        $s = app(CoinSnapshot::class)->for('BTC');

        $this->assertGreaterThan(0, $s['change_7d_pct']);
        $this->assertGreaterThan($s['change_7d_pct'], $s['change_30d_pct'], '30 วันต้องขึ้นมากกว่า 7 วันในเทรนด์ต่อเนื่อง');
        $this->assertLessThanOrEqual(0, $s['from_30d_high_pct'], 'ห่างจากจุดสูงสุดต้องไม่เป็นบวก');
        $this->assertGreaterThan(0, $s['from_30d_low_pct']);
        $this->assertSame('up', $s['trend']);
        $this->assertGreaterThan(0.9, $s['er']);
        $this->assertGreaterThan(50, $s['rsi_4h']);
        $this->assertGreaterThan(0, $s['atr_pct_4h']);
        $this->assertSame(0.01, $s['funding_rate_pct']);
    }

    #[Test]
    public function แท่งไม่พอหรือตลาดล่มต้องได้ค่าว่าง_ไม่ใช่เลขปลอมและไม่ระเบิด(): void
    {
        $this->bindMarket(20);
        $thin = app(CoinSnapshot::class)->technicals('BTC');
        $this->assertNull($thin['trend']);
        $this->assertNull($thin['change_7d_pct']);

        $this->bindMarket(200, throw: true);
        $down = app(CoinSnapshot::class)->technicals('BTC');
        $this->assertNull($down['rsi_4h']);
    }

    #[Test]
    public function ผลถูกแคชต่อเหรียญ_ไม่ยิงตลาดซ้ำในรอบเดียวกัน(): void
    {
        $calls = 0;
        $this->app->bind(MarketDataService::class, fn () => new class($calls) extends MarketDataService
        {
            public static int $hits = 0;

            public function __construct(int &$calls) {}

            public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
            {
                self::$hits++;
                $out = [];
                for ($i = 0; $i < 120; $i++) {
                    $out[] = ['time' => $i * 14_400_000, 'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100 + $i * 0.1, 'volume' => 1];
                }

                return $out;
            }

            public function getFundingRate(string $symbol): ?float
            {
                return null;
            }
        });

        $snapshot = app(CoinSnapshot::class);
        $first = $snapshot->for('ETH');
        $second = $snapshot->for('ETH');

        $this->assertSame($first, $second);
        $this->assertSame(1, get_class(app(MarketDataService::class))::$hits, 'เรียกครั้งที่สองต้องมาจากแคช');
    }
}
