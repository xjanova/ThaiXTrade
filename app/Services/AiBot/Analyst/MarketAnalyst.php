<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiMarketView;
use App\Services\AiBot\Advisor\AdvisorSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ถาม AI เป็นรอบว่า "ตลาดตอนนี้เป็นยังไง และเหรียญไหนน่าเทรด".
 *
 * ผลลัพธ์ถูกเก็บเป็น AiMarketView หนึ่งใบ ให้บอททุกตัวอ่านใบเดียวกัน
 * ขอบเขตอำนาจของ AI อยู่ใน config/aibot_analyst.php — อ่านตรงนั้นก่อนแก้ไฟล์นี้
 *
 * ⚠️ ทุกทางที่ล้มเหลวต้องคืน null ไม่ใช่ throw
 *    บอทเรียกใช้มุมมองนี้ระหว่างตัดสินใจ ถ้าเราปล่อย exception ขึ้นไป
 *    OpenAI ล่มหนึ่งครั้งจะทำให้บอททุกตัวหยุดเทรดพร้อมกัน ซึ่งแย่กว่าการ
 *    ถอยไปใช้กฎล้วนมาก (กฎล้วนคือสิ่งที่ระบบใช้มาตลอดและใช้ได้อยู่แล้ว)
 *
 * Developed by Xman Studio.
 */
class MarketAnalyst
{
    public function __construct(
        private readonly MarketContext $context,
        private readonly AdvisorSettings $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('aibot_analyst.enabled', false);
    }

    /**
     * รันรอบวิเคราะห์หนึ่งรอบ.
     *
     * @return array{ok: bool, view?: AiMarketView, reason?: string}
     */
    public function run(string $scope): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'reason' => 'ปิดระบบวิเคราะห์ด้วย AI ไว้ (AIBOT_ANALYST_ENABLED=false)'];
        }

        if (! array_key_exists($scope, (array) config('aibot_analyst.scopes', []))) {
            return ['ok' => false, 'reason' => "ไม่รู้จักรอบวิเคราะห์ชื่อ {$scope}"];
        }

        if (! config("aibot_analyst.scopes.{$scope}.enabled", true)) {
            // ปิดรายรอบได้โดยไม่ต้องแตะ schedule — ตัว schedule ยังเดินแต่จบทันที
            // ที่ทำแบบนี้เพราะแก้ .env ง่ายกว่าแก้ crontab และย้อนกลับได้ทันที
            return ['ok' => false, 'reason' => "รอบ {$scope} ถูกปิดไว้"];
        }

        $provider = (string) config('aibot_analyst.provider', 'openai');
        $apiKey = trim((string) ($this->settings->providerConfig($provider)['api_key'] ?? ''));

        if ($apiKey === '') {
            return ['ok' => false, 'reason' => "ยังไม่ได้ตั้งคีย์ของ {$provider} — กรอกที่ /admin/settings แท็บที่ปรึกษา"];
        }

        if ($this->callsToday() >= (int) config('aibot_analyst.max_calls_per_day', 200)) {
            return ['ok' => false, 'reason' => 'ใช้โควตาการวิเคราะห์ของวันนี้ครบแล้ว'];
        }

        if (! $this->anyoneNeeds($scope)) {
            /*
             * ไม่มีบอทตัวไหนได้ใช้มุมมองรอบนี้ = ไม่ต้องยิง
             *
             * รอบสั้นตั้งไว้ทุก 15 นาที = 96 ครั้ง/วัน ซึ่งเดินอยู่แม้ไม่มีบอทที่
             * แพลนถึงเกณฑ์สักตัว — เผาเงินฟรีล้วนๆ และคีย์ OpenAI ที่ใช้มาจาก
             * พูลของ Thaiprompt ที่ **บิลรวมกัน** กับบอทดูดวง (ราว 20M tokens/เดือน)
             * รอบเปล่าจึงไม่ได้แค่เปลืองของเรา แต่ไปเบียดงบก้อนเดียวกับงานอื่น
             */
            return ['ok' => false, 'reason' => "ยังไม่มีบอทที่ใช้รอบ {$scope} ได้ — ข้ามรอบนี้"];
        }

        $model = (string) config('aibot_analyst.model');
        $available = $this->assertModelAvailable($apiKey, $model);

        if ($available === false) {
            /*
             * ล้มแบบดัง ไม่ใช่เงียบ — บทเรียนจาก Groq ถอด Llama ออกเมื่อ 18 ส.ค.
             * แล้วงานเจนคอนเทนต์ล้มวันละ 2 รอบอยู่หลายวันโดยไม่มีใครรู้
             */
            Log::error('AI analyst: โมเดลที่ตั้งไว้ไม่มีอยู่จริงแล้ว', [
                'model' => $model,
                'provider' => $provider,
            ]);

            return ['ok' => false, 'reason' => "ผู้ให้บริการไม่มีโมเดล {$model} แล้ว — เปลี่ยนที่ /admin/settings"];
        }

        $context = $this->context->build($scope);
        $prompt = $this->buildPrompt($scope, $context);

        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('aibot_analyst.timeout', 60))
                ->withToken($apiKey)
                ->post((string) config('aibot_analyst.endpoint'), [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI analyst request failed', ['scope' => $scope, 'error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'เรียกผู้ให้บริการไม่สำเร็จ: '.$e->getMessage()];
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            Log::warning('AI analyst HTTP error', ['scope' => $scope, 'status' => $response->status()]);

            return ['ok' => false, 'reason' => 'ผู้ให้บริการตอบ HTTP '.$response->status()];
        }

        $raw = (string) $response->json('choices.0.message.content', '');
        $parsed = $this->parse($raw, $context);

        if ($parsed === null) {
            return ['ok' => false, 'reason' => 'อ่านคำตอบของ AI ไม่ได้ (ไม่ใช่ JSON ตามรูปแบบที่ขอ)'];
        }

        $this->countCall();

        $ttl = (int) config("aibot_analyst.scopes.{$scope}.ttl_minutes", 60);

        $view = AiMarketView::create([
            'scope' => $scope,
            'provider' => $provider,
            'model' => $model,
            'regime' => $parsed['regime'],
            'confidence' => $parsed['confidence'],
            'size_multiplier' => $parsed['size_multiplier'],
            'coins' => $parsed['coins'],
            'shortlist' => $parsed['shortlist'],
            'summary' => $parsed['summary'],
            'headlines' => $context['headlines'],
            'prompt' => $prompt,
            'raw_response' => $raw,
            'tokens_used' => (int) $response->json('usage.total_tokens', 0),
            'latency_ms' => $latencyMs,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $this->prune();

        return ['ok' => true, 'view' => $view];
    }

    /**
     * ลบมุมมองเก่าทิ้งตามนโยบายเก็บข้อมูล.
     *
     * ทำท้ายรอบที่สำเร็จ ไม่ใช่ตั้ง schedule แยก — รอบวิเคราะห์คือที่เดียวที่
     * สร้างแถวพวกนี้ ตัวลบจึงควรอยู่ที่เดียวกัน ไม่มีทางที่ตัวสร้างเดินแต่
     * ตัวลบตายโดยไม่มีใครรู้ (ซึ่งเป็นสิ่งที่เกิดกับ cron แยกเสมอ)
     *
     * ล้มแล้วไม่ throw — มุมมองรอบนี้บันทึกสำเร็จไปแล้ว การลบของเก่าไม่ได้
     * ต้องไม่ทำให้รอบที่สำเร็จกลายเป็นล้มเหลว
     */
    private function prune(): void
    {
        $days = (int) config('aibot_analyst.retention_days', 30);

        if ($days <= 0) {
            return;
        }

        try {
            AiMarketView::where('created_at', '<', now()->subDays($days))->delete();
        } catch (\Throwable $e) {
            Log::warning('AI analyst: ลบมุมมองเก่าไม่สำเร็จ', ['error' => $e->getMessage()]);
        }
    }

    /**
     * มีบอทที่กำลังเดินอยู่และแพลนเข้าถึงรอบนี้ได้ไหม.
     *
     * ตอบไม่ได้ (ตารางยังไม่ถูก migrate / DB สะดุด) = ให้ผ่าน ไม่ใช่บล็อก —
     * การข้ามรอบเพราะอ่านฐานข้อมูลไม่ได้จะทำให้บอทตาบอดโดยไม่มีใครรู้สาเหตุ
     * ซึ่งแย่กว่าการยิงเกินไปหนึ่งรอบมาก
     */
    private function anyoneNeeds(string $scope): bool
    {
        $required = (string) config("aibot_analyst.scopes.{$scope}.min_tier", 'free');
        $requiredRank = AiBotPlan::TIER_RANK[$required] ?? 0;

        $allowedTiers = array_keys(array_filter(
            AiBotPlan::TIER_RANK,
            fn (int $rank) => $rank >= $requiredRank,
        ));

        try {
            return AiBotConfig::query()
                ->where('status', 'running')
                ->whereExists(function ($query) use ($allowedTiers) {
                    $query->from('ai_bot_subscriptions as s')
                        ->join('ai_bot_plans as p', 'p.id', '=', 's.ai_bot_plan_id')
                        ->whereColumn('s.wallet_address', 'ai_bot_configs.wallet_address')
                        ->where('s.status', 'active')
                        ->where('s.expires_at', '>', now())
                        ->whereIn('p.tier', $allowedTiers);
                })
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('AI analyst: เช็คว่ามีบอทใช้รอบนี้ไหมไม่ได้ — ปล่อยผ่าน', [
                'scope' => $scope,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    // ── prompt ───────────────────────────────────────────────────────────────

    private function systemPrompt(): string
    {
        return implode("\n", [
            'คุณเป็นนักวิเคราะห์ตลาดคริปโตของ TPIX TRADE ที่ตรงไปตรงมาและไม่ขายฝัน',
            'หน้าที่ของคุณคือประเมินภาพตลาดและจัดอันดับเหรียญ ไม่ใช่สั่งซื้อขายเอง',
            'ระบบจะเอาความเห็นของคุณไปถ่วงน้ำหนักสัญญาณจากกฎที่เขียนไว้แล้วอีกที',
            'ตอบเป็น JSON ตามรูปแบบที่กำหนดเท่านั้น ห้ามมีข้อความอื่นนอก JSON',
        ]);
    }

    private function buildPrompt(string $scope, array $context): string
    {
        $horizon = $scope === AiMarketView::SCOPE_STRATEGIC
            ? 'มองภาพ 4-24 ชั่วโมงข้างหน้า เน้นแนวโน้มหลักและการจัดอันดับเหรียญ'
            : 'มองภาพ 15-60 นาทีข้างหน้า เน้นข่าวที่เพิ่งเข้าและการปรับท่าทีระยะสั้น';

        $lines = [
            "รอบวิเคราะห์: {$scope} — {$horizon}",
            'เวลาที่ประเมิน: '.$context['generated_at'],
            '',
            '## ต้นทุนที่ต้องคิดเสมอ',
            "การเข้าและออกหนึ่งรอบมีต้นทุนจริง {$context['cost_bps']} bps (ค่าธรรมเนียม + slippage)",
            'อย่าแนะนำให้สลับเหรียญหรือปิดไม้ ถ้าส่วนต่างที่คาดว่าจะได้ไม่ชนะต้นทุนนี้อย่างชัดเจน',
            '',
            '## เหรียญที่พิจารณาได้',
            'ตัวเลข change_24h_pct เป็นเปอร์เซ็นต์ · worst_panic 0-1 (ยิ่งสูงยิ่งข่าวร้าย) · held = บอทถือของอยู่',
            json_encode($context['coins'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            '## พาดหัวข่าวล่าสุด',
            json_encode($context['headlines'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            '## ของที่ถืออยู่ตอนนี้',
            $context['holdings'] === []
                ? 'ยังไม่มีไม้ค้างอยู่'
                : json_encode($context['holdings'], JSON_UNESCAPED_UNICODE),
            '',
            '## รูปแบบคำตอบ (JSON เท่านั้น)',
            '{',
            '  "regime": "risk_on" | "neutral" | "risk_off",',
            '  "confidence": 0.0-1.0,',
            '  "size_multiplier": 0.0-1.3,',
            '  "summary": "สรุปภาษาไทย 2-4 ประโยค ให้ผู้ใช้ทั่วไปอ่านเข้าใจ",',
            '  "coins": { "BTC": {"score": -1.0..1.0, "stance": "buy|hold|avoid|exit", "why": "เหตุผลสั้นๆ ภาษาไทย"} },',
            '  "shortlist": ["BTC/USDT", "ETH/USDT"]',
            '}',
            '',
            'กติกา:',
            '- ใส่ coins เฉพาะเหรียญที่คุณมีความเห็นจริงๆ ไม่ต้องครบทุกตัว',
            '- stance = "exit" ใช้เฉพาะเมื่อมีเหตุผลหนักพอที่จะยอมจ่ายต้นทุนออกจากตลาด',
            '- shortlist เรียงจากน่าสนใจที่สุด ไม่เกิน '.(int) config('aibot_analyst.auto_pair.shortlist_size', 5).' คู่ ใช้รูปแบบ BASE/USDT',
            '- ถ้าข้อมูลไม่พอจะสรุป ให้ confidence ต่ำและ regime = "neutral" อย่าเดา',
        ];

        return implode("\n", $lines);
    }

    // ── แปลคำตอบ ─────────────────────────────────────────────────────────────

    /**
     * แปลง JSON ที่ AI ตอบมาเป็นค่าที่ปลอดภัยพอจะเอาไปใช้กับเงินจริง.
     *
     * ทุกค่าถูกบีบให้อยู่ในกรอบของ config เสมอ — ไม่เชื่อตัวเลขที่ได้มาตรงๆ
     * แม้แต่ค่าเดียว คำตอบเพี้ยนครั้งเดียว (size_multiplier: 50) ต้องไม่กลายเป็น
     * ไม้ที่ใหญ่กว่าพอร์ต
     *
     * @return array{regime: string, confidence: float, size_multiplier: float, coins: array, shortlist: list<string>, summary: string}|null
     */
    private function parse(string $raw, array $context): ?array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            Log::warning('AI analyst: คำตอบไม่ใช่ JSON', ['head' => mb_substr($raw, 0, 200)]);

            return null;
        }

        $limits = (array) config('aibot_analyst.limits', []);
        $allowed = array_column($context['coins'], 'symbol');

        $regime = in_array($data['regime'] ?? '', ['risk_on', 'neutral', 'risk_off'], true)
            ? $data['regime']
            : 'neutral';

        $confidence = $this->clamp((float) ($data['confidence'] ?? 0), 0.0, 1.0);

        $sizeMultiplier = $this->clamp(
            (float) ($data['size_multiplier'] ?? 1.0),
            (float) ($limits['size_multiplier_min'] ?? 0.0),
            (float) ($limits['size_multiplier_max'] ?? 1.3),
        );

        $coins = [];

        foreach ((array) ($data['coins'] ?? []) as $symbol => $entry) {
            $symbol = strtoupper((string) $symbol);

            /*
             * ทิ้งเหรียญที่ไม่ได้อยู่ในบริบทที่เราส่งไป
             *
             * โมเดลชอบเติมเหรียญที่มันรู้จักจากการเทรนเข้ามาเอง ซึ่งอาจเป็นเหรียญ
             * ที่เราไม่ได้เปิดคู่เทรด หรือไม่มีอยู่แล้ว — ปล่อยผ่านไปจะได้ shortlist
             * ที่ชี้ไปยังคู่ที่กดซื้อไม่ได้
             */
            if (! in_array($symbol, $allowed, true) || ! is_array($entry)) {
                continue;
            }

            $stance = in_array($entry['stance'] ?? '', [
                AiMarketView::STANCE_BUY,
                AiMarketView::STANCE_HOLD,
                AiMarketView::STANCE_AVOID,
                AiMarketView::STANCE_EXIT,
            ], true) ? $entry['stance'] : AiMarketView::STANCE_HOLD;

            $coins[$symbol] = [
                'score' => round($this->clamp((float) ($entry['score'] ?? 0), -1.0, 1.0), 3),
                'stance' => $stance,
                'why' => mb_substr(trim((string) ($entry['why'] ?? '')), 0, 300),
            ];
        }

        $shortlist = [];
        $quote = (string) config('aibot_analyst.auto_pair.quote', 'USDT');

        foreach ((array) ($data['shortlist'] ?? []) as $pair) {
            $base = AiMarketView::baseOf((string) $pair);

            if (in_array($base, $allowed, true)) {
                $shortlist[] = "{$base}/{$quote}";
            }
        }

        $shortlist = array_slice(
            array_values(array_unique($shortlist)),
            0,
            (int) config('aibot_analyst.auto_pair.shortlist_size', 5),
        );

        return [
            'regime' => $regime,
            'confidence' => round($confidence, 3),
            'size_multiplier' => round($sizeMultiplier, 2),
            'coins' => $coins,
            'shortlist' => $shortlist,
            'summary' => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 1000),
        ];
    }

    // ── ตรวจโมเดล + โควตา ────────────────────────────────────────────────────

    /**
     * โมเดลที่ตั้งไว้ยังมีอยู่จริงไหม.
     *
     * @return bool|null true = มี · false = ผู้ให้บริการยืนยันว่าไม่มี · null = ถามไม่ได้ (ปล่อยผ่าน)
     *
     * แยก null ออกจาก false เพราะ "ถามไม่ได้เพราะเน็ตสะดุด" ไม่ใช่ "โมเดลหายไปแล้ว"
     * ถ้าเหมารวมกัน เน็ตสะดุดครั้งเดียวจะหยุดการวิเคราะห์ทั้งวันโดยไม่จำเป็น
     */
    private function assertModelAvailable(string $apiKey, string $model): ?bool
    {
        return Cache::remember("aibot:analyst:model-ok:{$model}", now()->addHours(6), function () use ($apiKey, $model) {
            try {
                $response = Http::timeout(15)
                    ->withToken($apiKey)
                    ->get((string) config('aibot_analyst.models_endpoint'));
            } catch (\Throwable $e) {
                return null;
            }

            if ($response->failed()) {
                return null;
            }

            $ids = array_column((array) $response->json('data', []), 'id');

            return $ids === [] ? null : in_array($model, $ids, true);
        });
    }

    private function callsToday(): int
    {
        return (int) Cache::get($this->quotaKey(), 0);
    }

    private function countCall(): void
    {
        $key = $this->quotaKey();

        Cache::put($key, $this->callsToday() + 1, now()->endOfDay());
    }

    private function quotaKey(): string
    {
        return 'aibot:analyst:calls:'.now()->toDateString();
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
