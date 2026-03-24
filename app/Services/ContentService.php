<?php

namespace App\Services;

use App\Models\Article;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — Content Service
 * สร้างบทความด้วย AI (Groq) + สร้างภาพด้วย AI (หลาย provider)
 * Image providers: Pollinations (ฟรี), Together.ai (FLUX), HuggingFace, Google Gemini.
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

        $parsed = $this->parseJsonResponse($result['content']);

        // สร้าง cover image ด้วย AI
        $coverImage = null;

        try {
            $imagePrompt = "Professional crypto blockchain article cover image about: {$topic}, dark theme, cyan blue accent, futuristic, minimalist, no text";
            $coverImage = $this->generateImage($imagePrompt);
        } catch (\Exception $e) {
            Log::warning('AI image generation failed', ['error' => $e->getMessage()]);
        }

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

    // =========================================================================
    // Image Generation — รองรับหลาย provider
    // =========================================================================

    /**
     * รายการ image provider ที่รองรับ.
     */
    public static function imageProviders(): array
    {
        return [
            'cloudflare' => [
                'name' => 'Cloudflare FLUX',
                'description' => 'FLUX.1-schnell — ฟรี คุณภาพสูง เร็ว (แนะนำ)',
                'requires_key' => false,
                'setting_key' => null,
            ],
            'pollinations' => [
                'name' => 'Pollinations.ai',
                'description' => 'ฟรี ไม่ต้อง API key — ภาพสไตล์ artistic',
                'requires_key' => false,
                'setting_key' => null,
            ],
            'together' => [
                'name' => 'Together.ai (FLUX)',
                'description' => 'FLUX.1 Schnell — คุณภาพสูง ภาพสมจริง',
                'requires_key' => true,
                'setting_key' => 'together_api_key',
            ],
            'huggingface' => [
                'name' => 'Hugging Face',
                'description' => 'SDXL-Lightning — เร็ว หลายโมเดล',
                'requires_key' => true,
                'setting_key' => 'huggingface_api_key',
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'description' => 'Imagen — คุณภาพระดับ Google (500 รูป/วัน ฟรี)',
                'requires_key' => true,
                'setting_key' => 'gemini_api_key',
            ],
        ];
    }

    /**
     * สร้างภาพ — เลือก provider ได้ (default = pollinations).
     */
    public function generateImage(string $prompt, int $width = 1200, int $height = 630, string $provider = 'auto'): ?string
    {
        if ($provider === 'auto') {
            $provider = $this->selectBestProvider();
        }

        $imageData = match ($provider) {
            'cloudflare' => $this->generateWithCloudflare($prompt),
            'together' => $this->generateWithTogether($prompt, $width, $height),
            'huggingface' => $this->generateWithHuggingFace($prompt, $width, $height),
            'gemini' => $this->generateWithGemini($prompt),
            default => $this->generateWithCloudflare($prompt),
        };

        if ($imageData) {
            $filename = 'articles/'.Str::random(20).'.jpg';
            Storage::disk('public')->put($filename, $imageData);

            return '/storage/'.$filename;
        }

        return null;
    }

    /**
     * เลือก provider ที่ดีที่สุดตาม API key ที่มี.
     */
    private function selectBestProvider(): string
    {
        // ถ้ามี API key ของ provider อื่น ให้ใช้ตัวนั้น
        foreach (['together', 'huggingface', 'gemini'] as $provider) {
            $settingKey = self::imageProviders()[$provider]['setting_key'] ?? null;
            if ($settingKey) {
                $key = SiteSetting::get('ai', $settingKey);
                if ($key && strlen($key) > 10 && ! str_starts_with($key, '****')) {
                    return $provider;
                }
            }
        }

        // Default: Cloudflare FLUX — ฟรี เร็ว คุณภาพสูง
        return 'cloudflare';
    }

    /**
     * Cloudflare Workers AI — FLUX.1-schnell (ฟรี คุณภาพสูง).
     */
    private function generateWithCloudflare(string $prompt): ?string
    {
        $workerUrl = config('services.image_gen.url', 'https://tpix-image-gen.xjanovax.workers.dev/');
        $apiKey = config('services.image_gen.key');

        try {
            $response = Http::timeout(60)
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($workerUrl, [
                    'prompt' => $prompt,
                    'steps' => 4,
                ]);

            if ($response->successful() && strlen($response->body()) > 1000) {
                return $response->body();
            }

            Log::warning('Cloudflare image failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::warning('Cloudflare image error', ['error' => $e->getMessage()]);
        }

        // Fallback to Pollinations
        return $this->generateWithPollinations($prompt, 1200, 630);
    }

    /**
     * Pollinations.ai — ฟรี ไม่ต้อง API key.
     */
    private function generateWithPollinations(string $prompt, int $width, int $height): ?string
    {
        // ลองหลาย model — Pollinations เปลี่ยน default model บ่อย
        $models = ['flux', 'turbo'];

        foreach ($models as $model) {
            try {
                $encodedPrompt = urlencode($prompt);
                $seed = rand(1, 99999);
                $url = "https://image.pollinations.ai/prompt/{$encodedPrompt}?model={$model}&width={$width}&height={$height}&seed={$seed}&nologo=true";

                $response = Http::timeout(90)
                    ->withOptions(['allow_redirects' => true])
                    ->get($url);

                if ($response->successful() && strlen($response->body()) > 1000) {
                    $contentType = $response->header('Content-Type') ?? '';
                    if (str_contains($contentType, 'image') || str_starts_with($response->body(), "\xFF\xD8") || str_starts_with($response->body(), "\x89PNG")) {
                        return $response->body();
                    }
                }

                Log::info("Pollinations model {$model} failed", ['status' => $response->status(), 'size' => strlen($response->body())]);
            } catch (\Exception $e) {
                Log::warning("Pollinations {$model} error", ['error' => $e->getMessage()]);
            }
        }

        // Fallback: สร้าง gradient placeholder ด้วย GD/Imagick
        return $this->generatePlaceholderImage($prompt, $width, $height);
    }

    /**
     * สร้าง placeholder image ด้วย PHP GD — gradient + text overlay.
     */
    private function generatePlaceholderImage(string $prompt, int $width, int $height): ?string
    {
        if (! extension_loaded('gd')) {
            Log::warning('GD extension not available for placeholder image');

            return null;
        }

        $img = imagecreatetruecolor($width, $height);

        // Dark gradient background
        for ($y = 0; $y < $height; $y++) {
            $r = (int) (10 + ($y / $height) * 15);
            $g = (int) (15 + ($y / $height) * 25);
            $b = (int) (30 + ($y / $height) * 50);
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $width, $y, $color);
        }

        // Cyan accent circle
        $cyan = imagecolorallocate($img, 6, 182, 212);
        imagefilledellipse($img, (int) ($width * 0.7), (int) ($height * 0.4), 200, 200, $cyan);

        // Semi-transparent overlay
        $overlay = imagecolorallocatealpha($img, 10, 15, 30, 80);
        imagefilledrectangle($img, 0, 0, $width, $height, $overlay);

        // Text: "TPIX TRADE"
        $white = imagecolorallocate($img, 255, 255, 255);
        $fontSize = 5;
        $text = 'TPIX TRADE';
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        imagestring($img, $fontSize, (int) (($width - $textWidth) / 2), (int) ($height * 0.4), $text, $white);

        // Subtitle
        $gray = imagecolorallocate($img, 150, 160, 180);
        $sub = substr($prompt, 0, 60);
        $subWidth = imagefontwidth(3) * strlen($sub);
        imagestring($img, 3, (int) (($width - $subWidth) / 2), (int) ($height * 0.55), $sub, $gray);

        ob_start();
        imagejpeg($img, null, 90);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data ?: null;
    }

    /**
     * Together.ai — FLUX.1 Schnell (คุณภาพสูง).
     */
    private function generateWithTogether(string $prompt, int $width, int $height): ?string
    {
        $apiKey = SiteSetting::get('ai', 'together_api_key') ?: config('services.together.key');
        if (! $apiKey) {
            return $this->generateWithPollinations($prompt, $width, $height);
        }

        try {
            // ปรับขนาดให้เป็นทวีคูณของ 64
            $w = (int) (round($width / 64) * 64);
            $h = (int) (round($height / 64) * 64);

            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.together.xyz/v1/images/generations', [
                    'model' => 'black-forest-labs/FLUX.1-schnell-Free',
                    'prompt' => $prompt,
                    'width' => min($w, 1440),
                    'height' => min($h, 1440),
                    'steps' => 4,
                    'n' => 1,
                    'response_format' => 'b64_json',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $b64 = $data['data'][0]['b64_json'] ?? null;
                if ($b64) {
                    return base64_decode($b64);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Together.ai image failed', ['error' => $e->getMessage()]);
        }

        return $this->generateWithPollinations($prompt, $width, $height);
    }

    /**
     * Hugging Face Inference API — SDXL-Lightning.
     */
    private function generateWithHuggingFace(string $prompt, int $width, int $height): ?string
    {
        $apiKey = SiteSetting::get('ai', 'huggingface_api_key') ?: config('services.huggingface.key');
        if (! $apiKey) {
            return $this->generateWithPollinations($prompt, $width, $height);
        }

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api-inference.huggingface.co/models/ByteDance/SDXL-Lightning', [
                    'inputs' => $prompt,
                    'parameters' => [
                        'width' => min($width, 1024),
                        'height' => min($height, 1024),
                    ],
                ]);

            if ($response->successful() && str_starts_with($response->header('Content-Type') ?? '', 'image/')) {
                return $response->body();
            }
        } catch (\Exception $e) {
            Log::warning('HuggingFace image failed', ['error' => $e->getMessage()]);
        }

        return $this->generateWithPollinations($prompt, $width, $height);
    }

    /**
     * Google Gemini — Imagen image generation.
     */
    private function generateWithGemini(string $prompt): ?string
    {
        $apiKey = SiteSetting::get('ai', 'gemini_api_key') ?: config('services.gemini.key');
        if (! $apiKey) {
            return $this->generateWithPollinations($prompt, 1200, 630);
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp-image-generation:generateContent', [
                    'contents' => [
                        ['parts' => [['text' => "Generate: {$prompt}"]]],
                    ],
                    'generationConfig' => [
                        'responseModalities' => ['IMAGE'],
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'] ?? [];
                foreach ($parts as $part) {
                    if (isset($part['inlineData']['data'])) {
                        return base64_decode($part['inlineData']['data']);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Gemini image failed', ['error' => $e->getMessage()]);
        }

        return $this->generateWithPollinations($prompt, 1200, 630);
    }

    // =========================================================================
    // Scheduling
    // =========================================================================

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
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);

        return is_array($parsed) ? $parsed : ['title' => 'Untitled', 'content' => $content];
    }
}
