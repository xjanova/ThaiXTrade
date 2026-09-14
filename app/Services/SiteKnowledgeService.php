<?php

namespace App\Services;

use App\Models\Article;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ข้อมูลสดของเว็บที่ป้อนให้ผู้ช่วย AI ("สมอง" ตัวเดียวกันทั้งหน้าเว็บและ Discord).
 *
 * เดิม prompt ของผู้ช่วยเป็นข้อความตายตัวที่ล้าสมัยไปแล้ว ("รับ USDT อย่างเดียว"
 * ทั้งที่รอบขายเปลี่ยนเป็นบัตรอย่างเดียว) — ถามเรื่องการขายเหรียญแล้วได้คำตอบผิด
 * ชั้นนี้ดึงของจริงจากฐานข้อมูลทุก 5 นาที แล้วบอก AI ว่า "ข้อมูลสดชนะข้อความข้างบน"
 *
 * ใส่เฉพาะข้อมูลสาธารณะเท่านั้น (สิ่งที่หน้าเว็บโชว์อยู่แล้ว) — ห้ามมีที่อยู่กระเป๋าคลัง/ค่าตั้งภายใน
 *
 * Developed by Xman Studio.
 */
class SiteKnowledgeService
{
    private const TTL = 300;

    private const LATEST_ARTICLES = 5;

    public function __construct(private readonly SaleStatusService $sale) {}

    public function liveFacts(): string
    {
        try {
            return Cache::remember('site_knowledge:live_facts', self::TTL, fn () => $this->build());
        } catch (\Throwable $e) {
            // สมองต้องตอบได้ต่อแม้ข้อมูลสดดึงไม่ได้ — แค่บอก AI ว่าไม่มีข้อมูลสด อย่าเดา
            Log::warning('SiteKnowledge: ดึงข้อมูลสดไม่สำเร็จ', ['error' => $e->getMessage()]);

            return "## LIVE DATA\nยังดึงข้อมูลสดไม่ได้ในขณะนี้ — ถ้าถูกถามเรื่องสถานะการขายเหรียญ ให้แนะนำดูที่ /token-sale และห้ามเดาว่าเปิดขายอยู่";
        }
    }

    public function forget(): void
    {
        Cache::forget('site_knowledge:live_facts');
    }

    private function build(): string
    {
        $now = now()->timezone('Asia/Bangkok')->format('Y-m-d H:i');
        $parts = [
            "## LIVE DATA (อัปเดตอัตโนมัติ {$now} เวลาไทย) — ข้อมูลส่วนนี้ถูกต้องที่สุด ถ้าขัดกับข้อความข้างบนให้เชื่อส่วนนี้",
            '### การขายเหรียญ TPIX (ตอบตามนี้เท่านั้น ห้ามบอกว่าเปิดขายถ้าสถานะไม่ใช่ "เปิดขายแล้ว")',
            $this->sale->asText(),
        ];

        $articles = Article::published()
            ->where('language', (string) config('discord.article_language', 'th'))
            ->orderByDesc('published_at')
            ->limit(self::LATEST_ARTICLES)
            ->get(['title', 'slug']);

        if ($articles->isNotEmpty()) {
            $parts[] = '### บทความล่าสุด';
            foreach ($articles as $article) {
                $parts[] = "- {$article->title} — /blog/{$article->slug}";
            }
        }

        $parts[] = '### วิดีโอ';
        $parts[] = 'ซีรีส์วิดีโอ Whitepaper '.count((array) config('discord.videos', [])).' ตอน ดูพร้อมเนื้อหาประกอบได้ที่ /whitepaper';

        $discord = trim((string) (SiteSetting::get('social', 'discord') ?: SiteSetting::get('social', 'discord_url') ?: ''));
        if ($discord !== '') {
            $parts[] = '### ชุมชน';
            $parts[] = "Discord: {$discord}";
        }

        return implode("\n", $parts);
    }
}
