<?php

namespace Tests\Unit\AiBot;

use App\Models\MarketNews;
use App\Services\AiBot\MarketRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ด่านความเสี่ยงคือสิ่งเดียวที่กันบอทไม่ให้รับมีดตก.
 *
 * กลยุทธ์เกือบทุกตัว "ซื้อตอนราคาถูก" — ในวันที่ตลาดพังจริงมันจะเห็นของถูกตลอดทาง
 * แล้วซื้อไม่หยุดจนพอร์ตหมด ด่านนี้จึงต้องมาก่อนกลยุทธ์เสมอ และต้องแม่น
 *
 * Developed by Xman Studio.
 */
class MarketRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketRiskService $risk;

    protected function setUp(): void
    {
        parent::setUp();

        // ผลข่าวถูกแคช 60 วิ — ต้องล้างไม่ให้เทสต์ก่อนหน้ารั่วมาถึงเทสต์นี้
        Cache::flush();

        $this->risk = app(MarketRiskService::class);
    }

    /**
     * สร้างแท่งเทียนราคานิ่ง — ใช้เป็นฉากหลังที่ "ไม่มีอะไรเกิดขึ้น".
     *
     * @return list<array<string, float|int>>
     */
    private function calmCandles(int $count = 60, float $price = 100.0): array
    {
        $candles = [];

        for ($i = 0; $i < $count; $i++) {
            // แกว่งเล็กน้อยแบบมีแบบแผน เพื่อให้ ATR ไม่เป็นศูนย์แต่ก็ไม่ผิดปกติ
            $wobble = ($i % 2 === 0) ? 0.2 : -0.2;
            $close = $price + $wobble;

            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $price,
                'high' => $close + 0.3,
                'low' => $close - 0.3,
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        return $candles;
    }

    // ─────────────────────── การแปลงคะแนนเป็นระดับ ───────────────────────

    #[Test]
    public function it_maps_scores_to_the_right_risk_level(): void
    {
        $this->assertSame('calm', $this->risk->levelFor(0.0));
        $this->assertSame('calm', $this->risk->levelFor(0.34));
        $this->assertSame('caution', $this->risk->levelFor(0.35));
        $this->assertSame('caution', $this->risk->levelFor(0.59));
        $this->assertSame('elevated', $this->risk->levelFor(0.6));
        $this->assertSame('elevated', $this->risk->levelFor(0.79));
        $this->assertSame('panic', $this->risk->levelFor(0.8));
        $this->assertSame('panic', $this->risk->levelFor(1.0));
    }

    #[Test]
    public function a_quiet_market_with_no_news_is_calm_and_allows_full_size(): void
    {
        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $this->assertSame('calm', $result['level']);
        $this->assertSame(1.0, $result['size_multiplier']);
        $this->assertFalse($result['force_exit']);
    }

    // ─────────────────────── ความเสี่ยงจากราคา ───────────────────────

    /**
     * ราคาร่วงแรงใน 1 ชั่วโมง = เข้าภาวะตื่นตระหนก ต้องบังคับเทออก.
     */
    #[Test]
    public function a_sharp_hourly_crash_forces_an_exit(): void
    {
        $candles = $this->calmCandles();
        $last = count($candles) - 1;

        // ร่วง 10% ในแท่งสุดท้าย (เกณฑ์ panic คือ -7%)
        $candles[$last]['close'] = 90.0;
        $candles[$last]['low'] = 89.0;

        $result = $this->risk->assess('BTC/USDT', $candles);

        $this->assertSame('panic', $result['level']);
        $this->assertTrue($result['force_exit'], 'ตลาดร่วงแรงต้องสั่งเทออก');
        $this->assertSame(0.0, $result['size_multiplier'], 'ห้ามเข้าไม้ใหม่ตอนตลาดพัง');
        $this->assertNotEmpty($result['reasons']);
    }

    /**
     * ย่อพอประมาณ = ลดขนาดไม้ แต่ยังไม่ต้องเท.
     */
    #[Test]
    public function a_moderate_dip_only_reduces_position_size(): void
    {
        $candles = $this->calmCandles();
        $last = count($candles) - 1;

        $candles[$last]['close'] = 95.5;   // ประมาณ -4.5% เข้าเกณฑ์ caution
        $candles[$last]['low'] = 95.0;

        $result = $this->risk->assess('BTC/USDT', $candles);

        $this->assertSame('caution', $result['level']);
        $this->assertFalse($result['force_exit'], 'ย่อธรรมดายังไม่ต้องเทของทิ้ง');
        $this->assertGreaterThan(0.0, $result['size_multiplier'], 'ยังเข้าไม้ได้ แค่เล็กลง');
        $this->assertLessThan(1.0, $result['size_multiplier']);
    }

    /**
     * ข้อมูลไม่พอ = ไม่เดา ต้องบอกตรงๆ ว่าประเมินไม่ได้.
     */
    #[Test]
    public function insufficient_candles_do_not_produce_a_fake_score(): void
    {
        $result = $this->risk->assess('BTC/USDT', $this->calmCandles(10));

        $this->assertFalse($result['market']['available']);
        $this->assertSame(0.0, $result['market']['score']);
    }

    /**
     * วอลุ่มพุ่งตอนราคาขึ้น ไม่ใช่เรื่องร้าย — ขาขึ้นแรงๆ วอลุ่มก็พุ่ง.
     */
    #[Test]
    public function a_volume_spike_on_the_way_up_is_not_treated_as_risk(): void
    {
        $candles = $this->calmCandles();
        $last = count($candles) - 1;

        $candles[$last]['close'] = 104.0;      // ราคาขึ้น
        $candles[$last]['high'] = 104.5;
        $candles[$last]['volume'] = 10000.0;   // วอลุ่ม 10 เท่า

        $result = $this->risk->assess('BTC/USDT', $candles);

        $this->assertFalse($result['force_exit'], 'ขาขึ้นวอลุ่มหนาไม่ควรทำให้บอทตกใจ');
    }

    // ─────────────────────── ความเสี่ยงจากข่าว ───────────────────────

    private function news(array $attributes = []): MarketNews
    {
        static $counter = 0;
        $counter++;

        return MarketNews::create(array_merge([
            'source' => 'coindesk',
            'title' => 'ข่าวทดสอบ '.$counter,
            'url_hash' => hash('sha256', 'news-'.$counter),
            'url' => 'https://example.test/'.$counter,
            'published_at' => now()->subMinutes(5),
            'panic_score' => 0.9,
            'sentiment' => -0.9,
            'symbols' => [],
            'matched_terms' => ['hack'],
        ], $attributes));
    }

    /**
     * ข่าวร้ายแรงสดๆ ต้องดันความเสี่ยงขึ้นได้เอง แม้ราคายังนิ่ง.
     *
     * นี่คือหัวใจของสิ่งที่ผู้ใช้ขอ — ให้บอทรู้ตัวก่อนราคาจะวิ่ง
     */
    #[Test]
    public function fresh_severe_news_raises_risk_even_when_price_is_quiet(): void
    {
        $this->news(['title' => 'Major exchange hack drains user funds', 'panic_score' => 0.95]);

        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $this->assertSame(0.0, $result['market']['score'], 'ราคายังนิ่ง');
        $this->assertGreaterThan(0.0, $result['news']['score'], 'แต่ข่าวต้องดันความเสี่ยงขึ้น');
        $this->assertNotSame('calm', $result['level']);
    }

    /**
     * ⭐ ใช้ค่าที่สูงกว่า ไม่ใช่ค่าเฉลี่ย.
     *
     * ถ้าเฉลี่ย ข่าว 0.95 กับราคานิ่ง 0.0 จะได้ 0.475 = แค่ caution
     * แปลว่าสัญญาณอันตรายถูกกลบจนบอทเดินเข้ากองไฟ
     */
    #[Test]
    public function the_higher_of_price_and_news_risk_wins_instead_of_the_average(): void
    {
        // ข่าวระดับตลาด (exchange) — ข่าวโปรโตคอลเดียวที่ไม่แท็กเหรียญถูกลดน้ำหนักโดยตั้งใจ
        $this->news(['title' => 'Major exchange hack drains user funds', 'panic_score' => 0.95]);

        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $average = (0.95 + 0.0) / 2;
        $this->assertGreaterThan($average, $result['score'], 'การเฉลี่ยจะกลบสัญญาณข่าวร้าย');
    }

    /**
     * ข่าวเก่ากว่าช่วงที่กำหนดต้องไม่ถูกนับ — ข่าวเมื่อวานไม่ใช่ความเสี่ยงวันนี้.
     */
    #[Test]
    public function stale_news_is_ignored(): void
    {
        config(['aibot_risk.lookback_minutes' => 180]);

        $this->news(['published_at' => now()->subMinutes(400), 'panic_score' => 1.0]);

        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $this->assertSame(0.0, $result['news']['score']);
        $this->assertSame('calm', $result['level']);
    }

    /**
     * ข่าวสดกว่าต้องมีน้ำหนักมากกว่าข่าวที่ใกล้หมดอายุ.
     */
    #[Test]
    public function fresher_news_carries_more_weight(): void
    {
        config(['aibot_risk.lookback_minutes' => 180]);

        $this->news(['published_at' => now()->subMinutes(170), 'panic_score' => 0.9]);
        $stale = $this->risk->assess('BTC/USDT', $this->calmCandles())['news']['score'];

        Cache::flush();
        MarketNews::query()->delete();

        $this->news(['published_at' => now()->subMinutes(2), 'panic_score' => 0.9]);
        $fresh = $this->risk->assess('BTC/USDT', $this->calmCandles())['news']['score'];

        $this->assertGreaterThan($stale, $fresh);
    }

    /**
     * ข่าวของเหรียญอื่นต้องไม่ลากคู่ที่ไม่เกี่ยวลงไปด้วย.
     */
    #[Test]
    public function news_about_another_coin_does_not_affect_this_pair(): void
    {
        $this->news(['symbols' => ['DOGE'], 'panic_score' => 1.0]);

        $result = $this->risk->assess('ETH/USDT', $this->calmCandles());

        $this->assertSame(0.0, $result['news']['score'], 'ข่าว DOGE ไม่ควรกระทบคู่ ETH');
    }

    /**
     * ข่าวที่พูดถึงเหรียญนี้ตรงๆ ต้องหนักกว่าข่าวตลาดรวม.
     */
    #[Test]
    public function news_naming_this_coin_outweighs_market_wide_news(): void
    {
        $this->news(['symbols' => ['ETH'], 'panic_score' => 0.9]);
        $direct = $this->risk->assess('ETH/USDT', $this->calmCandles())['news']['score'];

        Cache::flush();
        MarketNews::query()->delete();

        $this->news(['symbols' => [], 'panic_score' => 0.9]);
        $broad = $this->risk->assess('ETH/USDT', $this->calmCandles())['news']['score'];

        $this->assertGreaterThan($broad, $direct);
    }

    /**
     * ข่าว BTC ถือเป็นข่าวทั้งตลาด — BTC ล้ม ลากทุกเหรียญลงเสมอ.
     */
    #[Test]
    public function bitcoin_news_is_treated_as_market_wide(): void
    {
        $this->news(['symbols' => ['BTC'], 'panic_score' => 0.9]);

        $result = $this->risk->assess('SOL/USDT', $this->calmCandles());

        $this->assertGreaterThan(0.0, $result['news']['score'], 'BTC ร่วงกระทบทุกเหรียญ');
    }

    /**
     * ⭐ ข่าวโปรโตคอลเดียวที่ไม่ได้แท็กเหรียญ ทำให้ระวังได้ แต่สั่งเทออกไม่ได้.
     *
     * บทเรียนจาก prod 21 ส.ค. – 2 ก.ย. 2026: "Cronos halts blockchain after
     * Tectonic exploit" และ "crypto card hack crashed a neobank's token" ถูกนับเป็น
     * ข่าวทั้งตลาด → บังคับขาย 11 ไม้ของบอท BTC ทั้ง 7 ตัว ทั้งที่ไม่เกี่ยวกับ BTC
     */
    #[Test]
    public function untagged_news_about_a_single_protocol_worries_the_bot_but_never_forces_an_exit(): void
    {
        $this->news([
            'title' => 'Cronos halts blockchain after $75 million lending exploit hits lending app Tectonic',
            'symbols' => [],
            'panic_score' => 0.95,
            'matched_terms' => ['exploit'],
        ]);

        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $this->assertGreaterThan(0.0, $result['news']['score'], 'ข่าวแฮกยังต้องทำให้ระวัง');
        $this->assertLessThan(0.8, $result['news']['score'], 'แต่ต้องไม่ถึงเกณฑ์ panic ด้วยตัวเอง');
        $this->assertNotSame('panic', $result['level']);
        $this->assertFalse($result['force_exit'], 'เชนอื่นโดนแฮก ไม่ใช่เหตุให้เทบิตคอยน์ทิ้ง');
        $this->assertLessThan(1.0, $result['size_multiplier'], 'ลดขนาดไม้ได้ — นั่นคือ "ระวัง"');
    }

    /**
     * ข่าวไม่แท็กเหรียญแต่เป็นเรื่องระดับตลาด (exchange ล่ม) ยังต้องสั่งเทออกได้.
     */
    #[Test]
    public function untagged_news_about_the_whole_market_still_forces_an_exit(): void
    {
        $this->news([
            'title' => 'Major exchange halts withdrawals after hack',
            'symbols' => [],
            'panic_score' => 1.0,
            'matched_terms' => ['hack', 'halts withdrawals'],
        ]);

        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $this->assertSame('panic', $result['level']);
        $this->assertTrue($result['force_exit']);
    }

    /**
     * ⭐ ต้องแยก "ข่าวเงียบ" ออกจาก "ตัวดึงข่าวพัง" ได้.
     *
     * ถ้าดูแต่จำนวนข่าวเสี่ยง เลข 0 จะกำกวม — ระบบข่าวตายแล้วไม่มีใครรู้
     * total_recent บอกว่ามีข้อมูลไหลเข้ามาไหม โดยไม่สนว่าน่ากลัวหรือเปล่า
     */
    #[Test]
    public function it_reports_total_news_volume_so_a_dead_feed_is_distinguishable(): void
    {
        // ข่าวปกติ 3 ข่าว ไม่มีข่าวไหนน่ากลัวเลย
        foreach (range(1, 3) as $i) {
            $this->news(['panic_score' => 0, 'sentiment' => 0.4, 'matched_terms' => []]);
        }

        $quiet = $this->risk->assess('BTC/USDT', $this->calmCandles())['news'];

        $this->assertSame(0, $quiet['count'], 'ไม่มีข่าวเสี่ยง');
        $this->assertSame(3, $quiet['total_recent'], 'แต่ตัวดึงข่าวยังทำงานอยู่');
        $this->assertNotNull($quiet['last_ingested_at']);

        Cache::flush();
        MarketNews::query()->delete();

        $dead = $this->risk->assess('BTC/USDT', $this->calmCandles())['news'];

        $this->assertSame(0, $dead['count']);
        $this->assertSame(0, $dead['total_recent'], 'ไม่มีข้อมูลเลย = ตัวดึงข่าวพัง');
        $this->assertNull($dead['last_ingested_at']);
    }

    /**
     * ต้องส่งพาดหัวข่าวกลับมาให้ผู้ใช้เห็นด้วยว่าบอทตัดสินใจจากอะไร.
     */
    #[Test]
    public function it_returns_the_headlines_behind_the_decision(): void
    {
        $this->news(['title' => 'Exchange halts withdrawals amid insolvency fears', 'panic_score' => 0.95]);

        $result = $this->risk->assess('BTC/USDT', $this->calmCandles());

        $this->assertNotEmpty($result['news']['headlines']);
        $this->assertSame('Exchange halts withdrawals amid insolvency fears', $result['news']['headlines'][0]['title']);
        $this->assertNotEmpty($result['reasons']);
    }
}
