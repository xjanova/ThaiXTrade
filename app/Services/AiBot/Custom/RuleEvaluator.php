<?php

namespace App\Services\AiBot\Custom;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\MarketRegime;

/**
 * TPIX TRADE — ประเมินบล็อกเงื่อนไขของโหมด "วางกลยุทธ์เอง" กับแท่งเทียน.
 *
 * ตัวเดียวกันนี้เดินทั้งในบอทจริง (BotRunner → CustomStrategy) และใน backtest (BacktestEngine → CustomStrategy)
 * — ผลทดสอบย้อนหลังจึงมาจากโค้ดบรรทัดเดียวกับที่บอทใช้ตัดสินใจจริง
 *
 * คณิตศาสตร์ของอินดิเคเตอร์ใช้ของเดิมทั้งหมด (Indicators / MarketRegime) และเงื่อนไขของแต่ละบล็อก
 * ตรงกับที่กลยุทธ์สำเร็จรูปใช้: EMA ตัดกันแบบ momentum · กรอบ Donchian + ATR แบบ breakout ·
 * วอลุ่มเทียบค่าเฉลี่ยแบบ momentum · เส้นเทรนด์ใหญ่แบบ htf_confirm
 *
 * กติกา:
 *  - pure — ตัดสินจากแท่งเทียน + บล็อก + บริบทที่ engine ส่งมาเท่านั้น
 *  - ข้อมูลไม่พอ → ok = null (ไม่ใช่ false) ผู้เรียกต้องถือและบอกเหตุผล ไม่ใช่เดา
 *  - ข้อความของแต่ละบล็อกเป็นภาษาไทยเสมอและห้ามมี " · " (ตัวคั่นรายการของ BotRunner::localizeReason)
 *    เพิ่ม/แก้ข้อความแล้วต้องเพิ่มแม่แบบใน BotRunner::REASON_TEMPLATES และ lang/en.json ด้วย
 *
 * Developed by Xman Studio.
 */
final class RuleEvaluator
{
    /**
     * ประเมินเงื่อนไขหนึ่งฝั่ง (เข้า หรือ ออก).
     *
     * @param  array{match: string, blocks: list<array>}  $group  ผ่าน RuleSet::normalize() แล้ว
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $candles  แท่งปิดแล้ว เก่า → ใหม่
     * @param  array{bars_since_entry?: int}  $context  สิ่งที่ engine เป็นคนรู้
     * @return array{ok: bool|null, met: list<string>, unmet: list<string>, blocks: list<array{type: string, ok: bool|null, text: string, value: float|null}>}
     *                                                                                                                                                         ok = null เมื่อข้อมูลไม่พอจะตัดสิน
     */
    public static function evaluate(array $group, array $candles, array $context = []): array
    {
        $results = array_map(
            fn (array $block) => ['type' => (string) ($block['type'] ?? '')] + self::block($block, $candles, $context),
            array_values($group['blocks'] ?? []),
        );

        $met = array_values(array_filter($results, fn (array $r) => $r['ok'] === true));
        $unknown = array_filter($results, fn (array $r) => $r['ok'] === null);

        if ($results === []) {
            $ok = false;
        } elseif (($group['match'] ?? 'all') === 'any') {
            // ข้อใดข้อหนึ่งจริงก็พอ — ข้อที่ยังคำนวณไม่ได้ไม่ทำให้ข้อที่จริงแล้วเป็นโมฆะ
            $ok = $met !== [] ? true : ($unknown !== [] ? null : false);
        } else {
            // ครบทุกข้อ — มีข้อที่ยังคำนวณไม่ได้ = ยังตัดสินไม่ได้ ห้ามนับว่าผ่าน
            $ok = $unknown !== [] ? null : count($met) === count($results);
        }

        return [
            'ok' => $ok,
            'met' => array_column($met, 'text'),
            'unmet' => array_column(array_values(array_filter($results, fn (array $r) => $r['ok'] !== true)), 'text'),
            'blocks' => $results,
        ];
    }

    /**
     * ประเมินบล็อกเดียว.
     *
     * @return array{ok: bool|null, text: string, value: float|null}
     */
    public static function block(array $block, array $candles, array $context = []): array
    {
        return match ($block['type'] ?? '') {
            'ema_cross' => self::emaCross($block, $candles),
            'rsi' => self::rsi($block, $candles),
            'channel_breakout' => self::channelBreakout($block, $candles),
            'atr' => self::atr($block, $candles),
            'volume_ratio' => self::volumeRatio($block, $candles),
            'price_change' => self::priceChange($block, $candles),
            'trend' => self::trend($block, $candles),
            'interval' => self::interval($block, $context),
            // ชนิดที่ไม่รู้จักไม่มีทางผ่าน RuleSet มาถึงตรงนี้ — ถ้ามาถึงก็ต้องไม่นับว่าเงื่อนไขเป็นจริง
            default => self::result(null, 'เงื่อนไขที่ระบบไม่รองรับ'),
        };
    }

    // ── บล็อก ────────────────────────────────────────────────────────────────

    /** EMA เร็ว/ช้า — เงื่อนไขการตัดเหมือน MomentumStrategy (แท่งก่อนหน้าต้องอยู่คนละฝั่ง) */
    private static function emaCross(array $block, array $candles): array
    {
        $fastPeriod = (int) $block['fast'];
        $slowPeriod = (int) $block['slow'];
        $when = (string) $block['when'];

        $text = match ($when) {
            'crosses_above' => "EMA {$fastPeriod} ตัดขึ้นเหนือ EMA {$slowPeriod}",
            'crosses_below' => "EMA {$fastPeriod} ตัดลงใต้ EMA {$slowPeriod}",
            'is_above' => "EMA {$fastPeriod} อยู่เหนือ EMA {$slowPeriod}",
            default => "EMA {$fastPeriod} อยู่ใต้ EMA {$slowPeriod}",
        };

        $closes = array_column($candles, 'close');
        $fast = Indicators::ema($closes, $fastPeriod);
        $slow = Indicators::ema($closes, $slowPeriod);

        // EMA สองชุดยาวไม่เท่ากัน — ต้องเทียบจากท้ายอาเรย์เสมอ ไม่ใช่ index เดียวกัน
        if (count($fast) < 2 || count($slow) < 2) {
            return self::result(null, $text);
        }

        $fastNow = $fast[count($fast) - 1];
        $fastPrev = $fast[count($fast) - 2];
        $slowNow = $slow[count($slow) - 1];
        $slowPrev = $slow[count($slow) - 2];

        $ok = match ($when) {
            'crosses_above' => $fastPrev <= $slowPrev && $fastNow > $slowNow,
            'crosses_below' => $fastPrev >= $slowPrev && $fastNow < $slowNow,
            'is_above' => $fastNow > $slowNow,
            default => $fastNow < $slowNow,
        };

        return self::result($ok, $text, $slowNow > 0 ? ($fastNow - $slowNow) / $slowNow * 100 : null);
    }

    private static function rsi(array $block, array $candles): array
    {
        $period = (int) $block['period'];
        $level = (float) $block['level'];
        $below = $block['op'] === 'below';
        $rsi = Indicators::last(Indicators::rsi(array_column($candles, 'close'), $period));

        $text = sprintf($below ? 'RSI(%d) ต่ำกว่า %s (ตอนนี้ %s)' : 'RSI(%d) สูงกว่า %s (ตอนนี้ %s)', $period, self::num($level), $rsi === null ? '—' : self::num($rsi, 1));

        if ($rsi === null) {
            return self::result(null, $text);
        }

        return self::result($below ? $rsi < $level : $rsi > $level, $text, $rsi);
    }

    /** กรอบ Donchian ของ N แท่งก่อนหน้า (ไม่รวมแท่งปัจจุบัน) + ขนาดการทะลุเทียบ ATR(14) — เหมือน BreakoutStrategy */
    private static function channelBreakout(array $block, array $candles): array
    {
        $period = (int) $block['period'];
        $minAtr = (float) $block['min_atr'];
        $up = $block['side'] === 'above_high';

        $text = $up ? "ราคาปิดทะลุจุดสูงสุด {$period} แท่ง" : "ราคาปิดหลุดจุดต่ำสุด {$period} แท่ง";

        $channel = Indicators::donchian($candles, $period);
        $edge = Indicators::last($up ? $channel['upper'] : $channel['lower']);
        $atr = Indicators::last(Indicators::atr($candles, 14));

        if ($edge === null || $atr === null || $candles === []) {
            return self::result(null, $text);
        }

        $close = (float) $candles[count($candles) - 1]['close'];
        $distance = $up ? $close - $edge : $edge - $close;
        $size = $atr > 0 ? $distance / $atr : 0.0;

        // ทะลุนิดเดียว (เทียบ ATR) มักเป็นสัญญาณหลอก — ผู้ใช้ตั้งขนาดขั้นต่ำได้ · 0 = ไม่กรอง
        return self::result($distance > 0 && ($minAtr <= 0 || $size >= $minAtr), $text, $size);
    }

    private static function atr(array $block, array $candles): array
    {
        $period = (int) $block['period'];
        $threshold = (float) $block['pct'];
        $above = $block['op'] === 'above';

        $atr = Indicators::last(Indicators::atr($candles, $period));
        $close = $candles === [] ? 0.0 : (float) $candles[count($candles) - 1]['close'];
        $pct = ($atr !== null && $close > 0) ? $atr / $close * 100 : null;

        $text = sprintf($above ? 'ATR(%d) สูงกว่า %s%% ของราคา (ตอนนี้ %s%%)' : 'ATR(%d) ต่ำกว่า %s%% ของราคา (ตอนนี้ %s%%)', $period, self::num($threshold), $pct === null ? '—' : self::num($pct, 2));

        if ($pct === null) {
            return self::result(null, $text);
        }

        return self::result($above ? $pct > $threshold : $pct < $threshold, $text, $pct);
    }

    /** วอลุ่มแท่งล่าสุดเทียบค่าเฉลี่ย N แท่ง — เหมือนตัวกรองวอลุ่มของ MomentumStrategy */
    private static function volumeRatio(array $block, array $candles): array
    {
        $period = (int) $block['period'];
        $threshold = (float) $block['ratio'];
        $above = $block['op'] === 'above';

        $volumes = array_column($candles, 'volume');
        $average = Indicators::last(Indicators::sma($volumes, $period));
        $ratio = ($average !== null && $average > 0) ? (float) $volumes[count($volumes) - 1] / $average : null;

        $text = sprintf($above ? 'วอลุ่มสูงกว่า %s เท่าของค่าเฉลี่ย %d แท่ง (ตอนนี้ %s เท่า)' : 'วอลุ่มต่ำกว่า %s เท่าของค่าเฉลี่ย %d แท่ง (ตอนนี้ %s เท่า)', self::num($threshold), $period, $ratio === null ? '—' : self::num($ratio, 2));

        if ($ratio === null) {
            return self::result(null, $text);
        }

        return self::result($above ? $ratio >= $threshold : $ratio < $threshold, $text, $ratio);
    }

    private static function priceChange(array $block, array $candles): array
    {
        $bars = (int) $block['bars'];
        $threshold = (float) $block['pct'];
        $above = $block['op'] === 'above';

        $closes = array_column($candles, 'close');
        // Indicators::changePct คืน 0 เมื่อข้อมูลไม่พอ — ต้องแยกเองว่า "ไม่ขยับ" กับ "ยังไม่รู้" ไม่ใช่เรื่องเดียวกัน
        $change = count($closes) > $bars ? Indicators::changePct($closes, $bars) : null;

        $text = sprintf($above ? 'ราคาเปลี่ยนใน %d แท่งมากกว่า %s%% (ตอนนี้ %s%%)' : 'ราคาเปลี่ยนใน %d แท่งน้อยกว่า %s%% (ตอนนี้ %s%%)', $bars, self::num($threshold), $change === null ? '—' : self::num($change, 2));

        if ($change === null) {
            return self::result(null, $text);
        }

        return self::result($above ? $change > $threshold : $change < $threshold, $text, $change);
    }

    /** ราคาปิดเทียบ EMA ยาว — ตัวเดียวกับ htf_confirm ของ momentum/breakout (MarketRegime) */
    private static function trend(array $block, array $candles): array
    {
        $period = (int) $block['period'];
        $above = $block['side'] === 'above';

        $text = $above ? "ราคาอยู่เหนือเส้นเทรนด์ใหญ่ EMA {$period}" : "ราคาอยู่ใต้เส้นเทรนด์ใหญ่ EMA {$period}";

        $regime = MarketRegime::assess($candles, 20, $period);

        // flat = ยังไม่มี EMA ให้เทียบ (ข้อมูลไม่พอ) — ไม่รู้ทิศ ไม่ใช่ "ไม่ใช่ขาขึ้น"
        if ($regime['trend'] === 'flat') {
            return self::result(null, $text);
        }

        return self::result($above ? $regime['trend'] === 'up' : $regime['trend'] === 'down', $text, $regime['above_ema_pct']);
    }

    /** ครบ N แท่งนับจากการซื้อครั้งล่าสุด — engine เป็นคนนับให้ (ไม่เคยซื้อ = ครบแล้ว) */
    private static function interval(array $block, array $context): array
    {
        $bars = (int) $block['bars'];
        $since = (int) ($context['bars_since_entry'] ?? PHP_INT_MAX);

        return self::result($since >= $bars, "ครบรอบซื้อทุก {$bars} แท่ง", $since === PHP_INT_MAX ? null : (float) $since);
    }

    // ── ตัวช่วย ──────────────────────────────────────────────────────────────

    /** @return array{ok: bool|null, text: string, value: float|null} */
    private static function result(?bool $ok, string $text, ?float $value = null): array
    {
        return ['ok' => $ok, 'text' => $text, 'value' => $value === null ? null : round($value, 4)];
    }

    /** ตัวเลขในข้อความ — ไม่มีศูนย์ต่อท้าย (30 ไม่ใช่ 30.00) */
    private static function num(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
