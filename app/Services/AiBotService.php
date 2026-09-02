<?php

namespace App\Services;

use App\Models\AiBotConfig;
use App\Models\AiBotCredit;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TPIX TRADE — AI Trade (Cloud Bot) business logic.
 *
 * ทุกอย่างที่แตะเครดิตต้องผ่านคลาสนี้ เพื่อให้ ledger กับยอดคงเหลือตรงกันเสมอ
 * และเพื่อให้ทั้งเว็บและแอพมือถือเห็นกติกาเดียวกัน
 *
 * Developed by Xman Studio.
 */
class AiBotService
{
    /** ข้อผิดพลาดที่ตั้งใจส่งให้ผู้ใช้เห็น (controller แปลงเป็น JSON error code) */
    public const ERR_INSUFFICIENT_CREDITS = 'INSUFFICIENT_CREDITS';

    public const ERR_NO_SUBSCRIPTION = 'NO_SUBSCRIPTION';

    public const ERR_BOT_LIMIT = 'BOT_LIMIT_REACHED';

    public const ERR_STRATEGY_LOCKED = 'STRATEGY_LOCKED';

    /** มีคำขอเช่าของกระเป๋านี้กำลังทำงานอยู่ — กันกดรัวจนตัดเครดิตซ้อน */
    public const ERR_SUBSCRIBE_BUSY = 'SUBSCRIBE_IN_PROGRESS';

    /** ยังไม่เปิดให้เติมเครดิต (ยังไม่มีรางรับเงินจริง) */
    public const ERR_TOPUP_CLOSED = 'TOPUP_UNAVAILABLE';

    /** ยังไม่เปิดขายให้คนทั่วไป — อยู่ระหว่างทดสอบ */
    public const ERR_SALES_CLOSED = 'SALES_CLOSED';

    /** กลยุทธ์ถูกถอดออกจากการขายแล้ว (ธง retired ใน config/aibot.php) */
    public const ERR_STRATEGY_RETIRED = 'STRATEGY_RETIRED';

    // =========================================================================
    // เครดิต
    // =========================================================================

    public function balanceFor(string $wallet): float
    {
        $latest = AiBotCredit::where('wallet_address', $this->normalize($wallet))
            ->latest('id')
            ->first();

        return (float) ($latest->balance_after ?? 0);
    }

    /**
     * เขียนรายการเครดิต 1 แถวแบบ atomic.
     *
     * reference ต้องไม่ซ้ำต่อ wallet (มี unique index รองรับ) — ทำให้เรียกซ้ำ
     * จาก retry ของ client แล้วไม่ตัด/เพิ่มเครดิตสองรอบ คืนแถวเดิมแทน
     */
    public function record(string $wallet, string $type, float $amount, string $reference, array $meta = []): AiBotCredit
    {
        $wallet = $this->normalize($wallet);

        return DB::transaction(function () use ($wallet, $type, $amount, $reference, $meta) {
            $existing = AiBotCredit::where('wallet_address', $wallet)
                ->where('reference', $reference)
                ->first();

            if ($existing) {
                return $existing;
            }

            $current = (float) (AiBotCredit::where('wallet_address', $wallet)
                ->lockForUpdate()
                ->latest('id')
                ->first()->balance_after ?? 0);

            $next = round($current + $amount, 4);

            if ($next < 0) {
                throw new RuntimeException(self::ERR_INSUFFICIENT_CREDITS);
            }

            try {
                return AiBotCredit::create([
                    'wallet_address' => $wallet,
                    'type' => $type,
                    'amount' => round($amount, 4),
                    'balance_after' => $next,
                    'reference' => $reference,
                    'meta' => $meta ?: null,
                ]);
            } catch (QueryException $e) {
                // ชน unique index จาก request คู่ขนาน — อีกฝั่งเขียนสำเร็จแล้ว ใช้ของเขา
                $row = AiBotCredit::where('wallet_address', $wallet)
                    ->where('reference', $reference)
                    ->first();

                if (! $row) {
                    throw $e;
                }

                return $row;
            }
        });
    }

    /** โบนัสต้อนรับครั้งเดียวต่อ wallet (idempotent ด้วย reference คงที่) */
    public function grantWelcomeBonus(string $wallet): float
    {
        $bonus = (float) config('aibot.credits.welcome_bonus', 0);

        if ($bonus > 0) {
            $this->record($wallet, 'bonus', $bonus, 'welcome', ['note' => 'welcome bonus']);
        }

        return $this->balanceFor($wallet);
    }

    /**
     * สร้างคำขอเติมเครดิต — ยังไม่เพิ่มยอดจนกว่าจะยืนยันการชำระเงิน.
     *
     * ตั้งใจไม่เพิ่มเครดิตทันที: เงินจริงยังไม่เข้า ถ้าเพิ่มก่อนจะเปิดช่องให้
     * กดขอรัวๆ แล้วได้บอทฟรี การยืนยันทำที่หลังบ้าน (admin/webhook) แล้วเรียก record()
     *
     * @return array{reference: string, pack: array, status: string}
     */
    public function createTopupIntent(string $wallet, string $packCode): array
    {
        /*
         * ยังไม่มีรางรับเงินจริง — ต้องบอกตรงๆ ไม่ใช่ตอบ "สำเร็จ" แล้วเงียบหาย
         *
         * เมธอดนี้คืนแค่ใบแจ้งความจำนง (`pending_payment`) ไม่มีใครมารับเงินต่อ
         * และไม่มีหน้าแอดมินยืนยันการเติม ผู้ใช้ที่กดแล้วเห็นข้อความ "ส่งคำขอแล้ว"
         * จะรอเครดิตที่ไม่มีวันมา แล้วกลับมากดซ้ำ — เสียความเชื่อถือมากกว่าบอกว่า
         * ยังไม่เปิดตั้งแต่แรก
         *
         * เปิดใช้เมื่อไหร่ให้ตั้ง `aibot.credits.topup_enabled` เป็น true พร้อมกับ
         * ต่อตัวรับเงินจริง (ใช้ซ้ำจาก TradingTopupService ที่ยืนยันบนเชนได้แล้ว)
         */
        if (! config('aibot.credits.topup_enabled', false)) {
            throw new RuntimeException(self::ERR_TOPUP_CLOSED);
        }

        $pack = collect(config('aibot.credits.packs', []))
            ->firstWhere('code', $packCode);

        if (! $pack) {
            throw new RuntimeException('INVALID_PACK');
        }

        $reference = 'topup:'.Str::uuid()->toString();

        return [
            'reference' => $reference,
            'pack' => $pack,
            'currency' => config('aibot.credits.currency', 'TPIX'),
            'wallet_address' => $this->normalize($wallet),
            'status' => 'pending_payment',
        ];
    }

    // =========================================================================
    // การเช่า
    // =========================================================================

    /** แพลนฟรี (ถ้าแอดมินยังเปิดใช้อยู่) */
    public function freePlan(): ?AiBotPlan
    {
        return AiBotPlan::active()->where('code', 'free')->first();
    }

    /**
     * กระเป๋านี้เป็นของทีมงานไหม.
     *
     * เทียบแบบ lower-case ทั้งสองฝั่ง เพราะที่อยู่กระเป๋าที่คนพิมพ์ใส่ .env มัก
     * เป็น checksum case (มีตัวใหญ่ปน) ส่วนในระบบเก็บเป็นตัวเล็กล้วน —
     * เทียบตรงๆ แล้วจะไม่ตรงกันเลยสักใบ และอาการคือ "สิทธิ์แอดมินไม่ทำงาน"
     * ที่หาสาเหตุยากมากเพราะไม่มี error อะไรเลย
     */
    public function isAdminWallet(?string $wallet): bool
    {
        if (! $wallet) {
            return false;
        }

        $allowed = array_map('strtolower', config('aibot.admin_wallets', []));

        return in_array($this->normalize($wallet), $allowed, true);
    }

    /** แพลนของทีมงาน — ไม่อยู่ในรายการขาย จึงต้องหาโดยไม่ใช้ scope active() */
    public function adminPlan(): ?AiBotPlan
    {
        return AiBotPlan::where('code', AiBotPlanSeeder::ADMIN_PLAN_CODE)->first();
    }

    /** เปิดขายให้คนทั่วไปแล้วหรือยัง (ทีมงานใช้ได้เสมอไม่ว่าจะเปิดหรือปิด) */
    public function salesOpen(): bool
    {
        return (bool) config('aibot.sales_open', false);
    }

    /**
     * รับประกันว่า wallet นี้มีการเช่าอย่างน้อย "แพลนฟรี" เสมอ.
     *
     * ทำไมต้องสร้างแถวจริง ไม่ใช่ปล่อยให้ไม่มี subscription แล้วไปดักเป็นกรณีพิเศษ:
     * ทั้ง BotRunner, การนับโควตา, การปลดล็อกกลยุทธ์ และการตัดเครดิต ล้วนอ่านจาก
     * subscription->plan ถ้าปล่อยให้ผู้ใช้ฟรีไม่มีแถว ทุกจุดจะต้องมี if พิเศษของตัวเอง
     * แล้วสักจุดจะลืม — กลายเป็นผู้ใช้ฟรีได้สิทธิ์ของแพลนเสียเงินโดยไม่มีใครสังเกต
     *
     * แพลนฟรีไม่ตัดเครดิตและไม่มีวันหมดอายุจริง (ต่ออายุอัตโนมัติเมื่อถูกเรียก)
     */
    public function ensureFreeSubscription(string $wallet): ?AiBotSubscription
    {
        $wallet = $this->normalize($wallet);

        if ($existing = $this->activeSubscription($wallet)) {
            return $existing;
        }

        /*
         * กระเป๋าของทีมงานได้แพลนทีมงาน ไม่ใช่แพลนฟรี
         *
         * ต้องเป็น subscription จริง ไม่ใช่การใส่ if ตามจุดต่างๆ — ทั้งการปลดล็อก
         * กลยุทธ์ · โควตาบอท · การเดินบนคลาวด์ · เพดานทุน ล้วนอ่านจากแพลนอยู่แล้ว
         * ทำครั้งเดียวตรงนี้จึงถูกทุกจุดโดยอัตโนมัติ และไม่มีจุดไหนหลุด
         */
        $plan = $this->isAdminWallet($wallet)
            ? ($this->adminPlan() ?? $this->freePlan())
            : $this->freePlan();

        if (! $plan) {
            return null;
        }

        /*
         * ล็อกก่อนสร้าง — ตารางนี้ไม่มี unique index กันไว้
         *
         * หน้า /ai-trade กับการ์ดในหน้าเทรดเรียก status() ได้พร้อมกัน และหน้าเว็บ
         * ยังถามสถานะซ้ำเป็นระยะ ถ้าสองคำขอมาถึงพร้อมกันตอนยังไม่มีแถว ทั้งคู่จะ
         * เห็น "ไม่มี" แล้วสร้างคนละแถว — ผู้ใช้ฟรีคนเดียวได้ subscription สองใบ
         * (ไม่พังทันทีเพราะ activeSubscription เลือกใบล่าสุด แต่ตารางจะเน่าไปเรื่อยๆ
         * และการนับโควตา/ประวัติจะอ่านยากขึ้นทุกครั้งที่เกิด)
         *
         * ได้ล็อกช้า = อีกคำขอสร้างไปแล้ว จึงเช็คซ้ำอีกรอบก่อนสร้างจริง
         */
        $lock = Cache::lock("aibot:free-sub:{$wallet}", 10);

        if (! $lock->get()) {
            return $this->activeSubscription($wallet);
        }

        try {
            if ($existing = $this->activeSubscription($wallet)) {
                return $existing;
            }

            return $this->createFreeSubscription($wallet, $plan);
        } finally {
            $lock->release();
        }
    }

    /** สร้างแถวแพลนฟรี — เรียกจาก ensureFreeSubscription หลังถือล็อกแล้วเท่านั้น */
    private function createFreeSubscription(string $wallet, AiBotPlan $plan): AiBotSubscription
    {
        return AiBotSubscription::create([
            'wallet_address' => $wallet,
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'days' => 365,
            'credits_spent' => 0,
            'started_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
    }

    public function activeSubscription(string $wallet): ?AiBotSubscription
    {
        return AiBotSubscription::with('plan')
            ->where('wallet_address', $this->normalize($wallet))
            ->live()
            ->latest('expires_at')
            ->first();
    }

    /**
     * เช่าแพลน N วัน — ตัดเครดิตแล้วสร้าง/ต่ออายุการเช่า.
     *
     * แพลนเดิม → ต่ออายุจาก expires_at เดิม (ไม่เสียวันที่จ่ายไปแล้ว)
     * แพลนใหม่ → คืนเครดิตวันที่เหลือของแพลนเดิมก่อน แล้วเริ่มรอบใหม่
     */
    public function subscribe(string $wallet, AiBotPlan $plan, int $days): AiBotSubscription
    {
        $wallet = $this->normalize($wallet);
        $days = $this->clampRentalDays($days);
        $cost = round($plan->credits_per_day * $days, 4);

        /*
         * ด่านสุดท้ายก่อนมีใครเสียเงิน
         *
         * เจ้าของสั่งให้ปิดการขายไว้จนกว่าจะทดสอบกลยุทธ์ครบและมั่นใจ — ปิดที่นี่
         * เพราะเป็นทางเดียวที่เครดิตถูกตัด ปิดแค่ปุ่มบนหน้าเว็บไม่พอ แอพมือถือกับ
         * คนที่ยิง API ตรงไม่เห็นปุ่มเรา
         *
         * ทีมงานผ่านได้เสมอ (แพลนทีมงานราคา 0 อยู่แล้ว จึงไม่มีเงินเปลี่ยนมือ)
         */
        if (! $this->salesOpen() && ! $this->isAdminWallet($wallet) && $cost > 0) {
            throw new RuntimeException(self::ERR_SALES_CLOSED);
        }

        /*
         * ล็อกต่อกระเป๋าก่อนแตะเงิน
         *
         * เดิมไม่มีล็อกเลย ทั้งที่ ensureFreeSubscription() ซึ่งไม่แตะเงินยังมี —
         * คำขอสองใบพร้อมกันอ่าน activeSubscription() ได้ค่าเดียวกัน แล้วสร้าง
         * subscription คนละแถว ตัดเครดิตครบสองรอบ (reference ผูกกับ id ที่ต่างกัน
         * unique index จึงไม่ช่วย) ผู้ใช้จ่ายสองเท่าแต่ได้แพลนใบเดียว ส่วนอีกใบ
         * กลายเป็นแถวกำพร้าที่ activeSubscription() มองไม่เห็นและ cancel() ไม่คืนให้
         */
        $lock = Cache::lock("aibot:subscribe:{$wallet}", 15);

        if (! $lock->get()) {
            throw new RuntimeException(self::ERR_SUBSCRIBE_BUSY);
        }

        try {
            return $this->commitSubscription($wallet, $plan, $days, $cost);
        } finally {
            $lock->release();
        }
    }

    /** ตัวเนื้อของ subscribe() — เรียกได้เฉพาะตอนถือล็อกของกระเป๋านั้นแล้ว */
    private function commitSubscription(string $wallet, AiBotPlan $plan, int $days, float $cost): AiBotSubscription
    {
        return DB::transaction(function () use ($wallet, $plan, $days, $cost) {
            $current = $this->activeSubscription($wallet);
            $planChanged = $current && $current->ai_bot_plan_id !== $plan->id;

            if ($planChanged) {
                $this->refundRemaining($current);
                $current = null;
            }

            /*
             * เช็คยอด "หลัง" คืนเงินแพลนเดิม ไม่ใช่ก่อน
             *
             * เดิมเช็คนอก transaction ตั้งแต่ต้น → ผู้ใช้ VIP ที่ยอดคงเหลือ 0 แต่มี
             * มูลค่าค้างอยู่ในแพลน 7,200 กดลดมา Starter (900) โดนปฏิเสธว่า
             * "เครดิตไม่พอ กรุณาเติมเครดิต" ทั้งที่กำลังจะได้คืน 7,200 ในบรรทัดถัดไป
             * โยนที่นี่ทำให้ transaction ย้อนกลับ การคืนเงินจึงไม่เกิดขึ้นจริงด้วย
             */
            if ($this->balanceFor($wallet) < $cost) {
                throw new RuntimeException(self::ERR_INSUFFICIENT_CREDITS);
            }

            if ($current) {
                $subscription = $current;
                $subscription->days += $days;
                $subscription->expires_at = $current->expires_at->copy()->addDays($days);
            } else {
                $subscription = new AiBotSubscription([
                    'wallet_address' => $wallet,
                    'ai_bot_plan_id' => $plan->id,
                    'status' => 'active',
                    'days' => $days,
                    'credits_spent' => 0,
                    'started_at' => now(),
                    'expires_at' => now()->addDays($days),
                ]);
            }

            $subscription->save();

            /*
             * reference ต้องซ้ำได้เองเมื่อเป็น "คำขอเดิมที่ถูกส่งซ้ำ"
             *
             * เดิมใช้ subscription->id ซึ่งเปลี่ยนทุกครั้งที่สร้างแถวใหม่ (การอัปเกรด
             * จากแพลนฟรีเข้าเส้นทางนี้ทุกคน) → retry ของคำขอเดิมได้ reference ใหม่
             * → unique index ไม่ทำงาน → ตัดเครดิตครบสองรอบ
             * คอมเมนต์เดิมอ้างว่ามี hash ของ payload อยู่แล้ว ซึ่งไม่เคยมี — อันตราย
             * กว่าไม่มีคอมเมนต์ เพราะคนอ่านรอบหน้าจะเชื่อว่ากันซ้ำไว้แล้ว
             *
             * หน้าต่าง 1 นาที: กดปุ่มรัวหรือเน็ตหลุดแล้วยิงซ้ำ = ตัดครั้งเดียว
             * ส่วนการตั้งใจต่ออายุอีกรอบจริงๆ รอข้ามนาทีแล้วทำได้ตามปกติ
             */
            $window = now()->format('YmdHi');
            $this->record(
                $wallet,
                'charge',
                -$cost,
                sprintf('subscribe:%s:%d:%s', $plan->code, $days, $window),
                ['plan' => $plan->code, 'days' => $days]
            );

            $subscription->credits_spent = round((float) $subscription->credits_spent + $cost, 4);
            $subscription->save();

            $subscription = $subscription->fresh('plan');

            /*
             * เปลี่ยนแพลนแล้วต้องคำนวณกรอบความเสี่ยงของบอทเดิมใหม่
             *
             * นี่คือจุดที่ทำให้ "จ่ายแล้วไม่ได้ของ" จริงๆ: sanitizeRisk() ถูกเรียก
             * เฉพาะตอนสร้าง/แก้บอทเท่านั้น บอทที่สร้างตอนอยู่แพลนฟรีจึงเก็บเพดานทุน
             * ของแพลนฟรีไว้ในฐานข้อมูล พออัปเกรดเป็นแพลนเสียเงิน คลาวด์เดินบอทให้
             * ทุก 5 นาทีตามที่จ่าย แต่บอทยังถูกบีบด้วยเพดานเดิม จนกว่าผู้ใช้จะไปกด
             * บันทึกฟอร์มใหม่เอง โดยไม่มีอะไรบอกว่าต้องทำ
             *
             * ทำเฉพาะตอนเปลี่ยนแพลน — ต่ออายุแพลนเดิมไม่ควรไปทับค่าที่ผู้ใช้ตั้งเอง
             */
            if ($planChanged) {
                $this->reapplyPlanLimits($wallet, $subscription->plan);
            }

            return $subscription;
        });
    }

    /**
     * คำนวณกรอบความเสี่ยงของบอททุกตัวใหม่ตามแพลนที่ถืออยู่.
     *
     * เก็บค่าที่ผู้ใช้ตั้งไว้เป็นตัวตั้ง แล้วให้ sanitizeRisk() บีบตามเพดานของแพลนใหม่
     * → อัปเกรดแล้วเพดานขยายเอง · ลดแพลนแล้วถูกบีบลงตามสิทธิ์ที่เหลือ
     */
    private function reapplyPlanLimits(string $wallet, ?AiBotPlan $plan): void
    {
        AiBotConfig::forWallet($wallet)
            ->get()
            ->each(function (AiBotConfig $bot) use ($plan) {
                $next = $this->sanitizeRisk($bot->risk ?? [], $plan);

                if ($next !== ($bot->risk ?? [])) {
                    $bot->risk = $next;
                    $bot->save();
                }
            });
    }

    /** ยกเลิกการเช่า + คืนเครดิตของวันที่เหลือ แล้วหยุดบอททั้งหมด */
    public function cancel(string $wallet): ?AiBotSubscription
    {
        $subscription = $this->activeSubscription($wallet);

        if (! $subscription) {
            return null;
        }

        return DB::transaction(function () use ($subscription, $wallet) {
            $this->refundRemaining($subscription);

            AiBotConfig::forWallet($wallet)
                ->whereIn('status', ['running', 'paused'])
                ->update(['status' => 'stopped']);

            return $subscription->fresh('plan');
        });
    }

    /** คืนเครดิตตามจำนวนวันเต็มที่ยังไม่ได้ใช้ แล้วปิดการเช่า */
    private function refundRemaining(AiBotSubscription $subscription): void
    {
        /*
         * ปัดลง ไม่ใช่ปัดขึ้น — daysRemaining() มีไว้แสดงผลอย่างเดียว
         * (ปัดขึ้นแล้วเช่า-ยกเลิกวันละรอบได้ VIP ฟรีตลอดกาล ดู refundableDays)
         */
        $remainingDays = $subscription->refundableDays();
        $perDay = (float) ($subscription->plan?->credits_per_day ?? 0);
        $refund = round($remainingDays * $perDay, 4);

        /*
         * ห้ามคืนเกินที่จ่ายมาจริง
         *
         * เพดานนี้ไม่ใช่แค่กันเผื่อ: ผู้ใช้ที่ต่ออายุหลายรอบมี days สะสมขึ้นเรื่อยๆ
         * ถ้าราคาแพลนถูกปรับขึ้นทีหลัง ยอดคืนที่คิดจากราคาปัจจุบันจะมากกว่าที่เขาจ่าย
         * ตอนราคาถูก — กลายเป็นเสกเครดิตจากส่วนต่างราคาโดยไม่ต้องเทรดเลย
         */
        $refund = min($refund, round((float) $subscription->credits_spent, 4));

        if ($refund > 0) {
            $this->record(
                $subscription->wallet_address,
                'refund',
                $refund,
                'refund:'.$subscription->id,
                ['days' => $remainingDays]
            );
        }

        $subscription->status = 'cancelled';
        $subscription->cancelled_at = now();
        $subscription->expires_at = now();
        $subscription->save();
    }

    private function clampRentalDays(int $days): int
    {
        $allowed = config('aibot.credits.rental_days', [1, 7, 30, 90]);

        return in_array($days, $allowed, true) ? $days : (int) min($allowed);
    }

    // =========================================================================
    // บอท
    // =========================================================================

    /**
     * ตรวจว่า wallet สร้าง/เปิดบอทตัวนี้ได้ไหม — โยน RuntimeException ถ้าไม่ได้.
     *
     * @param  AiBotConfig|null  $existing  บอทที่กำลังแก้ (ไม่ให้นับซ้ำในโควตา)
     */
    public function assertCanRunBot(string $wallet, string $strategyCode, ?AiBotConfig $existing = null): AiBotSubscription
    {
        // ยังไม่เคยเช่า → ลงแพลนฟรีให้อัตโนมัติ แทนที่จะปฏิเสธไปเลย
        // (ฟรีก็สร้างบอทได้ แค่บอทจะเดินเฉพาะตอนเปิดหน้าเว็บทิ้งไว้)
        $subscription = $this->activeSubscription($wallet) ?? $this->ensureFreeSubscription($wallet);

        if (! $subscription) {
            throw new RuntimeException(self::ERR_NO_SUBSCRIPTION);
        }

        $plan = $subscription->plan;

        if (! in_array($strategyCode, $plan->unlockedStrategies(), true)) {
            throw new RuntimeException(self::ERR_STRATEGY_LOCKED);
        }

        /*
         * ถอดออกจากการขายแล้ว = สร้างใหม่ไม่ได้ และ "เริ่ม" ตัวเก่าก็ไม่ได้
         *
         * เช็คที่นี่เพราะทั้ง store/update/setState('start') ของ API ผ่านเมธอดนี้
         * ทั้งหมด — กันที่ปุ่มบนหน้าเว็บอย่างเดียวไม่พอ แอพมือถือไม่เห็นปุ่มเรา
         * (ดูเหตุผลที่ถอดในรายการของกลยุทธ์นั้นใน config/aibot.php)
         */
        if ($this->isRetired($strategyCode)) {
            throw new RuntimeException(self::ERR_STRATEGY_RETIRED);
        }

        $limit = min((int) $plan->max_bots, (int) config('aibot.limits.max_bots_hard_cap', 25));

        $used = AiBotConfig::forWallet($wallet)
            ->countingTowardQuota()
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->count();

        if ($used >= $limit) {
            throw new RuntimeException(self::ERR_BOT_LIMIT);
        }

        return $subscription;
    }

    /**
     * กรองพารามิเตอร์ของกลยุทธ์ให้เหลือเฉพาะคีย์ที่ engine รู้จัก แล้ว clamp ค่าให้อยู่ในกรอบ.
     *
     * ค่าที่ client ส่งมาเกินกรอบไม่ถือเป็น error — clamp แล้วเดินต่อ เพราะกรอบ
     * อาจแคบลงหลังผู้ใช้บันทึกไปแล้ว ไม่ควรทำให้บอทเดิมแก้ไม่ได้
     */
    public function sanitizeParams(string $strategyCode, array $input): array
    {
        $strategy = $this->strategy($strategyCode);

        if (! $strategy) {
            return [];
        }

        $clean = [];

        /*
         * สวิตช์ร่วมต้องรอดจากการล้างค่าด้วย
         *
         * เดิมวนเฉพาะ params ของกลยุทธ์ คีย์อื่นถูกตัดทิ้งเงียบๆ — `auto_pair`
         * (ให้ AI เลือกเหรียญ) จึงไม่เคยถูกบันทึกลงฐานข้อมูลเลยแม้ผู้ใช้จะเปิดไว้
         * และ `news_filter` ก็ปิดไม่ได้จริงในกลยุทธ์ที่ไม่ได้ประกาศคีย์นี้ไว้
         *
         * รวมรายการก่อนวน ไม่ใช่ต่อท้ายทีหลัง — ถ้ากลยุทธ์ไหนประกาศคีย์ชื่อเดียวกัน
         * ไว้เอง ค่าของกลยุทธ์ต้องชนะ (มันรู้ช่วงค่าที่ถูกต้องของตัวเองดีกว่า)
         */
        $specs = collect((array) config('aibot.common_params', []))
            ->concat($strategy['params'] ?? [])
            ->keyBy('key');

        foreach ($specs as $spec) {
            $key = $spec['key'];
            $value = $input[$key] ?? $spec['default'] ?? null;

            $clean[$key] = match ($spec['type']) {
                'number' => $this->clampNumber($value, $spec),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
                'select' => in_array($value, $spec['options'] ?? [], true)
                    ? $value
                    : ($spec['default'] ?? ($spec['options'][0] ?? null)),
                default => null,
            };
        }

        return $this->applyCrossParamRules($strategyCode, $clean);
    }

    /**
     * ต้นทุนเข้า-ออกหนึ่งรอบ หน่วย bps — คิดจาก config ไม่ใช่ตัวเลขฝังในโค้ด.
     *
     * ต้องคิดสดทุกครั้ง เพราะถ้าแอดมินปรับค่าธรรมเนียมหรือ slippage แล้วเลขนี้
     * ไม่ขยับตาม กฎที่อิงมันจะกลายเป็นของหลอกทันทีโดยไม่มีใครสังเกต
     */
    public function roundTripCostBps(): float
    {
        $feeBps = (float) config('aibot_risk.demo.fee_rate', 0.1) * 100;   // % → bps
        $slipBps = (float) config('aibot_risk.demo.slippage_bps', 8);

        return 2 * ($feeBps + $slipBps);   // เข้าหนึ่งครั้ง ออกหนึ่งครั้ง
    }

    /**
     * กฎที่ต้องดูหลายค่าพร้อมกัน — แต่ละค่าถูกต้องของตัวเอง แต่รวมกันแล้วใช้ไม่ได้.
     *
     * schema กำหนดช่วงของแต่ละคีย์แยกกัน จึงเลือกคู่ที่ "ผ่านทุกช่องแต่ขาดทุนแน่นอน"
     * ได้เสมอ เช่นกริด 60 ชั้นในกรอบ 0.5% = ระยะชั้นละ 0.8 bps ขณะที่ต้นทุนไปกลับ
     * 36 bps → เก็บครบทุกชั้นก็ยังติดลบ และผู้ใช้จะไม่มีทางรู้จนกว่าจะเสียเงินไปแล้ว
     */
    private function applyCrossParamRules(string $strategyCode, array $params): array
    {
        $costBps = $this->roundTripCostBps();

        if ($strategyCode === 'grid' && isset($params['grid_levels'], $params['range_pct'])) {
            // กรอบต้องกว้างพอให้ชั้นน้อยสุด (3) ยังห่างกันเกินต้นทุน
            $params['range_pct'] = max((float) $params['range_pct'], round(3 * $costBps / 100, 2));

            $maxLevels = max(3, (int) floor((float) $params['range_pct'] * 100 / $costBps));
            $params['grid_levels'] = min((float) $params['grid_levels'], $maxLevels);
        }

        if ($strategyCode === 'scalping' && isset($params['target_bps'])) {
            // เผื่อ 20% เหนือต้นทุน — เท่าทุนพอดีคือทำงานฟรี
            $params['target_bps'] = max((float) $params['target_bps'], round($costBps * 1.2));
        }

        return $params;
    }

    /** ตัดค่าเสี่ยงให้อยู่ในกรอบของระบบ และไม่เกินเพดานทุนของแพลน */
    public function sanitizeRisk(array $input, ?AiBotPlan $plan = null): array
    {
        $limits = config('aibot.limits');

        /*
         * ฐานของ "ทุนสูงสุดต่อไม้" คือค่าที่ผู้ใช้ขอ ไม่ใช่ค่าที่ถูกเพดานแพลนบีบไว้แล้ว
         *
         * ถ้าเก็บแต่ค่าที่บีบแล้ว ค่าที่ผู้ใช้ตั้งใจจะหายไปตลอดกาล — คนที่ตั้ง $5,000
         * ตอนอยู่แพลนฟรี (ถูกบีบเหลือ $100) พออัปเกรดเป็น VIP ที่ไม่จำกัด ก็ยังได้
         * $100 เท่าเดิม เพราะไม่มีใครจำได้แล้วว่าเขาเคยขอเท่าไหร่ = จ่ายแล้วไม่ได้ของ
         *
         * บอทที่สร้างก่อนมีคีย์นี้จะไม่มี _requested — ใช้ค่าที่บีบแล้วเป็นฐานไปก่อน
         * (ขยายกลับให้อัตโนมัติไม่ได้ แต่ผู้ใช้ตั้งใหม่เองได้ทันทีที่หน้าตั้งค่า)
         */
        $input['max_position_usd'] = $input['max_position_usd_requested']
            ?? $input['max_position_usd']
            ?? null;

        $risk = [];

        foreach (['max_position_usd', 'stop_loss_pct', 'take_profit_pct', 'max_daily_loss_usd'] as $key) {
            $risk[$key] = $this->clampNumber($input[$key] ?? null, $limits[$key]);
        }

        $risk['max_position_usd_requested'] = $risk['max_position_usd'];

        /*
         * เพดานทุนของแพลน (null = ไม่จำกัด) — บังคับที่ server เสมอ
         *
         * ⚠️ เทียบ `!== null` เฉยๆ ไม่พอ: คอลัมน์ cast เป็น decimal:2 จึงคืน "0.00"
         *    ซึ่งไม่ใช่ null → min(x, 0.0) = 0 → บอทเปิดไม้ไม่ได้เลยแบบเงียบๆ
         *    และค่า 0 ถูกเก็บลง DB ค้างไว้ข้ามการอัปเกรดแพลนด้วย
         *    เพดาน 0 ไม่ใช่ค่าที่ตั้งใจได้ในทางธุรกิจ — ถือว่าไม่ได้ตั้งเพดาน
         */
        $cap = $plan?->max_capital_usd;
        if ($cap !== null && (float) $cap > 0) {
            $risk['max_position_usd'] = min($risk['max_position_usd'], (float) $cap);

            // เพดานแพลนต้องไม่กดต่ำกว่าขั้นต่ำของระบบ ไม่งั้นได้ไม้ที่เล็กจนเปิดไม่ได้
            $risk['max_position_usd'] = max(
                $risk['max_position_usd'],
                (float) ($limits['max_position_usd']['min'] ?? 0)
            );
        }

        return $risk;
    }

    /** @param array{min?: float|int, max?: float|int, default?: float|int} $spec */
    private function clampNumber(mixed $value, array $spec): float
    {
        $default = (float) ($spec['default'] ?? 0);
        $number = is_numeric($value) ? (float) $value : $default;

        if (isset($spec['min'])) {
            $number = max($number, (float) $spec['min']);
        }
        if (isset($spec['max'])) {
            $number = min($number, (float) $spec['max']);
        }

        return round($number, 6);
    }

    public function strategy(string $code): ?array
    {
        foreach (config('aibot.strategies', []) as $strategy) {
            if (($strategy['code'] ?? null) === $code) {
                return $strategy;
            }
        }

        return null;
    }

    /** กลยุทธ์นี้ถูกถอดออกจากการขายแล้วไหม (ธง retired ในแคตตาล็อก) */
    public function isRetired(string $code): bool
    {
        return (bool) ($this->strategy($code)['retired'] ?? false);
    }

    public function normalize(string $wallet): string
    {
        return strtolower(trim($wallet));
    }
}
