<?php

namespace Tests\Unit\AiBot;

use App\Models\AiMarketView;
use App\Services\AiBot\Analyst\AnalystCalibration;
use App\Services\AiBot\Analyst\AnalystScorer;
use App\Services\MarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ให้คะแนน AI จากราคาจริง แล้วสรุปเป็นตาราง "มั่นใจเท่านี้ ถูกกี่เปอร์เซ็นต์".
 *
 * ทั้งสองชั้นนี้คือสิ่งที่แทน "ความมั่นใจที่ AI รายงานเอง" ในการตัดสินเรื่องเงิน
 * จึงต้องพิสูจน์ว่า (1) ห้ามใช้แท่งอนาคต (2) ยังไม่ถึงเวลาวัดต้องไม่นับ
 * (3) ตัวอย่างไม่พอต้องตอบ "ไม่รู้" ไม่ใช่ตัวเลขจาก 3 ครั้ง
 *
 * Developed by Xman Studio.
 */
class AnalystCalibrationTest extends TestCase
{
    use RefreshDatabase;

    /** ราคาปิดรายชั่วโมง: ขึ้น 0.5% ทุกชั่วโมงตลอด 30 วันย้อนหลัง */
    private function fakeMarket(float $stepPct = 0.5): void
    {
        $this->app->bind(MarketDataService::class, function () use ($stepPct) {
            return new class($stepPct) extends MarketDataService
            {
                public function __construct(private float $stepPct) {}

                public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
                {
                    $out = [];
                    $start = now()->subHours($limit)->startOfHour();
                    for ($i = 0; $i < $limit; $i++) {
                        $out[] = ['time' => $start->copy()->addHours($i)->getTimestamp() * 1000, 'close' => 100.0 * (1 + $this->stepPct / 100) ** $i];
                    }

                    return $out;
                }
            };
        });
    }

    /** มุมมองที่บันทึกไว้ ณ เวลาที่กำหนด (ชื่อ view() ชนกับ helper ของ Laravel TestCase) */
    private function storedView(array $coins, float $confidence, int $hoursAgo): AiMarketView
    {
        $at = now()->subHours($hoursAgo)->startOfHour();

        $view = AiMarketView::create([
            'scope' => AiMarketView::SCOPE_STRATEGIC, 'provider' => 'openai', 'model' => 'test',
            'regime' => 'neutral', 'confidence' => $confidence, 'size_multiplier' => 1.0,
            'coins' => $coins, 'shortlist' => [], 'expires_at' => $at->copy()->addHours(5),
        ]);
        $view->forceFill(['created_at' => $at])->saveQuietly();

        return $view;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['aibot_analyst.calibration.min_samples' => 3]);
    }

    #[Test]
    public function มันให้คะแนน_buy_ในตลาดขาขึ้นว่าถูกและชนะต้นทุน(): void
    {
        $this->fakeMarket();
        $this->storedView(['BTC' => ['stance' => 'buy', 'score' => 0.8]], 0.9, hoursAgo: 12);

        $calls = app(AnalystScorer::class)->score(AiMarketView::all(), 4);

        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['correct']);
        $this->assertTrue($calls[0]['beat_cost'], 'ขึ้น ~2% ใน 4 ชม. ต้องชนะต้นทุน 36 bps');
        $this->assertEqualsWithDelta(200, $calls[0]['move_bps'], 5);
    }

    #[Test]
    public function มุมมองที่ยังไม่ถึงเวลาวัดต้องถูกข้าม_ไม่ใช่ให้คะแนนด้วยราคาเดิม(): void
    {
        $this->fakeMarket();
        $this->storedView(['BTC' => ['stance' => 'buy']], 0.9, hoursAgo: 1);   // horizon 4 ชม. ยังมาไม่ถึง

        $this->assertSame([], app(AnalystScorer::class)->score(AiMarketView::all(), 4));
    }

    #[Test]
    public function exit_ในตลาดขาขึ้นคือทายผิด(): void
    {
        $this->fakeMarket();
        $this->storedView(['BTC' => ['stance' => 'exit']], 0.9, hoursAgo: 12);

        $calls = app(AnalystScorer::class)->score(AiMarketView::all(), 4);

        $this->assertFalse($calls[0]['correct']);
        $this->assertFalse($calls[0]['beat_cost']);
    }

    #[Test]
    public function ตารางนับตามช่วงความมั่นใจ_และตอบไม่รู้เมื่อตัวอย่างไม่พอ(): void
    {
        $this->fakeMarket();

        // buy ที่มั่นใจสูง 4 ครั้ง (ถูกหมดในขาขึ้น) · exit ที่มั่นใจสูง 1 ครั้ง (ไม่พอ)
        foreach ([10, 20, 30, 40] as $h) {
            $this->storedView(['BTC' => ['stance' => 'buy']], 0.85, $h);
        }
        $this->storedView(['ETH' => ['stance' => 'exit']], 0.9, 15);

        $calibration = app(AnalystCalibration::class);
        $table = $calibration->rebuild(days: 14, horizon: 4);

        $this->assertSame(4, $table['buckets']['buy']['high']['n']);
        $this->assertSame(1.0, $table['buckets']['buy']['high']['hit_rate']);
        $this->assertSame(1, $table['buckets']['exit']['high']['n']);

        $this->assertSame(1.0, $calibration->hitRate('buy', 0.85), 'ตัวอย่างพอ (≥3) → ตอบอัตราจริง');
        $this->assertNull($calibration->hitRate('exit', 0.9), 'ตัวอย่างไม่พอ → ไม่รู้');
        $this->assertNull($calibration->hitRate('buy', 0.65), 'ช่วงกลางไม่มีข้อมูล → ไม่รู้');
    }

    #[Test]
    public function ตารางอยู่ใน_cache_และหายไปเมื่อไม่มีใครสร้าง(): void
    {
        $calibration = app(AnalystCalibration::class);

        $this->assertNull($calibration->table());
        $this->assertNull($calibration->hitRate('buy', 0.9));

        $this->fakeMarket();
        $calibration->rebuild(14, 4);

        $this->assertNotNull($calibration->table());
        $this->assertArrayHasKey('built_at', $calibration->table());
    }

    /**
     * ⭐ ราคา "ณ เวลาตัดสิน" ต้องเป็นราคาปิดของแท่งที่ปิดแล้ว — ห้ามแอบใช้แท่งที่ยังวิ่งอยู่.
     *
     * เดิมคีย์ราคาด้วยเวลาเปิดแท่ง → คำตัดสินตอน 12:00 ได้ราคาปิดของแท่ง 12:00–13:00
     * (ราคาในอนาคต 1 ชม.) ฉากนี้ราคากระโดดในแท่งที่เปิดตรงเวลาตัดสินพอดี:
     * แบบเดิมได้ move 0 (ทั้งสองขาเห็นราคาหลังกระโดดแล้ว) · แบบถูกต้องได้ +1000 bps
     */
    #[Test]
    public function ราคา_ณ_เวลาตัดสินต้องมาจากแท่งที่ปิดแล้วเท่านั้น(): void
    {
        $at = now()->subHours(12)->startOfHour();

        $this->app->bind(MarketDataService::class, fn () => new class($at->getTimestamp()) extends MarketDataService
        {
            public function __construct(private int $jumpOpensAt) {}

            public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
            {
                $out = [];
                $start = now()->subHours($limit)->startOfHour();

                for ($i = 0; $i < $limit; $i++) {
                    $open = $start->copy()->addHours($i)->getTimestamp();
                    $out[] = ['time' => $open * 1000, 'close' => $open >= $this->jumpOpensAt ? 110.0 : 100.0];
                }

                return $out;
            }
        });

        $this->storedView(['BTC' => ['stance' => 'buy']], 0.9, hoursAgo: 12);

        $calls = app(AnalystScorer::class)->score(AiMarketView::all(), 4);

        $this->assertCount(1, $calls);
        $this->assertEqualsWithDelta(1000, $calls[0]['move_bps'], 1, 'ราคาเริ่มต้องเป็นของก่อนกระโดด (100) ไม่ใช่หลังกระโดด');
        $this->assertTrue($calls[0]['correct']);
    }

    #[Test]
    public function ตารางเก็บจำนวนตัวอย่างของ_brier_ไว้ตัดสินอำนาจ(): void
    {
        $this->fakeMarket();

        foreach ([30, 40, 50] as $h) {
            $this->storedView(['BTC' => ['stance' => 'buy', 'p_up' => 0.9]], 0.7, $h);
        }
        $this->storedView(['ETH' => ['stance' => 'buy']], 0.7, 60);   // รุ่นเก่า ไม่มี p_up

        $table = app(AnalystCalibration::class)->rebuild(days: 14, horizon: 24);

        $this->assertSame(24, $table['horizon']);
        $this->assertSame(3, $table['brier_samples'], 'นับเฉพาะคำตัดสินที่มี p_up');
        $this->assertEqualsWithDelta(0.01, $table['brier'], 0.0001, 'ขาขึ้นทุกครั้ง p_up 0.9 → (0.9−1)² = 0.01');
    }

    /**
     * ⭐ อำนาจของ AI มาจากฝีมือที่วัดได้ — ยังวัดไม่ได้ · แย่กว่าโยนเหรียญ · ดีกว่าโยนเหรียญ.
     */
    #[Test]
    public function คำตัดสินฝีมือ_ai_สามระดับ(): void
    {
        config(['aibot_analyst.authority.min_samples' => 60, 'aibot_analyst.authority.max_brier' => 0.25]);
        $calibration = app(AnalystCalibration::class);

        $this->assertSame('unproven', $calibration->skill()['verdict'], 'ยังไม่เคยสร้างตาราง = ยังไม่รู้');

        $seed = fn (?float $brier, int $n) => Cache::put(AnalystCalibration::CACHE_KEY, [
            'built_at' => now()->toIso8601String(), 'days' => 14, 'horizon' => 24, 'samples' => $n,
            'brier' => $brier, 'brier_samples' => $n, 'buckets' => [],
        ], now()->addHour());

        $seed(0.40, 59);
        $this->assertSame('unproven', $calibration->skill()['verdict'], 'แย่แค่ไหนแต่ตัวอย่างไม่พอ = ยังไม่ตัดสิน');

        // ตัวเลขจริงของออดิท R3: Brier 0.292 จาก 260 คำตัดสิน
        $seed(0.292, 260);
        $skill = $calibration->skill();
        $this->assertSame('no_skill', $skill['verdict']);
        $this->assertStringContainsString('โยนเหรียญ', $skill['reason']);

        $seed(0.25, 100);
        $this->assertSame('no_skill', $calibration->skill()['verdict'], 'เท่ากับตอบ 0.5 ทุกครั้ง = ไม่มีฝีมือ');

        $seed(0.21, 100);
        $this->assertSame('skilled', $calibration->skill()['verdict']);
    }

    #[Test]
    public function brier_ให้_0_เมื่อความน่าจะเป็นถูกต้องสมบูรณ์(): void
    {
        $calls = [
            ['stance' => 'buy', 'p_up' => 1.0, 'move_bps' => 50, 'correct' => true],
            ['stance' => 'buy', 'p_up' => 0.0, 'move_bps' => -50, 'correct' => false],
        ];

        $this->assertSame(0.0, AnalystScorer::brier($calls));
        $this->assertNull(AnalystScorer::brier([['stance' => 'buy', 'p_up' => null, 'move_bps' => 1, 'correct' => true]]));
    }
}
