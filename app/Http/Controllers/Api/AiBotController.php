<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBotConfig;
use App\Models\AiBotCredit;
use App\Models\AiBotPlan;
use App\Services\AiBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function __construct(private readonly AiBotService $bots) {}

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
            'credits_per_day' => $plan->credits_per_day,
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
            'stats' => $bot->stats ?? [],
            'last_run_at' => $bot->last_run_at?->toIso8601String(),
            'created_at' => $bot->created_at->toIso8601String(),
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
