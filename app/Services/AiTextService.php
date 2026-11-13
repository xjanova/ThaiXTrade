<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — งานข้อความด้วย AI (ผู้ช่วยตอบคำถาม · วิเคราะห์ตลาด · เขียนข่าว).
 *
 * แทนที่ `GroqService` ที่ผูกกับผู้ให้บริการเดียว — หน้าตาเมธอดเหมือนเดิมทุกตัว
 * เพื่อให้สลับได้โดยไม่ต้องแก้ผู้เรียกทั้งสี่จุด (ชั้นนี้ไม่เรียก GroqService แล้ว — ยิงเองทุกเจ้า
 * ด้วยโมเดลจาก config/ai_text.php)
 *
 * ═══ ทำไมต้องมีชั้นนี้ ═══
 *
 * ผู้ช่วย AI ของเว็บ **ตายเงียบมาตั้งแต่ ~18 ส.ค. 2026** เพราะ Groq ถอดโมเดล
 * `llama-3.3-70b-versatile` ออก (ฝังไว้ในโค้ดที่ GroqService::$defaultModel)
 * อาการเดียวกับที่ทำให้งานเจนคอนเทนต์ล้มวันละ 2 รอบโดยไม่มีใครรู้
 *
 * บทเรียนที่ทำให้ออกแบบแบบนี้: **ผู้ให้บริการรายเดียว = จุดตายจุดเดียว** และ
 * "ชื่อโมเดล" เป็นค่าที่เน่าได้เองโดยไม่มีใครแตะ ผู้ให้บริการถอดเมื่อไหร่ก็ได้
 *
 * ลำดับที่ใช้: OpenAI (คีย์จากพูล Thaiprompt) → Groq (สำรอง)
 * ทั้งสองเจ้าใช้รูปแบบ request เดียวกัน (Groq ตั้งใจทำ endpoint ให้เข้ากันได้กับ
 * OpenAI) จึงใช้โค้ดยิงชุดเดียวกันได้ ต่างแค่ URL + คีย์ + ชื่อโมเดล
 *
 * ⚠️ คีย์ OpenAI มาจากพูลของ Thaiprompt ซึ่ง **บิลรวมกันทั้ง org**
 *    เพิ่มการใช้งานตรงนี้ = เพิ่มบิลก้อนเดียวกับบอทดูดวง ดู ai:pull-pool-key
 *
 * Developed by Xman Studio.
 */
class AiTextService
{
    /** ผู้ให้บริการที่รองรับ เรียงตามลำดับที่จะลอง */
    private const PROVIDERS = ['openai', 'groq'];

    /**
     * สวิตช์เปิด/ปิดฟีเจอร์ AI ที่แอดมินตั้งในหลังบ้าน (กลุ่ม ai) — ไม่เคยตั้ง = เปิด.
     *
     * ⚠️ `ai_chatbot_enabled` กับ `ai_content_enabled` ถูกบันทึกจากแท็บ AI มาตลอด แต่ไม่มีโค้ดฝั่ง
     *    เซิร์ฟเวอร์ตัวไหนอ่านเลย — แอดมินปิดแล้วทุกอย่างยังทำงานต่อ (และกินโควตา API ต่อ)
     *
     * อ่านค่าไม่ได้ (ฐานข้อมูลมีปัญหา) = ถือว่าเปิด: สวิตช์นี้มีไว้ปิดบริการชั่วคราว ไม่ใช่ด่านความปลอดภัย
     * การล่มของฐานข้อมูลไม่ควรกลายเป็น "ปิดผู้ช่วย AI" เพิ่มอีกอาการ
     */
    public static function featureEnabled(string $key): bool
    {
        try {
            $value = SiteSetting::get('ai', $key);
        } catch (\Throwable) {
            return true;
        }

        if ($value === null || $value === '') {
            return true;
        }

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * ถาม AI หนึ่งครั้ง — ลอง OpenAI ก่อน ตกไป Groq ถ้าไม่ได้.
     *
     * @return array{success: bool, content?: string, model?: string, provider?: string, error?: string, processing_time_ms?: int}
     */
    public function chat(string $message, string $systemPrompt = '', array $options = []): array
    {
        $errors = [];

        foreach (self::PROVIDERS as $provider) {
            $config = $this->configFor($provider);

            if ($config === null) {
                $errors[] = __(':provider: ยังไม่ได้ตั้งคีย์', ['provider' => $provider]);

                continue;
            }

            /*
             * ทุกเจ้ายิงผ่าน send() ตัวเดียวกัน ด้วยโมเดลของเจ้านั้นจาก config/ai_text.php
             *
             * ⚠️ เดิมสาย Groq เรียก GroqService::chat() ซึ่งไม่อ่าน config นี้เลย — ใช้ค่าปริยายที่ฝังใน
             *    คลาส (llama-3.3-70b-versatile ที่ Groq ถอดแล้ว) หรือค่าเก่าที่ค้างในหลังบ้าน
             *    "ตัวสำรอง" จึงตายพร้อมตัวหลักทุกครั้ง: OpenAI ล้มเมื่อไหร่ Groq ก็ตอบ 404 ตามทันที
             */
            $result = $this->send($config, $message, $systemPrompt, $this->optionsFor($provider, $options));

            if ($result['success'] ?? false) {
                return $result + ['provider' => $provider];
            }

            /*
             * ล้มแล้วลองเจ้าถัดไป แต่ต้องเก็บเหตุผลไว้ทุกเจ้า
             *
             * เดิมเวลาพังจะเห็นแค่ error ของเจ้าสุดท้าย ทำให้ตามไม่ได้ว่าเจ้าแรก
             * ล้มเพราะอะไร — ซึ่งเป็นข้อมูลที่ต้องใช้ตอนแยกว่า 404 (โมเดลตาย)
             * 401 (คีย์) หรือ 429 (โควตา) สามอย่างนี้แก้คนละทางกันหมด
             */
            $errors[] = $provider.': '.($result['error'] ?? __('ไม่ทราบสาเหตุ'));
        }

        Log::warning('AI text: ล้มทุกผู้ให้บริการ', ['errors' => $errors]);

        return ['success' => false, 'error' => implode(' · ', $errors)];
    }

    /** วิเคราะห์ตลาด — เนื้อหา prompt เหมือนเดิม เปลี่ยนแค่ทางที่ยิงออก */
    public function analyzeMarket(string $symbol, string $type = 'technical', array $marketData = [], ?string $model = null): array
    {
        $systemPrompt = 'You are an expert cryptocurrency market analyst for TPIX TRADE exchange. Provide professional, data-driven analysis. Always include risk warnings. Format output with clear sections. Respond in the language specified by the user.';

        $dataContext = ! empty($marketData) ? "\n\nMarket Data:\n".json_encode($marketData, JSON_PRETTY_PRINT) : '';

        $prompts = [
            'technical' => "Perform a detailed technical analysis for {$symbol}. Include support/resistance levels, trend analysis, key indicators (RSI, MACD, Moving Averages), and trading signals. {$dataContext}",
            'sentiment' => "Analyze the current market sentiment for {$symbol}. Consider social media trends, news impact, fear & greed index, whale movements, and on-chain metrics. {$dataContext}",
            'price_prediction' => "Provide a price prediction analysis for {$symbol} for the next 24h, 7d, and 30d timeframes. Include bull and bear scenarios with probability estimates. Always include disclaimer about prediction limitations. {$dataContext}",
            'market_analysis' => "Provide a comprehensive market overview for {$symbol}. Include price action analysis, volume analysis, market cap trends, and comparison with overall crypto market. {$dataContext}",
        ];

        // โมเดลที่แอดมินเลือก (ถ้ามี) — ใช้ได้เฉพาะกับผู้ให้บริการที่มีโมเดลนั้น ดู optionsFor()
        return $this->chat($prompts[$type] ?? $prompts['market_analysis'], $systemPrompt, array_filter([
            'temperature' => 0.5,
            'max_tokens' => 4096,
            'model' => $model,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /** เขียนข่าว */
    public function generateNews(string $topic, string $category = 'market_update', string $language = 'th'): array
    {
        $langName = $language === 'th' ? 'Thai' : 'English';

        $systemPrompt = 'You are a professional cryptocurrency news writer for TPIX TRADE. Write well-researched, engaging articles. Include relevant data and analysis. The article should be professional and suitable for a trading platform\'s news section.';

        $prompt = "Write a comprehensive news article about: {$topic}\n\nCategory: {$category}\nLanguage: {$langName}\n\nFormat the response as JSON with these fields:\n- title: Article headline\n- summary: 2-3 sentence summary\n- content: Full article in HTML format with paragraphs, headings, and emphasis\n- tags: Array of relevant tags (3-5 tags)";

        return $this->chat($prompt, $systemPrompt, [
            'temperature' => 0.7,
            'max_tokens' => 4096,
        ]);
    }

    /**
     * โมเดลที่เลือกได้ในหลังบ้าน.
     *
     * ⚠️ รายการนี้เคยเป็นค่าฮาร์ดโค้ดของ Llama ทั้งชุด ซึ่ง Groq ถอดออกหมดแล้ว —
     *    แอดมินเปิดหน้าตั้งค่าแล้วเห็นแต่โมเดลที่ใช้ไม่ได้สักตัว แล้วเลือกอันไหน
     *    ก็พังเหมือนกันหมด รายการจึงต้องอยู่ใน config ไม่ใช่ในโค้ด
     *
     * @return array<string, string>
     */
    public function getModels(): array
    {
        return self::modelOptions();
    }

    /**
     * รายการเดียวกับ getModels() แบบไม่ต้องมีตัว service — ใช้ตรวจค่าที่ส่งมาจากฟอร์มหลังบ้าน
     * (อ่านจาก config ล้วน ไม่แตะคีย์หรือฐานข้อมูล).
     *
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        $models = [];

        foreach (self::PROVIDERS as $provider) {
            foreach ((array) config("ai_text.providers.{$provider}.models", []) as $id => $label) {
                $models[$id] = __($label);
            }
        }

        return $models;
    }

    /** ผู้ให้บริการที่พร้อมใช้จริงตอนนี้ — หน้าหลังบ้านใช้บอกสถานะ */
    public function availableProviders(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $provider) {
            $out[$provider] = $this->configFor($provider) !== null;
        }

        return $out;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * ค่าตั้งของผู้ให้บริการรายหนึ่ง — คืน null เมื่อยังไม่มีคีย์.
     *
     * คีย์อ่านจากหลังบ้านก่อน แล้วตกไป `.env` แบบเดียวกับที่ระบบทำอยู่ทุกที่
     */
    private function configFor(string $provider): ?array
    {
        $base = (array) config("ai_text.providers.{$provider}");

        if ($base === []) {
            return null;
        }

        $key = trim((string) (SiteSetting::get('ai', $base['key_setting'] ?? '') ?: config($base['key_config'] ?? '', '')));

        if ($key === '') {
            return null;
        }

        $model = trim((string) (SiteSetting::get('ai', $base['model_setting'] ?? '') ?: ''));

        /*
         * โมเดลที่ผู้ให้บริการถอดไปแล้ว = ถือว่าไม่ได้ตั้ง แล้วใช้ค่าปริยายจาก config
         *
         * ค่าในหลังบ้านไม่ได้เน่าเพราะใครแก้ — มันถูกบันทึกไว้ตอนโมเดลยังอยู่ (production ยังเก็บ
         * llama-3.3-70b-versatile) รอให้แอดมินเข้าไปเลือกใหม่เอง = ตัวสำรองใช้ไม่ได้ไปจนกว่าจะมีคนสังเกต
         */
        if ($model !== '' && in_array($model, (array) ($base['retired_models'] ?? []), true)) {
            $model = '';
        }

        return [
            'api_key' => $key,
            'endpoint' => $base['endpoint'],
            'model' => $model !== '' ? $model : $base['default_model'],
        ];
    }

    /**
     * ตัวเลือกของคำขอสำหรับผู้ให้บริการรายนี้ — โมเดลที่ผู้เรียกระบุมาใช้ได้เฉพาะกับเจ้าที่มีโมเดลนั้น.
     *
     * ผู้เรียกระบุโมเดลได้ตัวเดียว แต่ระบบลองหลายเจ้าตามลำดับ — ส่งชื่อโมเดลของ Groq ไปให้ OpenAI
     * (หรือกลับกัน) ได้ 404 แน่นอน ทั้งที่เจ้านั้นตอบด้วยโมเดลของตัวเองได้ จึงทิ้งชื่อที่ไม่ใช่ของเจ้านั้น
     * (รวมถึงชื่อที่ถูกถอดแล้ว) แล้วปล่อยให้ใช้โมเดลตามค่าตั้ง
     */
    private function optionsFor(string $provider, array $options): array
    {
        $requested = $options['model'] ?? null;

        if ($requested === null) {
            return $options;
        }

        $known = array_keys((array) config("ai_text.providers.{$provider}.models", []));
        $retired = (array) config("ai_text.providers.{$provider}.retired_models", []);

        if (! is_string($requested) || ! in_array($requested, $known, true) || in_array($requested, $retired, true)) {
            unset($options['model']);
        }

        return $options;
    }

    /** ยิงจริง — รูปแบบ chat completions ที่ทั้ง OpenAI และ Groq ใช้เหมือนกัน */
    private function send(array $config, string $message, string $systemPrompt, array $options): array
    {
        $messages = [];

        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $model = $options['model'] ?? $config['model'];
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('ai_text.timeout', 60))
                ->withToken($config['api_key'])
                ->post($config['endpoint'], [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $options['temperature'] ?? 0.7,
                    'max_tokens' => $options['max_tokens'] ?? 2048,
                ]);
        } catch (\Throwable $e) {
            /*
             * ข้อความดิบของ exception (URL ปลายทาง · รายละเอียด cURL) อยู่ใน log เท่านั้น
             * ผลของเมธอดนี้ถูกส่งต่อไปถึงหน้าจอแอดมินและถูกบันทึกลงฐานข้อมูล (error_message)
             */
            Log::warning('AI text: เชื่อมต่อผู้ให้บริการไม่ได้', ['endpoint' => $config['endpoint'], 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => __('เชื่อมต่อผู้ให้บริการไม่ได้ (หมดเวลาหรือเครือข่ายขัดข้อง)')];
        }

        if ($response->failed()) {
            // แยกสามสาเหตุให้ชัดตั้งแต่ต้นทาง — แก้คนละทางกันหมด
            $hint = match ($response->status()) {
                401, 403 => __('คีย์ใช้ไม่ได้'),
                404 => __('ไม่มีโมเดล :model แล้ว', ['model' => $model]),
                429 => __('เกินโควตา/ความถี่'),
                default => 'HTTP '.$response->status(),
            };

            return ['success' => false, 'error' => $hint];
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            return ['success' => false, 'error' => __('ได้คำตอบว่างเปล่า')];
        }

        return [
            'success' => true,
            'content' => trim($content),
            'model' => $model,
            'tokens_used' => (int) $response->json('usage.total_tokens', 0),
            'processing_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
