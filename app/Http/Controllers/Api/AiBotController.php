<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBotConfig;
use App\Models\AiBotCredit;
use App\Models\AiBotDecision;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotPlan;
use App\Models\AiBotPosition;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use App\Models\AiMarketView;
use App\Services\AiBot\Advisor\AdvisorFactory;
use App\Services\AiBot\BotRunner;
use App\Services\AiBot\MarketRiskService;
use App\Services\AiBot\PaperBroker;
use App\Services\AiBot\StrategyAnalytics;
use App\Services\AiBot\StrategyAvailability;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\WorkerHealth;
use App\Services\AiBotService;
use App\Services\MarketDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    /** ข้อมูลประกอบของกลยุทธ์จาก config — อ่านครั้งเดียวต่อหนึ่ง request */
    private ?array $strategyMetaCache = null;

    /** ข้อความที่ผู้ใช้เห็นเมื่อทำรายการไม่ผ่าน (ไม่เปิดเผยรายละเอียดภายใน) */
    private const ERROR_MESSAGES = [
        AiBotService::ERR_INSUFFICIENT_CREDITS => 'เครดิตการทำงานไม่พอ กรุณาเติมเครดิตก่อนเริ่มใช้งานบอท',
        AiBotService::ERR_NO_SUBSCRIPTION => 'ยังไม่ได้เช่าบอท AI TRADE — เลือกแพลนก่อนเริ่มใช้งาน',
        AiBotService::ERR_BOT_LIMIT => 'จำนวนบอทเต็มโควตาของแพลนนี้แล้ว',
        AiBotService::ERR_STRATEGY_LOCKED => 'กลยุทธ์นี้ต้องใช้แพลนระดับสูงกว่า',
        AiBotService::ERR_SUBSCRIBE_BUSY => 'กำลังทำรายการเช่าของกระเป๋านี้อยู่ กรุณารอสักครู่แล้วลองใหม่',
        AiBotService::ERR_TOPUP_CLOSED => 'ยังไม่เปิดให้เติมเครดิต — รอประกาศเปิดระบบชำระเงินก่อน ระหว่างนี้ใช้เครดิตต้อนรับทดลองได้',
        AiBotService::ERR_SALES_CLOSED => 'AI TRADE ยังอยู่ระหว่างทดสอบ ยังไม่เปิดให้เช่า — ระหว่างนี้ทดลองใช้โหมดทดลองได้เต็มที่ ไม่มีค่าใช้จ่าย',
        // เดิมไม่มีข้อความ — ผู้ใช้เห็นแค่ "ทำรายการไม่สำเร็จ" แล้วไม่รู้ว่าต้องเปลี่ยนกลยุทธ์
        AiBotService::ERR_STRATEGY_RETIRED => 'กลยุทธ์นี้ถูกถอดออกจากการขายแล้ว — สร้างหรือเริ่มบอทด้วยกลยุทธ์นี้ไม่ได้ (บอทเดิมยังแก้ชื่อ ลดความเสี่ยง หรือลบได้)',
        'INVALID_PACK' => 'ไม่พบแพ็กเกจเครดิตที่เลือก',
    ];

    /**
     * ด่านสิทธิ์ที่ไม่ผ่านตอนสั่งเดินจากเบราว์เซอร์ → รหัสเหตุผลการพัก + ข้อความที่เก็บไว้ในบอท.
     *
     * ทุกรหัสในนี้คือ "แพลนปัจจุบันไม่ให้บอทตัวนี้เดินแล้ว" — ไม่ใช่ปัญหาชั่วคราว
     * จึงต้องพักบอทพร้อมบอกเหตุผล ไม่ใช่ตอบ 403 แล้วปล่อยสถานะ running ค้างไว้
     */
    private const PLAN_PAUSES = [
        AiBotService::ERR_NO_SUBSCRIPTION => [AiBotConfig::PAUSE_PLAN_EXPIRED, 'การเช่าหมดอายุ — บอทถูกพักอัตโนมัติ ต่ออายุแพลนแล้วกดเริ่มใหม่ได้'],
        AiBotService::ERR_STRATEGY_LOCKED => [AiBotConfig::PAUSE_PLAN_LOCKED, 'แพลนปัจจุบันไม่รวมกลยุทธ์นี้ (แพลนเดิมอาจหมดอายุ) — บอทถูกพักไว้ ต่ออายุหรืออัปเกรดแพลนเพื่อใช้ต่อ'],
        AiBotService::ERR_BOT_LIMIT => [AiBotConfig::PAUSE_PLAN_QUOTA, 'จำนวนบอทเกินโควตาของแพลนปัจจุบัน — บอทถูกพักไว้ ลบบอทที่ไม่ใช้ให้เหลือตามโควตาก่อน'],
        AiBotService::ERR_STRATEGY_RETIRED => [AiBotConfig::PAUSE_STRATEGY_RETIRED, 'กลยุทธ์นี้ถูกถอดออกจากการขายแล้ว — บอทถูกพักไว้'],
    ];

    /**
     * ยอมให้เวลาเบราว์เซอร์คลาดได้กี่วินาทีจากรอบขั้นต่ำ.
     *
     * ตัวจับเวลาของเบราว์เซอร์กับเวลาเดินทางของคำขอแกว่งได้หลายร้อยมิลลิวินาที — ไม่เผื่อไว้เลย
     * คำขอที่มาถึงเร็วไป 0.2 วิจะถูกข้าม แล้วรอบที่ตั้งไว้ 30 วิกลายเป็น 60 วิ (ช้าลงครึ่งหนึ่ง)
     */
    private const BROWSER_TICK_SLACK_SECONDS = 5;

    public function __construct(
        private readonly AiBotService $bots,
        private readonly PaperBroker $broker,
        private readonly StrategyAvailability $availability,
        private readonly WorkerHealth $health,
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
            // เทียบกับ null ตรงๆ ห้ามใช้ truthiness — decimal:2 คืน "0.00" ซึ่ง PHP
            // มองว่า falsy แล้วส่ง null ออกไป หน้าเว็บจึงโฆษณาว่า "ไม่จำกัดทุนต่อไม้"
            // ทั้งที่ของจริงคือเปิดไม้ไม่ได้เลย (ตรงข้ามกันคนละขั้ว)
            'max_capital_usd' => $plan->max_capital_usd === null ? null : (float) $plan->max_capital_usd,
            'features' => $plan->features ?? [],
            'features_th' => $plan->features_th ?? [],
            'badge' => $plan->badge,
            'strategies' => $plan->unlockedStrategies(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'plans' => $plans,
                // แนบสถานะ "ลงมือได้จริงไหม" ไปด้วย — ปลดล็อกตามแพลนกับใช้ได้จริง
                // เป็นคนละเรื่อง หน้าเว็บและแอพต้องแยกสองอย่างนี้ออกจากกันให้ผู้ใช้เห็น
                'strategies' => $this->availability->decorate(config('aibot.strategies', [])),
                'packs' => config('aibot.credits.packs', []),
                'rental_days' => config('aibot.credits.rental_days', [1, 7, 30]),
                'timeframes' => config('aibot.timeframes', []),
                'limits' => config('aibot.limits', []),

                /*
                 * สวิตช์ที่ทุกกลยุทธ์มีเหมือนกัน (ให้ AI เลือกเหรียญ · ด่านข่าว)
                 *
                 * ต้องส่งแยกจาก strategies.*.params เพราะหน้าเว็บวาดฟอร์มจากรายการ
                 * ของกลยุทธ์ที่เลือกเท่านั้น — ไม่ส่งตัวนี้ไป สวิตช์จะไม่มีวันโผล่
                 * ให้ผู้ใช้เห็น ทั้งที่ฝั่งเซิร์ฟเวอร์รองรับแล้ว
                 */
                'common_params' => config('aibot.common_params', []),

                /* ให้หน้าเว็บรู้ว่าจะโชว์แผงมุมมองตลาดของ AI ไหม */
                'analyst_enabled' => (bool) config('aibot_analyst.enabled', false),
                /*
                 * ธงบอกว่าอะไร "เปิดใช้จริงแล้ว" — ส่งจากที่เดียวให้ทั้งเว็บและแอพ
                 *
                 * ก่อนหน้านี้หน้าเว็บฮาร์ดโค้ดข้อความ "ตอนนี้เปิดเฉพาะโหมดทดลอง"
                 * ไว้เอง ซึ่งแปลว่าวันที่เปิดโหมดจริง ต้องไล่แก้ทุกที่ที่เขียนไว้
                 * และแอพมือถือจะไม่มีทางรู้เลยว่าอะไรเปิดอะไรปิด
                 */
                'features' => [
                    'live_trading' => (bool) config('aibot.live_enabled', false),
                    'credit_topup' => (bool) config('aibot.credits.topup_enabled', false),
                    // ยังไม่เปิดให้เช่า = หน้าเว็บต้องปิดปุ่มเช่าและบอกเหตุผล
                    // ไม่ใช่ปล่อยให้กดแล้วค่อยเด้ง error ตอนจ่ายเงิน
                    'sales_open' => $this->bots->salesOpen(),
                ],
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

        /*
         * ลงแพลนฟรีให้ตั้งแต่ถามสถานะครั้งแรก ไม่ใช่รอตอนกดสร้างบอท
         *
         * เดิมใช้ activeSubscription() เฉยๆ → กระเป๋าที่ยังไม่เคยเช่าได้
         * subscription = null · max_bots = 0 · unlocked_strategies = []
         * หน้าเว็บจึงเห็นแผงเปล่า กลยุทธ์ล็อกหมด และปุ่ม "บอทใหม่" ถูกปิด
         * (quotaFull คิดจาก used_bots >= max_bots → 0 >= 0 เป็นจริง)
         * ผู้ใช้ใหม่จึงเข้ามาเจอหน้าที่กดอะไรไม่ได้เลย ทั้งที่แพลนฟรีเปิดให้ใช้อยู่
         *
         * assertCanRunBot() เรียกตัวเดียวกันนี้อยู่แล้ว — ย้ายมาเรียกให้เร็วขึ้น
         * เท่านั้น ไม่ได้เพิ่มสิทธิ์ใหม่ให้ใคร
         */
        $subscription = $this->bots->ensureFreeSubscription($wallet);
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
                /*
                 * กระเป๋าของทีมงาน — หน้าเว็บใช้ตัดสินว่าจะซ่อนด่านการขายไหม
                 * (สิทธิ์จริงบังคับที่เซิร์ฟเวอร์อยู่แล้ว ธงนี้มีไว้ให้จอแสดงผลถูก)
                 */
                'is_admin' => $this->bots->isAdminWallet($wallet),
                /*
                 * โบนัสต้อนรับรับไปแล้วหรือยัง — หน้าเว็บซ่อนปุ่มรับจากธงนี้
                 *
                 * เดิมหน้าเว็บโชว์ปุ่มเมื่อ "เครดิตเป็นศูนย์" ซึ่งเป็นจริงอีกครั้งหลังใช้เครดิตหมด
                 * ผู้ใช้กดรับซ้ำแล้วเห็นว่า "รับเรียบร้อย" ทั้งที่ไม่ได้อะไรเพิ่มเลย
                 */
                'welcome_bonus' => (float) config('aibot.credits.welcome_bonus', 0),
                'welcome_claimed' => $this->bots->welcomeClaimed($wallet),
                'bots' => $this->botList($wallet, $subscription),
                /*
                 * สัญญาณชีพของวอร์กเกอร์ — ให้หน้าจอบอกได้ว่า "ออนไลน์จริง" หรือแค่
                 * สถานะเขียน running ค้างไว้ตอนเซิร์ฟเวอร์ดับ (aibot:health เป็นคนเก็บ)
                 */
                'worker' => $this->health->summary(),
            ],
        ]);
    }

    /**
     * ไม้ที่บอทลงมือไปแล้ว — ทุกโหมด ทุกบอทของกระเป๋านี้.
     *
     * หน้าเทรดใช้ปักป้ายเข้า/ออกบนกราฟ จึงรับ `pair` มากรองและส่งกลับเรียงตามเวลา
     * จากเก่าไปใหม่ (ปลั๊กอิน marker ของกราฟต้องการลำดับนั้น) ไม่ใช่เรียงล่าสุดก่อน
     * แบบหน้าประวัติ — เรียงผิดแล้วป้ายหายทั้งชุดโดยไม่มี error ให้เห็น
     */
    public function trades(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);

        $validated = $request->validate([
            'pair' => ['nullable', 'string', 'max:20'],
            'mode' => ['nullable', 'string', 'in:demo,live'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $pair = isset($validated['pair']) ? strtoupper(str_replace('-', '/', $validated['pair'])) : null;
        $limit = (int) ($validated['limit'] ?? 300);

        $trades = AiBotTrade::with('bot:id,name,strategy')
            ->where('wallet_address', $wallet)
            ->when($pair, fn ($q) => $q->where('pair', $pair))
            ->when($validated['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AiBotTrade $t) => [
                'id' => $t->id,
                'bot_id' => $t->ai_bot_config_id,
                'bot_name' => $t->bot?->name,
                'strategy' => $t->strategy,
                'pair' => $t->pair,
                'mode' => $t->mode,
                'side' => $t->side,
                'price' => (float) $t->price,
                'quantity' => (float) $t->quantity,
                'gross_value' => (float) $t->gross_value,
                'fee' => (float) $t->fee,
                'realized_pnl' => $t->realized_pnl !== null ? (float) $t->realized_pnl : null,
                'reason' => $t->reason,
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['success' => true, 'data' => $trades]);
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

    /**
     * ประวัติการตัดสินใจของบอทในกระเป๋านี้ — ให้เจ้าของบอทเห็นว่า "AI คิดอะไรอยู่".
     *
     * ⚠️ เดิมตาราง ai_bot_decisions เปิดอ่านได้เฉพาะหลังบ้าน (AiBotAdminController)
     *    เจ้าของบอทเห็นได้แค่ `last_reason` ของรอบล่าสุดรอบเดียว ซึ่งถูกเขียนทับ
     *    ทุกครั้งที่บอทคิด — คนที่จ่ายเงินเช่าจึงมอนิเตอร์อะไรไม่ได้เลย ทั้งที่เหตุผล
     *    ที่บอท "ไม่ทำอะไร" คือสิ่งที่บอกได้ว่าบอททำงานถูกหรือเปล่า และเกิดบ่อยกว่า
     *    การเข้าไม้หลายสิบเท่า
     *
     * ผูกกับ wallet ของผู้เรียกเสมอผ่าน scopeForWallet — ส่ง bot_id ของคนอื่นมาก็ได้ผลว่าง
     */
    public function decisions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bot_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', 'string', 'in:demo,live'],
            'acted_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            // เลื่อนหน้าแบบ cursor — ส่ง id ของแถวสุดท้ายที่ได้ไปแล้ว
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $wallet = $this->wallet($request);
        $limit = (int) ($validated['limit'] ?? 50);

        $query = AiBotDecision::forWallet($wallet)->orderByDesc('id');

        if (! empty($validated['bot_id'])) {
            $query->where('ai_bot_config_id', (int) $validated['bot_id']);
        }

        if (! empty($validated['mode'])) {
            $query->where('mode', $validated['mode']);
        }

        if ($request->boolean('acted_only')) {
            $query->acted();
        }

        if (! empty($validated['before_id'])) {
            $query->where('id', '<', (int) $validated['before_id']);
        }

        /*
         * ดึงเกินมาหนึ่งแถวเพื่อรู้ว่ายังมีต่อไหม แทนการ count() ทั้งตาราง
         * ตารางนี้โตวันละหลายพันแถว — count ทุกครั้งคือจ่ายแพงเพื่อข้อมูลชิ้นเดียว
         */
        $rows = $query->with('bot:id,name')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'success' => true,
            'data' => [
                'decisions' => $rows->map(fn (AiBotDecision $d) => $this->presentDecision($d))->values(),
                'next_cursor' => $hasMore ? $rows->last()?->id : null,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * แปลงหนึ่งรอบความคิดของบอทให้หน้าจออ่านได้.
     *
     * ไม่ส่ง `params` ออกไป — เป็นค่าตั้งบอทที่หน้าจอมีอยู่แล้วจาก /bots
     * ส่งซ้ำทุกแถวคือถ่วงคำตอบให้ใหญ่ขึ้นหลายเท่าโดยไม่ได้อะไรเพิ่ม
     */
    private function presentDecision(AiBotDecision $decision): array
    {
        $meta = $this->strategyMetaFor($decision->strategy);

        return [
            'id' => $decision->id,
            'bot_id' => $decision->ai_bot_config_id,
            'bot_name' => $decision->bot?->name,
            'pair' => $decision->pair,
            'strategy' => $decision->strategy,
            'strategy_name' => $meta['name'] ?? $decision->strategy,
            'strategy_name_th' => $meta['name_th'] ?? ($meta['name'] ?? $decision->strategy),
            'timeframe' => $decision->timeframe,
            'mode' => $decision->mode,
            'action' => $decision->action,
            'reason' => $decision->reason,
            'risk_level' => $decision->risk_level,
            /*
             * decimal cast ของ Laravel คืนค่าเป็น string ("0.00000000")
             * ส่งดิบไปฝั่งแอพต้องเดาชนิดเอง แล้วเผลอเทียบ string กับตัวเลข
             */
            'price' => $decision->price === null ? null : (float) $decision->price,
            'budget' => $decision->budget === null ? null : (float) $decision->budget,
            'has_position' => (bool) $decision->has_position,
            'signal_meta' => $decision->signal_meta ?: null,
            'created_at' => $decision->created_at->toIso8601String(),
        ];
    }

    /** ข้อมูลประกอบของกลยุทธ์จาก config (โค้ด → meta) */
    private function strategyMetaFor(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        if ($this->strategyMetaCache === null) {
            $map = [];
            foreach (config('aibot.strategies', []) as $strategy) {
                $key = $strategy['code'] ?? null;
                if ($key !== null) {
                    $map[$key] = $strategy;
                }
            }
            $this->strategyMetaCache = $map;
        }

        return $this->strategyMetaCache[$code] ?? null;
    }

    /**
     * รับโบนัสต้อนรับ (ครั้งเดียวต่อ wallet).
     *
     * กดซ้ำยังตอบ success (ไม่ใช่ข้อผิดพลาดของผู้ใช้) แต่ `granted = false` บอกว่าครั้งนี้
     * ไม่ได้เครดิตเพิ่ม — หน้าเว็บ/แอพต้องไม่ขึ้นว่า "รับเรียบร้อย" ซ้ำทุกครั้งที่กด
     */
    public function claimWelcome(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);
        $result = $this->bots->grantWelcomeBonus($wallet);

        return response()->json([
            'success' => true,
            'data' => ['credits' => $result['credits'], 'granted' => $result['granted']],
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
            /*
             * 402 (ต้องชำระเงิน) ใช้ได้เฉพาะ "เครดิตไม่พอ" เท่านั้น
             *
             * เหมารวมทุกเหตุผลเป็น 402 ทำให้ "ยังไม่เปิดขาย" ถูกอ่านว่าเป็นเรื่องเงิน
             * ทั้งฝั่งแอพมือถือและใครก็ตามที่เขียนโค้ดคุยกับ API — เขาจะพาผู้ใช้ไป
             * หน้าเติมเงินทั้งที่เติมไปก็ยังเช่าไม่ได้อยู่ดี
             */
            $status = $e->getMessage() === AiBotService::ERR_INSUFFICIENT_CREDITS ? 402 : 422;

            return $this->failure($e->getMessage(), null, $status);
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

        if (! $this->pairHasCandles($validated['pair'], $validated['timeframe'])) {
            return $this->failure(
                'PAIR_NO_CANDLES',
                'คู่ '.strtoupper($validated['pair']).' ยังไม่มีข้อมูลแท่งเทียนให้บอทใช้ตัดสินใจ — เลือกคู่อื่นก่อน'
            );
        }

        [$params, $riskInput] = $this->applyTemplate($validated, $request, isNew: true);

        $bot = AiBotConfig::create([
            'wallet_address' => $wallet,
            'ai_bot_subscription_id' => $subscription->id,
            'name' => $validated['name'],
            'pair' => strtoupper($validated['pair']),
            'strategy' => $validated['strategy'],
            'timeframe' => $validated['timeframe'],
            'params' => $this->bots->sanitizeParams($validated['strategy'], $params),
            'risk' => $this->bots->sanitizeRisk($riskInput, $subscription->plan),
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

        // ส่งบอทเดิมไปด้วย เพื่อให้กลยุทธ์ที่มันใช้อยู่ยังแก้ไขต่อได้แม้จะถูกปิดไปแล้ว
        $validated = $this->validateBot($request, $bot);

        try {
            // editingOnly: แก้ชื่อ/ลดความเสี่ยงของบอทกลยุทธ์ที่ถูกถอดแล้วได้ (ตามที่ validateBot ตั้งใจ)
            $subscription = $this->bots->assertCanRunBot($wallet, $validated['strategy'], $bot, editingOnly: true);
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage(), null, 403);
        }

        [$params, $riskInput] = $this->applyTemplate($validated, $request);

        $bot->fill([
            'name' => $validated['name'],
            'pair' => strtoupper($validated['pair']),
            'strategy' => $validated['strategy'],
            'timeframe' => $validated['timeframe'],
            'params' => $this->bots->sanitizeParams($validated['strategy'], $params),
            'risk' => $this->bots->sanitizeRisk($riskInput, $subscription->plan),
        ]);

        /*
         * แก้ได้ แต่ห้ามเดินต่อ — บอทกลยุทธ์ที่ถูกถอดซึ่งยังค้าง running อยู่ถูกพักไว้ตรงนี้เลย
         * ไม่ต้องรอให้ BotRunner เจอในรอบถัดไป (เส้นทางเริ่มบอทใหม่ยังถูกด่านเต็มกันไว้เหมือนเดิม)
         */
        if ($bot->status === 'running' && $this->bots->isRetired($bot->strategy)) {
            $bot->status = 'paused';
            $bot->last_reason = self::PLAN_PAUSES[AiBotService::ERR_STRATEGY_RETIRED][1];
            $bot->stats = array_merge($bot->stats ?? [], ['pause_reason' => AiBotConfig::PAUSE_STRATEGY_RETIRED]);
        }

        $bot->save();

        return response()->json(['success' => true, 'data' => $this->presentBot($bot->fresh())]);
    }

    /**
     * เทมเพลต (ชุดตั้งค่าที่ทีมงาน backtest ไว้) เป็นฐาน แล้วค่าที่ผู้ใช้ส่งมาทับ.
     *
     * รับที่ API ไม่ใช่แค่หน้าเว็บ — แอพมือถือส่ง `template: "balanced"` มาเฉยๆ ก็ได้
     * ชุดที่ถูกต้องโดยไม่ต้องรู้จักพารามิเตอร์ทุกตัว (และเมื่อทีมงานปรับเทมเพลต
     * แอพเก่าได้ค่าใหม่อัตโนมัติ) รหัสที่ไม่มีอยู่ต้องแจ้ง ไม่ใช่เงียบแล้วใช้ค่าปริยาย
     *
     * @return array{0: array, 1: array} [params, risk] ที่ยังไม่ผ่าน sanitize
     */
    private function applyTemplate(array $validated, Request $request, bool $isNew = false): array
    {
        $template = $this->bots->templateFor($validated['strategy'], $validated['template'] ?? null);

        if (($validated['template'] ?? null) && ! $template) {
            abort(response()->json([
                'success' => false,
                'error' => ['code' => 'TEMPLATE_NOT_FOUND', 'message' => 'ไม่พบเทมเพลตนี้ของกลยุทธ์ที่เลือก'],
            ], 422));
        }

        /*
         * บอทใหม่ที่ไม่ระบุเทมเพลต = ใช้ชุด "สมดุล" (ชุดที่ผ่าน backtest 2 ปี) เป็นฐาน
         *
         * เจ้าของสั่ง 2026-09-23: "มีค่าปริยายการตั้งค่าที่เหมาะสมมาให้เลยตั้งแต่แรก" — หน้าเว็บ
         * ตั้งชุดสมดุลให้อยู่แล้ว แต่ API ไม่ทำ: แอป/ลูกค้าที่ไม่ส่ง template เคยได้ค่าปริยายดิบ
         * ของกลยุทธ์ (เช่น DCA ไม่พักช่วงขาลง ขาย +10% ตัด −5%) ไม่ใช่ชุดที่ทีมงานแนะนำ
         * ค่าที่ผู้ใช้ส่งมาเองยังชนะทุกช่อง (array_merge ข้างล่าง) · แก้บอทเดิมไม่ใช้ทางนี้
         * — ไม่งั้นช่องที่ไม่ได้ส่งมาจะถูกรีเซ็ตทับค่าที่ผู้ใช้ตั้งไว้
         */
        if (! $template && $isNew) {
            $template = $this->bots->templateFor($validated['strategy'], 'balanced');
        }

        return [
            array_merge($template['params'] ?? [], (array) $request->input('params', [])),
            array_merge($template['risk'] ?? [], (array) $request->input('risk', [])),
        ];
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
            // บอทที่ทีมงานปิดกั้นไว้ เจ้าของกดเริ่มเองไม่ได้ ไม่งั้นการแบนไม่มีความหมาย
            if ($bot->isBanned()) {
                return $this->failure(
                    'BOT_BANNED',
                    'บอทตัวนี้ถูกทีมงานระงับไว้'.($bot->banned_reason ? ' — '.$bot->banned_reason : ''),
                    403
                );
            }

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

        // เหตุผลที่ระบบเคยพักไว้ (เช่นแพลนหมดอายุ) ไม่จริงแล้ว — ผู้ใช้กดเองครั้งนี้
        $bot->clearPauseReason();
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

        /*
         * ⚠️ ต้องกันที่นี่อีกชั้น — `scopeRunnable()` คุมเฉพาะเส้นทางคลาวด์
         *    แพลนฟรีเดินบอทด้วยการให้เบราว์เซอร์ยิงเข้ามาตรงๆ ไม่ผ่าน scope นั้นเลย
         *    ลืมจุดนี้ = แบนแล้วบอทบนคลาวด์หยุด แต่บอทของแพลนฟรีเดินต่อได้เรื่อยๆ
         */
        if ($bot->isBanned()) {
            return $this->failure(
                'BOT_BANNED',
                'บอทตัวนี้ถูกทีมงานระงับไว้'.($bot->banned_reason ? ' — '.$bot->banned_reason : ''),
                403
            );
        }

        $plan = $this->bots->activeSubscription($wallet)?->plan;

        if ($plan?->runsInCloud()) {
            return $this->failure('CLOUD_BOT', 'บอทของแพลนนี้เดินบนคลาวด์อยู่แล้ว ไม่ต้องสั่งจากหน้าเว็บ');
        }

        /*
         * สิทธิ์ต้องคิดจากแพลน "ปัจจุบัน" ทุกรอบที่สั่งเดิน
         *
         * เดิมด่านเดียวคือ "แพลนนี้เป็นคลาวด์ไหม" — พอแพลน VIP หมดอายุ ระบบลง
         * แพลนฟรีให้ (execution = browser) เส้นทางนี้จึงเปิดกว้าง ผู้ใช้ที่เคยจ่าย
         * VIP รันบอท 10 ตัวด้วยกลยุทธ์ VIP ต่อได้ฟรีตลอดกาลผ่านหน้าเว็บ
         *
         * assertCanRunBot บังคับทั้งระดับกลยุทธ์และโควตาจำนวนบอทในที่เดียว
         * (ส่ง $bot เป็น existing เพื่อไม่ให้ตัวมันเองถูกนับซ้ำในโควตา)
         */
        try {
            $this->bots->assertCanRunBot($wallet, $bot->strategy, $bot);
        } catch (RuntimeException $e) {
            /*
             * แพลนปัจจุบันไม่ให้บอทตัวนี้เดินแล้ว = พักพร้อมเหตุผล
             *
             * เดิมตอบ 403 เฉยๆ บอทจึงค้าง running ตลอดกาล (เช่น VIP หมดอายุ → ระบบลงแพลนฟรีให้
             * → กลยุทธ์ VIP ถูกล็อก) หน้าจอโชว์เขียวอยู่พักหนึ่งแล้วกลายเป็นแดง "กำลังตรวจสอบ"
             * โดยไม่มีใครบอกผู้ใช้ว่าแค่ต้องต่ออายุแพลน
             */
            if ($pause = self::PLAN_PAUSES[$e->getMessage()] ?? null) {
                $bot->pauseBecause($pause[0], $pause[1]);
            }

            return $this->failure($e->getMessage(), null, 403);
        }

        // กันหน้าเว็บยิงรัวเกินจำเป็น — คิดหนึ่งรอบต่อช่วงเวลาที่กำหนดก็พอ
        $minSeconds = max(1, (int) config('aibot.browser_tick_min_seconds', 30));

        /*
         * ล็อกต่อบอท — ตรวจรอบ + บันทึกเวลาเริ่ม + เดินบอท ต้องเป็นก้อนเดียวกัน
         *
         * เดิมเป็น "เช็คแล้วค่อยทำ" ไม่มีล็อก: เปิดสองแท็บ (หรือการ์ดในหน้าเทรดกับหน้า /ai-trade
         * คนละแท็บ) คำขอสองใบเห็นว่าถึงรอบพร้อมกัน แล้วเดินบอทซ้อนกัน = ซื้อซ้ำสองไม้
         * ใบที่มาทีหลังได้ "ข้าม" ไปแทน — บอทเดินแค่ครั้งเดียวต่อรอบเสมอ
         */
        $lock = Cache::lock('aibot:browser-tick:'.$bot->id, 120);

        if (! $lock->get()) {
            return response()->json(['success' => true, 'data' => [
                'skipped' => true,
                'reason' => 'บอทตัวนี้กำลังคิดรอบนี้อยู่จากอีกหน้าต่าง',
                'next_in_seconds' => $minSeconds,
            ]]);
        }

        try {
            $lastStart = $this->lastBrowserRunStart($bot);

            // เวลาในอนาคต (นาฬิกา/โซนเวลาเลื่อน — ดู config/app.php) = ถึงรอบ ไม่ใช่ติดลบแล้วรอเป็นชั่วโมง
            if ($lastStart && ! $lastStart->isFuture()) {
                $elapsed = (int) floor($lastStart->diffInSeconds(now()));

                if ($elapsed < $minSeconds - self::BROWSER_TICK_SLACK_SECONDS) {
                    return response()->json(['success' => true, 'data' => [
                        'skipped' => true,
                        'reason' => 'ยังไม่ถึงรอบถัดไป',
                        'next_in_seconds' => max(1, $minSeconds - $elapsed),
                    ]]);
                }
            }

            /*
             * นับรอบจาก "เวลาเริ่ม" ไม่ใช่เวลาจบ
             *
             * last_run_at ถูกเขียนตอนจบรอบ (BotRunner::record) — เดิมเทียบกับค่านั้น
             * หน้าเว็บสั่งทุก 30 วิเท่ากับขั้นต่ำพอดี รอบที่ใช้เวลา 2 วิจึงทำให้คำขอถัดไป
             * เห็นว่าผ่านไปแค่ 28 วิ → ข้ามทุกรอบเว้นรอบ บอทเดินจริงทุก 60 วิ
             */
            Cache::put($this->browserRunStartKey($bot), now()->getTimestamp(), now()->addHour());

            $result = $runner->tick($bot);
        } finally {
            $lock->release();
        }

        return response()->json(['success' => true, 'data' => array_merge($result, [
            'skipped' => false,
            'bot' => $this->presentBot($bot->fresh()),
        ])]);
    }

    private function browserRunStartKey(AiBotConfig $bot): string
    {
        return 'aibot:browser-tick-start:'.$bot->id;
    }

    /**
     * เวลาที่รอบล่าสุดจากเบราว์เซอร์ "เริ่ม" เดิน.
     *
     * ไม่มีในแคช (แคชถูกล้างตอน deploy / บอทเพิ่งย้ายมาเดินจากเบราว์เซอร์) = ใช้ last_run_at
     * แทน ซึ่งเป็นเวลาจบรอบ — ช้ากว่าเวลาเริ่มเล็กน้อย จึงพลาดไปทางรอนานขึ้น ไม่ใช่เดินรัว
     */
    private function lastBrowserRunStart(AiBotConfig $bot): ?Carbon
    {
        $startedAt = Cache::get($this->browserRunStartKey($bot));

        if (is_numeric($startedAt)) {
            return Carbon::createFromTimestamp((int) $startedAt);
        }

        return $bot->last_run_at;
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
    public function demo(Request $request, MarketDataService $market): JsonResponse
    {
        $wallet = $this->wallet($request);

        /*
         * พอร์ตทดลองแยกตามกลยุทธ์ — แต่ละกลยุทธ์มีเงินก้อนของตัวเอง
         *
         * ต้องแยกเพราะถ้าใช้ก้อนเดียวกัน กลยุทธ์ที่เข้าไม้ก่อนกินงบไปหมด
         * แล้วตัวอื่นไม่เหลือให้เทรด — เอาผลมาเทียบกันไม่ได้เลยว่าตัวไหนดีกว่า
         * ซึ่งเป็นคำถามเดียวที่ต้องตอบให้ได้ก่อนเปิดขาย
         *
         * `account` ที่ส่งออกยังเป็นภาพรวมรวมทุกพอร์ต เพื่อไม่ให้หน้าจอเดิมพัง
         * ส่วน `portfolios` คือรายพอร์ตสำหรับหน้าที่อยากเทียบกลยุทธ์
         */
        $allAccounts = AiBotDemoAccount::where('wallet_address', $wallet)->get();

        /*
         * ⚠️ ตัวเลขทุกตัว (เงินสด · ทุนตั้งต้น · มูลค่าพอร์ต) ต้องมาจากพอร์ตชุดเดียวกัน
         *
         * เดิมเงินสดกับทุนตั้งต้นรวมทุกพอร์ต แต่มูลค่าพอร์ตใช้เงินสดของ "พอร์ตแรก" ใบเดียว
         * บวกกับที่หน้านี้เคยสร้างพอร์ตรวม (bucket = null) ให้กระเป๋าใหม่ ซึ่งไม่เคยถูกเทรด
         * (PaperBroker::accountFor ผูกกับกลยุทธ์เสมอ) — กระเป๋าใหม่ที่บอทกริดซื้อไป $100
         * จึงเห็น เงินสด $19,900 · ทุน $20,000 · มูลค่าพอร์ต $10,100 (ดูเหมือนขาดทุนครึ่งพอร์ต)
         *
         * พอร์ตรวมเดิมที่ไม่เคยขยับเลย = ที่เก็บโควตาการล้างเปล่าๆ ไม่ใช่เงินทดลอง → ไม่นับ
         * (ไม่ลบทิ้ง — ยังใช้เก็บโควตาการล้างของวันนี้อยู่) ส่วนพอร์ตรวมรุ่นเก่าที่เคยเทรดจริง
         * ยังนับครบทั้งสามตัวเลข เพื่อให้กำไร/ขาดทุนรวมถูกต้อง
         */
        $accounts = $allAccounts
            ->reject(fn (AiBotDemoAccount $a) => $this->isPlaceholderDemoAccount($a))
            ->values();

        $startingEach = (float) config('aibot_risk.demo.starting_balance', 10000);

        // ยังไม่มีพอร์ตไหนถูกใช้ = โชว์ทุนตั้งต้นหนึ่งก้อน โดยไม่สร้างแถวทิ้งไว้ (GET ต้องไม่เขียนข้อมูล)
        $cash = $accounts->isEmpty() ? $startingEach : (float) $accounts->sum(fn ($a) => (float) $a->balance);
        $startingTotal = $accounts->isEmpty() ? $startingEach : (float) $accounts->sum(fn ($a) => (float) $a->starting_balance);

        // โควตาการล้างนับรวมทั้งกระเป๋า (ดู PaperBroker::reset) — รวมพอร์ตที่ไม่นับเงินด้วย
        $resetsUsedToday = (int) $allAccounts
            ->filter(fn (AiBotDemoAccount $a) => $a->last_reset_at?->isToday())
            ->max('reset_count');

        $botIds = AiBotConfig::forWallet($wallet)->pluck('id');

        $openPositions = AiBotPosition::with('bot:id,name,strategy')
            ->whereIn('ai_bot_config_id', $botIds)
            ->where('mode', 'demo')
            ->get();

        /*
         * ตีราคาของที่ถืออยู่ด้วยราคาตลาดปัจจุบัน ไม่ใช่ราคาทุน
         *
         * เดิมส่งแต่ `cost_basis` ออกไป หน้าเว็บจึงคิดมูลค่าพอร์ตจากราคาทุน —
         * ซื้อที่ $100 แล้วราคาร่วงเหลือ $50 พอร์ตยังโชว์เต็ม $100 อยู่ดี
         * ขาดทุนจะโผล่ก็ต่อเมื่อบอทปิดไม้ ซึ่งอาจไม่เกิดขึ้นเลยถ้าราคาไม่กลับ
         *
         * โหมดทดลองมีไว้ให้ผู้ใช้ตัดสินใจว่าจะจ่ายเงินเช่าจริงไหม ตัวเลขที่สวย
         * เพราะซ่อนไม้ที่ติดลบไว้ จึงเป็นการหลอกให้ตัดสินใจผิด — ต้องเห็นว่า
         * "ตอนนี้ถ้าปิดจะได้เท่าไหร่" ทุกวินาที ไม่ใช่รอให้บอทยอมปิดก่อน
         *
         * ดึงราคาทีละคู่ที่ไม่ซ้ำกัน (แคช 10 วิอยู่แล้วใน MarketDataService)
         */
        $prices = $openPositions
            ->pluck('pair')
            ->unique()
            ->mapWithKeys(fn (string $pair) => [$pair => $market->getTokenPrice($pair)['price'] ?? null])
            ->all();

        $positions = $openPositions
            ->map(function (AiBotPosition $p) use ($prices) {
                $quantity = (float) $p->quantity;
                $costBasis = (float) $p->cost_basis;
                $price = $prices[$p->pair] ?? null;

                // ราคาตลาดดึงไม่ได้ = ตีเท่าทุนไปก่อน และบอกด้วยว่ายังตีราคาไม่ได้
                $marketValue = $price !== null ? $quantity * (float) $price : $costBasis;

                return [
                    'id' => $p->id,
                    'bot_id' => $p->ai_bot_config_id,
                    'bot_name' => $p->bot?->name,
                    'pair' => $p->pair,
                    'quantity' => $quantity,
                    'entry_price' => (float) $p->entry_price,
                    'cost_basis' => $costBasis,
                    'entry_count' => $p->entry_count,
                    'opened_at' => $p->opened_at?->toIso8601String(),
                    'current_price' => $price !== null ? (float) $price : null,
                    'market_value' => round($marketValue, 2),
                    'unrealized_pnl' => round($marketValue - $costBasis, 2),
                    'unrealized_pct' => $costBasis > 0
                        ? round((($marketValue - $costBasis) / $costBasis) * 100, 2)
                        : 0.0,
                    'priced' => $price !== null,
                ];
            })
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
                // รวมทุกพอร์ต — ตัวเลขที่ผู้ใช้เห็นเป็นภาพรวมของการทดลองทั้งหมด
                'balance' => round($cash, 2),
                'starting_balance' => round($startingTotal, 2),
                'resets_used_today' => $resetsUsedToday,
                'resets_per_day' => (int) config('aibot_risk.demo.max_resets_per_day', 3),
                'fee_rate' => (float) config('aibot_risk.demo.fee_rate', 0.1),
                'slippage_bps' => (int) config('aibot_risk.demo.slippage_bps', 8),
            ],
            'positions' => $positions,
            'trades' => $trades,
            /*
             * รายพอร์ต — ใช้เทียบว่ากลยุทธ์ไหนทำได้ดีกว่าในช่วงเวลาเดียวกัน
             * (bucket = null คือพอร์ตรวมของเดิมก่อนแยกพอร์ต)
             */
            'portfolios' => $accounts
                ->map(fn (AiBotDemoAccount $a) => [
                    'strategy' => $a->bucket,
                    'balance' => (float) $a->balance,
                    'starting_balance' => (float) $a->starting_balance,
                    'pnl' => round((float) $a->balance - (float) $a->starting_balance, 2),
                ])
                ->sortBy('strategy')
                ->values()
                ->all(),
            'summary' => [
                'realized_pnl' => round((float) $closed->sum('realized_pnl'), 2),
                /*
                 * กำไร/ขาดทุนของไม้ที่ยังไม่ปิด + มูลค่าพอร์ตรวม
                 *
                 * ต้องคิดจากฝั่งเซิร์ฟเวอร์ที่เห็นราคาจริง ไม่ใช่ให้หน้าเว็บบวกเอง
                 * จากราคาทุน — แอพมือถือก็อ่านชุดเดียวกันนี้และต้องได้เลขเดียวกัน
                 */
                'unrealized_pnl' => round(collect($positions)->sum('unrealized_pnl'), 2),
                'positions_value' => round(collect($positions)->sum('market_value'), 2),
                // เงินสดของ "ทุกพอร์ตที่นับ" + ของที่ถือ — ชุดเดียวกับ balance/starting_balance ด้านบน
                'equity' => round($cash + collect($positions)->sum('market_value'), 2),
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
     * มุมมองตลาดล่าสุดที่ AI สรุปไว้ — ตัวที่บอทใช้ตัดสินใจจริง.
     *
     * ต่างจาก advice() ตรงที่อันนั้นเป็นคำแนะนำให้ "คน" อ่านเฉยๆ ส่วนอันนี้คือ
     * สิ่งที่มีผลต่อการเทรดจริง ผู้ใช้จึงควรเห็นได้ว่าตอนนี้บอทกำลังคิดอะไรอยู่
     * และเห็นเวลาที่ประเมินไว้ด้วย — มุมมองเก่าค้างอยู่คือสัญญาณว่ารอบวิเคราะห์ตาย
     *
     * ไม่ส่ง prompt กับคำตอบดิบออกไป: ยาวมาก และเป็นรายละเอียดภายในที่ผู้ใช้
     * ไม่ต้องเห็น (ยังเก็บครบในฐานข้อมูลสำหรับย้อนตรวจ)
     */
    public function marketView(): JsonResponse
    {
        if (! config('aibot_analyst.enabled', false)) {
            return response()->json(['success' => true, 'data' => [
                'enabled' => false,
                'view' => null,
                'reason' => 'ยังไม่ได้เปิดใช้การวิเคราะห์ตลาดด้วย AI',
            ]]);
        }

        $view = AiMarketView::latestFor(AiMarketView::SCOPE_TACTICAL)
            ?? AiMarketView::latestFor(AiMarketView::SCOPE_STRATEGIC);

        if (! $view) {
            /*
             * ไม่มีมุมมองที่ยังไม่หมดอายุ — บอกตรงๆ ว่ากำลังใช้กฎล้วน
             *
             * เงียบไว้แล้วผู้ใช้จะเข้าใจว่า AI ทำงานอยู่ตลอด ทั้งที่จริงอาจเป็น
             * ช่วงที่รอบวิเคราะห์ล่มหรือโควตาหมด — ต้องเห็นความต่างนี้
             */
            return response()->json(['success' => true, 'data' => [
                'enabled' => true,
                'view' => null,
                'reason' => 'ยังไม่มีมุมมองล่าสุด — บอทกำลังตัดสินใจจากกฎล้วน',
            ]]);
        }

        return response()->json(['success' => true, 'data' => [
            'enabled' => true,
            'shadow' => (bool) config('aibot_analyst.shadow_mode', false),
            'view' => [
                'scope' => $view->scope,
                'regime' => $view->regime,
                'confidence' => (float) $view->confidence,
                'size_multiplier' => (float) $view->size_multiplier,
                'summary' => $view->summary,
                'coins' => $view->coins ?? [],
                'shortlist' => $view->shortlistPairs(),
                'headlines' => array_slice($view->headlines ?? [], 0, 6),
                'model' => $view->model,
                'created_at' => $view->created_at?->toIso8601String(),
                'expires_at' => $view->expires_at?->toIso8601String(),
            ],
        ]]);
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

        // ยังไม่มีไม้ที่ปิดแล้วก็ไม่มีอะไรให้วิเคราะห์ — ตัดจบตรงนี้ก่อนถึง LLM
        //
        // ถามไปทั้งที่ไม่มีข้อมูลจะได้คำตอบกลวงๆ ที่อ่านดูน่าเชื่อถือ แล้วผู้ใช้อาจ
        // เอาไปตั้งค่าเงินจริงตาม ทั้งยังกินโควตาฟรีของวันนั้นทิ้งไปเปล่าๆ ด้วย
        if ((int) ($stats['overall']['closed'] ?? 0) === 0) {
            return response()->json(['success' => true, 'data' => [
                'ok' => false,
                'provider' => $advisor->name(),
                'text' => '',
                'reason' => 'ยังไม่มีไม้ที่ปิดแล้วให้วิเคราะห์ — เปิดบอทเดินสักพักก่อนแล้วค่อยกลับมาถาม',
            ]]);
        }

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
    public function resetDemo(Request $request, MarketDataService $market): JsonResponse
    {
        $result = $this->broker->reset($this->wallet($request));

        if (! ($result['ok'] ?? false)) {
            return $this->failure('RESET_LIMIT', $result['reason'] ?? 'ล้างพอร์ตไม่สำเร็จ');
        }

        return $this->demo($request, $market);
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

            /*
             * โหมดจริงเดินจากกระเป๋าบอทเท่านั้น — คนละใบกับกระเป๋าที่ผู้ใช้เทรดเอง
             *
             * ต้องมีกระเป๋าบอทและมีเงินของบอทอยู่ในนั้นก่อน ไม่งั้นบอทเปิดโหมดจริงได้
             * แต่ไม่มีทุนให้ลงมือ แล้วผู้ใช้จะเข้าใจว่า "บอทไม่ทำงาน" ทั้งที่แค่ยังไม่ได้โอน
             */
            $botWallet = app(BotWalletService::class);

            // ด่านนี้มีผลเฉพาะเมื่อเปิดฟีเจอร์กระเป๋าบอทแล้ว — ก่อนหน้านั้นโหมดจริงยังเป็น
            // "สัญญาณรอยืนยัน" แบบ non-custodial ตามเดิม ไม่มีกระเป๋าให้เรียกหา
            if ($botWallet->enabled()) {
                $funded = $botWallet->find($wallet);

                if (! $funded) {
                    return $this->failure('BOT_WALLET_REQUIRED', 'โหมดจริงใช้กระเป๋าบอทแยก — สร้างกระเป๋าบอทและโอนทุนเข้าก่อน');
                }

                if ($funded->balanceOf('USDT') <= 0) {
                    return $this->failure('BOT_WALLET_EMPTY', 'กระเป๋าบอทยังไม่มี USDT — โอนทุนของบอทเข้ากระเป๋าบอทก่อนเปิดโหมดจริง');
                }
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

    /**
     * ตรวจข้อมูลบอท.
     *
     * $current = บอทที่กำลังแก้อยู่ ถ้ามี — กลยุทธ์เดิมของมันผ่านได้เสมอแม้จะถูกปิด
     * ไปแล้ว ไม่งั้นผู้ใช้ที่มีบอทอาร์บิทราจค้างอยู่จะแก้ชื่อหรือลดความเสี่ยงก็ไม่ได้
     * ต้องลบทิ้งอย่างเดียว ซึ่งไม่ใช่สิ่งที่ควรบังคับ
     */
    private function validateBot(Request $request, ?AiBotConfig $current = null): array
    {
        $strategyCodes = $this->availability->availableCodes();

        if ($current && ! in_array($current->strategy, $strategyCodes, true)) {
            $strategyCodes[] = $current->strategy;
        }

        /*
         * กรอบเวลาต้องตรวจกับกลยุทธ์ที่เลือก ไม่ใช่รายการรวมของทั้งระบบ
         *
         * เดิมตรวจกับ config('aibot.timeframes') ซึ่งเป็นรายการรวม 6 ค่า — หน้าเว็บ
         * กรองให้เองอยู่แล้ว แต่แอพมือถือหรือคนที่ยิง API ตรงสร้าง dca บน 1m ได้
         * ซึ่ง engine ต้องย้อนดู 1,440 แท่งแต่ดึงมาแค่ 150 → บอทเงียบตลอดกาล
         * โดยไม่มีข้อความบอกว่าตั้งค่าผิด (กันฟีเจอร์ต้องกันที่ API ด้วย)
         */
        // aibot.strategies เป็น "รายการ" ไม่ใช่ map ที่คีย์ด้วย code — ต้องค้นเอง
        $spec = collect(config('aibot.strategies', []))
            ->firstWhere('code', $request->input('strategy'));

        $timeframes = $spec['timeframes'] ?? config('aibot.timeframes', []);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.config('aibot.limits.max_name_length', 60)],
            // คู่เทรดรูปแบบ BASE/QUOTE เท่านั้น — กันสตริงแปลกปลอมไหลไป engine
            'pair' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,15}\/[A-Za-z0-9]{2,15}$/'],
            'strategy' => ['required', 'string', 'in:'.implode(',', $strategyCodes)],
            'timeframe' => ['required', 'string', 'in:'.implode(',', $timeframes)],
            'params' => ['sometimes', 'array'],
            'risk' => ['sometimes', 'array'],
            // เทมเพลตของกลยุทธ์ (conservative/balanced/aggressive) — ค่าที่ส่งมาทับเทมเพลตได้
            'template' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            // ข้อความปริยาย ("The selected strategy is invalid") ทำให้ผู้ใช้คิดว่าตัวเอง
            // กรอกผิด ทั้งที่กลยุทธ์นั้นมีอยู่จริงแค่ยังเปิดใช้ไม่ได้
            'strategy.in' => $this->strategyRejectedMessage($request->input('strategy')),
            'timeframe.in' => 'กลยุทธ์นี้ใช้กรอบเวลาได้เฉพาะ '.implode(' · ', $timeframes),
        ]);

        return $validated;
    }

    /**
     * คู่นี้มีแท่งเทียนให้บอทใช้จริงไหม.
     *
     * ต้องถามก่อนสร้างบอท ไม่ใช่ปล่อยให้รู้ตอนบอทเดินแล้วเงียบ — คู่ที่ไม่มีบน
     * Binance (รวม TPIX/USDT ซึ่งเป็นคู่เรือธงของเว็บเอง) ดึงแท่งเทียนไม่ได้เลย
     * บอทจะขึ้นว่า "ยังดึงแท่งเทียนของคู่นี้ไม่ได้" วนทุก 5 นาทีตลอดอายุการเช่า
     * ซึ่งอ่านเหมือนปัญหาชั่วคราว ทั้งที่ถาวร — ผู้ใช้รอไปเรื่อยๆ โดยไม่มีอะไรบอก
     *
     * แคชผลไว้เพราะเป็นคุณสมบัติของคู่เทรด ไม่ใช่ของผู้ใช้ และการยิงตลาด
     * ทุกครั้งที่กดบันทึกฟอร์มเป็นการรอที่ไม่จำเป็น
     *
     * ปล่อยผ่านเมื่อตัดสินไม่ได้ (เน็ตล่ม/โดนจำกัดอัตรา) — ปฏิเสธการสร้างบอทเพราะ
     * เราถามตลาดไม่สำเร็จ เป็นการลงโทษผู้ใช้ด้วยปัญหาของเราเอง และแคชคำตอบผิดไว้
     * อีก 6 ชั่วโมงด้วย จึงแคชเฉพาะคำตอบที่ชัดเจนเท่านั้น
     */
    private function pairHasCandles(string $pair, string $timeframe): bool
    {
        $key = 'aibot:pair-candles:'.strtolower($pair).':'.$timeframe;
        $cached = Cache::get($key);

        if ($cached !== null) {
            return (bool) $cached;
        }

        $answer = app(MarketDataService::class)->hasKlines($pair, $timeframe);

        if ($answer === null) {
            return true;
        }

        Cache::put($key, $answer, now()->addHours(6));

        return $answer;
    }

    /** บอกเหตุผลจริงว่าทำไมกลยุทธ์นี้ถึงใช้ไม่ได้ */
    private function strategyRejectedMessage(mixed $code): string
    {
        if (! is_string($code)) {
            return 'ไม่พบกลยุทธ์ที่เลือก';
        }

        $status = $this->availability->check($code);

        return $status['available']
            ? 'ไม่พบกลยุทธ์ที่เลือก'
            : 'กลยุทธ์นี้ยังเปิดใช้งานไม่ได้ — '.$status['reason'];
    }

    private function findBot(string $wallet, int $id): ?AiBotConfig
    {
        return AiBotConfig::forWallet($wallet)->where('id', $id)->first();
    }

    /**
     * พอร์ตรวมรุ่นเดิม (bucket = null) ที่ไม่เคยขยับเลย — ที่เก็บโควตาการล้าง ไม่ใช่เงินทดลอง.
     *
     * ไม่มีบอทตัวไหนเทรดจากพอร์ตนี้แล้ว (PaperBroker::accountFor ผูกกับกลยุทธ์เสมอ)
     * นับเข้าไปเท่ากับเสกทุนตั้งต้นเพิ่มอีกก้อนที่ไม่มีอยู่จริง
     */
    private function isPlaceholderDemoAccount(AiBotDemoAccount $account): bool
    {
        return ($account->bucket === null || $account->bucket === '')
            && abs((float) $account->balance - (float) $account->starting_balance) < 0.000001;
    }

    /**
     * @param  AiBotSubscription|null|false  $subscription  การเช่าของกระเป๋านี้ ถ้าผู้เรียกมีอยู่แล้ว
     *                                                      (false = ยังไม่ได้ถาม ให้ถามเอง)
     */
    private function botList(string $wallet, AiBotSubscription|null|false $subscription = false): array
    {
        $plan = $this->planContext($wallet, $subscription);

        return AiBotConfig::forWallet($wallet)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AiBotConfig $bot) => $this->presentBot($bot, $plan))
            ->all();
    }

    /**
     * สิ่งที่แพลนปัจจุบันของกระเป๋านี้ให้ — คิดครั้งเดียวต่อคำขอ ไม่ใช่ทีละบอท.
     *
     *  - interval = รอบคิดที่แพลนสัญญาไว้ (นาที) ใช้ตัดสินว่าบอท "เงียบผิดปกติ" หรือยัง
     *  - unlocked = กลยุทธ์ที่แพลนปลดล็อก (null = ไม่มีการเช่าที่ยังไม่หมดอายุเหลือเลย)
     *
     * @param  AiBotSubscription|null|false  $subscription  false = ยังไม่ได้ถาม
     * @return array{interval: int, unlocked: list<string>|null}
     */
    private function planContext(string $wallet, AiBotSubscription|null|false $subscription = false): array
    {
        if ($subscription === false) {
            $subscription = $this->bots->activeSubscription($wallet);
        }

        $plan = $subscription?->plan;
        $tier = $plan?->tier ?? 'free';

        return [
            'interval' => max(1, (int) (config('aibot.tick_interval_minutes.'.$tier) ?? 5)),
            'unlocked' => $plan ? $plan->unlockedStrategies() : null,
        ];
    }

    /**
     * บอทที่ผู้ใช้เปิดไว้แต่แพลนปัจจุบันไม่ให้เดินแล้ว — รหัสเหตุผล หรือ null ถ้าเดินได้ตามปกติ.
     *
     * แยกจาก "ออฟไลน์เพราะวอร์กเกอร์เงียบ" ให้ชัด: อย่างแรกผู้ใช้ต้องต่ออายุแพลน
     * อย่างหลังระบบกู้คืนเอง — โชว์ข้อความเดียวกันแล้วผู้ใช้จะนั่งรอสิ่งที่ไม่มีวันเกิด
     */
    private function planBlock(AiBotConfig $bot, array $plan): ?string
    {
        if ($plan['unlocked'] === null) {
            return AiBotConfig::PAUSE_PLAN_EXPIRED;
        }

        if (! in_array($bot->strategy, $plan['unlocked'], true)) {
            return AiBotConfig::PAUSE_PLAN_LOCKED;
        }

        return $this->bots->isRetired($bot->strategy) ? AiBotConfig::PAUSE_STRATEGY_RETIRED : null;
    }

    /**
     * @param  array{interval: int, unlocked: list<string>|null}|null  $plan  จาก planContext() — null = คิดเอง
     */
    private function presentBot(AiBotConfig $bot, ?array $plan = null): array
    {
        $plan ??= $this->planContext($bot->wallet_address);
        $meta = $bot->strategyMeta();
        $liveness = $this->health->botStatus($bot, $plan['interval']);

        // เปิดไว้แต่แพลนไม่ให้เดิน = ออฟไลน์ด้วยเหตุผลของแพลน ไม่ใช่ "ระบบกำลังตรวจสอบ"
        if ($bot->status === 'running' && ! $bot->isBanned() && ($block = $this->planBlock($bot, $plan)) !== null) {
            $liveness['online'] = false;
            $liveness['reason'] = $block;
        }

        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'pair' => $bot->pair,
            'strategy' => $bot->strategy,
            'strategy_name' => $meta['name'] ?? $bot->strategy,
            'strategy_name_th' => $meta['name_th'] ?? ($meta['name'] ?? $bot->strategy),
            'risk_level' => $meta['risk'] ?? 'medium',
            'timeframe' => $bot->timeframe,
            /*
             * ค่าที่บอท "ใช้จริง" (ล้างค่าแบบเดียวกับ BotRunner) ไม่ใช่ค่าดิบในฐานข้อมูล
             *
             * รีวิว 2026-09-23: บอทเก่าเก็บ news_filter=false ไว้ ไม่มี news_mode — ฟอร์มแก้ไขบนเว็บ
             * เติมค่าปริยาย "ขายเมื่อราคายืนยัน" ให้ แล้วกดบันทึกก็ทับการปิดข่าวที่ผู้ใช้ตั้งไว้เงียบๆ
             * (แอปไฮไลต์ตัวเลือก "แนะนำ" ทั้งที่บอทรันแบบปิดข่าว) · กลยุทธ์ที่ไม่มีใน config แล้ว = ค่าดิบ
             */
            'params' => $this->bots->sanitizeParams($bot->strategy, $bot->params ?? []) ?: ($bot->params ?? []),
            'risk' => $bot->risk ?? [],
            'status' => $bot->status,
            /*
             * ต้องส่งออกไปด้วย ไม่ใช่ปล่อยให้บอทเงียบเฉยๆ
             *
             * ถ้าไม่บอก เจ้าของจะเห็นบอทสถานะ "หยุด" ที่กดเริ่มแล้วไม่ติดโดยไม่มีเหตุผล
             * แล้วเดาว่าระบบพัง — ทั้งที่ทีมงานตั้งใจปิดกั้นและมีเหตุผลบันทึกไว้แล้ว
             */
            'banned' => $bot->isBanned(),
            'banned_reason' => $bot->banned_reason,
            'mode' => $bot->mode,
            'stats' => $bot->stats ?? [],
            /*
             * "ออนไลน์" คนละเรื่องกับ status = running
             *
             * running คือสิ่งที่ผู้ใช้ตั้งไว้ ออนไลน์คือวอร์กเกอร์เดินให้จริงและบอทได้รอบคิด
             * ตามที่แพลนสัญญา — เซิร์ฟเวอร์ดับแล้วสถานะยัง running อยู่ แต่ต้องไม่โชว์เขียว
             */
            'online' => $liveness['online'],
            'offline_reason' => $liveness['reason'],
            /*
             * ระบบพักบอทไว้เพราะอะไร (plan_expired · plan_locked · plan_quota · strategy_retired)
             * null = ไม่ได้พัก หรือผู้ใช้กดพักเอง — หน้าจอใช้บอกว่าต้องทำอะไรต่อ
             */
            'pause_reason' => $bot->pauseReason(),
            'worker_last_beat_at' => $liveness['worker_last_beat_at'],
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
