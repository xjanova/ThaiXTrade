<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Backtest\KlineArchive;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — คลังแท่งเทียนต้องดึงให้ครบ เก็บถาวร และไม่ยิงตลาดซ้ำ.
 *
 * Developed by Xman Studio.
 */
class KlineArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/testing/klines-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** แท่ง 1h ตามรูปแบบดิบของ Binance เริ่มที่ $fromMs จำนวน $n แท่ง */
    private function binanceRows(int $fromMs, int $n, float $price = 100.0): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $fromMs + $i * 3_600_000;
            $rows[] = [$t, (string) $price, (string) ($price + 1), (string) ($price - 1), (string) ($price + $i * 0.01), '10', $t + 3_599_999, '1000', 5, '5', '500', '0'];
        }

        return $rows;
    }

    #[Test]
    public function มันไล่ดึงทีละหน้าจนครบและเก็บลงไฟล์(): void
    {
        $from = 1_700_000_000_000;
        $calls = 0;

        Http::fake(function ($request) use ($from, &$calls) {
            $calls++;
            $start = (int) $request['startTime'];

            // หน้าแรกเต็ม 1000 แท่ง หน้าสองเหลือ 200 — ตัวดึงต้องไปต่อจนหมด
            return $start === $from
                ? Http::response($this->binanceRows($from, 1000))
                : Http::response($this->binanceRows($start, 200));
        });

        $archive = new KlineArchive(app(MarketDataService::class), $this->dir);
        $to = $from + 1199 * 3_600_000;

        $candles = $archive->range('BTC/USDT', '1h', $from, $to);

        $this->assertCount(1200, $candles);
        $this->assertSame(2, $calls, 'สองหน้าพอดี');
        $this->assertSame($from, $candles[0]['time']);
        $this->assertIsFloat($candles[0]['close']);
        $this->assertFileExists($archive->path('BTC/USDT', '1h'));
    }

    #[Test]
    public function รอบที่สองใช้คลังโดยไม่ยิงตลาดเลย(): void
    {
        $from = 1_700_000_000_000;
        $calls = 0;

        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response($this->binanceRows((int) $request['startTime'], 100));
        });

        $archive = new KlineArchive(app(MarketDataService::class), $this->dir);
        $to = $from + 99 * 3_600_000;

        $archive->range('BTC/USDT', '1h', $from, $to);
        $again = $archive->range('BTC/USDT', '1h', $from, $to);

        $this->assertSame(1, $calls, 'ครั้งที่สองต้องอ่านจากคลัง');
        $this->assertCount(100, $again);

        // โหมด offline ต้องไม่ยิงแม้ขอช่วงที่ไม่มี — คืนเท่าที่มี
        $partial = $archive->range('BTC/USDT', '1h', $from, $to + 50 * 3_600_000, offline: true);
        $this->assertSame(1, $calls);
        $this->assertCount(100, $partial);
    }

    #[Test]
    public function ขอช่วงที่ต่อท้ายของเดิม_ดึงเฉพาะส่วนที่ขาด(): void
    {
        $from = 1_700_000_000_000;
        $requestedStarts = [];

        Http::fake(function ($request) use (&$requestedStarts) {
            $requestedStarts[] = (int) $request['startTime'];

            return Http::response($this->binanceRows((int) $request['startTime'], 50));
        });

        $archive = new KlineArchive(app(MarketDataService::class), $this->dir);

        $archive->range('BTC/USDT', '1h', $from, $from + 49 * 3_600_000);
        $extended = $archive->range('BTC/USDT', '1h', $from, $from + 99 * 3_600_000);

        $this->assertCount(100, $extended);
        $this->assertSame([$from, $from + 50 * 3_600_000], $requestedStarts, 'ครั้งที่สองต้องเริ่มดึงต่อจากแท่งสุดท้ายที่มี');
    }

    #[Test]
    public function ตลาดตอบไม่สำเร็จต้องล้มดัง_ไม่ใช่คืนข้อมูลครึ่งเดียว(): void
    {
        Http::fake(['*' => Http::response('rate limited', 429)]);

        $archive = new KlineArchive(app(MarketDataService::class), $this->dir);

        $this->expectException(\RuntimeException::class);
        $archive->range('BTC/USDT', '1h', 1_700_000_000_000, 1_700_000_000_000 + 10 * 3_600_000);
    }
}
