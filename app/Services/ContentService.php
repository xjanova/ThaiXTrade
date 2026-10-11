<?php

namespace App\Services;

use App\Models\Article;
use App\Models\SiteSetting;
use App\Services\News\NewsEventCollector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — Content Service
 * สร้างบทความด้วย AI (AiTextService: OpenAI → Groq) + สร้างภาพด้วย AI (หลาย provider)
 * ข่าวจากเหตุการณ์จริงเขียนจากข้อเท็จจริงของ NewsEventCollector · ที่อยู่สัญญาระบบเติมเอง ไม่ผ่าน AI
 * Image providers: Pollinations (ฟรี), Together.ai (FLUX), HuggingFace, Google Gemini.
 */
class ContentService
{
    /** เนื้อหาสั้นกว่านี้ (ตัวอักษร ไม่นับแท็ก) = AI ตอบไม่ครบ/โดนตัด ไม่ใช่บทความ */
    private const MIN_CONTENT_CHARS = 300;

    private const ADDRESS_PATTERN = '/0x[a-fA-F0-9]{40}/';

    public function __construct(
        private AiTextService $groq,
        private NewsEventCollector $events,
    ) {}

    /**
     * บทความความรู้ทั่วไป — หลังบ้านกดสร้าง และบอทตามตารางเวลา.
     *
     * @param  string|null  $sourceKey  "topic:…" จากบอทตามตารางเวลา (ใช้กันหัวข้อวนซ้ำ)
     */
    public function generateArticle(string $topic, string $category = 'news', string $language = 'th', ?string $model = null, ?string $sourceKey = null): Article
    {
        $prompt = implode("\n", [
            "Write a helpful, accurate article about: {$topic}",
            "Category: {$category}",
            'Explain it so a newcomer understands, and connect it to what TPIX Chain / TPIX TRADE offers TODAY according to LIVE STATUS.',
            'Length: 600-1000 words. Use <h2>, <p>, <ul><li>, <strong>.',
        ]);

        return $this->write($prompt, [
            'category' => $category,
            'language' => $language,
            'model' => $model,
            'source_key' => $sourceKey,
            'fallback_title' => $topic,
            'image' => "Professional crypto blockchain article cover image about: {$topic}, dark theme, cyan blue accent, futuristic, minimalist, no text",
        ]);
    }

    /**
     * ข่าวจากเหตุการณ์จริง (NewsEventCollector) — เขียนจากข้อเท็จจริงที่ให้เท่านั้น โทนประกาศเปิดตัว.
     *
     * @param  array{key: string, headline: string, facts: list<string>, addresses: array<string, string>, links: array<string, string>, image: string}  $event
     */
    public function generateEventArticle(array $event, string $language): Article
    {
        $facts = implode("\n", array_map(fn (string $f) => "- {$f}", $event['facts']));
        $links = implode("\n", array_map(fn ($label, $url) => "- {$label}: {$url}", array_keys($event['links']), $event['links']));

        $prompt = <<<PROMPT
        BREAKING NEWS — write the announcement for something that just happened on TPIX Chain.
        Working headline (rewrite it in the target language, keep it punchy): {$event['headline']}

        EVENT FACTS — the only source for this article:
        {$facts}

        Links (already added under the article — you may mention them by name):
        {$links}

        Style: an exciting launch announcement — this is a real milestone for the TPIX community, so let it feel like one.
        - Headline: strong and specific, at most one emoji.
        - First paragraph: exactly what is live now and why it matters to TPIX holders.
        - A short "how to try it now" section with numbered steps.
        - End with a line inviting readers to try it and share feedback in the community.
        - Energy comes from the facts. No price talk, no "to the moon", no promises of profit.
        - 400-700 words. Use <h2>, <p>, <ol>/<ul>, <strong>.
        PROMPT;

        return $this->write($prompt, [
            'category' => 'news',
            'language' => $language,
            'source_key' => $event['key'],
            'fallback_title' => $event['headline'],
            'image' => $event['image'],
            'addresses' => $event['addresses'],
            'links' => $event['links'],
            'tags' => ['TPIX Chain', 'announcement'],
        ]);
    }

    /**
     * @param  array{category: string, language: string, model?: ?string, source_key?: ?string, fallback_title: string, image: string, addresses?: array<string, string>, links?: array<string, string>, tags?: list<string>}  $brief
     */
    private function write(string $prompt, array $brief): Article
    {
        $language = $brief['language'];
        $options = ['temperature' => 0.7, 'max_tokens' => 4096];
        if (! empty($brief['model'])) {
            $options['model'] = $brief['model'];
        }

        $result = $this->groq->chat($prompt, $this->systemPrompt($language), $options);

        if (! $result['success']) {
            throw new \RuntimeException('AI generation failed: '.($result['error'] ?? 'Unknown error'));
        }

        $parsed = $this->parseJsonResponse($result['content']);
        $title = trim(strip_tags((string) ($parsed['title'] ?? '')));
        $content = trim((string) ($parsed['content'] ?? ''));

        // เคยหลุดขึ้นเว็บเป็นบทความชื่อ "Untitled" (id 258, 2026-08-06) — JSON โดนตัดกลางทาง ระบบเก็บข้อความดิบไว้แล้ว publish
        if ($title === '' || $title === 'Untitled' || mb_strlen(strip_tags($content)) < self::MIN_CONTENT_CHARS) {
            throw new \RuntimeException('AI ตอบกลับไม่ครบ (ไม่มีหัวข้อ หรือเนื้อหาถูกตัดกลางทาง) — ไม่ได้บันทึกบทความ ลองใหม่อีกครั้ง');
        }

        $addresses = $brief['addresses'] ?? [];
        $content = $this->scrubAddresses($content, $language).$this->officialBlock($addresses, $brief['links'] ?? [], $language);
        $summary = $this->scrubAddresses(trim(strip_tags((string) ($parsed['summary'] ?? ''))), $language);

        $coverImage = null;

        try {
            $coverImage = $this->generateImage($brief['image']);
        } catch (\Exception $e) {
            Log::warning('AI image generation failed', ['error' => $e->getMessage()]);
        }

        $tags = array_values(array_unique(array_merge(
            $brief['tags'] ?? [],
            array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : null, (array) ($parsed['tags'] ?? [$brief['category']]))),
        )));

        return Article::create([
            'title' => mb_substr($title, 0, 250),
            'summary' => $summary,
            'content' => $content,
            'cover_image' => $coverImage,
            'language' => $language,
            'category' => $brief['category'],
            'tags' => array_slice($tags, 0, 8),
            'status' => 'draft',
            'is_ai_generated' => true,
            'ai_model' => $result['model'] ?? ($result['provider'] ?? null),
            'ai_tokens_used' => $result['tokens_used'] ?? 0,
            'ai_image_prompt' => $coverImage ? mb_substr($brief['image'], 0, 255) : null,
            'seo_title' => mb_substr($this->scrubAddresses(trim(strip_tags((string) ($parsed['seo_title'] ?? $title))), $language), 0, 250),
            'seo_description' => $this->scrubAddresses(trim(strip_tags((string) ($parsed['seo_description'] ?? $summary))), $language),
            'author_name' => 'TPIX AI Writer',
            'source_key' => $brief['source_key'] ?? null,
        ]);
    }

    /**
     * ข้อเท็จจริงคงที่ + สถานะสด + กติกา — เดิมมีแค่ข้อเท็จจริงคงที่ AI จึงเดาราคา/ข่าวตลาดเอาเอง
     * และไม่รู้ว่า DEX เปิดแล้ว.
     */
    private function systemPrompt(string $language): string
    {
        $langRule = $language === 'th'
            ? 'Write in natural, modern Thai like a Thai tech-news site. Keep product and token names in English (TPIX DEX, WTPIX, USDT, TPIX Wallet).'
            : 'Write in clear, natural English.';

        return implode("\n", [
            'You are the newsroom writer for TPIX TRADE (https://tpix.online) — the exchange and ecosystem site of TPIX Chain.',
            '',
            '## STANDING FACTS',
            '- TPIX Chain: EVM-compatible blockchain (Polygon Edge, IBFT consensus), chain ID 4289, ~2-second blocks, zero gas fees for users',
            '- TPIX total supply: 7,000,000,000 (fixed, no inflation)',
            '- Explorer: https://explorer.tpix.online · Developer: Xman Studio',
            '',
            $this->events->liveFacts(),
            '',
            '## RULES — an article that breaks any of these is thrown away',
            '1. Use only facts given in this prompt. Never invent prices, percentages, TVL, volumes, user counts, dates, partners, exchange listings, audits or roadmap promises.',
            '2. Never write a contract address or any 0x… hex string. Refer to contracts by name — the official addresses are appended under the article automatically.',
            '3. No price predictions, no "buy now", no promises of profit. Where it fits, remind readers that crypto is high-risk.',
            '4. If you would need a fact that is not given, leave that point out instead of guessing.',
            '5. '.$langRule,
            '',
            'Respond with valid JSON only (no markdown, no code fences):',
            '{"title":"...","summary":"1-2 sentences","content":"<p>HTML</p>","tags":["tag1","tag2"],"seo_title":"...","seo_description":"..."}',
        ]);
    }

    /** ที่อยู่ที่ AI พิมพ์เองเชื่อไม่ได้ (ผิดตัวเดียว = เงินผู้ใช้หาย) — ลบทิ้งแล้วชี้ไปรายการทางการท้ายบทความ */
    private function scrubAddresses(string $text, string $language): string
    {
        return (string) preg_replace(
            self::ADDRESS_PATTERN,
            $language === 'th' ? '(ดูที่อยู่ทางการท้ายบทความ)' : '(see the official addresses below)',
            $text,
        );
    }

    /**
     * ที่อยู่สัญญาทางการ + ลิงก์ — ระบบเขียนเองจากทะเบียนสัญญา ไม่ผ่าน AI.
     *
     * @param  array<string, string>  $addresses
     * @param  array<string, string>  $links
     */
    private function officialBlock(array $addresses, array $links, string $language): string
    {
        if ($addresses === [] && $links === []) {
            return '';
        }

        $th = $language === 'th';
        $explorer = rtrim((string) config('chains.chains.4289.explorer', 'https://explorer.tpix.online'), '/');
        $html = "\n<hr>";

        if ($addresses !== []) {
            $html .= '<h3>'.($th ? 'ที่อยู่สัญญาทางการ (TPIX Chain · 4289)' : 'Official contract addresses (TPIX Chain · 4289)').'</h3>';
            $html .= '<p>'.($th ? 'ตรวจที่อยู่ให้ตรงทุกครั้งก่อนโอนหรือสวอป — ใครก็สร้างเหรียญชื่อซ้ำได้' : 'Always check the address before you send or swap — anyone can create a token with the same name.').'</p><ul>';
            foreach ($addresses as $label => $address) {
                if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
                    continue;
                }
                $html .= '<li><strong>'.e($label).'</strong>: <a href="'.e("{$explorer}/address/{$address}").'" rel="noopener">'.e($address).'</a></li>';
            }
            $html .= '</ul>';
        }

        if ($links !== []) {
            $html .= '<h3>'.($th ? 'เริ่มใช้งาน' : 'Get started').'</h3><ul>';
            foreach ($links as $label => $url) {
                $html .= '<li><a href="'.e($url).'">'.e($label).'</a></li>';
            }
            $html .= '</ul>';
        }

        return $html;
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
        $workerUrl = SiteSetting::get('ai', 'cloudflare_image_url')
            ?: config('services.image_gen.url', 'https://tpix-image-gen.xjanovax.workers.dev/');
        $apiKey = SiteSetting::get('ai', 'cloudflare_image_key')
            ?: config('services.image_gen.key');

        // ถ้าไม่มี API key ข้ามไป fallback เลย (ไม่ต้องรอ timeout)
        if (empty($apiKey)) {
            Log::info('Cloudflare image skipped: no API key, falling back');

            return $this->generateWithPollinations($prompt, 1200, 630);
        }

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
