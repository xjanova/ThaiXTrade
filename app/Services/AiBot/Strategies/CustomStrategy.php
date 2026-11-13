<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Custom\RuleEvaluator;
use App\Services\AiBot\Custom\RuleSet;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — วางกลยุทธ์เอง: บอทลงมือตามกฎเข้า/ออกที่ผู้ใช้ประกอบเอง.
 *
 * เจ้าของสั่ง 2026-11-13: ผลิตภัณฑ์ต้องเอนไปทาง "ช่วยเทรดอัตโนมัติ" — ผู้ใช้เป็นคนตัดสินกฎ บอทเป็นคนลงมือ
 * คลาสนี้จึงไม่มีความเห็นของตัวเองเลย: เข้าเมื่อเงื่อนไขเข้าของผู้ใช้เป็นจริง ออกเมื่อเงื่อนไขออกเป็นจริง
 *
 * ลำดับตอนถือของ (ตัวแรกที่เป็นจริงชนะ):
 *   1. trailing stop   ราคาปิดย่อจากจุดสูงสุดตั้งแต่เข้าไม้ครบ % ที่ตั้ง
 *   2. time stop       ถือครบจำนวนแท่ง (เลือกได้: เฉพาะตอนยังไม่กำไร หรือปิดเสมอ)
 *   3. เงื่อนไขออก      บล็อกฝั่ง exit ของผู้ใช้
 *   4. ซื้อเพิ่ม         เฉพาะเมื่อเปิด add_to_position และเงื่อนไขเข้าเป็นจริงอีกครั้ง
 * ส่วน take profit / stop loss / เพดานขาดทุนต่อวัน เป็นกรอบความเสี่ยงของบอท — engine ตรวจก่อนถึงกลยุทธ์
 * เสมอ (BotRunner ขั้น 4 · BacktestEngine::userLimit) จึงไม่ซ้ำไว้ที่นี่
 *
 * สิ่งที่ engine เป็นคนรู้และส่งมาทาง params (เหมือน _bars_since_entry ของ DCA):
 *   _bars_since_entry  กี่แท่งแล้วตั้งแต่ซื้อครั้งล่าสุด (บล็อกรอบเวลา)
 *   _bars_since_exit   กี่แท่งแล้วตั้งแต่ปิดไม้ครั้งล่าสุด (พักหลังปิดไม้)
 *
 * จุดสูงสุดตั้งแต่เข้าไม้คิดจากราคาปิดของแท่งที่ปิดหลังเข้าไม้ (bars_held แท่งท้ายสุด) — ไม่ต้องมีสถานะ
 * เก็บเพิ่ม บอทจริงกับ backtest จึงได้ค่าเดียวกันจากแท่งเทียนชุดเดียวกัน
 *
 * Developed by Xman Studio.
 */
class CustomStrategy implements Strategy
{
    public function code(): string
    {
        return 'custom';
    }

    public function minCandles(array $params): int
    {
        // BotRunner ส่ง params ดิบของบอทมา — ต้องล้างเองก่อนอ่าน
        return RuleSet::minCandles(RuleSet::normalize($params['rules'] ?? null));
    }

    /**
     * เติมไม้ได้ "เมื่อผู้ใช้เปิดเอง" — engine ถามเมธอดนี้โดยไม่รู้ params จึงตอบ true
     * แล้ว decide() เป็นคนไม่สั่งซื้อซ้ำเมื่อ add_to_position ปิดอยู่ (ค่าปริยาย).
     */
    public function allowsPyramiding(): bool
    {
        return true;
    }

    /** ผู้ใช้ปิดอิทธิพลของ AI ได้ทั้งหมดด้วยสวิตช์ ai_gate — เปิดไว้ = ยอมให้ AI ขอปิดไม้เหมือนกลยุทธ์ตามสัญญาณอื่น */
    public function acceptsAiExit(): bool
    {
        return true;
    }

    /** กฎของผู้ใช้เป็นของผู้ใช้ — AI ไม่มีสิทธิ์ผ่อนเกณฑ์ที่เจ้าของเงินตั้งเอง */
    public function withAiRelief(array $params, float $reliefPoints): array
    {
        return $params;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        $rules = RuleSet::normalize($params['rules'] ?? null);

        if ($rules['invalid'] ?? false) {
            return Signal::hold('กฎที่บันทึกไว้มีเงื่อนไขที่ระบบไม่รองรับแล้ว — เปิดตัวสร้างกฎแล้วบันทึกใหม่ก่อน บอทจะไม่เปิดไม้จนกว่าจะแก้');
        }

        if ($rules['entry']['blocks'] === []) {
            return Signal::hold('ยังไม่ได้ตั้งเงื่อนไขเข้าไม้ — เปิดตัวสร้างกฎแล้วเพิ่มอย่างน้อย 1 ข้อ');
        }

        if (count($candles) < RuleSet::minCandles($rules)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับเงื่อนไขที่ตั้งไว้');
        }

        $context = ['bars_since_entry' => (int) ($params['_bars_since_entry'] ?? PHP_INT_MAX)];

        return $position
            ? $this->whileHolding($candles, $params, $rules, $position, $context)
            : $this->whileFlat($candles, $params, $rules, $context);
    }

    private function whileFlat(array $candles, array $params, array $rules, array $context): Signal
    {
        // พักหลังปิดไม้ — กันเข้าซ้ำทันทีหลังโดนตัดขาดทุน ตอนที่เงื่อนไขเข้ายังเป็นจริงอยู่
        $cooldown = (int) ($params['cooldown_bars'] ?? 0);
        $sinceExit = (int) ($params['_bars_since_exit'] ?? PHP_INT_MAX);

        if ($cooldown > 0 && $sinceExit < $cooldown) {
            return Signal::hold(sprintf('พักหลังปิดไม้ อีก %d แท่ง', $cooldown - $sinceExit), ['bars_since_exit' => $sinceExit]);
        }

        $entry = RuleEvaluator::evaluate($rules['entry'], $candles, $context);
        $meta = ['entry' => $entry['blocks'], 'match' => $rules['entry']['match']];

        if ($entry['ok'] === null) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับเงื่อนไขที่ตั้งไว้', $meta);
        }

        if (! $entry['ok']) {
            return Signal::hold('ยังไม่เข้าไม้ — รอ: '.implode(' · ', $entry['unmet']), $meta);
        }

        // ขนาดไม้มาจาก order_size_usd (PositionSizer) — ความแรงสัญญาณไม่ได้ใช้ย่อ/ขยายไม้ของโหมดนี้
        return Signal::buy(1.0, 'เงื่อนไขเข้าเป็นจริง: '.implode(' · ', $entry['met']), $meta);
    }

    private function whileHolding(array $candles, array $params, array $rules, array $position, array $context): Signal
    {
        $close = (float) $candles[count($candles) - 1]['close'];
        // วัดจากราคาตลาดตอนเข้า (entry_market) ไม่ใช่ต้นทุนรวมค่าธรรมเนียม — ไม่งั้นไม้ที่ราคาไม่ขยับ
        // จะถูกอ่านว่าขาดทุน 18 bps ตลอด (เหตุผลเดียวกับ momentum/breakout)
        $base = (float) ($position['entry_market'] ?? $position['entry']);
        $barsHeld = max(0, (int) ($position['bars_held'] ?? 0));
        $meta = ['bars_held' => $barsHeld];

        // 1) trailing stop
        $trailing = (float) ($params['trailing_stop_pct'] ?? 0);

        if ($trailing > 0) {
            $since = $barsHeld > 0 ? array_slice(array_column($candles, 'close'), -min($barsHeld, count($candles))) : [];
            $peak = max($base, ...($since === [] ? [$base] : array_map('floatval', $since)));
            $drop = $peak > 0 ? ($peak - $close) / $peak * 100 : 0.0;
            $meta += ['peak' => round($peak, 8), 'drop_pct' => round($drop, 3)];

            if ($drop >= $trailing) {
                return Signal::sell(1.0, sprintf('ราคาย่อจากจุดสูงสุด %.2f%% ถึง trailing stop ที่ตั้งไว้ (%.2f%%)', $drop, $trailing), $meta);
            }
        }

        // 2) time stop
        $maxHold = (int) ($params['max_hold_bars'] ?? 0);

        if ($maxHold > 0 && $barsHeld >= $maxHold) {
            if (($params['time_stop_mode'] ?? 'no_profit') === 'always') {
                return Signal::sell(1.0, "ถือครบ {$maxHold} แท่งตามที่ตั้งไว้ — ปิดไม้ (time stop)", $meta);
            }

            if ($close <= $base) {
                return Signal::sell(1.0, "ถือครบ {$maxHold} แท่งแล้วยังไม่กำไร — ปิดไม้คืนทุน (time stop)", $meta);
            }
        }

        // 3) เงื่อนไขออกของผู้ใช้
        if ($rules['exit']['blocks'] !== []) {
            $exit = RuleEvaluator::evaluate($rules['exit'], $candles, $context);
            $meta += ['exit' => $exit['blocks'], 'match' => $rules['exit']['match']];

            if ($exit['ok'] === true) {
                return Signal::sell(1.0, 'เงื่อนไขออกเป็นจริง: '.implode(' · ', $exit['met']), $meta);
            }
        }

        // 4) ซื้อเพิ่ม — เฉพาะเมื่อผู้ใช้เปิดเอง (engine คุมยอดรวมไม่ให้เกินทุนสูงสุดต่อไม้อยู่แล้ว)
        if (filter_var($params['add_to_position'] ?? false, FILTER_VALIDATE_BOOL)) {
            $entry = RuleEvaluator::evaluate($rules['entry'], $candles, $context);

            if ($entry['ok'] === true) {
                return Signal::buy(1.0, 'เงื่อนไขเข้าเป็นจริง: '.implode(' · ', $entry['met']), $meta + ['entry' => $entry['blocks']]);
            }
        }

        return Signal::hold('ถือต่อ — ยังไม่เข้าเงื่อนไขออกที่ตั้งไว้', $meta);
    }
}
