<?php

namespace App\Services\AiBot\Copy;

use App\Models\AiBotConfig;
use App\Models\AiBotTrade;

/**
 * TPIX TRADE — ผลงานของบอทหนึ่งตัว คิดจาก "ไม้เงินจริงที่ปิดแล้ว" เท่านั้น.
 *
 * เจ้าของสั่ง 2026-11-13: "ใช้ผลเงินจริงเหรียญจริง ได้เสียจริง" — ไม้โหมดทดลองไม่ถูกอ่านเลย
 * (กระดาษสร้างผลสวยได้ฟรี: เปิดบอทสิบตัวแล้วเลือกตัวที่บังเอิญกำไร) และกำไรลอยของไม้ที่ยังไม่ปิดไม่ถูกนับ
 *
 * ผลตอบแทนคิดต่อ "ทุนที่บอทใช้จริง" = ค่ามากกว่าระหว่างทุนสูงสุดต่อไม้ที่ตั้งไว้ กับยอดที่เคยลงสะสมจริงสูงสุด
 * — ตั้งทุนให้เล็กลงทีหลังเพื่อให้เปอร์เซ็นต์ดูสูงขึ้นไม่ได้
 *
 * Developed by Xman Studio.
 */
final class TrackRecord
{
    public const MODE = 'live';

    /**
     * @return array{mode: string, closed: int, wins: int, win_rate: float|null, realized_pnl: float, capital_usd: float,
     *               return_pct: float, max_drawdown_usd: float, max_drawdown_pct: float, profit_factor: float|null,
     *               days: float, first_trade_at: string|null, last_trade_at: string|null}
     */
    public static function forBot(AiBotConfig $bot): array
    {
        $trades = AiBotTrade::where('ai_bot_config_id', $bot->id)
            ->where('mode', self::MODE)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'side', 'gross_value', 'realized_pnl', 'created_at']);

        $capital = (float) (($bot->risk ?? [])['max_position_usd'] ?? 0);
        $deployed = 0.0;
        $cumulative = 0.0;
        $peak = 0.0;
        $drawdown = 0.0;
        $closed = 0;
        $wins = 0;
        $grossWin = 0.0;
        $grossLoss = 0.0;

        foreach ($trades as $trade) {
            if ($trade->side === 'buy') {
                $deployed += (float) $trade->gross_value;
                $capital = max($capital, $deployed);

                continue;
            }

            $pnl = (float) $trade->realized_pnl;
            $deployed = 0.0;
            $closed++;
            $cumulative += $pnl;

            if ($pnl > 0) {
                $wins++;
                $grossWin += $pnl;
            } else {
                $grossLoss += abs($pnl);
            }

            $peak = max($peak, $cumulative);
            $drawdown = max($drawdown, $peak - $cumulative);
        }

        $first = $trades->first()?->created_at;
        $last = $trades->last()?->created_at;

        return [
            'mode' => self::MODE,
            'closed' => $closed,
            'wins' => $wins,
            'win_rate' => $closed > 0 ? round($wins / $closed * 100, 1) : null,
            'realized_pnl' => round($cumulative, 2),
            'capital_usd' => round($capital, 2),
            'return_pct' => $capital > 0 ? round($cumulative / $capital * 100, 2) : 0.0,
            'max_drawdown_usd' => round($drawdown, 2),
            'max_drawdown_pct' => $capital > 0 ? round($drawdown / $capital * 100, 2) : 0.0,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            // นับจากไม้เงินจริงไม้แรกถึงตอนนี้ — บอทที่สร้างไว้นานแต่เพิ่งเทรดจริงเมื่อวานยังไม่มีประวัติ
            'days' => $first ? round(max(0, $first->diffInMinutes(now())) / 1440, 1) : 0.0,
            'first_trade_at' => $first?->toIso8601String(),
            'last_trade_at' => $last?->toIso8601String(),
        ];
    }
}
