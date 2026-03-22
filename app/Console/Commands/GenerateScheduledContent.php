<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\SiteSetting;
use App\Services\ContentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — Auto-generate content ตาม schedule ที่ตั้งไว้.
 *
 * ตรวจสอบ settings:
 * - content_auto_enabled: เปิด/ปิดระบบ
 * - content_frequency: daily, twice_daily, weekly, custom
 * - content_time_slots: ช่วงเวลาที่สร้าง (JSON array เช่น ["09:00","18:00"])
 * - content_categories: หมวดหมู่ที่สร้าง (JSON array)
 * - content_language: ภาษา (th/en/both)
 * - content_auto_publish: publish ทันทีหรือ draft
 */
class GenerateScheduledContent extends Command
{
    protected $signature = 'content:generate-scheduled';

    protected $description = 'Auto-generate AI articles based on schedule settings';

    public function handle(ContentService $contentService): int
    {
        // ตรวจว่าเปิดระบบ auto content หรือไม่
        $enabled = SiteSetting::get('content', 'content_auto_enabled', false);
        if (! $enabled) {
            $this->line('Auto content generation is disabled.');

            return self::SUCCESS;
        }

        // ตรวจว่าถึงเวลา slot หรือยัง
        $timeSlots = SiteSetting::get('content', 'content_time_slots', '["09:00"]');
        $slots = is_string($timeSlots) ? json_decode($timeSlots, true) : $timeSlots;
        $slots = is_array($slots) ? $slots : ['09:00'];

        $now = now();
        $currentTime = $now->format('H:i');
        $matched = false;

        foreach ($slots as $slot) {
            // ตรง ±5 นาที
            $slotMinutes = $this->timeToMinutes($slot);
            $nowMinutes = $this->timeToMinutes($currentTime);
            if (abs($slotMinutes - $nowMinutes) <= 5) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            $this->line('Not in time slot. Slots: '.implode(', ', $slots)." | Now: {$currentTime}");

            return self::SUCCESS;
        }

        // ตรวจว่าวันนี้สร้างไปแล้วกี่บทความ
        $maxPerDay = (int) SiteSetting::get('content', 'content_max_per_day', 2);
        $todayCount = Article::where('is_ai_generated', true)
            ->whereDate('created_at', $now->toDateString())
            ->count();

        if ($todayCount >= $maxPerDay) {
            $this->line("Daily limit reached ({$todayCount}/{$maxPerDay}).");

            return self::SUCCESS;
        }

        // ดึง categories + language
        $categories = SiteSetting::get('content', 'content_categories', '["tpix_chain","defi","news"]');
        $cats = is_string($categories) ? json_decode($categories, true) : $categories;
        $cats = is_array($cats) ? $cats : ['tpix_chain', 'defi', 'news'];

        $language = SiteSetting::get('content', 'content_language', 'th');
        $autoPublish = SiteSetting::get('content', 'content_auto_publish', false);

        // สุ่มหัวข้อจาก pool
        $topics = $this->getTopicPool($cats);
        $topic = $topics[array_rand($topics)];
        $category = $cats[array_rand($cats)];

        // สร้างภาษาที่ต้องการ
        $languages = $language === 'both' ? ['th', 'en'] : [$language];

        foreach ($languages as $lang) {
            try {
                $article = $contentService->generateArticle($topic, $category, $lang);

                if ($autoPublish) {
                    $article->update([
                        'status' => 'published',
                        'published_at' => now(),
                    ]);
                }

                $this->info("Generated: [{$lang}] {$article->title}");
                Log::info('Auto content generated', [
                    'id' => $article->id,
                    'title' => $article->title,
                    'category' => $category,
                    'language' => $lang,
                ]);
            } catch (\Exception $e) {
                $this->error("Failed: {$e->getMessage()}");
                Log::error('Auto content failed', ['error' => $e->getMessage()]);
            }
        }

        // Publish scheduled articles ที่ถึงเวลา
        $published = $contentService->publishScheduledArticles();
        if ($published > 0) {
            $this->info("Published {$published} scheduled article(s).");
        }

        return self::SUCCESS;
    }

    /**
     * สร้าง topic pool ตาม categories.
     */
    private function getTopicPool(array $categories): array
    {
        $pool = [
            'tpix_chain' => [
                'TPIX Chain ecosystem update and development progress',
                'How TPIX Chain gasless transactions work',
                'TPIX Chain vs other EVM blockchains comparison',
                'Building DApps on TPIX Chain - developer guide',
                'TPIX staking rewards and tokenomics explained',
                'TPIX Chain FoodPassport system for food traceability',
            ],
            'defi' => [
                'Latest DeFi trends and yield farming strategies',
                'Understanding liquidity pools and impermanent loss',
                'Top DEX protocols compared - which is best',
                'DeFi security best practices for traders',
                'Cross-chain bridging explained for beginners',
            ],
            'news' => [
                'Weekly crypto market analysis and outlook',
                'Latest blockchain technology developments',
                'Crypto regulation updates around the world',
                'NFT and digital asset market trends',
                'Web3 adoption progress in Southeast Asia',
            ],
            'analysis' => [
                'Technical analysis of top cryptocurrencies',
                'Bitcoin market cycle and price predictions',
                'Ethereum ecosystem growth analysis',
                'DeFi TVL trends and protocol comparison',
            ],
            'tutorial' => [
                'How to trade on a decentralized exchange safely',
                'Beginner guide to cryptocurrency wallets',
                'Understanding gas fees and transaction costs',
                'How to provide liquidity on TPIX TRADE',
            ],
            'technology' => [
                'Layer 2 scaling solutions explained',
                'Zero-knowledge proofs in blockchain',
                'AI and blockchain integration use cases',
                'Smart contract security best practices',
            ],
        ];

        $topics = [];
        foreach ($categories as $cat) {
            if (isset($pool[$cat])) {
                $topics = array_merge($topics, $pool[$cat]);
            }
        }

        return $topics ?: ['Latest cryptocurrency and blockchain news update'];
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }
}
