<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotPosition;
use App\Models\AiBotTrade;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TPIX TRADE — ศูนย์เฝ้าดูบอทเทรดของทุกคน.
 *
 * เจ้าของสั่งว่าทีมงานต้องเห็น "การวางไม้ การทำงานของบอททุกตัว ทั้งบนคลาวด์และบนหน้าเว็บแบบฟรี"
 * พร้อมเห็นเงินทุน กำไร และสั่งหยุด/แบนได้
 *
 * ⚠️ ห้ามกรองด้วย `cloudExecuted()` เด็ดขาด — บอทของแพลนฟรีเดินจากเบราว์เซอร์
 *    ไม่ผ่าน scope นั้นเลย ถ้ากรอง หน้านี้จะมองไม่เห็นบอทกลุ่มที่คุมยากที่สุด
 *    (ผู้ใช้ปิดแท็บเมื่อไหร่ก็หยุด ไม่มีร่องรอยฝั่งเรา) ซึ่งเป็นกลุ่มที่ต้องเฝ้าที่สุด
 *
 * ทุกตัวเลขในหน้านี้อ่านอย่างเดียว ยกเว้นปุ่มหยุด/แบนที่บันทึกลง AuditLog ทุกครั้ง
 *
 * Developed by Xman Studio.
 */
class AiBotAdminController extends Controller
{
    /** จำนวนแถวในฟีดล่าสุด — มากกว่านี้หน้าจะหนักโดยไม่ได้ข้อมูลเพิ่มที่ใช้จริง */
    private const FEED_LIMIT = 40;

    public function index(Request $request): InertiaResponse
    {
        $mode = $request->string('mode')->toString() ?: 'demo';
        $mode = in_array($mode, ['demo', 'live'], true) ? $mode : 'demo';

        $bots = AiBotConfig::query()
            ->with(['subscription.plan'])
            ->orderByDesc('status')          // running ขึ้นก่อน
            ->orderByDesc('last_run_at')
            ->get();

        $decisions = AiBotDecision::query()
            ->where('mode', $mode)
            ->where('created_at', '>=', now()->subDay())
            ->get();

        $trades = AiBotTrade::query()
            ->where('mode', $mode)
            ->get();

        $positions = AiBotPosition::query()->where('mode', $mode)->get();
        $accounts = AiBotDemoAccount::query()->get();
        $prices = $this->latestPrices($mode);

        return Inertia::render('Admin/AiBots/Index', [
            'mode' => $mode,
            'summary' => $this->summary($bots, $decisions, $trades, $positions, $accounts, $prices),
            'hourly' => $this->hourly($decisions),
            'strategies' => $this->strategies($bots, $decisions, $trades, $positions, $accounts, $prices),
            'bots' => $this->presentBots($bots, $decisions, $positions, $prices),
            'trades' => $this->presentTrades(),
            'decisions' => $this->presentDecisions($mode),
        ]);
    }

    // =========================================================================
    // การสั่งการ
    // =========================================================================

    /** หยุดชั่วคราว — เจ้าของกดเริ่มใหม่เองได้ ใช้กับบอทที่แค่ตั้งค่าเพี้ยน */
    public function pause(AiBotConfig $bot): RedirectResponse
    {
        $before = $bot->status;
        $bot->update(['status' => 'paused']);

        AuditLog::log('aibot.pause', null, ['status' => $before], [
            'bot' => $bot->id, 'wallet' => $bot->wallet_address, 'status' => 'paused',
        ]);

        return back()->with('success', "หยุดบอท #{$bot->id} แล้ว");
    }

    /**
     * ให้เดินต่อ.
     *
     * บอทที่ถูกแบนอยู่ต้องปลดแบนก่อน — ไม่งั้นปุ่มนี้จะกลายเป็นทางลัดที่ข้ามการแบนได้เอง
     */
    public function resume(AiBotConfig $bot): RedirectResponse
    {
        if ($bot->isBanned()) {
            return back()->with('error', 'บอทตัวนี้ถูกระงับอยู่ — ต้องปลดระงับก่อนถึงจะให้เดินต่อได้');
        }

        $bot->update(['status' => 'running']);
        AuditLog::log('aibot.resume', null, null, ['bot' => $bot->id, 'wallet' => $bot->wallet_address]);

        return back()->with('success', "ให้บอท #{$bot->id} เดินต่อแล้ว");
    }

    /**
     * ระงับถาวรจนกว่าทีมงานจะปลด — เจ้าของกดเริ่มเองไม่ได้.
     *
     * หยุดด้วย status อย่างเดียวไม่พอ เพราะเจ้าของกดเริ่มใหม่ได้ทันที
     * จึงต้องตั้ง banned_at ควบคู่กัน (ด่านอยู่ทั้งฝั่งคลาวด์และฝั่งเบราว์เซอร์)
     */
    public function ban(Request $request, AiBotConfig $bot): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'ต้องระบุเหตุผลที่ระงับ — ผู้ใช้ต้องรู้ว่าถูกระงับเพราะอะไร',
        ]);

        $bot->update([
            'status' => 'stopped',
            'banned_at' => now(),
            'banned_reason' => $validated['reason'],
            'banned_by' => Auth::guard('admin')->user()?->email,
        ]);

        AuditLog::log('aibot.ban', null, null, [
            'bot' => $bot->id, 'wallet' => $bot->wallet_address, 'reason' => $validated['reason'],
        ]);

        return back()->with('success', "ระงับบอท #{$bot->id} แล้ว");
    }

    /** ปลดระงับ — คืนเป็นสถานะหยุด ไม่ใช่เดินทันที ให้เจ้าของเป็นคนตัดสินใจเริ่มเอง */
    public function unban(AiBotConfig $bot): RedirectResponse
    {
        $bot->update([
            'banned_at' => null,
            'banned_reason' => null,
            'banned_by' => null,
            'status' => 'paused',
        ]);

        AuditLog::log('aibot.unban', null, null, ['bot' => $bot->id, 'wallet' => $bot->wallet_address]);

        return back()->with('success', "ปลดระงับบอท #{$bot->id} แล้ว (อยู่ในสถานะหยุด รอเจ้าของเริ่มเอง)");
    }

    // =========================================================================
    // ตัวประกอบข้อมูล
    // =========================================================================

    /**
     * ราคาล่าสุดของแต่ละคู่ จากบันทึกการตัดสินใจของบอทเอง.
     *
     * ไม่ยิงตลาดจากหน้าแอดมิน เพราะบอททุกตัวบันทึกราคาที่ใช้ตัดสินใจไว้ทุกนาทีอยู่แล้ว
     * ค่าที่ได้จึงสดพอ (≤ 1 นาที) โดยไม่ต้องรอ HTTP ต่อคู่ และหน้าไม่พังเวลาตลาดล่ม
     */
    private function latestPrices(string $mode): array
    {
        $rows = AiBotDecision::query()
            ->select('pair', DB::raw('MAX(id) as max_id'))
            ->where('mode', $mode)
            ->whereNotNull('price')
            ->groupBy('pair')
            ->pluck('max_id');

        return AiBotDecision::whereIn('id', $rows)
            ->get()
            ->mapWithKeys(fn (AiBotDecision $d) => [$d->pair => (float) $d->price])
            ->all();
    }

    /** มูลค่าของที่ถืออยู่ + กำไรลอย ณ ราคาล่าสุด */
    private function positionValue(AiBotPosition $position, array $prices): array
    {
        $price = $prices[$position->pair] ?? (float) $position->entry_price;
        $value = (float) $position->quantity * $price;

        return [
            'price' => $price,
            'value' => $value,
            'pnl' => $value - (float) $position->cost_basis,
        ];
    }

    private function summary(
        Collection $bots,
        Collection $decisions,
        Collection $trades,
        Collection $positions,
        Collection $accounts,
        array $prices,
    ): array {
        $closed = $trades->whereNotNull('realized_pnl');
        $wins = $closed->where('realized_pnl', '>', 0)->count();

        $openValue = $positions->sum(fn (AiBotPosition $p) => $this->positionValue($p, $prices)['value']);
        $openPnl = $positions->sum(fn (AiBotPosition $p) => $this->positionValue($p, $prices)['pnl']);

        /*
         * นับเฉพาะพอร์ตที่ผูกกับกลยุทธ์ — ข้ามพอร์ตรวมของเดิม (bucket = null)
         *
         * เจอตอนดูของจริงบน production: มีพอร์ตเก่าค้างอยู่หนึ่งใบที่ไม่มีบอทตัวไหนใช้
         * (`PaperBroker::accountFor()` ผูก bucket กับกลยุทธ์เสมอแล้ว) เงินก้อนนั้น
         * ทำให้ยอด "เงินทุน" ด้านบนบวมขึ้นทั้งก้อน และไม่เท่ากับผลรวมของการ์ดกลยุทธ์
         * ข้างล่างที่อ่านทีละ bucket — แอดมินที่บวกเลขตามจะเจอตัวเลขไม่ตรงกันโดยไม่มีคำอธิบาย
         */
        $tracked = $accounts->filter(fn (AiBotDemoAccount $a) => filled($a->bucket));

        $cash = (float) $tracked->sum('balance');
        $capital = (float) $tracked->sum('starting_balance');

        return [
            'bots_total' => $bots->count(),
            'bots_running' => $bots->where('status', 'running')->whereNull('banned_at')->count(),
            'bots_paused' => $bots->whereIn('status', ['paused', 'draft'])->whereNull('banned_at')->count(),
            'bots_banned' => $bots->whereNotNull('banned_at')->count(),
            'cloud' => $bots->filter(fn ($b) => $this->executionOf($b) === 'cloud')->count(),
            'browser' => $bots->filter(fn ($b) => $this->executionOf($b) === 'browser')->count(),
            'wallets' => $bots->pluck('wallet_address')->unique()->count(),

            'ticks_24h' => $decisions->count(),
            'buys_24h' => $decisions->where('action', 'buy')->count(),
            'sells_24h' => $decisions->where('action', 'sell')->count(),
            'holds_24h' => $decisions->where('action', 'hold')->count(),
            'errors_24h' => $decisions->whereIn('action', ['error', 'stopped'])->count(),

            // เงินทุน: เงินสด + มูลค่าของที่ถืออยู่ = เงินที่มีจริงตอนนี้
            'capital' => round($capital, 2),
            'cash' => round($cash, 2),
            'open_value' => round($openValue, 2),
            'equity' => round($cash + $openValue, 2),
            'net_pnl' => round($cash + $openValue - $capital, 2),
            'net_pnl_pct' => $capital > 0 ? round((($cash + $openValue - $capital) / $capital) * 100, 2) : 0.0,

            'realized_pnl' => round((float) $closed->sum('realized_pnl'), 2),
            'unrealized_pnl' => round($openPnl, 2),
            'fees' => round((float) $trades->sum('fee'), 4),
            'closed_trades' => $closed->count(),
            'open_positions' => $positions->count(),
            'win_rate' => $closed->count() > 0 ? round($wins / $closed->count() * 100, 1) : null,
        ];
    }

    /**
     * รอบคิดรายชั่วโมงย้อนหลัง 24 ชม.
     *
     * เติมชั่วโมงที่ไม่มีข้อมูลเป็นศูนย์ด้วย — ถ้าปล่อยให้หายไป กราฟจะบีบเข้าหากัน
     * แล้วช่วงที่บอท "เงียบผิดปกติ" จะมองไม่ออกเลย ทั้งที่เป็นสิ่งที่ต้องเห็นที่สุด
     */
    private function hourly(Collection $decisions): array
    {
        $byHour = $decisions->groupBy(fn (AiBotDecision $d) => $d->created_at->format('Y-m-d H'));
        $out = [];

        for ($i = 23; $i >= 0; $i--) {
            $at = now()->subHours($i);
            $rows = $byHour->get($at->format('Y-m-d H'), collect());

            $out[] = [
                'label' => $at->format('H:00'),
                'buy' => $rows->where('action', 'buy')->count(),
                'sell' => $rows->where('action', 'sell')->count(),
                'hold' => $rows->where('action', 'hold')->count(),
                'error' => $rows->whereIn('action', ['error', 'stopped'])->count(),
                'total' => $rows->count(),
            ];
        }

        return $out;
    }

    private function strategies(
        Collection $bots,
        Collection $decisions,
        Collection $trades,
        Collection $positions,
        Collection $accounts,
        array $prices,
    ): array {
        $catalog = collect(config('aibot.strategies', []))->keyBy('code');
        $botsByStrategy = $bots->groupBy('strategy');
        $accountsByBucket = $accounts->keyBy('bucket');

        return $catalog->map(function (array $spec, string $code) use (
            $botsByStrategy,
            $decisions,
            $trades,
            $positions,
            $accountsByBucket,
            $prices
        ) {
            $d = $decisions->where('strategy', $code);
            $t = $trades->where('strategy', $code);
            $closed = $t->whereNotNull('realized_pnl');
            $wins = $closed->where('realized_pnl', '>', 0)->count();

            $stratBots = $botsByStrategy->get($code, collect());
            $botIds = $stratBots->pluck('id')->all();
            $pos = $positions->whereIn('ai_bot_config_id', $botIds);

            $openValue = $pos->sum(fn (AiBotPosition $p) => $this->positionValue($p, $prices)['value']);
            $account = $accountsByBucket->get($code);
            $cash = $account ? (float) $account->balance : 0.0;
            $capital = $account ? (float) $account->starting_balance : 0.0;
            $equity = $cash + $openValue;

            return [
                'code' => $code,
                'name_th' => $spec['name_th'] ?? $code,
                'risk' => $spec['risk'] ?? 'medium',
                'bots' => $stratBots->count(),
                'running' => $stratBots->where('status', 'running')->whereNull('banned_at')->count(),
                'ticks' => $d->count(),
                'buy' => $d->where('action', 'buy')->count(),
                'sell' => $d->where('action', 'sell')->count(),
                'hold' => $d->where('action', 'hold')->count(),
                'closed' => $closed->count(),
                'win_rate' => $closed->count() > 0 ? round($wins / $closed->count() * 100, 1) : null,
                'realized_pnl' => round((float) $closed->sum('realized_pnl'), 2),
                'fees' => round((float) $t->sum('fee'), 4),
                'capital' => round($capital, 2),
                'cash' => round($cash, 2),
                'open_value' => round($openValue, 2),
                'equity' => round($equity, 2),
                'pnl_pct' => $capital > 0 ? round((($equity - $capital) / $capital) * 100, 2) : 0.0,
                'has_account' => $account !== null,
                // เหตุผลที่ถือบ่อยสุด = จุดที่ควรไปแก้ก่อนเพื่อน ถ้ากลยุทธ์เงียบเกินไป
                'top_hold_reason' => $d->where('action', 'hold')
                    ->groupBy('reason')
                    ->sortByDesc(fn ($g) => $g->count())
                    ->keys()
                    ->first(),
                'curve' => $this->equityCurve($code),
            ];
        })->values()->all();
    }

    /**
     * เส้นกำไรสะสมของกลยุทธ์ จากไม้ที่ปิดแล้วเรียงตามเวลา.
     *
     * ใช้เฉพาะไม้ที่ปิดแล้ว เพราะกำไรลอยของไม้ที่ยังเปิดอยู่เปลี่ยนทุกวินาที
     * เอามาต่อเป็นเส้นจะได้กราฟที่ขยับเองตลอดโดยไม่มีอะไรเกิดขึ้นจริง
     */
    private function equityCurve(string $strategy): array
    {
        $rows = AiBotTrade::where('strategy', $strategy)
            ->whereNotNull('realized_pnl')
            ->orderBy('id')
            ->limit(120)
            ->pluck('realized_pnl');

        $sum = 0.0;
        $curve = [0.0];

        foreach ($rows as $pnl) {
            $sum += (float) $pnl;
            $curve[] = round($sum, 4);
        }

        return count($curve) > 1 ? $curve : [];
    }

    /** คลาวด์หรือเบราว์เซอร์ — อ่านจากแพลนที่ผูกกับกระเป๋า ณ ตอนนี้ */
    private function executionOf(AiBotConfig $bot): string
    {
        return $bot->subscription?->plan?->execution ?? 'browser';
    }

    private function presentBots(Collection $bots, Collection $decisions, Collection $positions, array $prices): array
    {
        $lastDecision = $decisions->sortByDesc('id')->groupBy('ai_bot_config_id');
        $positionsByBot = $positions->keyBy('ai_bot_config_id');

        return $bots->map(function (AiBotConfig $bot) use ($lastDecision, $positionsByBot, $prices) {
            $position = $positionsByBot->get($bot->id);
            $valued = $position ? $this->positionValue($position, $prices) : null;

            return [
                'id' => $bot->id,
                'name' => $bot->name,
                'wallet' => $bot->wallet_address,
                'strategy' => $bot->strategy,
                'pair' => $bot->pair,
                'timeframe' => $bot->timeframe,
                'mode' => $bot->mode,
                'status' => $bot->status,
                'banned' => $bot->banned_at !== null,
                'banned_reason' => $bot->banned_reason,
                'banned_by' => $bot->banned_by,
                'plan' => $bot->subscription?->plan?->code,
                'plan_name_th' => $bot->subscription?->plan?->name_th,
                'execution' => $this->executionOf($bot),
                'last_run_at' => $bot->last_run_at?->toIso8601String(),
                /*
                 * วินาทีที่เงียบไป — ให้หน้าเว็บตัดสินเองว่าควรขึ้นสีอะไร
                 *
                 * ส่งเป็นตัวเลขไม่ใช่ข้อความ เพราะเกณฑ์ "เงียบผิดปกติ" ต่างกันตาม
                 * ประเภทการเดิน: คลาวด์ควรเดินทุกนาที ส่วนเบราว์เซอร์เดินเฉพาะ
                 * ตอนผู้ใช้เปิดหน้าไว้ — เอาเกณฑ์เดียวไปตัดสินทั้งสองแบบจะอ่านผิด
                 */
                'silent_seconds' => $bot->last_run_at ? $bot->last_run_at->diffInSeconds(now()) : null,
                'last_reason' => $bot->last_reason,
                'max_position_usd' => (float) (($bot->risk ?? [])['max_position_usd'] ?? 0),
                'ticks_24h' => $lastDecision->get($bot->id, collect())->count(),
                'position' => $valued ? [
                    'quantity' => (float) $position->quantity,
                    'entry' => (float) $position->entry_price,
                    'price' => round($valued['price'], 8),
                    'value' => round($valued['value'], 2),
                    'pnl' => round($valued['pnl'], 2),
                    'pnl_pct' => (float) $position->cost_basis > 0
                        ? round($valued['pnl'] / (float) $position->cost_basis * 100, 2)
                        : 0.0,
                ] : null,
                'created_at' => $bot->created_at->toIso8601String(),
            ];
        })->all();
    }

    /** ไม้ที่วางล่าสุด — หัวใจของคำสั่ง "แอดมินควรเห็นการวางไม้" */
    private function presentTrades(): array
    {
        return AiBotTrade::query()
            ->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (AiBotTrade $t) => [
                'id' => $t->id,
                'bot_id' => $t->ai_bot_config_id,
                'wallet' => $t->wallet_address,
                'strategy' => $t->strategy,
                'pair' => $t->pair,
                'mode' => $t->mode,
                'side' => $t->side,
                'price' => (float) $t->price,
                'quantity' => (float) $t->quantity,
                'value' => round((float) $t->quantity * (float) $t->price, 2),
                'fee' => round((float) $t->fee, 4),
                'realized_pnl' => $t->realized_pnl === null ? null : round((float) $t->realized_pnl, 2),
                'risk_level' => $t->risk_level,
                'reason' => $t->reason,
                'at' => $t->created_at->toIso8601String(),
            ])->all();
    }

    /** ทุกครั้งที่บอทคิด ไม่ใช่เฉพาะตอนลงมือ — "ทำไมถึงไม่ทำอะไร" คือข้อมูลที่ใช้ปรับปรุงได้จริง */
    private function presentDecisions(string $mode): array
    {
        return AiBotDecision::query()
            ->where('mode', $mode)
            ->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (AiBotDecision $d) => [
                'id' => $d->id,
                'bot_id' => $d->ai_bot_config_id,
                'wallet' => $d->wallet_address,
                'strategy' => $d->strategy,
                'pair' => $d->pair,
                'timeframe' => $d->timeframe,
                'action' => $d->action,
                'reason' => $d->reason,
                'risk_level' => $d->risk_level,
                'price' => $d->price === null ? null : (float) $d->price,
                'budget' => $d->budget === null ? null : (float) $d->budget,
                'has_position' => (bool) $d->has_position,
                'at' => $d->created_at->toIso8601String(),
            ])->all();
    }
}
