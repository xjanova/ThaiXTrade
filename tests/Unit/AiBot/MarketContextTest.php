<?php

namespace Tests\Unit\AiBot;

use App\Models\AiMarketView;
use App\Models\Chain;
use App\Models\MarketNews;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\AiBot\Analyst\MarketContext;
use App\Services\MarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — สิ่งที่ AI "ได้เห็น" ต้องครบ ไม่เอนเอียง และซื่อสัตย์เรื่องสเกล.
 *
 * ออดิท 2 ก.ย. 2026 พบว่าบริบทเดิมเอนไปทางร้ายโดยโครงสร้าง (พาดหัวเรียงตามความน่ากลัว
 * sentiment เป็นผลรวมที่เหรียญข่าวเยอะได้ ±10) และไม่มีภาพราคาเลยนอกจาก %24 ชม.
 *
 * Developed by Xman Studio.
 */
class MarketContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seedPair('BTC');
        $this->seedPair('ETH');

        // ตลาดปลอม: ticker สองเหรียญ · แท่ง 4 ชม. ขาขึ้น · funding · fear & greed
        $this->app->bind(MarketDataService::class, fn () => new class extends MarketDataService
        {
            public function __construct() {}

            public function getTickers(?string $symbol = null): array
            {
                return [
                    ['baseAsset' => 'BTC', 'lastPrice' => '79000', 'priceChangePercent' => '1.2', 'quoteVolume' => '100000'],
                    ['baseAsset' => 'ETH', 'lastPrice' => '3000', 'priceChangePercent' => '-0.5', 'quoteVolume' => '50000'],
                ];
            }

            public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
            {
                $out = [];
                $step = $interval === '4h' ? 14_400_000 : 3_600_000;
                $start = now()->subHours($limit * ($interval === '4h' ? 4 : 1))->getTimestamp() * 1000;
                for ($i = 0; $i < $limit; $i++) {
                    $close = 100.0 * (1.002 ** $i);
                    $out[] = ['time' => $start + $i * $step, 'open' => $close, 'high' => $close * 1.003, 'low' => $close * 0.997, 'close' => $close, 'volume' => 5];
                }

                return $out;
            }

            public function getFundingRate(string $symbol): ?float
            {
                return 0.012;
            }

            public function getFearGreedIndex(): ?array
            {
                return ['value' => 71, 'label' => 'Greed'];
            }
        });
    }

    #[Test]
    public function เหรียญได้ภาพราคาเต็ม_และภาพรวมมี_fear_greed(): void
    {
        $context = app(MarketContext::class)->build(AiMarketView::SCOPE_STRATEGIC);

        $btc = collect($context['coins'])->firstWhere('symbol', 'BTC');

        foreach (['change_7d_pct', 'change_30d_pct', 'from_30d_high_pct', 'rsi_4h', 'trend', 'er', 'atr_pct_4h', 'funding_rate_pct'] as $key) {
            $this->assertArrayHasKey($key, $btc, "ภาพราคาต้องมี {$key}");
        }

        $this->assertSame('up', $btc['trend']);
        $this->assertSame(0.012, $btc['funding_rate_pct']);
        $this->assertSame(71, $context['macro']['fear_greed']['value']);
    }

    #[Test]
    public function sentiment_เป็นค่าเฉลี่ยต่อข่าว_ไม่ใช่ผลรวม(): void
    {
        $this->news('BTC rallies on ETF inflows', ['BTC'], 0.0, 0.5);
        $this->news('BTC dips slightly', ['BTC'], 0.0, -0.1);
        $this->news('BTC steady', ['BTC'], 0.0, 0.2);

        $context = app(MarketContext::class)->build(AiMarketView::SCOPE_STRATEGIC);
        $btc = collect($context['coins'])->firstWhere('symbol', 'BTC');

        $this->assertSame(3, $btc['news_count']);
        $this->assertEqualsWithDelta(0.2, $btc['sentiment_avg'], 0.001, '(0.5 − 0.1 + 0.2) / 3');
        $this->assertArrayNotHasKey('sentiment', $btc, 'ผลรวมเดิมต้องไม่ถูกส่งไปให้ AI อ่านผิดสเกล');
    }

    #[Test]
    public function พาดหัวผสมข่าวล่าสุดและข่าวดี_ไม่ใช่แค่ข่าวที่น่ากลัวที่สุด(): void
    {
        config(['aibot_analyst.scopes.strategic.max_headlines' => 8]);

        // ข่าวร้าย 12 ชิ้น (เก่า 20 ชม.) และข่าวดีล่าสุด 3 ชิ้น
        for ($i = 0; $i < 12; $i++) {
            $this->news("Exchange hack number {$i}", ['BTC'], 0.9, -0.9, minutesAgo: 1200 + $i);
        }
        $this->news('Bitcoin ETF approval lifts market', ['BTC'], 0.0, 0.9, minutesAgo: 5);
        $this->news('ETH upgrade shipped smoothly', ['ETH'], 0.0, 0.5, minutesAgo: 10);
        $this->news('Markets calm ahead of Fed', [], 0.0, 0.1, minutesAgo: 15);

        $context = app(MarketContext::class)->build(AiMarketView::SCOPE_STRATEGIC);
        $titles = array_column($context['headlines'], 'title');

        $this->assertCount(8, $context['headlines']);
        $this->assertContains('Bitcoin ETF approval lifts market', $titles, 'ข่าวดีล่าสุดต้องอยู่ในบริบท');
        $this->assertContains('Markets calm ahead of Fed', $titles, 'ข่าวมหภาคที่ไม่แท็กเหรียญยังต้องผ่าน');
        $this->assertLessThan(8, count(array_filter($titles, fn ($t) => str_starts_with($t, 'Exchange hack'))), 'ข่าวร้ายต้องไม่กินโควตาทั้งหมด');
        $this->assertArrayHasKey('sentiment', $context['headlines'][0]);
    }

    #[Test]
    public function ประวัติคำตัดสินของตัวเองถูกส่งให้_ai_เมื่อวัดผลได้แล้ว(): void
    {
        $this->assertNull(app(MarketContext::class)->build(AiMarketView::SCOPE_STRATEGIC)['track_record'], 'ยังไม่มีมุมมอง = ไม่มีประวัติ');

        $at = now()->subHours(12)->startOfHour();
        $view = AiMarketView::create([
            'scope' => AiMarketView::SCOPE_STRATEGIC, 'provider' => 'openai', 'model' => 'test',
            'regime' => 'neutral', 'confidence' => 0.8, 'size_multiplier' => 1.0,
            'coins' => ['BTC' => ['score' => 0.6, 'stance' => 'buy']], 'shortlist' => [], 'expires_at' => $at->copy()->addHours(5),
        ]);
        $view->forceFill(['created_at' => $at])->saveQuietly();

        $record = app(MarketContext::class)->build(AiMarketView::SCOPE_STRATEGIC)['track_record'];

        $this->assertNotNull($record);
        $this->assertSame(1, $record['by_stance']['buy']['n']);
        $this->assertSame(100.0, $record['by_stance']['buy']['correct_pct'], 'ตลาดขาขึ้น buy ต้องถูก');
        $this->assertSame('BTC', $record['recent'][0]['symbol']);
        $this->assertTrue($record['recent'][0]['correct']);
    }

    // ── ตัวช่วย ───────────────────────────────────────────────────────────────

    private function news(string $title, array $symbols, float $panic, float $sentiment, int $minutesAgo = 30): void
    {
        MarketNews::create([
            'source' => 'test', 'title' => $title, 'url_hash' => hash('sha256', $title), 'url' => 'https://example.test/'.md5($title),
            'published_at' => now()->subMinutes($minutesAgo), 'panic_score' => $panic, 'sentiment' => $sentiment,
            'symbols' => $symbols, 'matched_terms' => [],
        ]);
    }

    private function seedPair(string $base): void
    {
        $chain = Chain::firstOrCreate(['chain_id' => 4289], [
            'name' => 'TPIX Chain', 'symbol' => 'TPIX', 'rpc_url' => 'https://rpc.example',
            'native_currency_name' => 'TPIX', 'native_currency_symbol' => 'TPIX', 'is_active' => true,
        ]);
        $baseToken = Token::firstOrCreate(['symbol' => $base, 'chain_id' => $chain->id], [
            'name' => $base, 'decimals' => 18, 'is_active' => true, 'contract_address' => '0x'.substr(hash('sha256', $base), 0, 40),
        ]);
        $quote = Token::firstOrCreate(['symbol' => 'USDT', 'chain_id' => $chain->id], [
            'name' => 'Tether', 'decimals' => 6, 'is_active' => true, 'contract_address' => '0x'.substr(hash('sha256', 'USDT'), 0, 40),
        ]);
        TradingPair::firstOrCreate(['symbol' => "{$base}/USDT"], [
            'base_token_id' => $baseToken->id, 'quote_token_id' => $quote->id, 'chain_id' => $chain->id, 'is_active' => true,
        ]);
    }
}
