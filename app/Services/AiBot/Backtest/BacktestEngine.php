<?php

namespace App\Services\AiBot\Backtest;

use App\Services\AiBot\MarketRiskService;
use App\Services\AiBot\PositionSizer;
use App\Services\AiBot\Signal;
use App\Services\AiBot\StrategyRegistry;
use App\Services\AiBot\Timeframe;
use App\Services\AiBotService;

/**
 * TPIX TRADE — เล่นแท่งเทียนย้อนหลังผ่านกลยุทธ์ตัวจริง ด้วยกติกาเดียวกับบอทที่เดินสด.
 *
 * ═══ ทำไมต้องมี ═══
 * ออดิท 21 ส.ค. – 2 ก.ย. 2026 พิสูจน์ว่าการ "รันจริงแล้วรอดู" ใช้เวลาเป็นเดือน
 * ต่อหนึ่งคำถาม (RSI 30 หรือ 35? EMA 12/26 หรือ 8/21?) และคำตอบที่ได้ก็ยัง
 * มีตัวอย่างไม่พอจะสรุป (mean_reversion ยิง 1 ไม้ใน 13 วัน) — ตัวนี้ตอบคำถาม
 * เดียวกันจากข้อมูล 90 วันในไม่กี่วินาที
 *
 * ═══ สิ่งที่เหมือนบอทจริง (ต้องเหมือน ไม่งั้นผลเชื่อไม่ได้) ═══
 *   - กลยุทธ์ตัวเดียวกัน (StrategyRegistry) ด้วย params ที่ผ่าน sanitizeParams เหมือนกัน
 *   - เห็นเฉพาะแท่งที่ปิดแล้ว และเห็นหน้าต่างขนาดเท่ากับที่ BotRunner ดึง (≤ 500 แท่ง)
 *   - ด่านความเสี่ยงจากราคา (MarketRiskService ตัวจริง — ไม่มีข่าวเพราะไม่มีข่าวย้อนหลัง)
 *   - กรอบผู้ใช้: stop loss / take profit / เพดานขาดทุนต่อวัน ลำดับเดียวกัน
 *   - ขนาดไม้จาก PositionSizer ตัวเดียวกัน · ต้นทุนจาก SimBroker ที่เท่ากับ PaperBroker
 *   - ตัวช่วยของ engine: รอบของ DCA · พักระหว่างไม้ของสแกลป์ คิดจากเวลาของแท่ง
 *
 * ═══ สิ่งที่ไม่มี (บอกไว้ให้ชัด) ═══
 *   - ด่านข่าว และมุมมอง AI — ไม่มีประวัติย้อนหลังให้เล่นซ้ำ ผลจึงเป็น "กฎล้วน"
 *     ซึ่งตรงกับบอทที่ปิด news_filter และ ai_gate
 *   - ราคาระหว่างแท่ง — stop ทำงานที่ราคาปิดของแท่ง เหมือนบอทจริงที่ดูราคาปิดเช่นกัน
 *
 * Developed by Xman Studio.
 */
class BacktestEngine
{
    public function __construct(
        private readonly StrategyRegistry $registry,
        private readonly AiBotService $bots,
        private readonly MarketRiskService $risk,
    ) {}

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $candles  แท่งปิดแล้ว เก่า→ใหม่ (รวมช่วงอุ่นเครื่อง)
     * @param  array  $params  พารามิเตอร์ดิบของกลยุทธ์ (จะถูก sanitize ให้)
     * @param  array  $risk  กรอบความเสี่ยงดิบ (จะถูก sanitize ให้)
     * @param  array{starting_balance?: float, fee_rate?: float, slippage_bps?: float, risk_gate?: bool, start_index?: int}  $options
     * @return array{summary: array, trades: list<array>, equity: list<array{time:int,equity:float,price:float}>, warmup: int, bars: int}
     */
    public function run(string $strategyCode, array $candles, string $timeframe, array $params = [], array $risk = [], array $options = []): array
    {
        $strategy = $this->registry->find($strategyCode);

        if (! $strategy) {
            throw new \InvalidArgumentException("ไม่รู้จักกลยุทธ์ {$strategyCode}");
        }

        $clean = $this->bots->sanitizeParams($strategyCode, $params);
        $riskCfg = $this->bots->sanitizeRisk($risk);
        $minutesPerBar = Timeframe::minutes($timeframe);

        // หน้าต่างเท่ากับที่ BotRunner::candles() ดึงจริง — ตัวชี้วัดที่ seed ด้วย SMA
        // (EMA/RSI) ให้ค่าต่างกันเล็กน้อยตามความยาวหน้าต่าง ต้องเท่ากันถึงจะเทียบได้
        $window = max(150, min(500, $strategy->minCandles($clean) + 31));

        $feeRate = (float) ($options['fee_rate'] ?? config('aibot_risk.demo.fee_rate', 0.1)) / 100;
        $slippage = (float) ($options['slippage_bps'] ?? config('aibot_risk.demo.slippage_bps', 8)) / 10000;
        $starting = (float) ($options['starting_balance'] ?? config('aibot_risk.demo.starting_balance', 10000));
        $useRiskGate = (bool) ($options['risk_gate'] ?? true);

        $broker = new SimBroker($starting, $feeRate, $slippage);
        $entryCostFactor = 1 + ($this->bots->roundTripCostBps() / 2) / 10000;

        $total = count($candles);
        $start = max((int) ($options['start_index'] ?? 0), min($window, $total) - 1);

        $lastBuyIndex = null;
        $lastTradeIndex = null;
        $pausedDay = null;          // เพดานขาดทุนรายวันชน → พักถึงวันถัดไป (เหมือน BotRunner พักบอท)
        $dailyPnl = [];             // realized ต่อวัน สำหรับเพดานขาดทุน
        $equity = [];
        $barsInMarket = 0;
        $maxDeployed = 0.0;         // ต้นทุนสะสมสูงสุดที่เคยถือ — เทียบกับเพดานทุนที่ตั้ง
        $decisionCounts = ['buy' => 0, 'sell' => 0, 'hold' => 0, 'blocked' => 0];

        for ($i = $start; $i < $total; $i++) {
            $slice = array_slice($candles, max(0, $i + 1 - $window), $window);
            $bar = $candles[$i];
            $price = (float) $bar['close'];
            $time = (int) $bar['time'];
            $day = (int) floor($time / 86_400_000);

            if ($broker->position) {
                $barsInMarket++;
            }

            $positionArray = $broker->position ? [
                'qty' => $broker->position['qty'],
                'entry' => $broker->entryPrice(),
                'entry_market' => $broker->entryPrice() / $entryCostFactor,
            ] : null;

            // 3) ด่านความเสี่ยงจากราคา (ไม่มีข่าวใน backtest)
            $sizeMultiplier = 1.0;

            if ($useRiskGate) {
                $gate = $this->risk->assess('BACKTEST/USDT', array_slice($slice, -120), $timeframe, false);

                if ($gate['force_exit'] && $broker->position) {
                    $reason = 'ตลาดเข้าภาวะตื่นตระหนก — เทออกทั้งหมด: '.implode(' · ', array_slice($gate['reasons'], 0, 2));
                    $this->sell($broker, $i, $time, $price, $reason, $dailyPnl, $day);
                    $lastTradeIndex = $i;
                    $decisionCounts['sell']++;
                    $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                    continue;
                }

                if ($gate['size_multiplier'] <= 0 && ! $broker->position) {
                    $decisionCounts['blocked']++;
                    $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                    continue;
                }

                $sizeMultiplier = (float) $gate['size_multiplier'];
            }

            // 4) กรอบความเสี่ยงของผู้ใช้ — stop ชนะสัญญาณเสมอ
            if ($broker->position) {
                $guard = $this->userLimit($broker, $riskCfg, $price, $dailyPnl[$day] ?? 0.0);

                if ($guard !== null) {
                    $this->sell($broker, $i, $time, $price, $guard['reason'], $dailyPnl, $day);
                    $lastTradeIndex = $i;
                    $decisionCounts['sell']++;

                    if ($guard['pause']) {
                        $pausedDay = $day;
                    }

                    $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                    continue;
                }
            }

            // 4.1) ทะลุเพดานขาดทุนของวันแล้ว = ห้ามเปิดไม้ใหม่ทั้งวัน
            if (! $broker->position) {
                $maxDailyLoss = (float) ($riskCfg['max_daily_loss_usd'] ?? 0);

                if ($pausedDay === $day || ($maxDailyLoss > 0 && ($dailyPnl[$day] ?? 0.0) <= -$maxDailyLoss)) {
                    $pausedDay = $day;
                    $decisionCounts['blocked']++;
                    $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                    continue;
                }
            }

            // 5) ถามกลยุทธ์ — พร้อมตัวช่วยที่ engine เป็นคนรู้ (เหมือน BotRunner::paramsFor)
            $runParams = $clean;

            if ($strategyCode === 'dca') {
                $runParams['_interval_bars'] = max(1, (int) round(((float) ($clean['interval_hours'] ?? 24)) * 60 / $minutesPerBar));
                $runParams['_bars_since_entry'] = $lastBuyIndex === null ? PHP_INT_MAX : $i - $lastBuyIndex;
            }

            if ($strategyCode === 'scalping') {
                $runParams['_seconds_since_trade'] = $lastTradeIndex === null
                    ? PHP_INT_MAX
                    : (int) (($time - (int) $candles[$lastTradeIndex]['time']) / 1000);
            }

            $signal = $strategy->decide($slice, $runParams, $positionArray);

            if (! $signal->isActionable()) {
                $decisionCounts['hold']++;
                $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                continue;
            }

            if ($signal->action === Signal::BUY && $broker->position && ! $strategy->allowsPyramiding()) {
                $decisionCounts['hold']++;
                $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                continue;
            }

            // 6) ลงมือ
            if ($signal->action === Signal::BUY) {
                $budget = PositionSizer::budget($strategyCode, $riskCfg, $clean, $signal->strength, $sizeMultiplier, $params);

                /*
                 * เพดานทุนต่อไม้คุม "ยอดรวมที่ถืออยู่" — เหมือน BotRunner
                 * (backtest ของ DCA คือตัวที่เปิดโปงว่าเดิมไม่มีเพดานนี้: อยู่ในตลาด
                 *  98% ของเวลา ต้นทุนสะสมทะลุเพดานที่ผู้ใช้ตั้งไปหลายเท่า)
                 */
                if ($broker->position) {
                    $room = (float) ($riskCfg['max_position_usd'] ?? 100) - $broker->position['cost'];

                    if ($room < 1.0) {
                        $decisionCounts['blocked']++;
                        $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];

                        continue;
                    }

                    $budget = min($budget, round($room, 2));
                }

                if ($broker->buy($i, $time, $price, $budget, $signal->reason)) {
                    $lastBuyIndex = $i;
                    $lastTradeIndex = $i;
                    $decisionCounts['buy']++;
                    $maxDeployed = max($maxDeployed, $broker->position['cost']);
                }
            } elseif ($signal->action === Signal::SELL && $broker->position) {
                $this->sell($broker, $i, $time, $price, $signal->reason, $dailyPnl, $day);
                $lastTradeIndex = $i;
                $decisionCounts['sell']++;
            }

            $equity[] = ['time' => $time, 'equity' => $broker->equity($price), 'price' => $price];
        }

        $lastPrice = $total > 0 ? (float) $candles[$total - 1]['close'] : 0.0;
        $firstPrice = $total > $start ? (float) $candles[$start]['close'] : 0.0;

        return [
            'summary' => $this->summarize($broker, $equity, $starting, $firstPrice, $lastPrice, $total - $start, $barsInMarket, $decisionCounts, $minutesPerBar, $riskCfg, $maxDeployed),
            'trades' => $broker->trades,
            'equity' => $equity,
            'warmup' => $start,
            'bars' => $total - $start,
            'params' => $clean,
            'risk' => $riskCfg,
        ];
    }

    /** ปิดไม้ + บันทึก realized ลงวันนั้น */
    private function sell(SimBroker $broker, int $i, int $time, float $price, string $reason, array &$dailyPnl, int $day): void
    {
        $pnl = $broker->sell($i, $time, $price, $reason);

        if ($pnl !== null) {
            $dailyPnl[$day] = ($dailyPnl[$day] ?? 0.0) + $pnl;
        }
    }

    /**
     * กรอบผู้ใช้ — ลำดับและสูตรเดียวกับ BotRunner::checkUserRiskLimits.
     *
     * @return array{reason: string, pause: bool}|null
     */
    private function userLimit(SimBroker $broker, array $risk, float $price, float $todayRealized): ?array
    {
        $entry = $broker->entryPrice();

        if ($entry === null || $entry <= 0) {
            return null;
        }

        $changePct = (($price - $entry) / $entry) * 100;

        $stopLoss = (float) ($risk['stop_loss_pct'] ?? 0);
        if ($stopLoss > 0 && $changePct <= -$stopLoss) {
            return ['reason' => sprintf('ถึงจุดตัดขาดทุนที่ตั้งไว้ (%.2f%%)', $changePct), 'pause' => false];
        }

        $takeProfit = (float) ($risk['take_profit_pct'] ?? 0);
        if ($takeProfit > 0 && $changePct >= $takeProfit) {
            return ['reason' => sprintf('ถึงเป้าทำกำไรที่ตั้งไว้ (+%.2f%%)', $changePct), 'pause' => false];
        }

        $maxDailyLoss = (float) ($risk['max_daily_loss_usd'] ?? 0);
        if ($maxDailyLoss > 0 && ($todayRealized + $broker->unrealizedPnl($price)) <= -$maxDailyLoss) {
            return ['reason' => sprintf('ขาดทุนสะสมวันนี้ถึงเพดาน $%.2f — ปิดไม้และพักบอท', $maxDailyLoss), 'pause' => true];
        }

        return null;
    }

    /**
     * ตัวเลขที่ตัดสินได้ว่า "เล่นต่อแล้วคุ้มไหม" — สูตรเดียวกับ StrategyAnalytics.
     */
    private function summarize(SimBroker $broker, array $equity, float $starting, float $firstPrice, float $lastPrice, int $bars, int $barsInMarket, array $counts, int $minutesPerBar, array $riskCfg = [], float $maxDeployed = 0.0): array
    {
        $sells = array_values(array_filter($broker->trades, fn ($t) => $t['side'] === 'sell'));
        $closed = count($sells);
        $wins = array_values(array_filter($sells, fn ($t) => $t['realized_pnl'] > 0));
        $losses = array_values(array_filter($sells, fn ($t) => $t['realized_pnl'] < 0));

        $realized = array_sum(array_column($sells, 'realized_pnl'));
        $fees = array_sum(array_column($broker->trades, 'fee'));
        $slip = array_sum(array_column($broker->trades, 'slippage_cost'));
        $costs = $fees + $slip;

        $grossWin = array_sum(array_column($wins, 'realized_pnl'));
        $grossLoss = abs(array_sum(array_column($losses, 'realized_pnl')));
        $winRate = $closed > 0 ? count($wins) / $closed : null;
        $avgWin = $wins !== [] ? $grossWin / count($wins) : 0.0;
        $avgLoss = $losses !== [] ? $grossLoss / count($losses) : 0.0;

        /*
         * edge (bps) = กำไรก่อนหักต้นทุนของไม้ที่ปิดแล้ว ÷ เงินที่ลงไปทั้งหมด × 10,000
         *
         * ถ่วงด้วยเงิน ไม่ใช่เฉลี่ยรายไม้ — ไม้ที่ลงเงินต่างกันมาก (DCA เติมไม้จน
         * ต้นทุนเป็นสิบเท่าของไม้เดี่ยว) ทำให้ค่าเฉลี่ยรายไม้ติดลบทั้งที่กำไรรวมเป็นบวก
         * ตัวเลขนี้จึงตอบตรงๆ ว่า "ทุกดอลลาร์ที่ลงไป ได้กลับมากี่ bps ก่อนจ่ายตลาด"
         * และเทียบกับต้นทุนไป-กลับ (36 bps) ได้ตรงๆ — ต่ำกว่า = แพ้โดยโครงสร้าง
         * ไม่ว่าจะจูนยังไง (บทเรียนสแกลป์: edge 0.2 bps บน 2,242 ไม้)
         *
         * ต้นทุนขาซื้อมาจากที่ SimBroker สะสมไว้ในไม้นั้นจริงๆ ไม่ใช่ประมาณ
         */
        $deployed = 0.0;
        $grossOnClosed = 0.0;
        foreach ($sells as $t) {
            $deployed += (float) ($t['cost_basis'] ?? 0);
            $grossOnClosed += (float) $t['realized_pnl'] + (float) $t['fee'] + (float) $t['slippage_cost'] + (float) ($t['buy_costs'] ?? 0);
        }
        $edgeBps = $deployed > 0 ? ($grossOnClosed / $deployed) * 10000 : null;

        // max drawdown จากเส้นมูลค่าพอร์ต (ไม่ใช่แค่ realized) — คือสิ่งที่คนเลิกใช้บอทเพราะมัน
        $peak = $starting;
        $maxDd = 0.0;
        $maxDdPct = 0.0;
        foreach ($equity as $point) {
            $peak = max($peak, $point['equity']);
            $dd = $peak - $point['equity'];
            if ($dd > $maxDd) {
                $maxDd = $dd;
                $maxDdPct = $peak > 0 ? $dd / $peak * 100 : 0.0;
            }
        }

        $finalEquity = $equity !== [] ? $equity[count($equity) - 1]['equity'] : $starting;
        $days = max(1e-9, $bars * $minutesPerBar / 1440);
        $unrealized = $broker->unrealizedPnl($lastPrice);

        return [
            'bars' => $bars,
            'days' => round($days, 1),
            'trades' => count($broker->trades),
            'closed' => $closed,
            'wins' => count($wins),
            'losses' => count($losses),
            'win_rate' => $winRate === null ? null : round($winRate * 100, 1),
            'realized_pnl' => round($realized, 2),
            'unrealized_pnl' => round($unrealized, 2),
            'fees' => round($fees, 2),
            'slippage' => round($slip, 2),
            'costs' => round($costs, 2),
            'gross_pnl' => round($realized + $costs, 2),
            'avg_win' => round($avgWin, 4),
            'avg_loss' => round($avgLoss, 4),
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            'expectancy' => $closed > 0 ? round((($winRate ?? 0) * $avgWin) - ((1 - ($winRate ?? 0)) * $avgLoss), 4) : null,
            'edge_bps' => $edgeBps === null ? null : round($edgeBps, 1),
            'cost_bps' => round($this->bots->roundTripCostBps(), 1),
            'max_drawdown' => round($maxDd, 2),
            'max_drawdown_pct' => round($maxDdPct, 2),
            'exposure_pct' => $bars > 0 ? round($barsInMarket / $bars * 100, 1) : 0.0,
            'return_pct' => $starting > 0 ? round(($finalEquity - $starting) / $starting * 100, 3) : 0.0,
            /*
             * ผลตอบแทนต่อ "ทุนที่ให้บอทใช้" (max_position_usd) — ตัวเลขที่เทียบกับ
             * ถือเฉยๆ ได้จริง: พอร์ตทดลอง 10,000 แต่บอทถูกจำกัดไม้ละ 100 ผลตอบแทน
             * ทั้งพอร์ตจึงดูเป็นศูนย์เสมอ ไม่ได้บอกว่ากลยุทธ์ดีหรือแย่
             */
            'capital_return_pct' => ($riskCfg['max_position_usd'] ?? 0) > 0
                ? round(($realized + $unrealized) / (float) $riskCfg['max_position_usd'] * 100, 2)
                : null,
            'max_deployed' => round($maxDeployed, 2),
            'buy_hold_pct' => $firstPrice > 0 ? round(($lastPrice / $firstPrice - 1) * 100, 2) : null,
            'trades_per_day' => round($closed / $days, 2),
            'avg_hold_bars' => $sells !== [] ? round(array_sum(array_column($sells, 'held_bars')) / count($sells), 1) : null,
            'decisions' => $counts,
            'final_equity' => round($finalEquity, 2),
        ];
    }
}
