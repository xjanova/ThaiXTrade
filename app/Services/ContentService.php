<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — Content Service
 * สร้างบทความด้วย AI (Groq) + สร้างภาพด้วย AI (Pollinations.ai — ฟรี)
 * รองรับ: auto-generate, scheduled publishing, multi-language.
 */
class ContentService
{
    public function __construct(
        private GroqService $groq,
    ) {}

    /**
     * สร้างบทความด้วย AI — ส่ง topic + category + language.
     */
    public function generateArticle(string $topic, string $category = 'news', string $language = 'th', ?string $model = null): Article
    {
        $langName = $language === 'th' ? 'Thai' : 'English';

        $systemPrompt = <<<'PROMPT'
        You are a professional crypto/blockchain content writer for TPIX TRADE — a decentralized exchange on TPIX Chain (Chain ID: 4289).
        Write well-researched, SEO-optimized articles. Always mention TPIX TRADE and TPIX Chain where relevant.

        Key facts about TPIX:
        - TPIX Chain: EVM blockchain, Chain ID 4289, Polygon Edge, IBFT consensus, 2s blocks, gasless
        - Total Supply: 7 billion TPIX (fixed, no inflation)
        - Use cases: DEX trading, FoodPassport, IoT Smart Farm, Carbon Credits, AI Bots, Staking
        - Website: https://tpix.online
        - Explorer: https://explorer.tpix.online
        - Developer: Xman Studio

        Format your response as valid JSON only (no markdown, no code fences):
        {"title":"...","summary":"...","content":"<p>HTML content here</p>","tags":["tag1","tag2"],"seo_title":"...","seo_description":"..."}
        PROMPT;

        $prompt = "Write a comprehensive article about: {$topic}\nCategory: {$category}\nLanguage: {$langName}\nLength: 800-1500 words";

        $options = ['temperature' => 0.7, 'max_tokens' => 4096];
        if ($model) {
            $options['model'] = $model;
        }

        $result = $this->groq->chat($prompt, $systemPrompt, $options);

        if (! $result['success']) {
            throw new \RuntimeException('AI generation failed: '.($result['error'] ?? 'Unknown error'));
        }

        // Parse JSON response
        $parsed = $this->parseJsonResponse($result['content']);

        // สร้าง cover image ด้วย AI
        $coverImage = null;

        try {
            $imagePrompt = "Professional crypto blockchain article cover image about: {$topic}, dark theme, cyan blue accent, futuristic, minimalist, no text";
            $coverImage = $this->generateImage($imagePrompt);
        } catch (\Exception $e) {
            Log::warning('AI image generation failed', ['error' => $e->getMessage()]);
        }

        // สร้าง Article
        return Article::create([
            'title' => $parsed['title'] ?? $topic,
            'summary' => $parsed['summary'] ?? '',
            'content' => $parsed['content'] ?? '<p>Content generation failed.</p>',
            'cover_image' => $coverImage,
            'language' => $language,
            'category' => $category,
            'tags' => $parsed['tags'] ?? [$category],
            'status' => 'draft',
            'is_ai_generated' => true,
            'ai_model' => $result['model'] ?? 'llama-3.3-70b-versatile',
            'ai_tokens_used' => $result['tokens_used'] ?? 0,
            'ai_image_prompt' => $coverImage ? ($imagePrompt ?? null) : null,
            'seo_title' => $parsed['seo_title'] ?? ($parsed['title'] ?? $topic),
            'seo_description' => $parsed['seo_description'] ?? ($parsed['summary'] ?? ''),
            'author_name' => 'TPIX AI Writer',
        ]);
    }

    /**
     * สร้างภาพด้วย Pollinations.ai (ฟรี ไม่ต้อง API key).
     */
    public function generateImage(string $prompt, int $width = 1200, int $height = 630): ?string
    {
        $encodedPrompt = urlencode($prompt);
        $seed = rand(1, 99999);
        $url = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width={$width}&height={$height}&seed={$seed}&nologo=true";

        try {
            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                $filename = 'articles/'.Str::random(20).'.jpg';
                Storage::disk('public')->put($filename, $response->body());

                return '/storage/'.$filename;
            }
        } catch (\Exception $e) {
            Log::warning('Image generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Publish scheduled articles ที่ถึงเวลาแล้ว.
     */
    public function publishScheduledArticles(): int
    {
        $articles = Article::scheduledReady()->get();

        foreach ($articles as $article) {
            $article->update([
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        return $articles->count();
    }

    /**
     * Parse JSON response จาก AI (อาจมี markdown fences).
     */
    private function parseJsonResponse(string $content): array
    {
        // ลบ markdown code fences ถ้ามี
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);

        return is_array($parsed) ? $parsed : ['title' => 'Untitled', 'content' => $content];
    }
}
