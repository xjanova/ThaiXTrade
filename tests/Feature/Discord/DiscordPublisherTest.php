<?php

namespace Tests\Feature\Discord;

use App\Models\Article;
use App\Models\DiscordPost;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทส่งข้อมูลจากเว็บเข้า Discord (เจ้าของ: "อัพเดทข่าวสาร วีดีโอ กฎ การจำหน่ายเหรียญ ใส่ให้ครบถ้วน").
 *
 * ห้ามหลุด:
 *   1. ปิดสวิตช์ = ไม่แตะ Discord เลย
 *   2. ข้อความประจำห้อง (สถานะการขาย/กฎ) แก้ของเดิมเมื่อเปลี่ยน — ไม่โพสต์ซ้ำทุก 10 นาที
 *   3. ข้อความเดิมถูกลบ → โพสต์ใหม่แทน
 *   4. บทความเกือบ 300 ชิ้นต้องไม่ถูกเทลงห้อง — ย้อนหลังแค่ไม่กี่ชิ้น แล้วต่อด้วยของใหม่
 *   5. โพสต์ไม่สำเร็จ → รอบหน้าลองใหม่ ไม่หายเงียบ
 *   6. เนื้อหาไม่ปิงคนทั้งเซิร์ฟเวอร์ และโทเค็นไม่หลุดไปใน log
 *
 * Developed by Xman Studio.
 */
class DiscordPublisherTest extends TestCase
{
    // ห้ามแต่งให้หน้าตาเหมือนโทเค็น Discord จริง (MTI…xxxxxx.xxx) — GitHub push protection บล็อกทั้ง push
    private const TOKEN = 'test_only.not_a_real_discord_token.0123456789abcdefghijklmnop';

    private const GUILD = '111111111111111111';

    private const RULES = '222222222222222201';

    private const NEWS = '222222222222222202';

    private const SALE = '222222222222222203';

    private const VIDEOS = '222222222222222204';

    private const WHITEPAPER = '222222222222222205';

    /** @var list<string> */
    private array $logged = [];

    private int $messageSeq = 900000000000000000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Sleep::fake();

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        $settings = app(DiscordSettings::class);
        $settings->saveBotToken(self::TOKEN);
        $settings->set('discord_guild_id', self::GUILD);
        $settings->set('discord_enabled', true, 'boolean');
        $settings->set('discord_ask_enabled', false, 'boolean');
        $settings->saveChannelMap([
            'rules' => self::RULES,
            'news' => self::NEWS,
            'sale' => self::SALE,
            'videos' => self::VIDEOS,
            'whitepaper' => self::WHITEPAPER,
        ]);

        config(['discord.videos' => [
            ['code' => 'ep01', 'file' => 'ep01-foundation.mp4', 'title' => 'ตอนที่ 1', 'part' => 'Part One'],
            ['code' => 'ep02', 'file' => 'ep02-the-chain.mp4', 'title' => 'ตอนที่ 2', 'part' => 'Part Two'],
        ]]);
    }

    private function fakeDiscord(array $overrides = []): void
    {
        Http::fake(array_merge($overrides, [
            'discord.com/api/v10/channels/*/messages/*' => Http::response(['id' => 'edited']),
            'discord.com/api/v10/channels/*/messages' => fn () => Http::response(['id' => (string) $this->messageSeq++]),
            // RPC ของเชน (ด่านความพร้อมของการขาย) — ไม่มีเหรียญ = ยังไม่เปิดขาย
            '*' => Http::response(['result' => '0x0']),
        ]));
    }

    private function publisher(): DiscordPublisher
    {
        app(DiscordSettings::class)->forget();

        return app(DiscordPublisher::class);
    }

    /** @return list<Request> */
    private function discordCalls(string $method): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'discord.com') && $r->method() === $method)
            ->values()
            ->all();
    }

    private function article(string $title, string $publishedAt, string $language = 'th'): Article
    {
        return Article::create([
            'title' => $title,
            'summary' => "สรุป {$title}",
            'content' => '<p>เนื้อหา</p>',
            'language' => $language,
            'category' => 'news',
            'status' => 'published',
            'published_at' => $publishedAt,
        ]);
    }

    public function test_nothing_is_sent_while_the_bot_is_switched_off(): void
    {
        $this->fakeDiscord();
        app(DiscordSettings::class)->set('discord_enabled', false, 'boolean');

        $outcome = $this->publisher()->sync();

        $this->assertFalse($outcome['ran']);
        $this->assertSame([], $this->discordCalls('POST'));
    }

    public function test_first_sync_posts_rules_whitepaper_sale_status_and_videos_into_their_rooms(): void
    {
        $this->fakeDiscord();

        $this->publisher()->sync();

        $posts = collect($this->discordCalls('POST'));
        $this->assertTrue($posts->contains(fn (Request $r) => str_contains($r->url(), '/channels/'.self::RULES.'/messages')
            && str_contains($r['embeds'][0]['title'], 'กฎของชุมชน')));
        // ยังไม่มีรอบขายในฐานข้อมูลเทสต์ = ต้องประกาศตามจริงว่ายังไม่มี ไม่ใช่ "เปิดขาย"
        $this->assertTrue($posts->contains(fn (Request $r) => str_contains($r->url(), '/channels/'.self::SALE.'/messages')
            && str_contains($r['embeds'][0]['title'], 'การขายเหรียญ')
            && str_contains($r['embeds'][0]['description'], 'ยังไม่มีรอบขายเหรียญ')));
        // ห้ามมี embeds (แม้แต่ว่าง) — ไม่งั้น Discord ไม่ทำตัวเล่นวิดีโอให้ลิงก์ mp4
        $this->assertTrue($posts->contains(fn (Request $r) => str_contains($r->url(), '/channels/'.self::VIDEOS.'/messages')
            && str_contains($r['content'], '/videos/whitepaper/ep01-foundation.mp4')
            && ! array_key_exists('embeds', $r->data())));

        // ทุกข้อความปิดการปิง และมีโทเค็นอยู่ใน header เท่านั้น
        foreach ($posts as $request) {
            $this->assertSame(['parse' => []], $request['allowed_mentions']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bot '.self::TOKEN));
        }

        $this->assertSame(1, DiscordPost::where('kind', 'rules')->count());
        $this->assertSame(2, DiscordPost::where('kind', 'video')->whereNotNull('message_id')->count());
    }

    public function test_second_sync_with_no_changes_sends_nothing(): void
    {
        $this->fakeDiscord();
        $this->publisher()->sync();
        $before = count(Http::recorded());

        $outcome = $this->publisher()->sync();

        $discordCallsAfter = collect(Http::recorded())
            ->slice($before)
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'discord.com'))
            ->count();
        $this->assertSame(0, $discordCallsAfter);
        $this->assertTrue(collect($outcome['results'])->every(fn ($r) => $r['action'] === 'unchanged'));
    }

    public function test_changed_rules_edit_the_existing_message_instead_of_posting_again(): void
    {
        $this->fakeDiscord();
        $this->publisher()->sync();
        $rulesMessage = DiscordPost::where('kind', 'rules')->value('message_id');

        app(DiscordSettings::class)->set('discord_rules_text', '1. ห้ามสแปม (กฎใหม่)', 'text');
        $outcome = $this->publisher()->sync();

        $this->assertTrue(collect($outcome['results'])->contains(fn ($r) => $r['kind'] === 'rules' && $r['action'] === 'edited'));
        $patch = collect($this->discordCalls('PATCH'))->first(fn (Request $r) => str_contains($r->url(), '/channels/'.self::RULES."/messages/{$rulesMessage}"));
        $this->assertNotNull($patch);
        $this->assertStringContainsString('กฎใหม่', $patch['embeds'][0]['description']);
        $this->assertSame($rulesMessage, DiscordPost::where('kind', 'rules')->value('message_id'));
    }

    public function test_a_deleted_message_is_posted_again(): void
    {
        // แอดมินลบข้อความกฎของบอททิ้งไปแล้ว → แก้ข้อความเดิมได้ 404 (Unknown Message)
        Http::fake([
            'discord.com/api/v10/channels/*/messages/*' => Http::response(['message' => 'Unknown Message', 'code' => 10008], 404),
            'discord.com/api/v10/channels/*/messages' => fn () => Http::response(['id' => (string) $this->messageSeq++]),
            '*' => Http::response(['result' => '0x0']),
        ]);
        $this->publisher()->sync();
        $old = DiscordPost::where('kind', 'rules')->value('message_id');

        app(DiscordSettings::class)->set('discord_rules_text', 'กฎฉบับแก้', 'text');
        $outcome = $this->publisher()->sync();

        $this->assertTrue(collect($outcome['results'])->contains(fn ($r) => $r['kind'] === 'rules' && $r['action'] === 'created'));
        $this->assertNotSame($old, DiscordPost::where('kind', 'rules')->value('message_id'));
    }

    public function test_only_a_few_old_articles_are_backfilled_then_new_ones_follow(): void
    {
        config(['discord.article_backfill' => 2, 'discord.max_new_posts_per_run' => 10, 'discord.videos' => []]);
        $this->article('เก่ามาก', '2026-07-01 10:00:00');
        $this->article('เก่า', '2026-08-01 10:00:00');
        $this->article('ล่าสุดก่อนเปิดบอท 1', '2026-09-10 10:00:00');
        $this->article('ล่าสุดก่อนเปิดบอท 2', '2026-09-11 10:00:00');
        // ภาษาอังกฤษเป็นภาษาหลักของห้อง (เจ้าของ 2026-09-15) — บทความอังกฤษนับรวมในย้อนหลังด้วย
        $this->article('English only', '2026-09-12 10:00:00', 'en');
        $this->fakeDiscord();

        $this->publisher()->sync();

        $titles = collect($this->discordCalls('POST'))
            ->filter(fn (Request $r) => str_contains($r->url(), '/channels/'.self::NEWS.'/messages'))
            ->map(fn (Request $r) => $r['embeds'][0]['title'])
            ->values()
            ->all();
        $this->assertSame(['ล่าสุดก่อนเปิดบอท 2', 'English only'], $titles);

        $new = $this->article('ข่าวใหม่หลังเปิดบอท', now()->subMinute()->toDateTimeString());
        $this->publisher()->sync();

        $this->assertNotNull(DiscordPost::where('kind', 'article')->where('ref_key', (string) $new->id)->value('message_id'));
        $this->assertSame(3, DiscordPost::where('kind', 'article')->count());
    }

    public function test_a_failed_post_is_retried_on_the_next_run_and_the_reason_is_kept(): void
    {
        config(['discord.videos' => []]);
        Http::fake([
            'discord.com/api/v10/channels/'.self::RULES.'/messages' => Http::sequence()
                ->push(['message' => 'Missing Permissions', 'code' => 50013], 403)
                ->push(['id' => '888888888888888888']),
            'discord.com/api/v10/channels/*/messages' => fn () => Http::response(['id' => (string) $this->messageSeq++]),
            '*' => Http::response(['result' => '0x0']),
        ]);

        $first = $this->publisher()->sync();

        $rules = collect($first['results'])->firstWhere('kind', 'rules');
        $this->assertSame('error', $rules['action']);
        $this->assertStringContainsString('Send Messages', $rules['error']);
        $this->assertStringContainsString('Send Messages', DiscordPost::where('kind', 'rules')->value('last_error'));

        $this->publisher()->sync();

        $this->assertSame('888888888888888888', DiscordPost::where('kind', 'rules')->value('message_id'));
        $this->assertNull(DiscordPost::where('kind', 'rules')->value('last_error'));
    }

    public function test_a_second_sync_while_one_is_running_does_not_post_twice(): void
    {
        // แอดมินกด "โพสต์ตอนนี้" ซ้ำ หรือชนกับรอบตั้งเวลา
        $this->fakeDiscord();
        $running = Cache::lock('discord:sync', 300);
        $this->assertTrue($running->get());

        $outcome = $this->publisher()->sync();

        $this->assertFalse($outcome['ran']);
        $this->assertStringContainsString('กำลังซิงก์อยู่', $outcome['reason']);
        $this->assertSame([], $this->discordCalls('POST'));

        $running->release();
    }

    public function test_dry_run_reports_what_would_happen_without_calling_discord(): void
    {
        $this->fakeDiscord();

        $outcome = $this->publisher()->sync(dryRun: true);

        $this->assertSame([], $this->discordCalls('POST'));
        $this->assertTrue(collect($outcome['results'])->contains(fn ($r) => $r['action'] === 'would_create'));
        $this->assertSame(0, DiscordPost::count());
    }

    public function test_the_bot_token_never_reaches_the_logs(): void
    {
        config(['discord.videos' => []]);
        Http::fake([
            'discord.com/*' => fn () => throw new ConnectionException('cURL error 28 (Authorization: Bot '.self::TOKEN.')'),
            '*' => Http::response(['result' => '0x0']),
        ]);

        $this->publisher()->sync();

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
        }
        $this->assertStringNotContainsString(self::TOKEN, (string) DiscordPost::pluck('last_error')->implode(' '));
    }
}
