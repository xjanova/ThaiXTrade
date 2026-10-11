<?php

namespace App\Services\Stabilizer;

use App\Support\Wei;
use Carbon\CarbonInterface;

/**
 * กฎของ TPIX Stability Reserve — ฟังก์ชันบริสุทธิ์: ข้อมูลเดิม → ผลเดิมทุกครั้ง.
 *
 * ไม่อ่านเชน ไม่เขียนฐานข้อมูล ไม่เรียก AI — รับภาพรวม (snapshot) กับพารามิเตอร์แล้วคืน
 * การตัดสินใจพร้อมรหัสเหตุผล เพื่อให้ใครก็ตรวจย้อนได้ว่าทำไมถึงทำ/ไม่ทำ
 *
 * ลำดับด่าน (ด่านแรกที่ไม่ผ่าน = ไม่ทำอะไร และบันทึกเหตุผลนั้น):
 *   1. อ่านพูล/เชนได้ และเชนยังเดินอยู่ (บล็อกล่าสุดไม่เก่าเกิน)
 *   2. พูลลึกพอ (บางเกิน = ไม่แตะ เพราะไม้เล็ก ๆ ก็ขยับราคาแรงเกินควบคุม)
 *   3. มีประวัติราคาพอสำหรับราคาอ้างอิง และข้อมูลล่าสุดไม่เก่า
 *   4. ราคาอยู่ในกรอบ → ไม่ทำอะไร (กรณีปกติส่วนใหญ่)
 *   5. หลุดกรอบ → เช็คช่วงพักระหว่างไม้ + ช่วงพักฝั่งตรงข้าม (กัน wash trade)
 *   6. เช็คงบรายวัน / หลักประกันของ USDT / ยอดในกระเป๋า
 *   7. คำนวณขนาดไม้: ดันกลับไม่เกินขอบกรอบ และไม่เกิน max impact ต่อไม้
 *
 * Developed by Xman Studio
 */
class StabilizerRules
{
    public const TPIX_DECIMALS = 18;

    /**
     * @param  array<string, mixed>  $s  snapshot จาก StabilizerMarket::snapshot()
     * @param  array<string, int|float>  $p  พารามิเตอร์จาก StabilizerSettings::params()
     * @return array<string, mixed>
     */
    public function evaluate(array $s, array $p): array
    {
        /** @var CarbonInterface $now */
        $now = $s['now'];
        $maxAge = (int) $p['max_data_age_minutes'];

        if (($s['contracts'] ?? null) === null) {
            return $this->none('dex_not_ready');
        }

        $pool = $s['pool'] ?? null;
        if ($pool === null || $pool['price'] === null) {
            return $this->none('chain_unreadable', ['what' => 'pool']);
        }

        $base = [
            'pool_price' => $pool['price'],
            'reserve_tpix' => $pool['reserve_tpix'],
            'reserve_usdt' => $pool['reserve_usdt'],
        ];

        $head = $s['chain_head_at'] ?? null;
        if (! $head instanceof CarbonInterface || $head->lt($now->copy()->subMinutes($maxAge))) {
            return $this->none('chain_stale', $base + ['head_at' => $head?->toIso8601String(), 'max_age_minutes' => $maxAge]);
        }

        $wallet = $s['wallet'] ?? [];
        if (($wallet['tpix'] ?? null) === null || ($wallet['usdt'] ?? null) === null) {
            return $this->none('chain_unreadable', $base + ['what' => 'wallet']);
        }

        $decimals = (int) $s['usdt_decimals'];
        $minPoolUsdt = Wei::toWei(PoolMath::dec($p['min_pool_usdt']), $decimals);
        $minPoolTpix = Wei::toWei(PoolMath::dec($p['min_pool_tpix']), self::TPIX_DECIMALS);
        if (bccomp($pool['reserve_usdt'], $minPoolUsdt, 0) < 0 || bccomp($pool['reserve_tpix'], $minPoolTpix, 0) < 0) {
            return $this->none('pool_thin', $base + [
                'pool_usdt' => Wei::format($pool['reserve_usdt'], $decimals),
                'pool_tpix' => Wei::format($pool['reserve_tpix'], self::TPIX_DECIMALS),
                'min_pool_usdt' => PoolMath::dec($p['min_pool_usdt']),
                'min_pool_tpix' => PoolMath::dec($p['min_pool_tpix']),
            ]);
        }

        $ref = $s['reference'] ?? [];
        if (($ref['price'] ?? null) === null || (float) $ref['coverage_pct'] < (float) $p['min_history_coverage_pct']) {
            return $this->none('history_insufficient', $base + [
                'coverage_pct' => (float) ($ref['coverage_pct'] ?? 0),
                'min_coverage_pct' => (float) $p['min_history_coverage_pct'],
                'lookback_days' => (int) $p['lookback_days'],
            ]);
        }

        $latest = $ref['latest_at'] ?? null;
        if (! $latest instanceof CarbonInterface || $latest->lt($now->copy()->subMinutes($maxAge))) {
            return $this->none('price_data_stale', $base + ['latest_at' => $latest?->toIso8601String(), 'max_age_minutes' => $maxAge]);
        }

        // ── กรอบราคา ─────────────────────────────────────────────────────
        $reference = (string) $ref['price'];
        $bandPct = (float) $p['band_pct'];
        $low = PoolMath::shift($reference, -$bandPct);
        $high = PoolMath::shift($reference, $bandPct);
        $price = $pool['price'];
        $base += ['reference_price' => $reference, 'band_low' => $low, 'band_high' => $high];

        if (bccomp($price, $low, 18) >= 0 && bccomp($price, $high, 18) <= 0) {
            return $this->none('in_band', $base);
        }

        $side = bccomp($price, $low, 18) < 0 ? 'buy' : 'sell';
        $base['side'] = $side;
        $base['deviation_pct'] = PoolMath::changePct($side === 'buy' ? $low : $high, $price);

        // ── จังหวะเวลา (กัน wash trade / ยิงถี่) ───────────────────────
        $ledger = $s['ledger'];
        $intervalMinutes = (int) $p['trade_interval_minutes'];
        $lastTrade = $ledger['last_trade_at'] ?? null;
        if ($lastTrade instanceof CarbonInterface && $lastTrade->gt($now->copy()->subMinutes($intervalMinutes))) {
            return $this->none('interval_wait', $base + [
                'last_trade_at' => $lastTrade->toIso8601String(),
                'interval_minutes' => $intervalMinutes,
            ]);
        }

        $cooldownHours = (int) $p['opposite_cooldown_hours'];
        $lastOpposite = $side === 'buy' ? ($ledger['last_sell_at'] ?? null) : ($ledger['last_buy_at'] ?? null);
        if ($lastOpposite instanceof CarbonInterface && $lastOpposite->gt($now->copy()->subHours($cooldownHours))) {
            return $this->none('cooldown_opposite', $base + [
                'last_opposite_at' => $lastOpposite->toIso8601String(),
                'cooldown_hours' => $cooldownHours,
            ]);
        }

        return $side === 'buy'
            ? $this->planBuy($s, $p, $base, $low)
            : $this->planSell($s, $p, $base, $high);
    }

    /** ราคาต่ำกว่ากรอบ → ซื้อ TPIX ด้วย USDT ดันขึ้นไม่เกินขอบล่างของกรอบ */
    private function planBuy(array $s, array $p, array $base, string $low): array
    {
        $decimals = (int) $s['usdt_decimals'];
        $pool = $s['pool'];
        $ledger = $s['ledger'];
        $wallet = $s['wallet'];
        $bridge = $s['bridge'] ?? ['available' => false, 'backed' => null];

        // หลักประกันของ USDT_TPIX: ถ้ามีข้อมูลแล้วบอกว่าหนุนไม่ครบ = ห้ามซื้อเด็ดขาด
        if (($bridge['available'] ?? false) && ($bridge['backed'] ?? null) !== true) {
            return $this->none('usdt_unbacked', $base + ['bridge' => $bridge]);
        }

        $dailyLimit = Wei::toWei(PoolMath::dec($p['daily_budget_usdt']), $decimals);
        $dailyLeft = self::positive(bcsub($dailyLimit, $ledger['usdt_spent_today'], 0));
        if (bccomp($dailyLeft, '0', 0) <= 0) {
            return $this->none('budget_daily_exhausted', $base + [
                'spent_today' => Wei::format($ledger['usdt_spent_today'], $decimals),
                'daily_budget' => PoolMath::dec($p['daily_budget_usdt']),
            ]);
        }

        $caps = [
            'impact_and_band' => null,
            'max_trade' => Wei::toWei(PoolMath::dec($p['max_trade_usdt']), $decimals),
            'daily_left' => $dailyLeft,
            'wallet' => (string) $wallet['usdt'],
        ];

        // ไม่มีหลักฐานทุนสำรองของสะพาน → ใช้ได้เฉพาะ USDT ที่เจ้าของฝากและบันทึกเป็นงบไว้
        $mode = ($bridge['available'] ?? false) ? 'bridge_proof' : 'deposits_only';
        if ($mode === 'deposits_only') {
            $depositLeft = self::positive(bcsub($ledger['usdt_deposits'], $ledger['usdt_spent_total'], 0));
            if (bccomp($depositLeft, '0', 0) <= 0) {
                return $this->none('budget_deposits_exhausted', $base + [
                    'deposits' => Wei::format($ledger['usdt_deposits'], $decimals),
                    'spent_total' => Wei::format($ledger['usdt_spent_total'], $decimals),
                ]);
            }
            $caps['deposit_left'] = $depositLeft;
        }

        if (bccomp((string) $wallet['usdt'], '0', 0) <= 0) {
            return $this->none('insufficient_balance', $base + ['token' => 'usdt']);
        }

        // เป้า = ขอบล่างของกรอบ หรือขึ้นไม่เกิน max impact จากราคาตอนนี้ — เอาตัวที่ต่ำกว่า
        $impactTarget = PoolMath::shift($pool['price'], (float) $p['max_impact_pct']);
        $target = bccomp($impactTarget, $low, 18) < 0 ? $impactTarget : $low;
        $caps['impact_and_band'] = PoolMath::sizeBuy($pool['reserve_tpix'], $pool['reserve_usdt'], $decimals, $target);

        [$amount, $limitedBy] = self::smallest($caps);
        $minTrade = Wei::toWei(PoolMath::dec($p['min_trade_usdt']), $decimals);
        $capsText = array_map(fn ($v) => Wei::format((string) $v, $decimals), $caps);

        if (bccomp($amount, $minTrade, 0) < 0) {
            return $this->none('too_small', $base + [
                'amount' => Wei::format($amount, $decimals),
                'min_trade' => PoolMath::dec($p['min_trade_usdt']),
                'limited_by' => $limitedBy,
                'caps' => $capsText,
            ]);
        }

        $quote = PoolMath::quoteBuy($amount, $pool['reserve_tpix'], $pool['reserve_usdt'], $decimals);
        $minOut = self::minOut($quote['out'], (int) $p['slippage_bps']);
        $plan = $base + [
            'usdt_mode' => $mode,
            'amount_in' => Wei::format($amount, $decimals),
            'expected_out' => Wei::format($quote['out'], self::TPIX_DECIMALS),
            'min_out' => Wei::format($minOut, self::TPIX_DECIMALS),
            'price_after' => $quote['price_after'],
            'target_price' => $target,
            'impact_pct' => PoolMath::changePct($pool['price'], (string) $quote['price_after']),
            'limited_by' => $limitedBy,
            'caps' => $capsText,
        ];

        $order = [
            'side' => 'buy',
            'amount_in_wei' => $amount,
            'expected_out_wei' => $quote['out'],
            'min_out_wei' => $minOut,
            'price_after' => $quote['price_after'],
        ];

        // router ต้องได้รับอนุญาตให้ดึง USDT ก่อน — ขอเท่าเพดานต่อไม้ (ไม่ใช่ไม่จำกัด)
        // เพื่อให้ความเสียหายสูงสุดถ้า router มีปัญหาเท่ากับไม้เดียว
        if (bccomp((string) ($s['wallet']['allowance'] ?? '0'), $amount, 0) < 0) {
            return [
                'action' => 'approve',
                'reason_code' => 'approve_needed',
                'details' => $plan + ['approve_amount' => PoolMath::dec($p['max_trade_usdt'])],
                'order' => $order + ['approve_wei' => $caps['max_trade']],
            ];
        }

        return ['action' => 'buy', 'reason_code' => 'below_band', 'details' => $plan, 'order' => $order];
    }

    /** ราคาสูงกว่ากรอบ → ขาย TPIX รับ USDT ดันลงไม่ต่ำกว่าขอบบนของกรอบ */
    private function planSell(array $s, array $p, array $base, string $high): array
    {
        $decimals = (int) $s['usdt_decimals'];
        $pool = $s['pool'];
        $ledger = $s['ledger'];
        $wallet = $s['wallet'];

        $dailyLimit = Wei::toWei(PoolMath::dec($p['daily_budget_tpix']), self::TPIX_DECIMALS);
        $dailyLeft = self::positive(bcsub($dailyLimit, $ledger['tpix_sold_today'], 0));
        if (bccomp($dailyLeft, '0', 0) <= 0) {
            return $this->none('budget_daily_exhausted', $base + [
                'spent_today' => Wei::format($ledger['tpix_sold_today'], self::TPIX_DECIMALS),
                'daily_budget' => PoolMath::dec($p['daily_budget_tpix']),
            ]);
        }

        if (bccomp((string) $wallet['tpix'], '0', 0) <= 0) {
            return $this->none('insufficient_balance', $base + ['token' => 'tpix']);
        }

        $impactTarget = PoolMath::shift($pool['price'], -(float) $p['max_impact_pct']);
        $target = bccomp($impactTarget, $high, 18) > 0 ? $impactTarget : $high;

        $caps = [
            'impact_and_band' => PoolMath::sizeSell($pool['reserve_tpix'], $pool['reserve_usdt'], $decimals, $target),
            'max_trade' => Wei::toWei(PoolMath::dec($p['max_trade_tpix']), self::TPIX_DECIMALS),
            'daily_left' => $dailyLeft,
            'wallet' => (string) $wallet['tpix'],
        ];

        [$amount, $limitedBy] = self::smallest($caps);
        $minTrade = Wei::toWei(PoolMath::dec($p['min_trade_tpix']), self::TPIX_DECIMALS);
        $capsText = array_map(fn ($v) => Wei::format((string) $v, self::TPIX_DECIMALS), $caps);

        if (bccomp($amount, $minTrade, 0) < 0) {
            return $this->none('too_small', $base + [
                'amount' => Wei::format($amount, self::TPIX_DECIMALS),
                'min_trade' => PoolMath::dec($p['min_trade_tpix']),
                'limited_by' => $limitedBy,
                'caps' => $capsText,
            ]);
        }

        $quote = PoolMath::quoteSell($amount, $pool['reserve_tpix'], $pool['reserve_usdt'], $decimals);
        $minOut = self::minOut($quote['out'], (int) $p['slippage_bps']);

        return [
            'action' => 'sell',
            'reason_code' => 'above_band',
            'details' => $base + [
                'amount_in' => Wei::format($amount, self::TPIX_DECIMALS),
                'expected_out' => Wei::format($quote['out'], $decimals),
                'min_out' => Wei::format($minOut, $decimals),
                'price_after' => $quote['price_after'],
                'target_price' => $target,
                'impact_pct' => PoolMath::changePct($pool['price'], (string) $quote['price_after']),
                'limited_by' => $limitedBy,
                'caps' => $capsText,
            ],
            'order' => [
                'side' => 'sell',
                'amount_in_wei' => $amount,
                'expected_out_wei' => $quote['out'],
                'min_out_wei' => $minOut,
                'price_after' => $quote['price_after'],
            ],
        ];
    }

    private function none(string $reason, array $details = []): array
    {
        return ['action' => 'none', 'reason_code' => $reason, 'details' => $details, 'order' => null];
    }

    /** @return array{0: string, 1: string} จำนวนที่น้อยที่สุด + ชื่อเพดานที่จำกัด */
    private static function smallest(array $caps): array
    {
        $amount = null;
        $by = '';
        foreach ($caps as $name => $value) {
            $value = (string) $value;
            if ($amount === null || bccomp($value, $amount, 0) < 0) {
                $amount = $value;
                $by = (string) $name;
            }
        }

        return [self::positive((string) $amount), $by];
    }

    private static function minOut(string $expected, int $slippageBps): string
    {
        return bcdiv(bcmul($expected, (string) (10000 - $slippageBps), 0), '10000', 0);
    }

    private static function positive(string $value): string
    {
        return bccomp($value, '0', 0) > 0 ? $value : '0';
    }
}
