<?php

namespace App\Services\Discord;

use App\Models\Article;
use App\Models\DiscordPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * TPIX TRADE — ซิงก์ข้อมูลจากเว็บเข้าเซิร์ฟเวอร์ Discord (คำสั่ง discord:sync ทุก 10 นาที).
 *
 * เจ้าของสั่ง: "อัพเดทข้อมูลข่าวสาร วีดีโอ ใส่ไว้ให้ครบถ้วน กฎ และการจำหน่ายเหรียญต่าง ๆ จากเว็บหลัก"
 *
 * ═══ สองแบบของข้อความ ═══
 *
 * 1) ข้อความประจำห้อง (สถานะการขาย / กฎ / whitepaper / วิธีถาม) — มีชิ้นเดียวต่อเรื่อง
 *    ข้อมูลเปลี่ยน → "แก้ข้อความเดิม" ไม่โพสต์ใหม่ (ไม่งั้นทุก 10 นาทีห้องจะรกด้วยข้อความซ้ำ)
 *    ข้อความเดิมถูกลบ → โพสต์ใหม่แทน
 * 2) ข่าวและวิดีโอ — โพสต์ครั้งเดียวต่อชิ้น จำไว้ใน discord_posts ไม่ซ้ำแม้ cron ซ้อน
 *    ครั้งแรกโพสต์ย้อนหลังแค่ไม่กี่ชิ้น (เว็บมีบทความเกือบ 300 ชิ้น — ห้ามเทลงห้องทั้งหมด)
 *
 * ปิดสวิตช์ในหลังบ้าน = ไม่แตะ Discord เลย
 *
 * Developed by Xman Studio.
 */
class DiscordPublisher
{
    /** ข้อความประจำห้อง: kind => [บทบาทห้อง, เมธอดสร้างเนื้อหา] */
    private const PINNED = [
        'rules' => ['rules', 'rules'],
        'whitepaper' => ['whitepaper', 'whitepaper'],
        'sale_status' => ['sale', 'saleStatus'],
        'ask_hint' => ['ask', 'askHint'],
    ];

    /** เว้นจังหวะระหว่างโพสต์ใหม่ — เพดานของ Discord ราว 5 ข้อความ / 5 วินาทีต่อห้อง */
    private const PAUSE_MS = 400;

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordClient $client,
        private readonly DiscordContent $content,
        private readonly DiscordChannelPlanner $planner,
    ) {}

    /**
     * @return array{ran: bool, reason?: string, results: list<array<string, mixed>>}
     */
    public function sync(bool $dryRun = false): array
    {
        if (! $this->settings->enabled()) {
            return ['ran' => false, 'reason' => 'ปิดบอทอยู่ (เปิดได้ที่ /admin/discord)', 'results' => []];
        }

        if (! $this->settings->hasBotToken() || $this->settings->guildId() === null) {
            return ['ran' => false, 'reason' => 'ยังตั้งค่าไม่ครบ (โทเค็นบอท / Guild ID)', 'results' => []];
        }

        if ($dryRun) {
            return $this->run(true);
        }

        // ปุ่ม "โพสต์ตอนนี้" กดซ้ำ หรือชนกับรอบตั้งเวลา = สองรอบเห็นว่ายังไม่เคยโพสต์พร้อมกัน → โพสต์ซ้ำ
        $lock = Cache::lock('discord:sync', 300);

        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'กำลังซิงก์อยู่อีกรอบหนึ่ง — รอสักครู่แล้วดูผล', 'results' => []];
        }

        try {
            return $this->run(false);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{ran: bool, results: list<array<string, mixed>>}
     */
    private function run(bool $dryRun): array
    {
        $map = $this->ensureChannelMap();
        $results = [];

        foreach (self::PINNED as $kind => [$role, $builder]) {
            if ($kind === 'ask_hint' && ! $this->settings->askEnabled()) {
                continue;
            }

            $results[] = $this->upsert($kind, 'main', $map[$role] ?? null, fn () => $this->content->{$builder}(), $dryRun);
        }

        // ข่าวก่อนวิดีโอ — ข่าวใหม่มีอายุ ส่วนวิดีโอเป็นชุดเดิมที่ทยอยลงจนครบก็พอ
        $budget = max(1, (int) config('discord.max_new_posts_per_run', 6));

        foreach ($this->pendingArticles($dryRun) as $article) {
            if ($budget <= 0) {
                break;
            }
            $result = $this->postOnce('article', (string) $article->id, $map['news'] ?? null, fn () => $this->content->article($article), $dryRun);
            $results[] = $result;
            $budget -= in_array($result['action'], ['created', 'would_create', 'error'], true) ? 1 : 0;
        }

        foreach ($this->pendingVideos() as $video) {
            if ($budget <= 0) {
                break;
            }
            $result = $this->postOnce('video', $video['code'], $map['videos'] ?? null, fn () => $this->content->video($video), $dryRun);
            $results[] = $result;
            $budget -= in_array($result['action'], ['created', 'would_create', 'error'], true) ? 1 : 0;
        }

        if (! $dryRun) {
            $this->settings->mergeState([
                'last_sync_at' => now()->toIso8601String(),
                'last_sync' => array_values(array_filter($results, fn ($r) => $r['action'] !== 'unchanged')),
            ]);
        }

        return ['ran' => true, 'results' => $results];
    }

    /**
     * ดึงรายชื่อห้อง + วางผังใหม่ — ใช้ตอนกด "ทดสอบการเชื่อมต่อ" / "วางผังใหม่" ในหลังบ้าน.
     *
     * @return array{ok: bool, error?: string, map?: array<string, string>}
     */
    public function refreshChannels(bool $replan = false): array
    {
        $guildId = $this->settings->guildId();

        if ($guildId === null) {
            return ['ok' => false, 'error' => 'ยังไม่ได้ใส่ Guild ID'];
        }

        $response = $this->client->guildChannels($guildId);

        if (! $response['ok'] || ! is_array($response['data'])) {
            return ['ok' => false, 'error' => $response['error'] ?? 'ดึงรายชื่อห้องไม่สำเร็จ'];
        }

        $plan = $this->planner->plan($response['data']);
        $this->settings->mergeState(['channels' => $plan['postable'], 'channels_at' => now()->toIso8601String()]);

        $current = $this->settings->channelMap();
        $known = array_column($plan['postable'], 'id');

        // มีผังอยู่แล้ว = แอดมินเลือก/ยืนยันไว้ รวมถึงเรื่องที่ตั้งใจ "ไม่ลงห้องไหน" (ไม่มี key)
        // ดึงรายชื่อห้องใหม่ห้ามเติมเรื่องพวกนั้นกลับ — เคยทำให้บอทโพสต์กฎซ้ำกับกฎที่เจ้าของเขียนไว้เองในห้อง #rules
        // วางผังใหม่ทั้งหมดเฉพาะครั้งแรก (ยังไม่มีผัง) หรือเมื่อแอดมินกด "จัดห้องใหม่อัตโนมัติ"
        $map = $replan || $current === [] ? $plan['map'] : array_intersect($current, $known);
        $this->settings->saveChannelMap($map);

        return ['ok' => true, 'map' => $map];
    }

    /**
     * ตัวอย่างสิ่งที่บอทจะโพสต์ในแต่ละห้อง พร้อมเนื้อหาจริง — ไม่ส่งอะไรไป Discord และไม่แตะฐานข้อมูล
     * (หน้าทดสอบในหลังบ้าน: เจ้าของอยาก "ทดสอบบอทผ่านหน้าเว็บ" ก่อนให้ขึ้นห้องจริง).
     *
     * status: new = ยังไม่เคยโพสต์ · update = จะแก้ข้อความเดิม · current = ในห้องตรงกับนี้แล้ว
     *         no_channel = ยังไม่มีห้อง · failed = เคยโพสต์ไม่สำเร็จ (จะลองใหม่รอบหน้า)
     *
     * @return list<array<string, mixed>>
     */
    public function preview(int $upcoming = 3): array
    {
        $map = $this->settings->channelMap();
        $posted = DiscordPost::all()->keyBy(fn (DiscordPost $p) => $p->kind.':'.$p->ref_key);
        $items = [];

        $status = function (string $kind, string $ref, ?string $channel, ?string $hash) use ($posted): string {
            $record = $posted["{$kind}:{$ref}"] ?? null;

            return match (true) {
                $channel === null => 'no_channel',
                $record === null => 'new',
                $record->message_id === null => 'failed',
                $record->channel_id !== $channel => 'new',
                $hash !== null && $record->content_hash !== $hash => 'update',
                default => 'current',
            };
        };

        foreach (self::PINNED as $kind => [$role, $builder]) {
            if ($kind === 'ask_hint' && ! $this->settings->askEnabled()) {
                continue;
            }

            $payload = $this->content->{$builder}();
            $channel = $map[$role] ?? null;
            $items[] = [
                'kind' => $kind,
                'ref' => 'main',
                'role' => $role,
                'channel_id' => $channel,
                'status' => $status($kind, 'main', $channel, DiscordContent::hash($payload)),
                'error' => $posted["{$kind}:main"]->last_error ?? null,
                'payload' => $payload,
            ];
        }

        foreach (array_slice($this->pendingArticles(true), 0, $upcoming) as $article) {
            $channel = $map['news'] ?? null;
            $items[] = [
                'kind' => 'article',
                'ref' => (string) $article->id,
                'role' => 'news',
                'channel_id' => $channel,
                'status' => $status('article', (string) $article->id, $channel, null),
                'error' => $posted['article:'.$article->id]->last_error ?? null,
                'payload' => $this->content->article($article),
            ];
        }

        foreach (array_slice($this->pendingVideos(), 0, $upcoming) as $video) {
            $channel = $map['videos'] ?? null;
            $items[] = [
                'kind' => 'video',
                'ref' => $video['code'],
                'role' => 'videos',
                'channel_id' => $channel,
                'status' => $status('video', $video['code'], $channel, null),
                'error' => $posted['video:'.$video['code']]->last_error ?? null,
                'payload' => $this->content->video($video),
            ];
        }

        return $items;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function ensureChannelMap(): array
    {
        $map = $this->settings->channelMap();

        if ($map === []) {
            $this->refreshChannels();
            $map = $this->settings->channelMap();
        }

        return $map;
    }

    /**
     * ข้อความประจำห้อง — สร้างครั้งแรก แก้เมื่อเนื้อหาเปลี่ยน สร้างใหม่ถ้าข้อความเดิมหาย/ย้ายห้อง.
     */
    private function upsert(string $kind, string $ref, ?string $channel, callable $build, bool $dryRun): array
    {
        if ($channel === null) {
            return $this->result($kind, $ref, 'no_channel', 'ยังไม่มีห้องสำหรับเรื่องนี้ — เลือกห้องที่หลังบ้าน');
        }

        $payload = $build();
        $hash = DiscordContent::hash($payload);
        $record = DiscordPost::where('kind', $kind)->where('ref_key', $ref)->first();

        $sameMessage = $record !== null && $record->message_id !== null && $record->channel_id === $channel;

        if ($sameMessage && $record->content_hash === $hash) {
            return $this->result($kind, $ref, 'unchanged');
        }

        if ($dryRun) {
            return $this->result($kind, $ref, $sameMessage ? 'would_edit' : 'would_create', null, $channel);
        }

        if ($sameMessage) {
            $response = $this->client->editMessage($channel, $record->message_id, $payload);

            if ($response['ok']) {
                $record->update(['content_hash' => $hash, 'last_error' => null]);

                return $this->result($kind, $ref, 'edited', null, $channel);
            }

            // ข้อความเดิมถูกลบไปแล้ว → โพสต์ใหม่ด้านล่าง ส่วนพังแบบอื่น (สิทธิ์/ความถี่) รายงานแล้วรอรอบหน้า
            if (! in_array($response['code'], [10008], true) && $response['status'] !== 404) {
                $record->update(['last_error' => $response['error']]);

                return $this->result($kind, $ref, 'error', $response['error'], $channel);
            }
        }

        return $this->create($kind, $ref, $channel, $payload, $hash);
    }

    /**
     * ข่าว/วิดีโอ — โพสต์ครั้งเดียวต่อชิ้น (โพสต์ไม่สำเร็จ = ลองใหม่รอบหน้า).
     */
    private function postOnce(string $kind, string $ref, ?string $channel, callable $build, bool $dryRun): array
    {
        if ($channel === null) {
            return $this->result($kind, $ref, 'no_channel', 'ยังไม่มีห้องสำหรับเรื่องนี้ — เลือกห้องที่หลังบ้าน');
        }

        $record = DiscordPost::where('kind', $kind)->where('ref_key', $ref)->first();

        if ($record?->message_id !== null) {
            return $this->result($kind, $ref, 'unchanged');
        }

        if ($dryRun) {
            return $this->result($kind, $ref, 'would_create', null, $channel);
        }

        $payload = $build();

        return $this->create($kind, $ref, $channel, $payload, DiscordContent::hash($payload));
    }

    private function create(string $kind, string $ref, string $channel, array $payload, string $hash): array
    {
        $response = $this->client->createMessage($channel, $payload);
        $messageId = is_array($response['data']) ? (string) ($response['data']['id'] ?? '') : '';

        if (! $response['ok'] || $messageId === '') {
            DiscordPost::updateOrCreate(
                ['kind' => $kind, 'ref_key' => $ref],
                ['channel_id' => $channel, 'message_id' => null, 'last_error' => $response['error'] ?? 'ไม่ได้รับ ID ข้อความ'],
            );

            Log::warning('Discord: โพสต์ไม่สำเร็จ', ['kind' => $kind, 'ref' => $ref, 'error' => $response['error']]);

            return $this->result($kind, $ref, 'error', $response['error'] ?? 'ไม่ได้รับ ID ข้อความ', $channel);
        }

        DiscordPost::updateOrCreate(
            ['kind' => $kind, 'ref_key' => $ref],
            ['channel_id' => $channel, 'message_id' => $messageId, 'content_hash' => $hash, 'last_error' => null, 'posted_at' => now()],
        );

        Sleep::for(self::PAUSE_MS)->milliseconds();

        return $this->result($kind, $ref, 'created', null, $channel);
    }

    /** @return list<array{code: string, file: string, title: string, part: string}> */
    private function pendingVideos(): array
    {
        $posted = DiscordPost::where('kind', 'video')->whereNotNull('message_id')->pluck('ref_key')->all();

        return array_values(array_filter(
            (array) config('discord.videos', []),
            fn ($video) => ! in_array($video['code'], $posted, true),
        ));
    }

    /**
     * บทความที่ยังไม่เคยโพสต์ เรียงเก่า → ใหม่.
     *
     * "เส้นเริ่ม" ปักครั้งเดียวตอนรันครั้งแรก = เวลาเผยแพร่ของบทความลำดับที่ article_backfill นับจากล่าสุด
     * แล้วทุกรอบโพสต์ทุกชิ้นตั้งแต่เส้นนั้นที่ยังไม่มีใน discord_posts
     * → ชิ้นที่ส่งไม่สำเร็จ หรือโควตาต่อรอบหมดก่อน จะถูกหยิบใหม่รอบหน้าเอง ไม่หลุดหาย
     *
     * @return list<Article>
     */
    private function pendingArticles(bool $dryRun): array
    {
        $language = (string) config('discord.article_language', 'th');
        $floor = $this->settings->state()['articles_floor'] ?? null;

        if ($floor === null) {
            $backfill = max(0, (int) config('discord.article_backfill', 5));
            $nth = $backfill > 0
                ? Article::published()->where('language', $language)->orderByDesc('published_at')->skip($backfill - 1)->value('published_at')
                : null;
            $oldest = Article::published()->where('language', $language)->min('published_at');

            $floor = (string) ($nth ?? ($backfill > 0 && $oldest ? $oldest : now()->toDateTimeString()));

            if (! $dryRun) {
                $this->settings->mergeState(['articles_floor' => $floor]);
            }
        }

        $candidates = Article::published()
            ->where('language', $language)
            ->where('published_at', '>=', $floor)
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $posted = DiscordPost::where('kind', 'article')->whereNotNull('message_id')
            ->whereIn('ref_key', $candidates->pluck('id')->map(fn ($id) => (string) $id))
            ->pluck('ref_key')->all();

        return $candidates->reject(fn (Article $a) => in_array((string) $a->id, $posted, true))->values()->all();
    }

    private function result(string $kind, string $ref, string $action, ?string $error = null, ?string $channel = null): array
    {
        return array_filter([
            'kind' => $kind,
            'ref' => $ref,
            'action' => $action,
            'channel_id' => $channel,
            'error' => $error,
        ], fn ($v) => $v !== null);
    }
}
