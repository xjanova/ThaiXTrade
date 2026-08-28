<?php

namespace Tests\Unit\AiBot;

use App\Models\MarketNews;
use App\Services\AiBot\NewsFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ข่าวต้องครอบคลุมทุกเหรียญที่เปิดเทรด.
 *
 * เจ้าของสั่ง 28 ส.ค. 2026: "ใช้คู่เทรดที่มีในระบบ และนั่นทำให้บอทต้องหาข่าว
 * ให้ครบทุกเหรียญที่มีเทรด"
 *
 * วัดบน prod วันเดียวกันก่อนแก้: ข่าว 478 แถว **247 แถวไม่ถูกแท็กเหรียญเลย**
 * และ 8 เหรียญที่เปิดเทรดอยู่มีข่าว 0 ข่าวตลอด 14 วัน — ด่านข่าวตาบอดสำหรับ
 * เหรียญพวกนั้น และ AI จะจัดอันดับมันไม่ได้เลย
 *
 * ความเสี่ยงที่ใหญ่ที่สุดของการเติมเหรียญคือ **คำพ้อง** — ตัวย่อครึ่งหนึ่งเป็น
 * คำอังกฤษปกติ ใส่มั่วแล้วข่าวการเมืองหนึ่งข่าวจะถูกแท็กเป็น 5 เหรียญ
 *
 * Developed by Xman Studio.
 */
class CoinNewsCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** พาดหัวที่ตัวปลอม HTTP จะตอบในการเรียกครั้งถัดไป */
    private string $nextTitle = '';

    /** สถานะ HTTP ที่จะตอบ — เปลี่ยนเป็น 503 เพื่อจำลองฟีดล่ม */
    private int $nextStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        // ตั้งครั้งเดียว แล้วเปลี่ยนเนื้อหาผ่านคุณสมบัติ — ดูเหตุผลที่ detect()
        Http::fake(fn () => Http::response(
            $this->nextStatus === 200 ? $this->rss($this->nextTitle) : '',
            $this->nextStatus,
        ));
    }

    #[Test]
    public function every_coin_has_a_news_query_except_our_own(): void
    {
        $missing = [];

        foreach (config('aibot_coins.coins') as $symbol => $coin) {
            if ($symbol === 'TPIX') {
                continue;   // เหรียญเราเอง ไม่มีสำนักข่าวไหนเขียนถึง
            }

            if (empty($coin['query'])) {
                $missing[] = $symbol;
            }
        }

        $this->assertSame([], $missing, 'เหรียญที่ไม่มีคำค้น = หาข่าวให้มันไม่ได้เลย');
    }

    #[Test]
    public function ambiguous_tickers_never_match_ordinary_english(): void
    {
        /*
         * พาดหัวจริงแบบที่เจอทุกวันในฟีดข่าว — ไม่มีอันไหนพูดถึงคริปโตสักตัว
         *
         * ถ้าเทสต์นี้แดง แปลว่ามีคนเติมตัวย่อที่เป็นคำอังกฤษปกติลง aliases
         * แล้วคะแนนความตื่นตระหนกจะรั่วข้ามเหรียญกันทั้งระบบ
         */
        $headlines = [
            'Fed keeps rates near zero, etc. analysts say',
            'Vet clinic opens near the old algo trading office',
            'Neo classical art sale draws a huge crowd',
            'Comp time policy under review at the sandbox startup',
            'Atom smasher experiment yields new data',
            'Canon EOS camera review: the flow is smooth',
            'Jupiter is closest to Earth this week',
            'Operations chief says the render farm is at capacity',
        ];

        foreach ($headlines as $headline) {
            $found = $this->detect($headline);

            $this->assertSame(
                [],
                $found,
                "พาดหัว \"{$headline}\" ไม่ควรถูกแท็กเป็นเหรียญ แต่ได้: ".implode(',', $found),
            );
        }
    }

    #[Test]
    public function real_coin_names_are_still_matched(): void
    {
        $cases = [
            'Bitcoin ETF inflows hit a record' => 'BTC',
            'Polkadot parachain auction concludes' => 'DOT',
            'Chainlink partners with a major bank' => 'LINK',
            'Optimism airdrop goes live today' => 'OP',
            'Ethereum Classic hashrate doubles' => 'ETC',
            'NEAR Protocol launches a new SDK' => 'NEAR',
        ];

        foreach ($cases as $headline => $expected) {
            $this->assertContains(
                $expected,
                $this->detect($headline),
                "พาดหัว \"{$headline}\" ควรถูกแท็กเป็น {$expected}",
            );
        }
    }

    #[Test]
    public function the_rotation_reaches_every_coin(): void
    {
        /*
         * 70 เหรียญยิงทุกรอบไม่ไหว จึงหมุนทีละชุด — แต่ต้องพิสูจน์ว่าหมุน "ครบ"
         * ไม่ใช่วนอยู่ชุดเดิม (ซึ่งเป็นสิ่งที่เกิดขึ้นถ้าเก็บตัวนับไว้ใน cache
         * แล้ว cache ถูกล้างทุกครั้งที่ deploy)
         */
        $service = app(NewsFeedService::class);
        $all = array_keys(config('aibot_coins.coins'));
        $seen = [];

        // เดินหน้าทีละ 15 นาทีให้ครบจำนวนชุดที่เป็นไปได้
        $slices = (int) ceil(count($all) / config('aibot_coins.rotation_size')) + 1;

        /*
         * ต้องยึดจากเวลาฐานคงที่ ไม่ใช่ now()->addMinutes() ทุกรอบ
         * — now() อ่านค่าที่ setTestNow ตั้งไว้รอบก่อน เวลาจึงทบกันเป็น 0,15,45,90…
         *   ข้ามชุดไปหลายชุดแล้วเทสต์จะแดงทั้งที่โค้ดจริงถูก
         */
        $base = now();

        for ($i = 0; $i < $slices; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes(15 * $i));
            $seen = array_merge($seen, $service->coinsThisRound());
        }

        Carbon::setTestNow();

        $this->assertSame([], array_diff($all, array_unique($seen)), 'มีเหรียญที่ไม่เคยถึงคิวเลย');
    }

    #[Test]
    public function priority_coins_are_always_included(): void
    {
        $service = app(NewsFeedService::class);

        // PEPE อยู่ท้ายรายการ ปกติต้องรอคิวหลายรอบ
        $coins = $service->coinsThisRound(['PEPE']);

        $this->assertContains('PEPE', $coins);
    }

    #[Test]
    public function a_per_coin_feed_tags_the_coin_it_asked_for(): void
    {
        /*
         * นี่คือทางเดียวที่ OP / NEAR / ETC จะมีข่าวได้ — เรารู้อยู่แล้วว่ายิง
         * คำค้นอะไรไป จึงแท็กจากต้นทางได้เลย ไม่ต้องเดาจากพาดหัว
         */
        $this->nextTitle = 'Optimism ecosystem grows';

        $result = app(NewsFeedService::class)->syncCoin('OP');

        $this->assertSame(1, $result['stored']);
        $this->assertContains('OP', MarketNews::first()->symbols);
    }

    #[Test]
    public function a_coin_without_a_query_is_skipped_quietly(): void
    {
        $result = app(NewsFeedService::class)->syncCoin('TPIX');

        $this->assertFalse($result['failed']);
        $this->assertSame(0, $result['fetched']);
        Http::assertNothingSent();
    }

    #[Test]
    public function one_dead_coin_feed_does_not_fail_the_round(): void
    {
        $this->nextStatus = 503;

        $result = app(NewsFeedService::class)->syncCoin('BTC');

        $this->assertTrue($result['failed']);
        $this->assertSame(0, $result['stored']);
    }

    // ── ตัวช่วย ───────────────────────────────────────────────────────────────

    /**
     * เรียก detectSymbols ผ่านเส้นทางจริง (private) โดยยัดข่าวหนึ่งชิ้นเข้าไป.
     *
     * ⚠️ ตัวปลอม HTTP ถูกตั้งครั้งเดียวใน setUp แล้วอ่านพาดหัวจาก $this->nextTitle
     *
     *    เรียก Http::fake() ซ้ำใน test เดียวกัน **ไม่ได้แทนที่ stub เดิม** — ตัวแรก
     *    ที่ URL ตรงกันยังตอบอยู่ ทำให้ทุกรอบได้ข่าวเดิม แล้ว dedupe ก็ไม่ทำงานด้วย
     *    (เจอจริงตอนเขียนเทสต์นี้: กรณี DOT แดง แต่กรณีคำพ้องเขียวโดยไม่ได้ตรวจอะไร)
     */
    private function detect(string $title): array
    {
        $this->nextTitle = $title;

        app(NewsFeedService::class)->syncCoin('SUI');   // ใช้ฟีดใดก็ได้ที่มี query

        $row = MarketNews::where('url_hash', hash('sha256', $this->urlFor($title)))->first();

        $this->assertNotNull($row, "ไม่ได้บันทึกข่าว \"{$title}\" — ตัวปลอม HTTP ไม่ทำงาน");

        // ตัดเหรียญที่ถูกแท็กจากต้นทาง (SUI) ออก — เราสนใจเฉพาะที่จับได้จากพาดหัว
        return array_values(array_diff((array) $row->symbols, ['SUI']));
    }

    private function urlFor(string $title): string
    {
        return 'https://example.test/'.md5($title);
    }

    private function rss(string $title): string
    {
        $date = now()->toRfc2822String();
        $url = 'https://example.test/'.md5($title);

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <item>
            <title>{$title}</title>
            <link>{$url}</link>
            <pubDate>{$date}</pubDate>
          </item>
        </channel></rss>
        XML;
    }
}
