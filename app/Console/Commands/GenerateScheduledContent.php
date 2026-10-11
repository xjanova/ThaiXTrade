<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\SiteSetting;
use App\Services\ContentService;
use App\Services\News\NewsEventCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — บทความ AI อัตโนมัติ: ข่าวจากเหตุการณ์จริง + บทความความรู้ตามเวลา.
 *
 * 1. ข่าวเหตุการณ์ (content_event_enabled, ค่าตั้งต้นเปิด) — deploy สัญญา / DEX เปิด / คู่เทรดใหม่
 *    เขียนทันทีที่เห็น ไม่รอช่วงเวลา ทั้งไทยและอังกฤษ (ดู NewsEventCollector)
 * 2. บทความความรู้ (content_auto_enabled) — ตามช่วงเวลา content_time_slots
 *    เลือกหมวดก่อนแล้วค่อยเลือกหัวข้อในหมวดนั้น · หัวข้อเดิมไม่วนกลับมาภายใน TOPIC_COOLDOWN_DAYS วัน
 *
 * ค่าตั้งอื่น (กลุ่ม content): content_max_per_day · content_categories · content_language (th/en/both)
 * · content_auto_publish (เผยแพร่ทันที หรือเก็บเป็น draft ให้ตรวจก่อน — ใช้กับทั้งสองแบบ)
 */
class GenerateScheduledContent extends Command
{
    protected $signature = 'content:generate-scheduled';

    protected $description = 'Write AI news from real on-chain events and scheduled AI articles';

    /** ข่าวเหตุการณ์ออกทั้งสองภาษาเสมอ — Discord ใช้อังกฤษเป็นภาษาหลัก + ปุ่มอ่านภาษาไทย */
    private const EVENT_LANGUAGES = ['th', 'en'];

    /** AI ล้มกี่ครั้งต่อเหตุการณ์ต่อวันแล้วพักไว้วันพรุ่งนี้ — ไม่เผาโควตาทุก 30 นาที */
    private const EVENT_ATTEMPTS_PER_DAY = 3;

    /** หัวข้อบทความความรู้เดิมห้ามวนกลับมาภายในกี่วัน */
    private const TOPIC_COOLDOWN_DAYS = 45;

    public function handle(ContentService $contentService, NewsEventCollector $events): int
    {
        // บทความที่แอดมินตั้งเวลาไว้ต้องออกเสมอ — เดิมอยู่หลังด่านเปิดระบบ/ช่วงเวลา/โควตา ปิด auto content แล้วค้างหมด
        $published = $contentService->publishScheduledArticles();
        if ($published > 0) {
            $this->info("Published {$published} scheduled article(s).");
        }

        $autoPublish = (bool) SiteSetting::get('content', 'content_auto_publish', false);

        if ((bool) SiteSetting::get('content', 'content_event_enabled', true)) {
            $this->writeEventNews($contentService, $events, $autoPublish);
        } else {
            $this->line('Event news is disabled.');
        }

        $this->writeScheduledArticle($contentService, $autoPublish);

        return self::SUCCESS;
    }

    /** เหตุการณ์ที่ค้างเขียนเรื่องเดียวต่อรอบ (เก่าสุดก่อน) */
    private function writeEventNews(ContentService $contentService, NewsEventCollector $events, bool $autoPublish): void
    {
        foreach ($events->pending(self::EVENT_LANGUAGES) as $event) {
            $attemptKey = 'content:event-attempts:'.md5($event['key']).':'.now()->toDateString();
            if ((int) Cache::get($attemptKey, 0) >= self::EVENT_ATTEMPTS_PER_DAY) {
                continue;
            }

            // สร้างครบทุกภาษาก่อนแล้วค่อยเผยแพร่พร้อมกัน — บอท Discord จับคู่ไทย/อังกฤษเป็นข้อความเดียวได้
            $written = [];
            foreach ($event['missing'] as $lang) {
                try {
                    $written[] = $contentService->generateEventArticle($event, $lang);
                } catch (\Throwable $e) {
                    Cache::put($attemptKey, (int) Cache::get($attemptKey, 0) + 1, now()->endOfDay());
                    $this->error("Event news failed [{$lang}] {$event['key']}: {$e->getMessage()}");
                    Log::error('Event news failed', ['event' => $event['key'], 'language' => $lang, 'error' => $e->getMessage()]);
                }
            }

            $this->release($written, $autoPublish);
            foreach ($written as $article) {
                $this->info("Event news: [{$article->language}] {$article->title}");
                Log::info('Event news generated', ['id' => $article->id, 'event' => $event['key'], 'language' => $article->language, 'status' => $article->status]);
            }

            return;
        }
    }

    private function writeScheduledArticle(ContentService $contentService, bool $autoPublish): void
    {
        if (! SiteSetting::get('content', 'content_auto_enabled', false)) {
            $this->line('Auto content generation is disabled.');

            return;
        }

        $timeSlots = SiteSetting::get('content', 'content_time_slots', '["09:00"]');
        $slots = is_string($timeSlots) ? json_decode($timeSlots, true) : $timeSlots;
        $slots = is_array($slots) ? $slots : ['09:00'];

        $currentTime = now()->format('H:i');
        $nowMinutes = $this->timeToMinutes($currentTime);
        $matched = collect($slots)->contains(fn ($slot) => abs($this->timeToMinutes((string) $slot) - $nowMinutes) <= 5);

        if (! $matched) {
            $this->line('Not in time slot. Slots: '.implode(', ', $slots)." | Now: {$currentTime}");

            return;
        }

        // โควตารายวันนับเฉพาะบทความความรู้ — ข่าวเหตุการณ์ไม่กินโควตา (วัน DEX เปิดต้องไม่ทำให้บทความประจำหาย)
        $maxPerDay = (int) SiteSetting::get('content', 'content_max_per_day', 2);
        $todayCount = Article::where('is_ai_generated', true)
            ->whereDate('created_at', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('source_key')->orWhere('source_key', 'like', 'topic:%'))
            ->count();

        if ($todayCount >= $maxPerDay) {
            $this->line("Daily limit reached ({$todayCount}/{$maxPerDay}).");

            return;
        }

        $categories = SiteSetting::get('content', 'content_categories', '["tpix_chain","defi","news"]');
        $cats = is_string($categories) ? json_decode($categories, true) : $categories;
        $cats = is_array($cats) && $cats !== [] ? array_values($cats) : ['tpix_chain', 'defi', 'news'];

        [$category, $topic] = $this->pickTopic($cats);
        $sourceKey = 'topic:'.Str::limit(Str::slug($topic), 150, '');

        $language = SiteSetting::get('content', 'content_language', 'th');
        $languages = $language === 'both' ? ['th', 'en'] : [$language];

        $written = [];
        foreach ($languages as $lang) {
            try {
                $written[] = $contentService->generateArticle($topic, $category, $lang, null, $sourceKey);
            } catch (\Exception $e) {
                $this->error("Failed: {$e->getMessage()}");
                Log::error('Auto content failed', ['topic' => $topic, 'language' => $lang, 'error' => $e->getMessage()]);
            }
        }

        $this->release($written, $autoPublish);
        foreach ($written as $article) {
            $this->info("Generated: [{$article->language}] {$article->title}");
            Log::info('Auto content generated', ['id' => $article->id, 'title' => $article->title, 'category' => $category, 'language' => $article->language]);
        }
    }

    /** @param  list<Article>  $articles */
    private function release(array $articles, bool $autoPublish): void
    {
        if (! $autoPublish || $articles === []) {
            return;
        }

        $at = now();
        foreach ($articles as $article) {
            $article->update(['status' => 'published', 'published_at' => $at]);
        }
    }

    /**
     * หมวดก่อน หัวข้อทีหลัง — เดิมสุ่มหัวข้อจากทุกหมวดรวมกันแล้วสุ่มหมวดแยกอีกที
     * ได้บทความ "liquidity pool" ในหมวดข่าว และ "รอบตลาด Bitcoin" ในหมวดสอนใช้งาน.
     *
     * หัวข้อที่เคยเขียนภายใน TOPIC_COOLDOWN_DAYS วันถูกข้าม · ใช้ครบทุกหัวข้อแล้ว = หยิบหัวข้อที่เขียนนานที่สุด
     *
     * @param  list<string>  $categories
     * @return array{0: string, 1: string} [หมวด, หัวข้อ]
     */
    private function pickTopic(array $categories): array
    {
        $pool = self::topicPool();
        $candidates = [];
        foreach ($categories as $cat) {
            foreach ($pool[$cat] ?? [] as $topic) {
                $candidates['topic:'.Str::limit(Str::slug($topic), 150, '')] = [$cat, $topic];
            }
        }

        if ($candidates === []) {
            return ['tpix_chain', $pool['tpix_chain'][0]];
        }

        $lastUsed = Article::withTrashed()
            ->whereIn('source_key', array_keys($candidates))
            ->selectRaw('source_key, MAX(created_at) as last_used')
            ->groupBy('source_key')
            ->pluck('last_used', 'source_key');

        $cutoff = now()->subDays(self::TOPIC_COOLDOWN_DAYS);
        $fresh = array_filter($candidates, fn ($_, $key) => ! isset($lastUsed[$key]) || $lastUsed[$key] < $cutoff, ARRAY_FILTER_USE_BOTH);

        if ($fresh !== []) {
            return $fresh[array_rand($fresh)];
        }

        $oldest = $lastUsed->sort()->keys()->first();

        return $candidates[$oldest] ?? $candidates[array_rand($candidates)];
    }

    /**
     * หัวข้อบทความความรู้ — ตั้งใจให้เป็นเรื่องที่ไม่ต้องใช้ข้อมูลตลาดสด.
     * เดิมมี "วิเคราะห์ตลาดประจำสัปดาห์" / "คาดการณ์ราคา Bitcoin" ซึ่ง AI ไม่มีข้อมูลจริง → แต่งตัวเลขเอง.
     *
     * @return array<string, list<string>>
     */
    public static function topicPool(): array
    {
        return [
            'tpix_chain' => [
                'What TPIX Chain is and why it has zero gas fees',
                'How to add TPIX Chain (chain ID 4289) to your wallet step by step',
                'TPIX Chain vs other EVM blockchains: what is different',
                'Building DApps on TPIX Chain - a developer starter guide',
                'TPIX tokenomics explained: a fixed supply of 7 billion',
                'TPIX Chain FoodPassport: tracing food on the blockchain',
                'How master nodes and validators keep TPIX Chain running',
            ],
            'defi' => [
                'Understanding liquidity pools and impermanent loss',
                'How an AMM exchange like TPIX DEX prices a swap',
                'Slippage and price impact: how to avoid a bad swap',
                'DeFi security best practices for traders',
                'Cross-chain bridging explained for beginners',
            ],
            'news' => [
                'Web3 adoption in Southeast Asia: what it means for everyday users',
                'What a blockchain explorer shows you and how to read it',
                'How to spot crypto scams and fake tokens',
            ],
            'analysis' => [
                'How to read a candlestick chart - a beginner guide',
                'Market cap, circulating supply and FDV explained',
                'Why liquidity matters more than price for small tokens',
            ],
            'tutorial' => [
                'How to trade on a decentralized exchange safely',
                'Beginner guide to crypto wallets and seed phrases',
                'Gas fees explained - and why TPIX Chain has none',
                'How to provide liquidity on TPIX DEX',
            ],
            'technology' => [
                'Layer 2 scaling solutions explained',
                'Zero-knowledge proofs in plain language',
                'AI and blockchain: real use cases',
                'Smart contract security best practices',
            ],
        ];
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }
}
