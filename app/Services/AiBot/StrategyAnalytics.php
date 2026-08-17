<?php

namespace App\Services\AiBot;

use App\Models\AiBotTrade;
use Illuminate\Support\Collection;

/**
 * TPIX TRADE — สรุปผลงานย้อนหลังของแต่ละกลยุทธ์.
 *
 * เจ้าของต้องการให้ "ประวัติบันทึกเป๊ะ แล้วนำกลับมาวิเคราะห์เพื่อตัดสินใจในอนาคตได้"
 * ตัวนี้คือชั้นที่แปลงประวัติดิบเป็นตัวเลขที่ตัดสินใจต่อได้จริง
 *
 * หลักการที่ยึด:
 *
 * 1. นับเฉพาะไม้ที่ปิดแล้ว — กำไรลอยยังไม่ใช่เงิน เอามารวมแล้วตัวเลขจะสวยเกินจริง
 *
 * 2. ต้นทุนต้องอยู่ในสมการเสมอ (ค่าธรรมเนียม + slippage)
 *    กลยุทธ์ที่ "ชนะ 70%" แต่กำไรต่อไม้น้อยกว่าค่าธรรมเนียม คือกลยุทธ์ที่ขาดทุน
 *    อัตราชนะอย่างเดียวจึงหลอกได้ง่ายที่สุดในบรรดาตัวเลขทั้งหมด
 *
 * 3. แยกผลตามระดับความเสี่ยงของตลาดตอนเข้าไม้
 *    ตอบคำถามที่มีค่าที่สุดข้อหนึ่ง: "เทรดตอนตลาดผันผวนแล้วได้หรือเสีย"
 *    ถ้าไม้ที่เปิดตอน elevated ขาดทุนตลอด แปลว่าควรรัดด่านความเสี่ยงให้แน่นขึ้น
 *
 * Developed by Xman Studio.
 */
class StrategyAnalytics
{
    /**
     * สรุปผลงานของ wallet หนึ่ง แยกตามกลยุทธ์.
     *
     * @return array{overall: array, by_strategy: list<array>, by_pair: list<array>, by_risk: list<array>}
     */
    public function forWallet(string $wallet, string $mode = 'demo'): array
    {
        $trades = AiBotTrade::where('wallet_address', strtolower($wallet))
            ->where('mode', $mode)
            ->orderBy('created_at')
            ->get();

        return [
            'overall' => $this->summarize($trades),
            'by_strategy' => $this->groupBy($trades, 'strategy'),
            'by_pair' => $this->groupBy($trades, 'pair'),
            'by_risk' => $this->groupBy($trades, 'risk_level'),
        ];
    }

    /** จัดกลุ่มแล้วสรุปทีละกลุ่ม เรียงจากกำไรมากไปน้อย */
    private function groupBy(Collection $trades, string $key): array
    {
        return $trades
            ->groupBy($key)
            ->map(fn (Collection $group, $name) => array_merge(
                ['key' => (string) $name],
                $this->summarize($group),
            ))
            ->sortByDesc('realized_pnl')
            ->values()
            ->all();
    }

    /**
     * ตัวเลขสรุปของกลุ่มไม้หนึ่งชุด.
     */
    private function summarize(Collection $trades): array
    {
        // ไม้ที่ปิดแล้วเท่านั้นถึงรู้ผลกำไรขาดทุนจริง
        $closed = $trades->filter(fn ($t) => $t->realized_pnl !== null);

        $wins = $closed->filter(fn ($t) => (float) $t->realized_pnl > 0);
        $losses = $closed->filter(fn ($t) => (float) $t->realized_pnl < 0);

        $grossWin = (float) $wins->sum('realized_pnl');
        $grossLoss = abs((float) $losses->sum('realized_pnl'));

        $closedCount = $closed->count();
        $winRate = $closedCount > 0 ? $wins->count() / $closedCount : null;

        $avgWin = $wins->count() > 0 ? $grossWin / $wins->count() : 0.0;
        $avgLoss = $losses->count() > 0 ? $grossLoss / $losses->count() : 0.0;

        return [
            'trades' => $trades->count(),
            'closed' => $closedCount,
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'win_rate' => $winRate === null ? null : round($winRate * 100, 1),

            'realized_pnl' => round((float) $closed->sum('realized_pnl'), 2),
            'avg_pnl' => $closedCount > 0 ? round((float) $closed->sum('realized_pnl') / $closedCount, 4) : null,
            'best_trade' => $closedCount > 0 ? round((float) $closed->max('realized_pnl'), 2) : null,
            'worst_trade' => $closedCount > 0 ? round((float) $closed->min('realized_pnl'), 2) : null,

            // ต้นทุนที่กินกำไรเงียบๆ — ต้องโชว์คู่กับกำไรเสมอ
            'total_fees' => round((float) $trades->sum('fee'), 4),
            'total_slippage' => round((float) $trades->sum('slippage_cost'), 4),
            'total_cost' => round((float) $trades->sum('fee') + (float) $trades->sum('slippage_cost'), 4),

            /*
             * profit factor = กำไรรวม ÷ ขาดทุนรวม
             * > 1 คือทำเงินได้ · ตีความง่ายกว่าอัตราชนะมาก เพราะรวมขนาดของไม้เข้าไปด้วย
             * ยังไม่มีไม้ขาดทุนเลย = ยังตัดสินไม่ได้ (null) ไม่ใช่ "ดีเลิศ"
             */
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,

            /*
             * expectancy = กำไรคาดหวังต่อไม้
             * ตัวเลขเดียวที่ตอบว่า "เล่นกลยุทธ์นี้ต่อไปเรื่อยๆ แล้วคุ้มไหม"
             */
            'expectancy' => $closedCount > 0
                ? round((($winRate ?? 0) * $avgWin) - ((1 - ($winRate ?? 0)) * $avgLoss), 4)
                : null,

            'max_drawdown' => $this->maxDrawdown($closed),
            'first_trade_at' => $trades->first()?->created_at?->toIso8601String(),
            'last_trade_at' => $trades->last()?->created_at?->toIso8601String(),
        ];
    }

    /**
     * ขาดทุนสูงสุดจากยอดสูงสุดที่เคยทำได้ (peak-to-trough).
     *
     * ตัวเลขที่บอกว่า "แย่ที่สุดที่เคยเจอคือเท่าไหร่" — สำคัญกว่ากำไรรวม
     * เพราะเป็นตัวที่ทำให้คนเลิกใช้บอทกลางทาง ไม่ใช่ผลตอบแทนที่ต่ำ
     */
    private function maxDrawdown(Collection $closed): ?float
    {
        if ($closed->isEmpty()) {
            return null;
        }

        $running = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($closed as $trade) {
            $running += (float) $trade->realized_pnl;
            $peak = max($peak, $running);
            $maxDrawdown = max($maxDrawdown, $peak - $running);
        }

        return round($maxDrawdown, 2);
    }
}
