<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBotConfig;
use App\Models\AiBotCredit;
use App\Models\AiBotPlan;
use App\Models\AiBotPosition;
use App\Models\AiBotTrade;
use App\Services\AiBot\Advisor\AdvisorFactory;
use App\Services\AiBot\BotRunner;
use App\Services\AiBot\MarketRiskService;
use App\Services\AiBot\PaperBroker;
use App\Services\AiBot\StrategyAnalytics;
use App\Services\AiBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * TPIX TRADE — AI Trade (Cloud Bot) API.
 *
 * ใช้ร่วมกันระหว่างเว็บ (/ai-trade + การ์ดในหน้าเทรด) และแอพมือถือ
 * catalog เป็น public ให้หน้าแสดงราคาได้ก่อนต่อ wallet
 * ที่เหลืออยู่หลัง VerifyWalletOwnership — ทุก query ผูกกับ wallet ของผู้เรียกเสมอ
 *
 * Developed by Xman Studio.
 */
class AiBotController extends Controller
{
    /** ข้อความที่ผู้ใช้เห็นเมื่อทำรายการไม่ผ่าน (ไม่เปิดเผยรายละเอียดภายใน) */
    private const ERROR_MESSAGES = [
        AiBotService::ERR_INSUFFICIENT_CREDITS => 'เครดิตการทำงานไม่พอ กรุณาเติมเครดิตก่อนเริ่มใช้งานบอท',
        AiBotService::ERR_NO_SUBSCRIPTION => 'ยังไม่ได้เช่าบอท AI TRADE — เลือกแพลนก่อนเริ่มใช้งาน',
        AiBotService::ERR_BOT_LIMIT => 'จำนวนบอทเต็มโควตาของแพลนนี้แล้ว',
        AiBotService::ERR_STRATEGY_LOCKED => 'กลยุทธ์นี้ต้องใช้แพลนระดับสูงกว่า',
        'INVALID_PACK' => 'ไม่พบแพ็กเกจเครดิตที่เลือก',
    ];

    public function __construct(
        private readonly AiBotService $bots,
        private readonly PaperBroker $broker,
    ) {}

    // =========================================================================
    // Public
    // =========================================================================

    /**
     * แคตตาล็อกทั้งหมด: แพลน, กลยุทธ์, แพ็กเครดิต, กรอบความเสี่ยง.
     * public เพื่อให้หน้าเว็บ/แอพแสดงราคาได้ก่อนเชื่อม wallet.
     */
    public function catalog(): JsonResponse
    {
        $plans = AiBotPlan::active()->orderBy('sort_order')->get()->map(fn (AiBotPlan $plan) => [
            'code' => $plan->code,
            'name' => $plan->name,
            'name_th' => $plan->name_th,
            'description' => $plan->description,
            'description_th' => $plan->description_th,
            'tier' => $plan->tier,
            // ที่บอทเดิน — browser = ต้องเปิดเว็บทิ้งไว้, cloud = เซิร์ฟเวอร์เดินให้
            'execution' => $plan->execution,
            'credits_per_day' => $plan->credits_per_day,
            'price_tpix_per_day' => (float) $plan->price_tpix_per_day,
            'max_bots' => $plan->max_bots,
            'max_capital_usd' => $plan->max_capital_usd ? (float) $plan->max_capital_usd : null,
            'features' => $plan->features ?? [],
            'features_th' => $plan->features_th ?? [],
            'badge' => $plan->badge,
            'strategies' => $plan->unlockedStrategies(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'plans' => $plans,
                'strategies' => config('aibot.strategies', []),
                'packs' => config('aibot.credits.packs', []),
                'rental_days' => config('aibot.credits.rental_days', [1, 7, 30]),
                'timeframes' => config('aibot.timeframes', []),
                'limits' => config('aibot.limits', []),
            ],
        ]);
    }

    // =========================================================================
    // Protected (wallet verified)
    // =========================================================================

    /** สถานะรวมของ wallet: การเช่า, เครดิต, บอท, โควตาที่เหลือ */
    public function status(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);
        $subscription = $this->bots->activeSubscription($wallet);
        $plan = $subscription?->plan;

        $usedBots = AiBotConfig::forWallet($wallet)->countingTowardQuota()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $wallet,
                'credits' => $this->bots->balanceFor($wallet),
                'is_active' => (bool) $subscription,
                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'plan_code' => $plan?->code,
                    'plan_name' => $plan?->name,
                    'plan_name_th' => $plan?->name_th,
                    'tier' => $plan?->tier,
                    'execution' => $plan?->execution,
                    'is_free' => $plan?->isFree() ?? true,
                    'credits_per_day' => $plan?->credits_per_day,
                    'days_remaining' => $subscription->daysRemaining(),
                    'expires_at' => $subscription->expires_at->toIso8601String(),
                    'started_at' => $subscription->started_at->toIso8601String(),
                ] : null,
                'quota' => [
                    'max_bots' => $plan?->max_bots ?? 0,
                    'used_bots' => $usedBots,
                ],
                'unlocked_strategies' => $plan?->unlockedStrategies() ?? [],
                'bots' => $this->botList($wallet),
            ],
        ]);
    }

    /** ประวัติเครดิตล่าสุด (ใช้ในหน้า /ai-trade) */
    public function credits(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);

        $entries = AiBotCredit::where('wallet_address', $wallet)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AiBotCredit $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'amount' => (float) $c->amount,
                'balance_after' => (float) $c->balance_after,
                'reference' => $c->reference,
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $this->bots->balanceFor($wallet),
                'entries' => $entries,
            ],
        ]);
    }

    /** รับโบนัสต้อนรับ (ครั้งเดียวต่อ wallet) */
    public function claimWelcome(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);

        return response()->json([
            'success' => true,
            'data' => ['credits' => $this->bots->grantWelcomeBonus($wallet)],
        ]);
    }

    /** สร้างคำขอเติมเครดิต — ยอดจะเพิ่มหลังยืนยันการชำระเงินที่หลังบ้าน */
    public function topup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pack' => ['required', 'string', 'max:40'],
        ]);

        try {
            $intent = $this->bots->createTopupIntent($this->wallet($request), $validated['pack']);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage());
        }

        return response()->json(['success' => true, 'data' => $intent]);
    }

    /** เช่าแพลน (ตัดเครดิตทันที) */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:40'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $plan = AiBotPlan::active()->where('code', $validated['plan_code'])->first();

        if (! $plan) {
            return $this->failure('PLAN_NOT_FOUND', 'ไม่พบแพลนที่เลือก', 404);
        }

        try {
            $this->bots->subscribe($this->wallet($request), $plan, (int) $validated['days']);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), null, 402);
        }

        return $this->status($request);
    }

    /** ยกเลิกการเช่า — คืนเครดิตวันที่เหลือ แล้วหยุดบอททั้งหมด */
    public function cancel(Request $request): JsonResponse
    {
        $this->bots->cancel($this->wallet($request));

        return $this->status($request);
    }

    // =========================================================================
    // บอท
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->botList($this->wallet($request)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);
        $validated = $this->validateBot($request);

        try {
            $subscription = $this->bots->assertCanRunBot($wallet, $validated['strategy']);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), null, 403);
        }

        $bot = AiBotConfig::create([
            'wallet_address' => $wallet,
            'ai_bot_subscription_id' => $subscription->id,
            'name' => $validated['name'],
            'pair' => strtoupper($validated['pair']),
            'strategy' => $validated['strategy'],
            'timeframe' => $validated['timeframe'],
            'params' => $this->bots->sanitizeParams($validated['strategy'], $request->input('params', [])),
            'risk' => $this->bots->sanitizeRisk($request->input('risk', []), $subscription->plan),
            'status' => 'paused',
        ]);

        return response()->json(['success' => true, 'data' => $this->presentBot($bot)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $wallet = $this->wallet($request);
        $bot = $this->findBot($wallet, $id);

        if (! $bot) {
            return $this->failure('BOT_NOT_FOUND', 'ไม่พบบอทตัวนี้', 404);
        }

        $validated = $this->validateBot($request);

        try {
            $subscription = $this->bots->assertCanRunBot($wallet, $validated['strategy'], $bot);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), null, 403);
        }

        $bot->update([
            'name' => $validated['name'],
            'pair' => strtoupper($validated['pair']),
            'strategy' => $validated['strategy'],
            'timeframe' => $validated['timeframe'],
            'params' => $this->bots->sanitizeParams($validated['strategy'], $request->input('params', [])),
            'risk' => $this->bots->sanitizeRisk($request->input('risk', []), $subscription->plan),
        ]);

        return response()->json(['success' => true, 'data' => $this->presentBot($bot->fresh())]);
    }

    /** เริ่ม/พัก/หยุดบอท — start ต้องมีการเช่าที่ยังไม่หมดอายุเสมอ */
    public function setState(Request $request, int $id): JsonResponse
    {
        $wallet = $this->wallet($request);
        $bot = $this->findBot($wallet, $id);

        if (! $bot) {
            return $this->failure('BOT_NOT_FOUND', 'ไม่พบบอทตัวนี้', 404);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:start,pause,stop'],
        ]);

        if ($validated['action'] === 'start') {
            try {
                $this->bots->assertCanRunBot($wallet, $bot->strategy, $bot);
            } catch (RuntimeException $e) {
                return $this->failure($e->getMessage(), null, 403);
            }
        }

        $bot->status = match ($validated['action']) {
            'start' => 'running',
            'pause' => 'paused',
            default => 'stopped',
        };
        $bot->save();

        return response()->json(['success' => true, 'data' => $this->presentBot($bot)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $wallet = $this->wallet($request);
        $bot = $this->findBot($wallet, $id);

        if (! $bot) {
            return $this->failure('BOT_NOT_FOUND', 'ไม่พบบอทตัวนี้', 404);
        }

        $bot->delete();

        return response()->json(['success' => true, 'data' => ['deleted' => $id]]);
    }

    /**
     * ให้เบราว์เซอร์สั่งบอทของแพลนฟรีเดินหนึ่งรอบ.
     *
     * แพลนฟรีไม่ได้ซื้อการรันบนคลาวด์ ตัวจับเวลาของเซิร์ฟเวอร์จึงข้ามบอทพวกนี้ไป
     * หน้าเว็บที่เปิดค้างอยู่เป็นคนเรียก endpoint นี้เป็นระยะแทน — ปิดแท็บก็หยุดจริง
     *
     * บอทของแพลนคลาวด์ห้ามเรียกทางนี้เด็ดขาด ไม่งั้นจะถูกเดินสองทาง
     * (ทั้งเซิร์ฟเวอร์และเบราว์เซอร์) แล้วเปิดไม้ซ้ำ
     */
    public function tickFromBrowser(Request $request, int $id, BotRunner $runner): JsonResponse
    {
        $wallet = $this->wallet($request);
        $bot = $this->findBot($wallet, $id);

        if (! $bot) {
            return $this->failure('BOT_NOT_FOUND', 'ไม่พบบอทตัวนี้', 404);
        }

        if ($bot->status !== 'running') {
            return $this->failure('BOT_NOT_RUNNING', 'บอทตัวนี้ยังไม่ได้เริ่มทำงาน');
        }

        $plan = $this->bots->activeSubscription($wallet)?->plan;

        if ($plan?->runsInCloud()) {
            return $this->failure('CLOUD_BOT', 'บอทของแพลนนี้เดินบนคลาวด์อยู่แล้ว ไม่ต้องสั่งจากหน้าเว็บ');
        }

        // กันหน้าเว็บยิงรัวเกินจำเป็น — คิดหนึ่งรอบต่อช่วงเวลาที่กำหนดก็พอ
        $minSeconds = (int) config('aibot.browser_tick_min_seconds', 30);

        if ($bot->last_run_at && $bot->last_run_at->diffInSeconds(now()) < $minSeconds) {
            return response()->json(['success' => true, 'data' => [
                'skipped' => true,
                'reason' => 'ยังไม่ถึงรอบถัดไป',
                'next_in_seconds' => $minSeconds - (int) $bot->last_run_at->diffInSeconds(now()),
            ]]);
        }

        $result = $runner->tick($bot);

        return response()->json(['success' => true, 'data' => array_merge($result, [
            'skipped' => false,
            'bot' => $this->presentBot($bot->fresh()),
        ])]);
    }

    // =========================================================================
    // โหมดทดลอง (เครดิตเดโม)
    // =========================================================================

    /**
     * ภาพรวมพอร์ตทดลอง — เงิน ของที่ถือ ไม้ที่ผ่านมา และสรุปผล.
     *
     * ตัวเลขสรุปคำนวณจาก "ไม้ที่ปิดแล้ว" เท่านั้น ไม่รวมกำไรลอยของไม้ที่ยังเปิดอยู่
     * เพราะกำไรลอยเปลี่ยนทุกวินาทีและยังไม่ใช่เงินจริง — โชว์รวมกันจะดูดีเกินจริง
     */
    public function demo(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);
        $account = $this->broker->account($wallet);

        $botIds = AiBotConfig::forWallet($wallet)->pluck('id');

        $positions = AiBotPosition::with('bot:id,name,strategy')
            ->whereIn('ai_bot_config_id', $botIds)
            ->where('mode', 'demo')
            ->get()
            ->map(fn (AiBotPosition $p) => [
                'id' => $p->id,
                'bot_id' => $p->ai_bot_config_id,
                'bot_name' => $p->bot?->name,
                'pair' => $p->pair,
                'quantity' => (float) $p->quantity,
                'entry_price' => (float) $p->entry_price,
                'cost_basis' => (float) $p->cost_basis,
                'entry_count' => $p->entry_count,
                'opened_at' => $p->opened_at?->toIso8601String(),
            ])
            ->all();

        $trades = AiBotTrade::whereIn('ai_bot_config_id', $botIds)
            ->where('mode', 'demo')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (AiBotTrade $t) => [
                'id' => $t->id,
                'bot_id' => $t->ai_bot_config_id,
                'pair' => $t->pair,
                'side' => $t->side,
                'price' => (float) $t->price,
                'quantity' => (float) $t->quantity,
                'gross_value' => (float) $t->gross_value,
                'fee' => (float) $t->fee,
                'slippage_cost' => (float) $t->slippage_cost,
                'realized_pnl' => $t->realized_pnl === null ? null : (float) $t->realized_pnl,
                'strategy' => $t->strategy,
                'reason' => $t->reason,
                'risk_level' => $t->risk_level,
                'created_at' => $t->created_at->toIso8601String(),
            ])
            ->all();

        $closed = AiBotTrade::whereIn('ai_bot_config_id', $botIds)
            ->where('mode', 'demo')
            ->whereNotNull('realized_pnl')
            ->get();

        $wins = $closed->where('realized_pnl', '>', 0)->count();
        $closedCount = $closed->count();

        return response()->json(['success' => true, 'data' => [
            'account' => [
                'balance' => (float) $account->balance,
                'starting_balance' => (float) $account->starting_balance,
                'resets_used_today' => $account->last_reset_at?->isToday() ? $account->reset_count : 0,
                'resets_per_day' => (int) config('aibot_risk.demo.max_resets_per_day', 3),
                'fee_rate' => (float) config('aibot_risk.demo.fee_rate', 0.1),
                'slippage_bps' => (int) config('aibot_risk.demo.slippage_bps', 8),
            ],
            'positions' => $positions,
            'trades' => $trades,
            'summary' => [
                'realized_pnl' => round((float) $closed->sum('realized_pnl'), 2),
                'total_fees' => round((float) AiBotTrade::whereIn('ai_bot_config_id', $botIds)->where('mode', 'demo')->sum('fee'), 2),
                'trade_count' => AiBotTrade::whereIn('ai_bot_config_id', $botIds)->where('mode', 'demo')->count(),
                'closed_count' => $closedCount,
                'wins' => $wins,
                'losses' => $closedCount - $wins,
                'win_rate' => $closedCount > 0 ? round(($wins / $closedCount) * 100, 1) : null,
            ],
        ]]);
    }

    /**
     * สถิติผลงานย้อนหลังของทุกกลยุทธ์ที่ wallet นี้เคยใช้.
     *
     * เจ้าของต้องการให้ประวัติ "นำกลับมาวิเคราะห์เพื่อตัดสินใจในอนาคตได้"
     * จึงไม่ใช่แค่รายการไม้ แต่เป็นตัวเลขที่ตัดสินใจต่อได้: profit factor,
     * expectancy, ขาดทุนสูงสุด และผลแยกตามระดับความเสี่ยงของตลาดตอนเข้าไม้
     */
    public function analytics(Request $request, StrategyAnalytics $analytics): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', 'string', 'in:demo,live'],
        ]);

        return response()->json(['success' => true, 'data' => $analytics->forWallet(
            $this->wallet($request),
            $validated['mode'] ?? 'demo',
        )]);
    }

    /**
     * ขอความเห็นจากที่ปรึกษา AI ตามแพลนของผู้ใช้ (ฟรี = Gemini, เสียเงิน = OpenAI).
     *
     * ⚠️ คำแนะนำนี้ไม่มีผลต่อการเทรด — บอทยังตัดสินใจจากกฎ + ด่านความเสี่ยงเหมือนเดิม
     *    เป็นข้อมูลให้ "คน" อ่านประกอบการตัดสินใจปรับตั้งค่าเท่านั้น
     */
    public function advice(Request $request, StrategyAnalytics $analytics, AdvisorFactory $advisors): JsonResponse
    {
        $wallet = $this->wallet($request);
        $plan = $this->bots->activeSubscription($wallet)?->plan;
        $advisor = $advisors->forPlan($plan);

        if (! $advisor->isAvailable()) {
            return response()->json(['success' => true, 'data' => [
                'ok' => false,
                'provider' => $advisor->name(),
                'text' => '',
                'reason' => $advisor->advise([])['reason'] ?? 'ยังไม่พร้อมใช้งาน',
            ]]);
        }

        $stats = $analytics->forWallet($wallet);

        // แคชต่อ wallet — รีเฟรชหน้าไม่ควรยิง LLM ใหม่ทุกครั้ง
        $minutes = (int) config('aibot_advisor.cache_minutes', 15);
        $key = 'aibot:advice:'.$wallet.':'.md5(json_encode($stats['overall']));

        $result = Cache::remember($key, now()->addMinutes($minutes), fn () => $advisor->advise([
            'stats' => $stats['overall'],
            'by_strategy' => $stats['by_strategy'],
        ]));

        return response()->json(['success' => true, 'data' => $result]);
    }

    /** ล้างพอร์ตทดลองกลับไปตั้งต้น */
    public function resetDemo(Request $request): JsonResponse
    {
        $result = $this->broker->reset($this->wallet($request));

        if (! ($result['ok'] ?? false)) {
            return $this->failure('RESET_LIMIT', $result['reason'] ?? 'ล้างพอร์ตไม่สำเร็จ');
        }

        return $this->demo($request);
    }

    /**
     * สลับโหมดของบอทระหว่างทดลองกับจริง.
     *
     * แยกจาก update() เพราะเป็นการตัดสินใจเรื่องเงินจริง ควรเป็นการกระทำที่ชัดเจน
     * ไม่ใช่ผลข้างเคียงของการกดบันทึกการตั้งค่า
     */
    public function setMode(Request $request, int $id): JsonResponse
    {
        $wallet = $this->wallet($request);
        $bot = $this->findBot($wallet, $id);

        if (! $bot) {
            return $this->failure('BOT_NOT_FOUND', 'ไม่พบบอทตัวนี้', 404);
        }

        $validated = $request->validate(['mode' => ['required', 'string', 'in:demo,live']]);

        if ($validated['mode'] === 'live') {
            // ยังไม่เปิดโหมดจริงทั้งระบบ — ให้ทุกคนอยู่โหมดทดลองไปก่อน
            if (! config('aibot.live_enabled', false)) {
                return $this->failure('LIVE_DISABLED', 'ตอนนี้เปิดให้ใช้เฉพาะโหมดทดลองก่อน');
            }

            if (! $this->bots->activeSubscription($wallet)) {
                return $this->failure('NO_SUBSCRIPTION', 'ต้องเช่าบอทก่อนถึงจะใช้โหมดจริงได้');
            }
        }

        // ของที่ถืออยู่ในโหมดเดิมต้องไม่ตามข้ามโหมดไป — คนละบัญชีคนละความหมาย
        $bot->update(['mode' => $validated['mode']]);

        return response()->json(['success' => true, 'data' => $this->presentBot($bot->fresh())]);
    }

    /**
     * ความเสี่ยงล่าสุดของคู่เทรด พร้อมพาดหัวข่าวที่ทำให้บอทตัดสินใจแบบนั้น.
     *
     * เปิดให้เรียกได้โดยไม่ต้องมี wallet — เป็นข้อมูลตลาด ไม่ใช่ข้อมูลส่วนตัว
     * และเป็นตัวที่ทำให้ผู้ใช้เชื่อใจบอทได้ว่ามันไม่ได้สุ่มตัดสินใจ
     */
    public function risk(Request $request, MarketRiskService $risk): JsonResponse
    {
        $validated = $request->validate([
            'pair' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,15}\/[A-Za-z0-9]{2,15}$/'],
        ]);

        return response()->json(['success' => true, 'data' => $risk->assess(strtoupper($validated['pair']))]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validateBot(Request $request): array
    {
        $strategyCodes = collect(config('aibot.strategies', []))->pluck('code')->all();

        return $request->validate([
            'name' => ['required', 'string', 'max:'.config('aibot.limits.max_name_length', 60)],
            // คู่เทรดรูปแบบ BASE/QUOTE เท่านั้น — กันสตริงแปลกปลอมไหลไป engine
            'pair' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,15}\/[A-Za-z0-9]{2,15}$/'],
            'strategy' => ['required', 'string', 'in:'.implode(',', $strategyCodes)],
            'timeframe' => ['required', 'string', 'in:'.implode(',', config('aibot.timeframes', []))],
            'params' => ['sometimes', 'array'],
            'risk' => ['sometimes', 'array'],
        ]);
    }

    private function findBot(string $wallet, int $id): ?AiBotConfig
    {
        return AiBotConfig::forWallet($wallet)->where('id', $id)->first();
    }

    private function botList(string $wallet): array
    {
        return AiBotConfig::forWallet($wallet)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AiBotConfig $bot) => $this->presentBot($bot))
            ->all();
    }

    private function presentBot(AiBotConfig $bot): array
    {
        $meta = $bot->strategyMeta();

        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'pair' => $bot->pair,
            'strategy' => $bot->strategy,
            'strategy_name' => $meta['name'] ?? $bot->strategy,
            'strategy_name_th' => $meta['name_th'] ?? ($meta['name'] ?? $bot->strategy),
            'risk_level' => $meta['risk'] ?? 'medium',
            'timeframe' => $bot->timeframe,
            'params' => $bot->params ?? [],
            'risk' => $bot->risk ?? [],
            'status' => $bot->status,
            'mode' => $bot->mode,
            'stats' => $bot->stats ?? [],
            'last_run_at' => $bot->last_run_at?->toIso8601String(),
            'last_signal_at' => $bot->last_signal_at?->toIso8601String(),
            'last_reason' => $bot->last_reason,
            'position' => $this->openPosition($bot),
            'created_at' => $bot->created_at->toIso8601String(),
        ];
    }

    /** ของที่บอทถืออยู่ในโหมดปัจจุบัน (null = ไม่ได้ถืออะไร) */
    private function openPosition(AiBotConfig $bot): ?array
    {
        $position = AiBotPosition::where('ai_bot_config_id', $bot->id)
            ->where('mode', $bot->mode)
            ->first();

        if (! $position) {
            return null;
        }

        return [
            'quantity' => (float) $position->quantity,
            'entry_price' => (float) $position->entry_price,
            'cost_basis' => (float) $position->cost_basis,
            'entry_count' => $position->entry_count,
            'opened_at' => $position->opened_at?->toIso8601String(),
        ];
    }

    /**
     * wallet ของผู้เรียก.
     *
     * VerifyWalletOwnership ตรวจลายเซ็นให้แล้ว "เฉพาะเมื่อมี wallet_address ในคำขอ"
     * ถ้าไม่ส่งมาเลย middleware จะปล่อยผ่าน — จึงต้องกันซ้ำที่นี่ ไม่งั้นคำขอที่ไม่มี
     * wallet จะกลายเป็น wallet ว่างเปล่าที่ทุกคนใช้ร่วมกัน
     */
    private function wallet(Request $request): string
    {
        $wallet = $this->bots->normalize(
            (string) ($request->input('wallet_address') ?? $request->query('wallet_address', ''))
        );

        abort_unless(
            preg_match('/^0x[a-f0-9]{40}$/', $wallet) === 1,
            response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_WALLET', 'message' => 'Invalid wallet address format.'],
            ], 422)
        );

        return $wallet;
    }

    private function failure(string $code, ?string $message = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message ?? self::ERROR_MESSAGES[$code] ?? 'ทำรายการไม่สำเร็จ',
            ],
        ], $status);
    }
}
