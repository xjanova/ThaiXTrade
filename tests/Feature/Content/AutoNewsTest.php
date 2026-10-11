<?php

namespace Tests\Feature\Content;

use App\Console\Commands\GenerateScheduledContent;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Services\AiTextService;
use App\Services\Discord\DiscordLiveData;
use App\Services\SiteKnowledgeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทเขียนข่าวต้องเขียนจากเรื่องที่เกิดขึ้นจริง.
 *
 * เจ้าของ (2026-10-11): "ระบบเขียนข่าว การอัพเดท อัตโนมัติ ไม่เคยเขียนข่าวที่ถูกเลยเหมือนกันหมด
 * เช่นเมื่อวาน ดีพลอยเหรียญแล้วก็ควร เขียนให้น่าตื่นเต้น บอทไม่เขียนเหรอ"
 *
 * ที่เจอบน prod: บอทสุ่มหัวข้อจากรายการตายตัว ~28 หัวข้อ (liquidity pool ซ้ำ 3 รอบ) ไม่รู้ว่า DEX เปิดแล้ว
 * มีบทความ "Untitled" หลุดขึ้นเว็บ และแท็บตั้งค่าในหลังบ้านบันทึกไม่ได้มาตั้งแต่ 2026-03-24
 *
 * ห้ามหลุด:
 *   1. DEX เปิด = ข่าวทั้งไทยและอังกฤษ เผยแพร่พร้อมกัน — ครั้งเดียว
 *   2. ที่อยู่สัญญาในบทความมาจากทะเบียนสัญญาเท่านั้น ที่อยู่ที่ AI พิมพ์เองถูกลบ
 *   3. AI ตอบพัง = ไม่มีบทความ (ไม่มี "Untitled") และไม่ลองซ้ำไม่รู้จบ
 *   4. เหตุการณ์เก่าไม่ขุดมาเขียน · บทความที่แอดมินลบไม่งอกกลับ
 *   5. บทความความรู้ไม่วนหัวข้อเดิม และหมวดตรงกับหัวข้อ
 *
 * Developed by Xman Studio.
 */
class AutoNewsTest extends TestCase
{
    private const DEX = [
        'wtpix' => '0x0A3dB168aF6b85e61D81CA78C608BDadAC832DFF',
        'usdt_tpix' => '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
        'dex_factory' => '0x94b1254acE355559611890D4EB2d07332eD1fB8A',
        'dex_router' => '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04',
    ];

    /** ที่อยู่ที่ AI "แต่ง" ใส่มาในเนื้อหา — ต้องไม่รอดขึ้นเว็บ */
    private const HALLUCINATED = '0x1234567890abcdef1234567890abcdef12345678';

    /** @var list<array{message: string, system: string}> */
    private array $prompts = [];

    /** คำตอบถัดไปของ AI — null = JSON บทความปกติ */
    private ?string $aiReply = null;

    /** @var list<string> */
    private array $pairs = ['TPIX-USDT'];

    /** จำลอง AI ที่ตอบภาษาไทยแม้ขอภาษาอังกฤษ (เกิดจริงบน prod 2026-10-11) */
    private bool $thaiOnly = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');

        Http::fake([
            'image.pollinations.ai/*' => Http::response(str_repeat("\xFF\xD8\xFF", 600), 200, ['Content-Type' => 'image/jpeg']),
            // RPC ของเชน — eth_getCode มีโค้ด = สัญญาอยู่บนเชนจริง
            '*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x6080604052']),
        ]);

        $this->mock(AiTextService::class, function ($mock) {
            $mock->shouldReceive('chat')->andReturnUsing(function (string $message, string $system) {
                $this->prompts[] = ['message' => $message, 'system' => $system];

                $english = str_contains($message, 'LANGUAGE: English');

                return ['success' => true, 'content' => $this->aiReply ?? $this->articleJson(count($this->prompts), $english && ! $this->thaiOnly), 'model' => 'gpt-test', 'provider' => 'openai'];
            });
        });

        $this->mock(DiscordLiveData::class, function ($mock) {
            $mock->shouldReceive('dexPairs')->andReturnUsing(fn () => collect($this->pairs)
                ->map(fn (string $s) => (new TradingPair())->forceFill(['symbol' => $s, 'base_token_id' => null])));
            $mock->shouldReceive('network')->andReturn(['chain_id' => 4289, 'rpc' => 'https://rpc.tpix.online', 'explorer' => 'https://explorer.tpix.online']);
        });

        $this->mock(SiteKnowledgeService::class, fn ($mock) => $mock->shouldReceive('liveFacts')->andReturn('## LIVE DATA — การขายเหรียญ: ยังไม่เปิดขาย'));

        SiteSetting::set('content', 'content_auto_publish', '1', 'boolean');
    }

    private function articleJson(int $n, bool $english = false): string
    {
        if ($english) {
            return json_encode([
                'title' => "TPIX DEX is live! #{$n}",
                'summary' => 'Trade TPIX on its own chain today at '.self::HALLUCINATED,
                'content' => '<p>'.str_repeat('TPIX DEX is now open for trading on TPIX Chain with zero gas fees. ', 8).'</p><p>The router lives at '.self::HALLUCINATED.'</p>',
                'tags' => ['TPIX DEX', 'launch'],
                'seo_title' => 'TPIX DEX is live',
                'seo_description' => 'Trade TPIX on TPIX Chain',
            ]);
        }

        return json_encode([
            'title' => "TPIX DEX เปิดแล้ว! #{$n}",
            'summary' => 'เทรด TPIX บนเชนของตัวเองได้แล้ววันนี้ ที่ '.self::HALLUCINATED,
            'content' => '<p>'.str_repeat('TPIX DEX เปิดให้เทรดบนเชน TPIX แล้ว ค่าแก๊สเป็นศูนย์ ', 12).'</p><p>สัญญา Router อยู่ที่ '.self::HALLUCINATED.'</p>',
            'tags' => ['TPIX DEX', 'launch'],
            'seo_title' => 'TPIX DEX เปิดแล้ว',
            'seo_description' => 'เทรด TPIX บนเชน TPIX',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function registerDex(): void
    {
        foreach (self::DEX as $key => $address) {
            SiteSetting::set('contracts', $key, $address);
        }
    }

    private function run_(): void
    {
        $this->artisan('content:generate-scheduled')->assertSuccessful();
    }

    public function test_dex_launch_becomes_an_exciting_news_story_in_thai_and_english(): void
    {
        $this->registerDex();

        $this->run_();

        $articles = Article::orderBy('language')->get();
        $this->assertSame(['en', 'th'], $articles->pluck('language')->all());
        $key = 'event:dex_live:'.strtolower(self::DEX['dex_router']);

        foreach ($articles as $article) {
            $this->assertSame($key, $article->source_key);
            $this->assertSame('news', $article->category);
            $this->assertSame('published', $article->status);
            $this->assertNotNull($article->cover_image);

            // ที่อยู่ทางการครบทั้ง 4 ตัว ลิงก์ไป explorer · ที่อยู่ที่ AI แต่งถูกลบทั้งในเนื้อหาและสรุป
            foreach (self::DEX as $address) {
                $this->assertStringContainsString('https://explorer.tpix.online/address/'.$address, $article->content);
            }
            $this->assertStringNotContainsString(self::HALLUCINATED, $article->content.$article->summary);
        }

        // ไทย/อังกฤษเผยแพร่เวลาเดียวกัน — บอท Discord รวมเป็นข้อความเดียว
        $this->assertEquals($articles[0]->published_at, $articles[1]->published_at);
        $this->assertStringContainsString('ดูที่อยู่ทางการท้ายบทความ', $articles->firstWhere('language', 'th')->content);
        $this->assertStringStartsWith('TPIX DEX is live!', $articles->firstWhere('language', 'en')->title);

        // AI ได้ข้อเท็จจริงของเหตุการณ์ + สถานะสด + กติกาห้ามแต่งตัวเลข/ที่อยู่
        $this->assertCount(2, $this->prompts);
        $this->assertStringContainsString('BREAKING', $this->prompts[0]['message']);
        $this->assertStringContainsString('TPIX/USDT', $this->prompts[0]['message']);
        $this->assertStringContainsString('TPIX DEX (our own AMM exchange on TPIX Chain) is LIVE', $this->prompts[0]['system']);
        $this->assertStringContainsString('Never write a contract address', $this->prompts[0]['system']);
        $this->assertStringContainsString('NOT issued by Tether', $this->prompts[0]['message']);
        $this->assertStringContainsString('ยังไม่เปิดขาย', $this->prompts[0]['system']);
        $this->assertStringContainsString('natural, modern Thai', $this->prompts[0]['system']);
    }

    public function test_the_same_event_is_never_written_twice(): void
    {
        $this->registerDex();

        $this->run_();
        $this->run_();
        $this->run_();

        $this->assertSame(2, Article::count());
        $this->assertCount(2, $this->prompts);
    }

    public function test_events_older_than_the_lookback_are_not_dug_up(): void
    {
        $this->travelTo(now()->subDays(4));
        $this->registerDex();
        $this->travelBack();

        $this->run_();

        $this->assertSame(0, Article::count());
        $this->assertSame([], $this->prompts);
    }

    public function test_a_broken_ai_answer_is_never_published_and_retries_stop_for_the_day(): void
    {
        $this->registerDex();
        $this->aiReply = '{"title":"TPIX DEX เปิดแล้ว","content":"<p>เนื้อหาถูกตัดกลา'; // JSON โดนตัดกลางทาง

        $this->run_(); // ไทย + อังกฤษ ล้ม = 2 ครั้ง
        $this->run_(); // ล้มอีก 2 = 4 ครั้ง
        $this->run_(); // ครบโควตาวันนี้แล้ว ไม่เรียก AI

        $this->assertSame(0, Article::withTrashed()->count());
        $this->assertCount(4, $this->prompts);

        // วันถัดไปลองใหม่ได้ และคำตอบดี = ได้ข่าว
        $this->travel(1)->days();
        $this->aiReply = null;
        $this->run_();
        $this->assertSame(2, Article::count());
    }

    public function test_an_english_story_written_in_thai_is_rejected_and_retried(): void
    {
        $this->registerDex();
        $this->thaiOnly = true;

        $this->run_();

        // ไทยผ่าน อังกฤษที่ออกมาเป็นไทยไม่ถูกบันทึก — คำสั่งภาษาอยู่ท้ายคำสั่งหลักทั้งสองภาษา
        $this->assertSame(['th'], Article::pluck('language')->all());
        $this->assertStringContainsString('LANGUAGE: Thai', $this->prompts[0]['message']);
        $this->assertStringContainsString('LANGUAGE: English', $this->prompts[1]['message']);

        // รอบหน้าเขียนเฉพาะภาษาที่ขาด
        $this->thaiOnly = false;
        $this->run_();
        $this->assertSame(['en', 'th'], Article::orderBy('language')->pluck('language')->all());
        $this->assertCount(3, $this->prompts);
        $this->assertStringContainsString('LANGUAGE: English', $this->prompts[2]['message']);
    }

    public function test_an_event_story_the_admin_deleted_is_not_written_again(): void
    {
        $this->registerDex();
        $this->run_();

        Article::query()->get()->each->delete();
        $this->run_();

        $this->assertSame(0, Article::count());
        $this->assertSame(2, Article::withTrashed()->count());
        $this->assertCount(2, $this->prompts);
    }

    public function test_switching_event_news_off_writes_nothing(): void
    {
        SiteSetting::set('content', 'content_event_enabled', '0', 'boolean');
        $this->registerDex();

        $this->run_();

        $this->assertSame(0, Article::count());
    }

    public function test_without_auto_publish_event_stories_wait_as_drafts(): void
    {
        SiteSetting::set('content', 'content_auto_publish', '0', 'boolean');
        $this->registerDex();

        $this->run_();

        $this->assertSame(['draft', 'draft'], Article::pluck('status')->all());
    }

    public function test_a_newly_listed_pair_gets_its_own_story_but_pairs_that_already_existed_do_not(): void
    {
        $this->run_(); // รอบแรก: จด TPIX-USDT เป็นของเดิม
        $this->assertSame(0, Article::count());

        $this->pairs = ['TPIX-USDT', 'ABC-TPIX'];
        $this->run_();

        $this->assertSame(['event:pair:ABC-TPIX'], Article::distinct()->pluck('source_key')->all());
        $this->assertSame(2, Article::count());
        $this->assertStringContainsString('ABC/TPIX', $this->prompts[0]['message']);
        // DEX ยังไม่ครบสัญญา — AI ต้องรู้ว่ายังห้ามบอกว่าเทรดบนเชน TPIX ได้
        $this->assertStringContainsString('NOT live yet', $this->prompts[0]['system']);
    }

    public function test_scheduled_articles_stay_in_their_category_and_do_not_repeat_topics(): void
    {
        SiteSetting::set('content', 'content_auto_enabled', '1', 'boolean');
        SiteSetting::set('content', 'content_time_slots', json_encode([now()->format('H:i')]));
        SiteSetting::set('content', 'content_categories', '["defi"]');
        SiteSetting::set('content', 'content_max_per_day', '20', 'number');
        SiteSetting::set('content', 'content_language', 'th');
        $defi = GenerateScheduledContent::topicPool()['defi'];

        foreach (range(1, count($defi)) as $_) {
            $this->run_();
            $this->travel(30)->seconds();
        }

        $articles = Article::orderBy('id')->get();
        $this->assertSame(array_fill(0, count($defi), 'defi'), $articles->pluck('category')->all());
        $this->assertCount(count($defi), $articles->pluck('source_key')->unique(), 'หัวข้อซ้ำก่อนใช้ครบทุกหัวข้อ');

        // ใช้ครบแล้ว — รอบถัดไปหยิบหัวข้อที่เขียนนานที่สุด
        $this->run_();
        $this->assertSame($articles->first()->source_key, Article::orderByDesc('id')->value('source_key'));
    }

    public function test_event_news_does_not_eat_the_daily_quota_of_scheduled_articles(): void
    {
        SiteSetting::set('content', 'content_auto_enabled', '1', 'boolean');
        SiteSetting::set('content', 'content_time_slots', json_encode([now()->format('H:i')]));
        SiteSetting::set('content', 'content_max_per_day', '1', 'number');
        SiteSetting::set('content', 'content_language', 'th');
        $this->registerDex();

        $this->run_();

        $this->assertSame(2, Article::where('source_key', 'like', 'event:%')->count());
        $this->assertSame(1, Article::where('source_key', 'like', 'topic:%')->count());
    }

    public function test_admin_can_save_the_content_tab(): void
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')->put('/admin/settings/content', [
            'content_auto_enabled' => true,
            'content_event_enabled' => false,
            'content_max_per_day' => 3,
            'content_language' => 'both',
            'content_auto_publish' => true,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        SiteSetting::clearCache();
        $this->assertTrue((bool) SiteSetting::get('content', 'content_auto_enabled'));
        $this->assertFalse((bool) SiteSetting::get('content', 'content_event_enabled', true));
        $this->assertSame('both', SiteSetting::get('content', 'content_language'));
    }
}
