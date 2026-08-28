<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — งานข้อความด้วย AI (ผู้ช่วยตอบคำถาม · วิเคราะห์ตลาด · เขียนข่าว).
 *
 * แทนที่ `GroqService` ที่ผูกกับผู้ให้บริการเดียว — หน้าตาเมธอดเหมือนเดิมทุกตัว
 * เพื่อให้สลับได้โดยไม่ต้องแก้ผู้เรียกทั้งสี่จุด
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

    public function __construct(private readonly GroqService $groq) {}

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
                $errors[] = "{$provider}: ยังไม่ได้ตั้งคีย์";

                continue;
            }

            $result = $provider === 'groq'
                ? $this->groq->chat($message, $systemPrompt, $options)
                : $this->send($config, $message, $systemPrompt, $options);

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
            $errors[] = $provider.': '.($result['error'] ?? 'ไม่ทราบสาเหตุ');
        }

        Log::warning('AI text: ล้มทุกผู้ให้บริการ', ['errors' => $errors]);

        return ['success' => false, 'error' => implode(' · ', $errors)];
    }

    /** วิเคราะห์ตลาด — เนื้อหา prompt เหมือนเดิม เปลี่ยนแค่ทางที่ยิงออก */
    public function analyzeMarket(string $symbol, string $type = 'technical', array $marketData = []): array
    {
        $systemPrompt = 'You are an expert cryptocurrency market analyst for TPIX TRADE exchange. Provide professional, data-driven analysis. Always include risk warnings. Format output with clear sections. Respond in the language specified by the user.';

        $dataContext = ! empty($marketData) ? "\n\nMarket Data:\n".json_encode($marketData, JSON_PRETTY_PRINT) : '';

        $prompts = [
            'technical' => "Perform a detailed technical analysis for {$symbol}. Include support/resistance levels, trend analysis, key indicators (RSI, MACD, Moving Averages), and trading signals. {$dataContext}",
            'sentiment' => "Analyze the current market sentiment for {$symbol}. Consider social media trends, news impact, fear & greed index, whale movements, and on-chain metrics. {$dataContext}",
            'price_prediction' => "Provide a price prediction analysis for {$symbol} for the next 24h, 7d, and 30d timeframes. Include bull and bear scenarios with probability estimates. Always include disclaimer about prediction limitations. {$dataContext}",
            'market_analysis' => "Provide a comprehensive market overview for {$symbol}. Include price action analysis, volume analysis, market cap trends, and comparison with overall crypto market. {$dataContext}",
        ];

        return $this->chat($prompts[$type] ?? $prompts['market_analysis'], $systemPrompt, [
            'temperature' => 0.5,
            'max_tokens' => 4096,
        ]);
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
        $models = [];

        foreach (self::PROVIDERS as $provider) {
            foreach ((array) config("ai_text.providers.{$provider}.models", []) as $id => $label) {
                $models[$id] = $label;
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

        return [
            'api_key' => $key,
            'endpoint' => $base['endpoint'],
            'model' => $model !== '' ? $model : $base['default_model'],
        ];
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
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            // แยกสามสาเหตุให้ชัดตั้งแต่ต้นทาง — แก้คนละทางกันหมด
            $hint = match ($response->status()) {
                401, 403 => 'คีย์ใช้ไม่ได้',
                404 => "ไม่มีโมเดล {$model} แล้ว",
                429 => 'เกินโควตา/ความถี่',
                default => 'HTTP '.$response->status(),
            };

            return ['success' => false, 'error' => $hint];
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            return ['success' => false, 'error' => 'ได้คำตอบว่างเปล่า'];
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
