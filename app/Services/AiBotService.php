<?php

namespace App\Services;

use App\Models\AiBotConfig;
use App\Models\AiBotCredit;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use Illuminate\Database\QueryException;
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

        $plan = $this->freePlan();

        if (! $plan) {
            return null;
        }

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

        if ($this->balanceFor($wallet) < $cost) {
            throw new RuntimeException(self::ERR_INSUFFICIENT_CREDITS);
        }

        return DB::transaction(function () use ($wallet, $plan, $days, $cost) {
            $current = $this->activeSubscription($wallet);

            if ($current && $current->ai_bot_plan_id !== $plan->id) {
                $this->refundRemaining($current);
                $current = null;
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

            // reference ผูกกับ (subscription, วันที่ต่อ, ยอด) จึงต่ออายุซ้ำได้ แต่ retry
            // ของคำขอเดิมจะไม่ตัดซ้ำ เพราะ hash ของ payload เดียวกันให้ reference เดิม
            $this->record(
                $wallet,
                'charge',
                -$cost,
                'subscribe:'.$subscription->id.':'.$subscription->days,
                ['plan' => $plan->code, 'days' => $days]
            );

            $subscription->credits_spent = round((float) $subscription->credits_spent + $cost, 4);
            $subscription->save();

            return $subscription->fresh('plan');
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
        $remainingDays = $subscription->daysRemaining();
        $perDay = (float) ($subscription->plan?->credits_per_day ?? 0);
        $refund = round($remainingDays * $perDay, 4);

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

        foreach ($strategy['params'] ?? [] as $spec) {
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

        return $clean;
    }

    /** ตัดค่าเสี่ยงให้อยู่ในกรอบของระบบ และไม่เกินเพดานทุนของแพลน */
    public function sanitizeRisk(array $input, ?AiBotPlan $plan = null): array
    {
        $limits = config('aibot.limits');

        $risk = [];

        foreach (['max_position_usd', 'stop_loss_pct', 'take_profit_pct', 'max_daily_loss_usd'] as $key) {
            $risk[$key] = $this->clampNumber($input[$key] ?? null, $limits[$key]);
        }

        // เพดานทุนของแพลน (null = ไม่จำกัด) — บังคับที่ server เสมอ
        $cap = $plan?->max_capital_usd;
        if ($cap !== null) {
            $risk['max_position_usd'] = min($risk['max_position_usd'], (float) $cap);
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

    public function normalize(string $wallet): string
    {
        return strtolower(trim($wallet));
    }
}
