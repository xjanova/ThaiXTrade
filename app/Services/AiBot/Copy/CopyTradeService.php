<?php

namespace App\Services\AiBot\Copy;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotPosition;
use App\Models\AiBotTrade;
use App\Models\AiCopyFee;
use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Services\AiBot\Live\LiveBroker;
use App\Services\AiBotService;
use App\Services\TpixDexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — Master / Copy Trade: ใครเป็นมาสเตอร์ได้ ใครตามใคร และค่าบริการเท่าไหร่.
 *
 * เจ้าของสั่ง 2026-11-13: ผู้ใช้ที่ทำกำไรได้มากถูกชวนให้เปิดตัวเป็นมาสเตอร์ ตั้งอัตราค่าก๊อปปี้เอง
 * แบ่งกับแพลตฟอร์ม ผู้ตามเลือกมาสเตอร์จากผลงานแล้วก๊อปปี้ตามอัตราที่มาสเตอร์ตั้ง
 * และ "ใช้ผลเงินจริงเหรียญจริง ได้เสียจริง" · ค่าบริการเป็น TPIX · มาสเตอร์ต้องผ่าน KYC
 *
 * กติกาที่ยึดทั้งไฟล์:
 *  - ผลงาน = ไม้เงินจริงที่ปิดแล้วของบอทหนึ่งตัว (TrackRecord) — โหมดทดลองไม่ถูกนับและไม่ถูกโชว์
 *  - ผู้ตามเห็นผลงาน ไม่เห็นกฎ: ไม่มีเมธอดไหนในนี้ส่ง params/กลยุทธ์ของบอทมาสเตอร์ออกไป
 *  - ค่าบริการคิดจากกำไรที่ปิดแล้วของผู้ตามแบบ high-water mark — ผู้ตามขาดทุนไม่เสียอะไร
 *    และกำไรที่แค่ชดเชยขาดทุนเก่าไม่ถูกคิดซ้ำ
 *  - ไฟล์นี้ "ลงบัญชี" ค่าบริการเท่านั้น ไม่มีการเก็บหรือจ่าย TPIX จริง (ยังไม่มีรางนั้น) —
 *    รายการเป็น recorded จนกว่าจะเปิด aibot.copy_trade.fees.collect แล้วจึงเป็น accrued (ค้างเก็บ)
 *  - การ "นำเสนอมากขึ้น" จากส่วนแบ่งแพลตฟอร์มมีผลกับแถบ "แนะนำ" ที่ติดป้ายเท่านั้น
 *    ลำดับของกระดานหลักมาจากผลงานล้วน จ่ายเพิ่มแล้วดันอันดับไม่ได้
 *
 * Developed by Xman Studio.
 */
class CopyTradeService
{
    public const ERR_DISABLED = 'COPY_TRADE_DISABLED';

    public const ERR_NEEDS_LIVE = 'COPY_NEEDS_LIVE_TRADING';

    public const ERR_NOT_ELIGIBLE = 'COPY_NOT_ELIGIBLE';

    public const ERR_KYC_REQUIRED = 'COPY_MASTER_KYC_REQUIRED';

    public const ERR_ALREADY_MASTER = 'COPY_ALREADY_MASTER';

    public const ERR_MASTER_NOT_FOUND = 'COPY_MASTER_NOT_FOUND';

    public const ERR_MASTER_CLOSED = 'COPY_MASTER_NOT_ACCEPTING';

    public const ERR_SELF = 'COPY_CANNOT_FOLLOW_SELF';

    public const ERR_ALREADY_FOLLOWING = 'COPY_ALREADY_FOLLOWING';

    public const ERR_PAIR_UNSUPPORTED = 'COPY_PAIR_UNSUPPORTED';

    public const ERR_FOLLOW_NOT_FOUND = 'COPY_FOLLOW_NOT_FOUND';

    public const STRATEGY = 'copy';

    public function __construct(
        private readonly AiBotService $bots,
        private readonly LiveBroker $live,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('aibot.copy_trade.enabled', false);
    }

    // =========================================================================
    // เกณฑ์มาสเตอร์
    // =========================================================================

    /**
     * บอทตัวนี้ใช้เป็นผลงานของมาสเตอร์ได้ไหม — พร้อมรายการเกณฑ์ที่ผ่าน/ไม่ผ่าน (ไม่รวม KYC ซึ่งผูกกับคน ไม่ใช่บอท).
     *
     * เกณฑ์ทุกข้อคิดจากไม้เงินจริงที่ปิดแล้ว: โหมดทดลองสร้างผลสวยได้ฟรี เกณฑ์ที่อ่านโหมดทดลองจึงโกงได้เสมอ
     *
     * @return array{eligible: bool, record: array, checks: list<array{code: string, ok: bool, need: float|int|null, have: float|int|null}>}
     */
    public function eligibility(AiBotConfig $bot): array
    {
        $rule = (array) config('aibot.copy_trade.master', []);
        $record = TrackRecord::forBot($bot);

        $checks = [
            // บอทที่ก๊อปปี้คนอื่นเอาผลของคนอื่นมาขายต่อไม่ได้
            ['code' => 'own_strategy', 'ok' => $bot->strategy !== self::STRATEGY, 'need' => null, 'have' => null],
            // คู่ที่สภาพคล่องลึกพอ — มาสเตอร์ที่เทรดคู่บางจะถูกไม้ของผู้ตามตัวเองดันราคา
            ['code' => 'liquid_pair', 'ok' => $this->live->supports((string) $bot->pair), 'need' => null, 'have' => null],
            ['code' => 'min_days', 'ok' => $record['days'] >= (float) ($rule['min_days'] ?? 30), 'need' => (float) ($rule['min_days'] ?? 30), 'have' => $record['days']],
            ['code' => 'min_closed_trades', 'ok' => $record['closed'] >= (int) ($rule['min_closed_trades'] ?? 20), 'need' => (int) ($rule['min_closed_trades'] ?? 20), 'have' => $record['closed']],
            ['code' => 'min_return_pct', 'ok' => $record['return_pct'] >= (float) ($rule['min_return_pct'] ?? 10), 'need' => (float) ($rule['min_return_pct'] ?? 10), 'have' => $record['return_pct']],
            ['code' => 'max_drawdown_pct', 'ok' => $record['max_drawdown_pct'] <= (float) ($rule['max_drawdown_pct'] ?? 25), 'need' => (float) ($rule['max_drawdown_pct'] ?? 25), 'have' => $record['max_drawdown_pct']],
        ];

        return [
            'eligible' => collect($checks)->every(fn (array $check) => $check['ok']),
            'record' => $record,
            'checks' => $checks,
        ];
    }

    /**
     * บอทของกระเป๋านี้ที่ผ่านเกณฑ์มาสเตอร์ — ใช้ชวนเจ้าของให้เปิดตัวเป็นมาสเตอร์.
     *
     * @return list<array{bot_id: int, name: string, pair: string, eligible: bool, record: array, checks: array}>
     */
    public function candidates(string $wallet): array
    {
        return AiBotConfig::forWallet($wallet)
            ->where('strategy', '!=', self::STRATEGY)
            ->orderByDesc('id')
            ->limit((int) config('aibot.limits.max_bots_hard_cap', 25))
            ->get()
            ->map(function (AiBotConfig $bot) {
                $result = $this->eligibility($bot);

                return ['bot_id' => $bot->id, 'name' => $bot->name, 'pair' => $bot->pair] + $result;
            })
            // บอทที่ยังไม่มีไม้เงินจริงเลยไม่มีอะไรให้ดู — ไม่ต้องโชว์เป็นรายการที่ "ยังไม่ผ่าน"
            ->filter(fn (array $row) => $row['record']['closed'] > 0 || $row['eligible'])
            ->values()
            ->all();
    }

    // =========================================================================
    // มาสเตอร์
    // =========================================================================

    public function masterOf(string $wallet): ?AiCopyMaster
    {
        return AiCopyMaster::where('wallet_address', $wallet)->first();
    }

    /**
     * เปิดตัวเป็นมาสเตอร์ด้วยบอทตัวนี้.
     *
     * @param  array{display_name: string, bio?: string|null, fee_rate_pct: float, platform_share_pct: float}  $profile  ผ่านการตรวจช่วงแล้ว
     * @param  bool  $kycApproved  ผู้เรียก (controller) ถาม KycGate ให้แล้ว — บริการนี้ไม่รู้จักคำขอ HTTP
     */
    public function register(string $wallet, AiBotConfig $bot, array $profile, bool $kycApproved): AiCopyMaster
    {
        $this->assertEnabled();

        if ((bool) config('aibot.copy_trade.master.requires_kyc', true) && ! $kycApproved) {
            throw new RuntimeException(self::ERR_KYC_REQUIRED);
        }

        if ($bot->wallet_address !== $wallet || ! $this->eligibility($bot)['eligible']) {
            throw new RuntimeException(self::ERR_NOT_ELIGIBLE);
        }

        return DB::transaction(function () use ($wallet, $bot, $profile) {
            $existing = AiCopyMaster::where('wallet_address', $wallet)->lockForUpdate()->first();

            if ($existing && $existing->isOpen()) {
                throw new RuntimeException(self::ERR_ALREADY_MASTER);
            }

            $attributes = [
                'ai_bot_config_id' => $bot->id,
                'display_name' => $profile['display_name'],
                'bio' => $profile['bio'] ?? null,
                'fee_rate_pct' => $profile['fee_rate_pct'],
                'platform_share_pct' => $profile['platform_share_pct'],
                'status' => AiCopyMaster::STATUS_ACTIVE,
                'registered_at' => now(),
                'closed_at' => null,
            ];

            // เคยเป็นมาสเตอร์แล้วปิดไป = เปิดใหม่บนแถวเดิม (ประวัติค่าบริการของการเปิดรอบก่อนยังอยู่ครบ)
            $master = $existing ? tap($existing)->update($attributes) : AiCopyMaster::create(['wallet_address' => $wallet] + $attributes);

            return $this->refreshStats($master);
        });
    }

    /**
     * แก้โปรไฟล์/อัตรา — อัตราใหม่มีผลกับผู้ตามใหม่เท่านั้น ผู้ตามเดิมอยู่ที่อัตราที่ตัวเองยอมรับไว้.
     *
     * @param  array{display_name?: string, bio?: string|null, fee_rate_pct?: float, platform_share_pct?: float, status?: string}  $changes
     */
    public function updateMaster(AiCopyMaster $master, array $changes): AiCopyMaster
    {
        $this->assertEnabled();

        $allowed = array_intersect_key($changes, array_flip(['display_name', 'bio', 'fee_rate_pct', 'platform_share_pct']));

        // พักรับ/กลับมารับผู้ตามใหม่ — การปิดโปรไฟล์ใช้ closeMaster (ต้องปลดผู้ตามทุกคนด้วย)
        if (in_array($changes['status'] ?? null, [AiCopyMaster::STATUS_ACTIVE, AiCopyMaster::STATUS_PAUSED], true) && $master->isOpen()) {
            $allowed['status'] = $changes['status'];
        }

        $master->update($allowed);

        return $master->fresh();
    }

    /** ปิดโปรไฟล์ — ผู้ตามทุกคนถูกปลด (ไม้ที่ถืออยู่ยังเป็นของผู้ตามและยังถูก stop ของผู้ตามคุมอยู่) */
    public function closeMaster(AiCopyMaster $master): AiCopyMaster
    {
        DB::transaction(function () use ($master) {
            $master->follows()->active()->get()->each(fn (AiCopyFollow $follow) => $this->stopFollow($follow));
            $master->update(['status' => AiCopyMaster::STATUS_CLOSED, 'closed_at' => now()]);
        });

        return $master->fresh();
    }

    /** คิดผลงานล่าสุดของมาสเตอร์แล้วเก็บไว้กับแถว (กระดานอันดับอ่านจากที่เก็บ ไม่คิดสดทุกคำขอ) */
    public function refreshStats(AiCopyMaster $master): AiCopyMaster
    {
        $bot = $master->bot;
        $eligibility = $bot ? $this->eligibility($bot) : null;

        $master->update([
            'stats' => [
                'record' => $eligibility['record'] ?? null,
                // ยังผ่านเกณฑ์มาสเตอร์อยู่ไหม — หลุดเกณฑ์แล้วยังอยู่บนกระดานด้วยตัวเลขจริง แต่ไม่ถูก "แนะนำ"
                'meets_floor' => (bool) ($eligibility['eligible'] ?? false),
                'pair' => $bot?->pair,
                'timeframe' => $bot?->timeframe,
                // นับเฉพาะผู้ตามที่ก๊อปปี้จนปิดไม้เงินจริงไปแล้วอย่างน้อยหนึ่งไม้ — เปิดกระเป๋าเปล่ามากดตามปั๊มยอดไม่ได้
                'followers' => $master->follows()->active()->where('last_settled_trade_id', '>', 0)->count(),
            ],
            'stats_at' => now(),
        ]);

        return $master->fresh();
    }

    /**
     * กระดานมาสเตอร์ + แถบแนะนำ.
     *
     * @return array{masters: list<AiCopyMaster>, promoted: list<int>}
     */
    public function leaderboard(): array
    {
        $staleBefore = now()->subMinutes(max(1, (int) config('aibot.copy_trade.stats_minutes', 15)));

        $masters = AiCopyMaster::open()->with('bot')->get()
            ->map(fn (AiCopyMaster $master) => ! $master->stats_at || $master->stats_at->lt($staleBefore) ? $this->refreshStats($master) : $master)
            // ลำดับหลัก = ผลตอบแทนจากไม้ที่ปิดแล้ว แล้วจึงจำนวนไม้ (ตัวอย่างมากกว่าน่าเชื่อกว่า) — ไม่มีส่วนแบ่งแพลตฟอร์มในสูตรนี้
            ->sortByDesc(fn (AiCopyMaster $master) => [
                (float) ($master->stats['record']['return_pct'] ?? -INF),
                (int) ($master->stats['record']['closed'] ?? 0),
            ])
            ->values();

        $promoted = $masters
            ->filter(fn (AiCopyMaster $master) => $master->status === AiCopyMaster::STATUS_ACTIVE && ($master->stats['meets_floor'] ?? false))
            ->sortByDesc(fn (AiCopyMaster $master) => [$master->platform_share_pct, (float) ($master->stats['record']['return_pct'] ?? 0)])
            // ส่วนแบ่งขั้นต่ำไม่ได้ซื้ออะไรเพิ่ม — ขึ้นแถบแนะนำได้เฉพาะคนที่ให้แพลตฟอร์มมากกว่าขั้นต่ำ
            ->filter(fn (AiCopyMaster $master) => $master->platform_share_pct > (float) config('aibot.copy_trade.fees.platform_share_pct.min', 20))
            ->take(max(0, (int) config('aibot.copy_trade.promotion.slots', 3)))
            ->pluck('id')
            ->all();

        return ['masters' => $masters->all(), 'promoted' => $promoted];
    }

    // =========================================================================
    // ผู้ตาม
    // =========================================================================

    /**
     * เริ่มก๊อปปี้มาสเตอร์ — สร้างบอทผู้ตาม (โหมดเงินจริง) แล้วผูกกับมาสเตอร์ด้วยอัตราปัจจุบัน.
     *
     * ก๊อปปี้ได้เฉพาะเงินจริง: ถ้าให้ตามด้วยโหมดทดลอง ผู้ใช้จะเห็นไม้ของมาสเตอร์สดๆ ฟรี
     * (ไม่มีกำไรจริง = ไม่มีค่าบริการ) แล้วไปลงมือเอง — เท่ากับเอาสัญญาณของมาสเตอร์ไปแจก
     *
     * @param  array  $riskInput  กรอบความเสี่ยงของผู้ตามเอง (ทุนสูงสุด · stop loss · take profit · ขาดทุนต่อวัน)
     */
    public function follow(string $wallet, AiCopyMaster $master, array $riskInput): AiCopyFollow
    {
        $this->assertEnabled();

        if (! $this->live->operational()) {
            throw new RuntimeException(self::ERR_NEEDS_LIVE);
        }

        if ($master->status !== AiCopyMaster::STATUS_ACTIVE || ! $master->bot) {
            throw new RuntimeException(self::ERR_MASTER_CLOSED);
        }

        if ($master->wallet_address === $wallet) {
            throw new RuntimeException(self::ERR_SELF);
        }

        if (! $this->live->supports((string) $master->bot->pair)) {
            throw new RuntimeException(self::ERR_PAIR_UNSUPPORTED);
        }

        // สิทธิ์ตามแพลน + โควตาจำนวนบอท — ด่านเดียวกับการสร้างบอททุกตัว
        $subscription = $this->bots->assertCanRunBot($wallet, self::STRATEGY);

        return DB::transaction(function () use ($wallet, $master, $riskInput, $subscription) {
            $existing = AiCopyFollow::where('ai_copy_master_id', $master->id)
                ->where('follower_wallet', $wallet)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isActive()) {
                throw new RuntimeException(self::ERR_ALREADY_FOLLOWING);
            }

            $bot = AiBotConfig::create([
                'wallet_address' => $wallet,
                'ai_bot_subscription_id' => $subscription->id,
                'name' => mb_substr('Copy · '.$master->display_name, 0, (int) config('aibot.limits.max_name_length', 60)),
                'pair' => $master->bot->pair,
                'strategy' => self::STRATEGY,
                'timeframe' => $master->bot->timeframe,
                'params' => $this->bots->sanitizeParams(self::STRATEGY, []),
                'risk' => $this->bots->sanitizeRisk($riskInput, $subscription->plan instanceof AiBotPlan ? $subscription->plan : null),
                'status' => 'paused',
                'mode' => 'live',
            ]);

            $attributes = [
                'ai_bot_config_id' => $bot->id,
                'fee_rate_pct' => $master->fee_rate_pct,
                'platform_share_pct' => $master->platform_share_pct,
                'status' => AiCopyFollow::STATUS_ACTIVE,
                'started_at' => now(),
                'stopped_at' => null,
            ];

            // เคยตามแล้วเลิก = ตามใหม่บนแถวเดิม: high-water mark เดิมยังอยู่ กำไรที่เคยคิดค่าบริการไปแล้วไม่ถูกคิดซ้ำ
            return $existing
                ? tap($existing)->update($attributes)
                : AiCopyFollow::create(['ai_copy_master_id' => $master->id, 'follower_wallet' => $wallet] + $attributes);
        });
    }

    /**
     * เลิกตาม — ทำได้ทุกเมื่อ ไม่มีเงื่อนไข.
     *
     * ไม้ที่ถืออยู่ไม่ถูกบังคับขาย: บอทยังเดินต่อในสภาพ "ไม่ผูกกับมาสเตอร์" เพื่อให้ stop loss / take profit
     * ของผู้ตามยังทำงาน (พักบอททั้งที่ยังถือของ = ไม่มีใครตัดขาดทุนให้) · ไม่ถือของแล้วจึงพักบอท
     */
    public function stopFollow(AiCopyFollow $follow): AiCopyFollow
    {
        if (! $follow->isActive()) {
            return $follow;
        }

        $this->settleFees($follow);

        $follow->update(['status' => AiCopyFollow::STATUS_STOPPED, 'stopped_at' => now()]);

        $bot = $follow->bot;

        if ($bot && $bot->status === 'running' && ! $this->live->hasExposure($bot)) {
            $bot->update(['status' => 'paused', 'last_reason' => 'เลิกตามมาสเตอร์แล้ว — บอทถูกพักไว้']);
        }

        return $follow->fresh();
    }

    /** ผู้ตามยอมรับอัตราปัจจุบันของมาสเตอร์ (หลังมาสเตอร์แก้อัตรา) — มีผลกับกำไรหลังจากนี้เท่านั้น */
    public function acceptCurrentRate(AiCopyFollow $follow): AiCopyFollow
    {
        // ปิดบัญชีของอัตราเดิมให้จบก่อน — ไม้ที่ปิดไปแล้วต้องถูกคิดด้วยอัตราที่ตกลงไว้ตอนนั้น
        $this->settleFees($follow);

        $follow->update([
            'fee_rate_pct' => $follow->master->fee_rate_pct,
            'platform_share_pct' => $follow->master->platform_share_pct,
        ]);

        return $follow->fresh();
    }

    public function followOfBot(AiBotConfig $bot): ?AiCopyFollow
    {
        return AiCopyFollow::where('ai_bot_config_id', $bot->id)->first();
    }

    /** บอทตัวนี้เป็นผลงานของมาสเตอร์ที่ยังเปิดโปรไฟล์อยู่ไหม — ใช้กันการลบ/ย้ายคู่ของบอทที่มีคนตามอยู่ */
    public function isOpenMasterBot(AiBotConfig $bot): bool
    {
        return AiCopyMaster::open()->where('ai_bot_config_id', $bot->id)->exists();
    }

    // =========================================================================
    // เครื่องยนต์
    // =========================================================================

    /**
     * สิ่งที่บอทผู้ตามต้องรู้เกี่ยวกับมาสเตอร์รอบนี้ — BotRunner ใส่ให้กลยุทธ์ทาง params['_copy'].
     *
     * ส่งเฉพาะ "ถืออยู่ไหม · สัดส่วนทุนที่ใช้ · ราคาเข้า" — ไม่มีกฎหรือพารามิเตอร์ของมาสเตอร์ในนี้
     *
     * @return array{state: string, holding?: bool, exposure?: float, entry_market?: float|null, opened_after_follow?: bool, follower_cap?: float, master?: string}
     */
    public function signalContext(AiBotConfig $followerBot): array
    {
        $follow = $this->followOfBot($followerBot);

        if (! $follow || ! $follow->isActive()) {
            return ['state' => 'detached'];
        }

        $master = $follow->master;
        $masterBot = $master?->bot;

        if (! $master || ! $master->isOpen() || ! $masterBot) {
            return ['state' => 'master_gone'];
        }

        $position = AiBotPosition::where('ai_bot_config_id', $masterBot->id)
            ->where('mode', TrackRecord::MODE)
            ->where('quantity', '>', 0)
            ->first();

        $masterCap = max(1.0, (float) (($masterBot->risk ?? [])['max_position_usd'] ?? 100));
        $entryCostFactor = 1 + ($this->bots->roundTripCostBps() / 2) / 10000;

        return [
            'state' => 'ok',
            'master' => $master->display_name,
            'holding' => (bool) $position,
            // มาสเตอร์ใช้ทุนไปกี่ส่วนของเพดานตัวเอง — ผู้ตามใช้สัดส่วนเดียวกันของเพดานผู้ตาม
            'exposure' => $position ? min(1.0, (float) $position->cost_basis / $masterCap) : 0.0,
            'entry_market' => $position ? (float) $position->entry_price / $entryCostFactor : null,
            // ไม้ที่มาสเตอร์เปิดไว้ก่อนเริ่มตามไม่ถูกก๊อปปี้ — ผู้ตามจะเข้าที่ราคาคนละจุดกับที่มาสเตอร์ได้
            'opened_after_follow' => $position && $position->opened_at !== null && $position->opened_at->gte($follow->started_at),
            'follower_cap' => (float) (($followerBot->risk ?? [])['max_position_usd'] ?? 100),
        ];
    }

    // =========================================================================
    // ค่าบริการ
    // =========================================================================

    /**
     * ลงบัญชีค่าบริการจากไม้ขายเงินจริงของบอทผู้ตามที่ยังไม่เคยถูกนำมาคิด — เรียกซ้ำกี่รอบก็ได้ผลเดิม.
     *
     * high-water mark: ค่าบริการคิดจากส่วนที่กำไรสะสม "ขึ้นสูงกว่าจุดสูงสุดเดิม" เท่านั้น
     * ผู้ตามขาดทุน 100 แล้วกำไรคืน 100 = ยังไม่ได้กำไรเลย ไม่เสียค่าบริการ
     *
     * @return int จำนวนรายการค่าบริการที่ลงใหม่
     */
    public function settleFees(AiCopyFollow $follow): int
    {
        $bot = $follow->bot;

        // บอทถูกเปลี่ยนไปใช้กลยุทธ์อื่นแล้ว = ไม้หลังจากนี้ไม่ใช่ไม้ที่ก๊อปปี้ ไม่มีสิทธิ์คิดค่าบริการ
        if (! $bot || $bot->strategy !== self::STRATEGY) {
            return 0;
        }

        return DB::transaction(function () use ($follow, $bot) {
            $follow = AiCopyFollow::whereKey($follow->id)->lockForUpdate()->firstOrFail();
            $master = $follow->master;

            $sells = AiBotTrade::where('ai_bot_config_id', $bot->id)
                ->where('mode', TrackRecord::MODE)
                ->where('side', 'sell')
                ->where('id', '>', $follow->last_settled_trade_id)
                ->orderBy('id')
                ->get(['id', 'realized_pnl', 'created_at']);

            if ($sells->isEmpty() || ! $master) {
                return 0;
            }

            $cumulative = $follow->realized_pnl;
            $highWater = $follow->high_water_mark;
            $feesUsd = $follow->fees_usd;
            $created = 0;
            $tpixPrice = $this->tpixUsdPrice();
            $status = (bool) config('aibot.copy_trade.fees.collect', false) ? AiCopyFee::STATUS_ACCRUED : AiCopyFee::STATUS_RECORDED;

            foreach ($sells as $trade) {
                $cumulative += (float) $trade->realized_pnl;

                if ($cumulative <= $highWater) {
                    continue;
                }

                $profit = $cumulative - $highWater;
                $highWater = $cumulative;

                $fee = round($profit * $follow->fee_rate_pct / 100, 8);
                $platform = round($fee * $follow->platform_share_pct / 100, 8);
                $toTpix = fn (float $usd) => $tpixPrice !== null ? round($usd / $tpixPrice, 8) : null;

                AiCopyFee::create([
                    'ai_copy_follow_id' => $follow->id,
                    'ai_copy_master_id' => $master->id,
                    'ai_bot_trade_id' => $trade->id,
                    'follower_wallet' => $follow->follower_wallet,
                    'master_wallet' => $master->wallet_address,
                    'profit_usd' => $profit,
                    'fee_rate_pct' => $follow->fee_rate_pct,
                    'platform_share_pct' => $follow->platform_share_pct,
                    'fee_usd' => $fee,
                    'master_usd' => $fee - $platform,
                    'platform_usd' => $platform,
                    'tpix_usd_price' => $tpixPrice,
                    'fee_tpix' => $toTpix($fee),
                    'master_tpix' => $toTpix($fee - $platform),
                    'platform_tpix' => $toTpix($platform),
                    'status' => $status,
                    'charged_at' => $trade->created_at ?? now(),
                ]);

                $feesUsd += $fee;
                $created++;
            }

            $follow->update([
                'realized_pnl' => $cumulative,
                'high_water_mark' => $highWater,
                'fees_usd' => $feesUsd,
                'last_settled_trade_id' => (int) $sells->last()->id,
            ]);

            return $created;
        });
    }

    /** ราคา TPIX เป็น USD จากพูลของเราเอง — ไม่มีราคา = ลงบัญชีเป็น USD ไว้ก่อน ยังไม่แปลง (ไม่เดาราคา) */
    private function tpixUsdPrice(): ?float
    {
        try {
            $price = app(TpixDexService::class)->tpixUsdPrice();

            return $price !== null && $price > 0 ? (float) $price : null;
        } catch (\Throwable $e) {
            Log::warning('copy trade: อ่านราคา TPIX ไม่ได้ ลงบัญชีค่าบริการเป็น USD ไว้ก่อน', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException(self::ERR_DISABLED);
        }
    }
}
