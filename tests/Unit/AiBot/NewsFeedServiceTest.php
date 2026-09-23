<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\NewsFeedService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — การให้คะแนนข่าวต้องแม่น เพราะมันสั่งให้บอทเทออกได้.
 *
 * ชุดทดสอบนี้เกิดจากบั๊กจริง: ใช้ str_contains กับคำว่า "ban" แล้วมันไปแมตช์กับ
 * "bankers" / "banks" / "mega-bank" — พาดหัวข่าวธนาคาร 3 ใน 5 อันดับแรกของฟีดจริง
 * ได้คะแนนตื่นตระหนก 0.70 ทั้งที่สองอันเป็นข่าวดี (UBS เพิ่มการถือบิตคอยน์)
 * ถ้าปล่อยไว้ บอทจะเทของทิ้งเพราะข่าวดี ซึ่งตรงข้ามกับที่ควรทำ
 *
 * Developed by Xman Studio.
 */
class NewsFeedServiceTest extends TestCase
{
    private NewsFeedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NewsFeedService();
    }

    /**
     * พาดหัวจริงจากฟีดวันที่เจอบั๊ก — ต้องไม่ถูกมองว่าเป็นข่าวตื่นตระหนก.
     */
    #[Test]
    #[DataProvider('headlinesThatMustNotPanic')]
    public function it_does_not_panic_on_words_that_merely_contain_a_risk_term(string $headline): void
    {
        $result = $this->service->score($headline);

        $this->assertSame(0.0, $result['panic'], "พาดหัวนี้ไม่ควรถูกนับเป็นข่าวร้าย: {$headline}");
        $this->assertSame([], $result['terms']);
    }

    /** @return array<string, array{string}> */
    public static function headlinesThatMustNotPanic(): array
    {
        return [
            // ทั้งสามอันนี้คือของจริงที่หลุดมาตอนรันฟีดครั้งแรก
            'bankers' => ["The 'long bitcoin, short the bankers' era is officially over as TradFi moves in"],
            'mega-bank ข่าวดี' => ['Swiss mega-bank UBS ramps up its Bitcoin exposure with a massive 24-fold increase'],
            'banks' => ["The stablecoin yield clash that won't go away has banks, crypto battling"],
            // คำอื่นที่เป็นสับสตริงของคำปกติ
            'freeze ใน antifreeze' => ['Antifreeze protein research lands on a blockchain registry'],
            'scam ใน scampi' => ['Scampi restaurant chain accepts bitcoin payments'],
        ];
    }

    /**
     * ข่าวร้ายของจริงต้องยังจับได้ครบ — กันการแก้บั๊กแบบเหวี่ยงแหจนไม่เหลืออะไรเลย.
     */
    #[Test]
    #[DataProvider('headlinesThatMustPanic')]
    public function it_still_flags_genuinely_bad_news(string $headline, string $expectedTerm, float $minPanic): void
    {
        $result = $this->service->score($headline);

        $this->assertContains($expectedTerm, $result['terms'], "ควรจับคำว่า '{$expectedTerm}' ได้: {$headline}");
        $this->assertGreaterThanOrEqual($minPanic, $result['panic']);
    }

    /** @return array<string, array{string, string, float}> */
    public static function headlinesThatMustPanic(): array
    {
        return [
            'breach ของจริง' => ['Crypto wallet SafePal reveals a data breach exposing nearly 40,000 customers', 'breach', 0.85],
            'scam ของจริง' => ["MiCA's cleanup is creating a new scam wave across the European Union", 'scam', 0.7],
            'ban ที่เป็นคำเดี่ยว' => ['China moves to ban all crypto mining operations nationwide', 'ban', 0.7],
            'hack' => ['Major DEX hack drains $50M from liquidity pools', 'hack', 1.0],
            'วลีสองคำ' => ['Investors lose millions in latest rug pull on Solana', 'rug pull', 0.95],
            'ขีดกลาง' => ['Broad sell-off hits crypto markets as equities tumble', 'sell-off', 0.7],
        ];
    }

    /**
     * หลายคำร้ายพร้อมกัน = หนักกว่าคำเดียว แต่ต้องไม่เกิน 1.0.
     */
    #[Test]
    public function stacking_risk_terms_increases_panic_but_stays_capped(): void
    {
        $single = $this->service->score('Exchange reports a security breach');
        $multiple = $this->service->score('Exchange hack leads to bankruptcy as it halts withdrawals amid panic');

        $this->assertGreaterThan($single['panic'], $multiple['panic']);
        $this->assertLessThanOrEqual(1.0, $multiple['panic']);
    }

    /**
     * ⭐ ข่าวหลังเหตุการณ์ (กู้คืน / ชดเชย / จับคนร้าย / คดีของปีเก่า) ลดเหลือแค่ "ระวัง".
     *
     * พาดหัวจริงจากออดิท R3 (2 → 23 ก.ย. 2026) — สองอันแรกสั่งเทออกบอททั้งฝูงไปแล้วจริงๆ
     * (คะแนน 1.00) หลังเทออก BTC ขึ้นต่อ +129 และ +801 bps
     */
    #[Test]
    #[DataProvider('aftermathHeadlines')]
    public function aftermath_stories_are_downgraded_to_caution(string $headline): void
    {
        $result = $this->service->score($headline);

        $this->assertGreaterThan(0.0, $result['panic'], 'ยังเป็นข่าวที่ควรระวัง ไม่ใช่ศูนย์');
        $this->assertLessThanOrEqual(0.5, $result['panic'], "ข่าวหลังเหตุการณ์ต้องไม่ถึงขั้นเทออก: {$headline}");
        $this->assertNotEmpty(
            array_filter($result['terms'], fn (string $t) => str_starts_with($t, '~')),
            'ต้องย้อนดูได้ว่าคะแนนถูกลดเพราะคำไหน',
        );
    }

    /** @return array<string, array{string}> */
    public static function aftermathHeadlines(): array
    {
        return [
            'กู้คืน — เทออกจริง 22 ก.ย.' => ['Whitehats move 52 bitcoin from the Coldcard hack to a recovery trust'],
            'คดีของปี 2020 — เทออกจริง 17 ก.ย.' => ['Celsius sues BitMEX for $495 million over 2020 crash liquidations'],
            'ได้เงินคืน + ค่าหัว' => ['Symbiosis says recovered 15 BTC from bridge hack, offers 20% bounty'],
            'หลายเดือนหลังเหตุ' => ['Five Months After the Kelp Hack, Aave Is Still Down $8 Billion'],
            'ชดเชยผู้เสียหาย' => ['Thorchain Opens $10M Compensation Portal After Multichain Exploit Drains Four Networks'],
            'คนร้ายรับสารภาพ' => ['Singaporean 22-year old pleads guilty to being the ringleader in $245 million crypto fraud case'],
        ];
    }

    /**
     * ⭐ ข่าววิกฤตที่กำลังเกิดต้องไม่ถูกลดคะแนนเพราะเลขที่ "ดูเหมือนปี" หรือคำกำกวม.
     *
     * รีวิว 2026-09-23 เจอ regex ปีเก่าจับราคา ($2000) และจำนวนเหรียญ (2025 BTC) —
     * ข่าวแฮกจริงถูกหารครึ่ง แล้วบอทยังเปิดไม้ใหม่ได้กลางวิกฤต
     */
    #[Test]
    #[DataProvider('liveCrisisHeadlines')]
    public function a_live_crisis_is_not_mistaken_for_an_aftermath_story(string $headline): void
    {
        $result = $this->service->score($headline);

        $this->assertGreaterThanOrEqual(0.9, $result['panic'], "ข่าวที่กำลังเกิดต้องได้น้ำหนักเต็ม: {$headline}");
        $this->assertEmpty(array_filter($result['terms'], fn (string $t) => str_starts_with($t, '~')));
    }

    /** @return array<string, array{string}> */
    public static function liveCrisisHeadlines(): array
    {
        return [
            'ราคา $2000 ไม่ใช่ปี 2000' => ['Ethereum crashes below $2000 as exchange hack drains funds'],
            'จำนวนเหรียญ 2025 BTC' => ['Exchange hacked: attackers take 2025 BTC from hot wallet'],
            'ระบบยังล่มอยู่' => ['Binance still down after exploit hits withdrawals'],
            'ฟื้นไม่ขึ้น' => ['Bitcoin fails to recover as hack losses mount'],
        ];
    }

    /** เหตุร้ายที่ "กำลังเกิด" ต้องได้น้ำหนักเต็มเหมือนเดิม — ปีปัจจุบันไม่นับเป็นข่าวเก่า */
    #[Test]
    public function a_live_incident_keeps_its_full_weight(): void
    {
        $this->assertSame(1.0, $this->service->score('Major exchange hack drains user funds')['panic']);

        $year = now()->year;
        $this->assertSame(1.0, $this->service->score("Biggest exchange hack of {$year} drains user funds")['panic']);
    }

    /**
     * ข่าวดีต้องได้ sentiment บวก ไม่ใช่แค่ panic เป็นศูนย์.
     */
    #[Test]
    public function positive_news_scores_positive_sentiment(): void
    {
        $result = $this->service->score('SEC grants approval for spot bitcoin ETF');

        $this->assertSame(0.0, $result['panic']);
        $this->assertGreaterThan(0.0, $result['sentiment']);
    }

    /**
     * ⭐ pubDate ที่เป็น UTC ต้องถูกแปลงเป็นโซนเวลาของแอพก่อนเก็บ.
     *
     * ไม่งั้นข่าวจะดูเก่ากว่าจริงเท่ากับ offset ของโซนเวลา (ไทย = 7 ชั่วโมง)
     * แล้วหน้าต่าง 180 นาทีจะไม่มีข่าวไหนผ่านเลย = ด่านข่าวตาบอด
     */
    #[Test]
    public function it_converts_feed_timestamps_into_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Bangkok']);

        $method = new \ReflectionMethod(NewsFeedService::class, 'parseDate');
        // ไม่ใส่ชื่อวันในสตริงทดสอบ — ถ้าใส่ชื่อวันผิด PHP จะเลื่อนไปวันนั้นของสัปดาห์ถัดไป
        // แล้วเทสต์จะแดงด้วยเหตุผลที่ไม่เกี่ยวกับโซนเวลาซึ่งเป็นสิ่งที่กำลังทดสอบ
        $parsed = $method->invoke($this->service, '17 Aug 2026 09:13:02 +0000');

        $this->assertSame('Asia/Bangkok', $parsed->timezone->getName());
        // 09:13 UTC = 16:13 ตามเวลาไทย
        $this->assertSame('2026-08-17 16:13:02', $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * ข่าวที่เพิ่งออกต้องนับว่า "สด" เมื่อเทียบกับ now() ของแอพ.
     */
    #[Test]
    public function a_just_published_article_is_recent_relative_to_app_now(): void
    {
        config(['app.timezone' => 'Asia/Bangkok']);

        // ข่าวที่ออกเมื่อ 10 นาทีที่แล้ว แต่ฟีดส่งมาเป็น UTC
        $raw = now('UTC')->subMinutes(10)->format('D, d M Y H:i:s O');

        $method = new \ReflectionMethod(NewsFeedService::class, 'parseDate');
        $parsed = $method->invoke($this->service, $raw);

        $ageMinutes = now()->diffInMinutes($parsed, false) * -1;

        $this->assertLessThan(
            180,
            $ageMinutes,
            "ข่าวอายุ {$ageMinutes} นาที ต้องอยู่ในหน้าต่าง 180 นาที — ถ้าเกินแปลว่าโซนเวลาเพี้ยน"
        );
    }

    /**
     * ตัวย่อเหรียญต้องเทียบแบบทั้งคำ — กัน "sol" ไปโดน "solution".
     */
    #[Test]
    #[DataProvider('symbolCases')]
    public function it_detects_only_whole_word_symbols(string $headline, array $expected): void
    {
        $this->assertSame($expected, $this->service->score($headline)['symbols']);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function symbolCases(): array
    {
        return [
            'ชื่อเต็ม' => ['Bitcoin rallies past resistance', ['BTC']],
            'ตัวย่อ' => ['BTC and ETH lead the move', ['BTC', 'ETH']],
            'sol ต้องไม่โดน solution' => ['A new scaling solution ships this week', []],
            'sol ต้องไม่โดน sold' => ['Traders sold into strength', []],
            'solana จับได้' => ['Solana network activity hits a record', ['SOL']],
        ];
    }
}
