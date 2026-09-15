<?php

namespace App\Services\Discord;

use App\Models\Article;
use App\Models\DiscordPost;
use Illuminate\Support\Collection;
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
    /**
     * ข้อความประจำห้อง: kind => [บทบาทห้อง, เมธอดสร้างเนื้อหา]
     * เมธอดคืน null = อ่านข้อมูลไม่ได้รอบนี้ → ปล่อยข้อความเดิมไว้ ไม่ทับด้วยข้อความ error.
     */
    private const PINNED = [
        'rules' => ['rules', 'rules'],
        'whitepaper' => ['whitepaper', 'whitepaper'],
        'sale_status' => ['sale', 'saleStatus'],
        'ask_hint' => ['ask', 'askHint'],
        'price_card' => ['price', 'priceCard'],
        'dex_pairs' => ['listings', 'pairsCard'],
        'guide_help' => ['help', 'guideHelp'],
        'guide_dex' => ['dex', 'guideDex'],
        'guide_bugs' => ['bugs', 'guideBugs'],
        'guide_ideas' => ['ideas', 'guideIdeas'],
        'guide_intro' => ['intro', 'guideIntro'],
    ];

    /** ข้อความประจำห้องที่ต้องปักหมุด — สมาชิกหาเจอจากปุ่มหมุด แม้แชทไหลไปไกลแล้ว (ปักไม่ติด = รอบหน้าลองใหม่ ดู pinned_at) */
    private const PIN_ON_CREATE = ['price_card', 'dex_pairs', 'guide_help', 'guide_dex', 'guide_bugs', 'guide_ideas', 'guide_intro'];

    /** เว้นจังหวะระหว่างโพสต์ใหม่ — เพดานของ Discord ราว 5 ข้อความ / 5 วินาทีต่อห้อง */
    private const PAUSE_MS = 400;

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordClient $client,
        private readonly DiscordContent $content,
        private readonly DiscordChannelPlanner $planner,
        private readonly DiscordLiveData $live,
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

            // การ์ด/คู่มือเสริมเป็นแบบเลือกเปิด: ยังไม่ได้เลือกห้อง = ข้ามเงียบ ๆ (ไม่ขึ้น "ยังไม่มีห้อง" สีแดงทุกรอบ)
            if (in_array($kind, self::PIN_ON_CREATE, true) && ! isset($map[$role])) {
                continue;
            }

            $results[] = $this->upsert($kind, 'main', $map[$role] ?? null, fn () => $this->content->{$builder}(), $dryRun);
        }

        // ข่าวก่อนวิดีโอ — ข่าวใหม่มีอายุ ส่วนวิดีโอเป็นชุดเดิมที่ทยอยลงจนครบก็พอ
        $budget = max(1, (int) config('discord.max_new_posts_per_run', 6));

        foreach ($this->pendingArticles($dryRun) as [$article, $thai]) {
            if ($budget <= 0) {
                break;
            }
            $result = $this->postOnce('article', (string) $article->id, $map['news'] ?? null, fn () => $this->content->article($article, $thai), $dryRun);
            $results[] = $result;
            $budget -= in_array($result['action'], ['created', 'would_create', 'error'], true) ? 1 : 0;

            // คู่ภาษาไทยอยู่ในข้อความเดียวกันแล้ว (ปุ่มอ่านภาษาไทย) — จดไว้ว่าโพสต์แล้ว จะได้ไม่ออกซ้ำอีกข้อความ
            if ($thai !== null && $result['action'] === 'created') {
                $posted = DiscordPost::where('kind', 'article')->where('ref_key', (string) $article->id)->first();
                DiscordPost::updateOrCreate(
                    ['kind' => 'article', 'ref_key' => (string) $thai->id],
                    ['channel_id' => $posted->channel_id, 'message_id' => $posted->message_id, 'content_hash' => $posted->content_hash, 'last_error' => null, 'posted_at' => now()],
                );
            }
        }

        foreach ($this->pendingVideos() as $video) {
            if ($budget <= 0) {
                break;
            }
            $result = $this->postOnce('video', $video['code'], $map['videos'] ?? null, fn () => $this->content->video($video), $dryRun);
            $results[] = $result;
            $budget -= in_array($result['action'], ['created', 'would_create', 'error'], true) ? 1 : 0;
        }

        // แอปรุ่นใหม่ / คู่เทรดใหม่ — ยังไม่ได้เลือกห้อง = ข้ามเงียบ ๆ (ไม่รกผลซิงก์ด้วย "ยังไม่มีห้อง" ทุกรอบ)
        if (isset($map['dev'])) {
            foreach ($this->live->releases() as $release) {
                if ($budget <= 0) {
                    break;
                }
                $result = $this->postOnce('release', "{$release['product']}:{$release['version']}", $map['dev'], fn () => $this->content->release($release), $dryRun);
                $results[] = $result;
                $budget -= in_array($result['action'], ['created', 'would_create', 'error'], true) ? 1 : 0;
            }
        }

        if (isset($map['listings'])) {
            foreach ($this->newPairs($dryRun) as $symbol) {
                if ($budget <= 0) {
                    break;
                }
                $result = $this->postOnce('dex_pair', $symbol, $map['listings'], fn () => $this->content->newPair($symbol), $dryRun);
                $results[] = $result;
                $budget -= in_array($result['action'], ['created', 'would_create', 'error'], true) ? 1 : 0;
            }
        }

        if (! $dryRun) {
            $results = array_merge($results, $this->refreshPosted($map));

            $this->settings->mergeState([
                'last_sync_at' => now()->toIso8601String(),
                'last_sync' => array_values(array_filter($results, fn ($r) => $r['action'] !== 'unchanged')),
            ]);
        }

        return ['ran' => true, 'results' => $results];
    }

    /**
     * ข่าว/วิดีโอ/แอปรุ่นใหม่ที่โพสต์ไปแล้ว — รูปแบบข้อความเปลี่ยน (เช่นเพิ่มภาษาอังกฤษเป็นภาษาหลัก 2026-09-15)
     * หรือบทความถูกแก้บนเว็บ → แก้ข้อความเดิมทีละไม่กี่ชิ้นต่อรอบ ไม่โพสต์ใหม่ (สมาชิกไม่โดนแจ้งเตือนซ้ำ).
     *
     * ดูเฉพาะชุดที่ยังสร้างเนื้อหาได้: วิดีโอทั้งชุด · แอปรุ่นล่าสุด · บทความ 20 ข้อความล่าสุด
     *
     * @param  array<string, string>  $map
     * @return list<array<string, mixed>>
     */
    private function refreshPosted(array $map): array
    {
        $budget = max(1, (int) config('discord.max_edits_per_run', 6));
        $jobs = [];

        $videos = collect((array) config('discord.videos', []))->keyBy('code');
        foreach (DiscordPost::where('kind', 'video')->whereNotNull('message_id')->get() as $post) {
            if (isset($videos[$post->ref_key])) {
                $video = $videos[$post->ref_key];
                $jobs[] = [$post, fn () => $this->content->video($video), collect([$post])];
            }
        }

        if (isset($map['dev'])) {
            $releases = collect($this->live->releases())->keyBy(fn ($r) => "{$r['product']}:{$r['version']}");
            foreach (DiscordPost::where('kind', 'release')->whereNotNull('message_id')->whereIn('ref_key', $releases->keys()->all())->get() as $post) {
                $release = $releases[$post->ref_key];
                $jobs[] = [$post, fn () => $this->content->release($release), collect([$post])];
            }
        }

        // ข้อความหนึ่งอาจเป็นของบทความคู่ภาษา (อังกฤษ + ปุ่มภาษาไทย) — สร้างจากฝั่งอังกฤษเสมอ
        $groups = DiscordPost::where('kind', 'article')->whereNotNull('message_id')->latest('posted_at')->limit(20)->get()->groupBy('message_id');
        $articles = Article::whereIn('id', $groups->flatten()->pluck('ref_key')->map(fn ($id) => (int) $id))->get()->keyBy('id');
        foreach ($groups as $group) {
            $inGroup = $group->map(fn (DiscordPost $p) => $articles[(int) $p->ref_key] ?? null)->filter();
            $main = $inGroup->sortBy(fn (Article $a) => $a->language === 'en' ? 0 : 1)->first();
            if ($main === null) {
                continue; // บทความถูกลบจากเว็บ — ปล่อยข้อความเดิมไว้
            }
            $thai = $main->language === 'en' ? $inGroup->firstWhere('language', 'th') : null;
            $record = $group->first(fn (DiscordPost $p) => (int) $p->ref_key === $main->id);
            $jobs[] = [$record, fn () => $this->content->article($main, $thai), $group];
        }

        $results = [];
        foreach ($jobs as [$record, $build, $sharing]) {
            if ($budget <= 0) {
                break;
            }

            $payload = $build();
            $hash = DiscordContent::hash($payload);
            if ($record->content_hash === $hash) {
                continue;
            }

            $budget--;
            $response = $this->client->editMessage($record->channel_id, $record->message_id, $payload);

            // ข้อความถูกลบไปแล้ว = จำ hash ไว้ด้วย ไม่งั้นพยายามแก้ข้อความที่ไม่มีอยู่ทุกรอบ (ไม่โพสต์ข่าวเก่าซ้ำ)
            $gone = ! $response['ok'] && (($response['code'] ?? null) === 10008 || $response['status'] === 404);

            if ($response['ok'] || $gone) {
                DiscordPost::whereIn('id', $sharing->pluck('id'))->update(['content_hash' => $hash, 'last_error' => $gone ? 'ข้อความถูกลบไปแล้ว' : null]);
                $results[] = $this->result($record->kind, $record->ref_key, $response['ok'] ? 'edited' : 'unchanged', null, $record->channel_id);
            } else {
                $record->update(['last_error' => $response['error']]);
                $results[] = $this->result($record->kind, $record->ref_key, 'error', $response['error'], $record->channel_id);
            }

            Sleep::for(self::PAUSE_MS)->milliseconds();
        }

        return $results;
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
                'status' => $payload === null ? 'unavailable' : $status($kind, 'main', $channel, DiscordContent::hash($payload)),
                'error' => $posted["{$kind}:main"]->last_error ?? null,
                'payload' => $payload ?? ['content' => '⚠️ อ่านข้อมูลสดไม่ได้ตอนนี้ — ในห้องจะยังเป็นข้อความเดิม', 'components' => []],
            ];
        }

        foreach (array_slice($this->live->releases(), 0, $upcoming) as $release) {
            $channel = $map['dev'] ?? null;
            $ref = "{$release['product']}:{$release['version']}";
            $items[] = [
                'kind' => 'release',
                'ref' => $ref,
                'role' => 'dev',
                'channel_id' => $channel,
                'status' => $status('release', $ref, $channel, null),
                'error' => $posted['release:'.$ref]->last_error ?? null,
                'payload' => $this->content->release($release),
            ];
        }

        foreach (array_slice($this->pendingArticles(true), 0, $upcoming) as [$article, $thai]) {
            $channel = $map['news'] ?? null;
            $items[] = [
                'kind' => 'article',
                'ref' => (string) $article->id,
                'role' => 'news',
                'channel_id' => $channel,
                'status' => $status('article', (string) $article->id, $channel, null),
                'error' => $posted['article:'.$article->id]->last_error ?? null,
                'payload' => $this->content->article($article, $thai),
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
        if ($payload === null) {
            return $this->result($kind, $ref, 'skipped', 'อ่านข้อมูลสดไม่ได้รอบนี้ — คงข้อความเดิมไว้ รอบหน้าลองใหม่', $channel);
        }

        $hash = DiscordContent::hash($payload);
        $record = DiscordPost::where('kind', $kind)->where('ref_key', $ref)->first();

        $sameMessage = $record !== null && $record->message_id !== null && $record->channel_id === $channel;

        if ($sameMessage && $record->content_hash === $hash) {
            if (! $dryRun) {
                $this->ensurePinned($record);
            }

            return $this->result($kind, $ref, 'unchanged');
        }

        if ($dryRun) {
            return $this->result($kind, $ref, $sameMessage ? 'would_edit' : 'would_create', null, $channel);
        }

        if ($sameMessage) {
            $response = $this->client->editMessage($channel, $record->message_id, $payload);

            if ($response['ok']) {
                $record->update(['content_hash' => $hash, 'last_error' => null]);
                $this->ensurePinned($record);

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

        // ข้อความใหม่ = ยังไม่ได้ปัก (ข้อความเก่าที่ถูกลบไปแล้วอาจเคยปักไว้)
        $record = DiscordPost::updateOrCreate(
            ['kind' => $kind, 'ref_key' => $ref],
            ['channel_id' => $channel, 'message_id' => $messageId, 'content_hash' => $hash, 'last_error' => null, 'posted_at' => now(), 'pinned_at' => null],
        );

        $this->ensurePinned($record);

        Sleep::for(self::PAUSE_MS)->milliseconds();

        return $this->result($kind, $ref, 'created', null, $channel);
    }

    /**
     * ปักหมุดข้อความประจำห้องที่ยังไม่ได้ปัก — ปักไม่ติด (ยังไม่ได้สิทธิ์ Pin Messages / หมุดเต็ม 50)
     * ไม่ใช่เหตุให้ถือว่าโพสต์พัง แค่ปล่อย pinned_at ว่างไว้ให้รอบถัดไปลองใหม่.
     */
    private function ensurePinned(DiscordPost $record): void
    {
        if (! in_array($record->kind, self::PIN_ON_CREATE, true) || $record->message_id === null || $record->pinned_at !== null) {
            return;
        }

        $pinned = $this->client->pinMessage($record->channel_id, $record->message_id);

        if ($pinned['ok']) {
            $record->update(['pinned_at' => now()]);

            return;
        }

        Log::info('Discord: ปักหมุดไม่สำเร็จ รอบหน้าลองใหม่', ['kind' => $record->kind, 'error' => $pinned['error']]);
    }

    /**
     * คู่เทรดที่เพิ่งมีสภาพคล่อง — เทียบกับ "ชุดตั้งต้น" ที่จดไว้ตอนเปิดฟีดครั้งแรก
     * คู่ที่มีอยู่แล้วตอนนั้นอยู่ในการ์ดรายการคู่เทรดอยู่แล้ว ไม่ประกาศว่า "ใหม่" ย้อนหลัง
     * (เทียบกับชุดตั้งต้น ไม่ใช่ created_at — คู่ที่สร้างไว้นานแต่เพิ่งมีสภาพคล่องก็นับเป็นคู่ใหม่ได้).
     *
     * @return list<string>
     */
    private function newPairs(bool $dryRun): array
    {
        $active = $this->live->dexPairs()->pluck('symbol')->map(fn ($s) => (string) $s)->all();
        $baseline = $this->settings->state()['dex_pairs_baseline'] ?? null;

        if (! is_array($baseline)) {
            if (! $dryRun) {
                $this->settings->mergeState(['dex_pairs_baseline' => $active]);
            }

            return [];
        }

        return array_values(array_diff($active, $baseline));
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
     * @return list<array{0: Article, 1: ?Article}> [บทความที่จะโพสต์, คู่ภาษาไทยที่ใส่เป็นปุ่มในข้อความเดียวกัน]
     */
    private function pendingArticles(bool $dryRun): array
    {
        $languages = (array) config('discord.article_languages', ['en', 'th']);
        $floor = $this->settings->state()['articles_floor'] ?? null;

        if ($floor === null) {
            $backfill = max(0, (int) config('discord.article_backfill', 5));
            $nth = $backfill > 0
                ? Article::published()->whereIn('language', $languages)->orderByDesc('published_at')->skip($backfill - 1)->value('published_at')
                : null;
            $oldest = Article::published()->whereIn('language', $languages)->min('published_at');

            $floor = (string) ($nth ?? ($backfill > 0 && $oldest ? $oldest : now()->toDateTimeString()));

            if (! $dryRun) {
                $this->settings->mergeState(['articles_floor' => $floor]);
            }
        }

        $candidates = Article::published()
            ->whereIn('language', $languages)
            ->where('published_at', '>=', $floor)
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $posted = array_flip(DiscordPost::where('kind', 'article')->whereNotNull('message_id')
            ->whereIn('ref_key', $candidates->pluck('id')->map(fn ($id) => (string) $id))
            ->pluck('ref_key')->all());

        $out = [];
        $claimed = [];
        foreach ($candidates as $article) {
            $id = (string) $article->id;
            if (isset($posted[$id]) || isset($claimed[$id])) {
                continue;
            }

            if ($article->language === 'en') {
                $thai = $this->sibling($article, 'th', $candidates);
                if ($thai !== null && ! isset($posted[(string) $thai->id])) {
                    $claimed[(string) $thai->id] = true;
                } else {
                    $thai = null;
                }
                $out[] = [$article, $thai];

                continue;
            }

            // บทความไทยที่มีคู่ภาษาอังกฤษรออยู่ → ออกพร้อมฝั่งอังกฤษ (ภาษาหลัก) ไม่ออกแยก
            $english = $this->sibling($article, 'en', $candidates);
            if ($english !== null && ! isset($posted[(string) $english->id])) {
                continue;
            }
            $out[] = [$article, null];
        }

        return $out;
    }

    /**
     * บทความคู่ภาษา — เว็บสร้างแต่ละภาษาแยกกันจากหัวข้อเดียวในรอบเดียว (GenerateScheduledContent)
     * ไม่มีคอลัมน์ผูกกัน จึงจับคู่จากหมวดเดียวกันที่เผยแพร่ห่างกันไม่เกิน 15 นาที.
     *
     * @param  Collection<int, Article>  $pool
     */
    private function sibling(Article $article, string $language, Collection $pool): ?Article
    {
        return $pool->first(fn (Article $other) => $other->language === $language
            && $other->category === $article->category
            && $other->published_at !== null && $article->published_at !== null
            && abs($other->published_at->diffInSeconds($article->published_at)) <= 900);
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
