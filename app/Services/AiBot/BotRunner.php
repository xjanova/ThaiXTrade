<?php

namespace App\Services\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotPosition;
use App\Services\AiBotService;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ตัวรันบอทหนึ่งรอบ.
 *
 * ลำดับการทำงานต่อบอทหนึ่งตัว (ห้ามสลับลำดับ):
 *   1. เจ้าของยังเช่าอยู่ไหม        — หมดอายุแล้วต้องหยุด ไม่ใช่เทรดฟรีต่อ
 *   2. ดึงแท่งเทียนจริง
 *   3. **ด่านความเสี่ยง**            — ตลาดพัง/ข่าวร้าย = เทออกก่อน ไม่ต้องถามกลยุทธ์
 *   4. **กรอบความเสี่ยงของผู้ใช้**   — stop loss / take profit / ขาดทุนสูงสุดต่อวัน
 *   5. ถามกลยุทธ์
 *   6. ลงมือผ่านโบรกเกอร์จำลอง
 *
 * ที่ให้ด่านความเสี่ยงมาก่อนกลยุทธ์เพราะกลยุทธ์ส่วนใหญ่ "ซื้อตอนราคาถูก" —
 * ในวันที่ตลาดพังจริงมันจะเห็นเป็นของถูกแล้วรับมีดตกตลอดทาง
 *
 * โหมด live ยังไม่ลงมือส่งธุรกรรมเอง — ระบบไม่ถือกุญแจของผู้ใช้ (non-custodial)
 * จึงบันทึกสัญญาณไว้ให้ผู้ใช้กดยืนยันเองในหน้าเว็บ
 *
 * Developed by Xman Studio.
 */
class BotRunner
{
    /** จำนวนแท่งต่อชั่วโมงของแต่ละ timeframe — ใช้แปลงรอบ DCA เป็นจำนวนแท่ง */
    private const BARS_PER_HOUR = ['1m' => 60, '5m' => 12, '15m' => 4, '1h' => 1, '4h' => 0.25, '1d' => 0.0417];

    public function __construct(
        private readonly MarketDataService $market,
        private readonly MarketRiskService $risk,
        private readonly StrategyRegistry $registry,
        private readonly PaperBroker $broker,
        private readonly AiBotService $bots,
    ) {}

    /**
     * รันบอทหนึ่งตัวหนึ่งรอบ.
     *
     * @return array{action: string, reason: string, risk?: string}
     */
    public function tick(AiBotConfig $bot): array
    {
        // 1) การเช่ายังไม่หมดอายุ
        if (! $this->bots->activeSubscription($bot->wallet_address)) {
            $bot->update(['status' => 'paused', 'last_reason' => 'การเช่าหมดอายุ — บอทถูกพักอัตโนมัติ']);

            return $this->record($bot, 'stopped', 'การเช่าหมดอายุ — บอทถูกพักอัตโนมัติ');
        }

        $strategy = $this->registry->find($bot->strategy);

        if (! $strategy) {
            return $this->record($bot, 'error', "ไม่รู้จักกลยุทธ์ {$bot->strategy}");
        }

        // 2) ข้อมูลตลาดจริง
        $candles = $this->candles($bot);

        if (count($candles) < 30) {
            return $this->record($bot, 'hold', 'ยังดึงแท่งเทียนของคู่นี้ไม่ได้');
        }

        $price = (float) $candles[count($candles) - 1]['close'];
        $position = AiBotPosition::where('ai_bot_config_id', $bot->id)->where('mode', $bot->mode)->first();
        $positionArray = $position ? ['qty' => (float) $position->quantity, 'entry' => (float) $position->entry_price] : null;

        // 3) ด่านความเสี่ยงจากตลาด + ข่าว
        $risk = $this->risk->assess($bot->pair, $candles);

        if ($risk['force_exit'] && $position) {
            $reason = 'ตลาดเข้าภาวะตื่นตระหนก — เทออกทั้งหมด: '.implode(' · ', array_slice($risk['reasons'], 0, 2));
            $this->execute($bot, 'sell', $price, 0, $reason, $risk);

            return $this->record($bot, 'sell', $reason, $risk['level']);
        }

        if ($risk['size_multiplier'] <= 0 && ! $position) {
            $reason = 'หยุดเข้าไม้ใหม่ชั่วคราว: '.(implode(' · ', array_slice($risk['reasons'], 0, 2)) ?: 'ความเสี่ยงสูง');

            return $this->record($bot, 'hold', $reason, $risk['level']);
        }

        // 4) กรอบความเสี่ยงของผู้ใช้เอง (มาก่อนกลยุทธ์ — stop ต้องชนะสัญญาณเสมอ)
        if ($position) {
            $guard = $this->checkUserRiskLimits($bot, $position, $price);

            if ($guard !== null) {
                $this->execute($bot, 'sell', $price, 0, $guard, $risk);

                return $this->record($bot, 'sell', $guard, $risk['level']);
            }
        }

        // 5) ถามกลยุทธ์
        $params = $this->paramsFor($bot, $candles, $position);
        $signal = $strategy->decide($candles, $params, $positionArray);

        if (! $signal->isActionable()) {
            return $this->record($bot, 'hold', $signal->reason, $risk['level']);
        }

        if ($signal->action === Signal::BUY && $position && ! $strategy->allowsPyramiding()) {
            return $this->record($bot, 'hold', 'ถือของอยู่แล้ว กลยุทธ์นี้ไม่เติมไม้', $risk['level']);
        }

        // 6) ลงมือ
        $budget = $this->budgetFor($bot, $signal->strength, $risk['size_multiplier']);
        $result = $this->execute($bot, $signal->action, $price, $budget, $signal->reason, $risk, $signal->meta);

        if (! ($result['ok'] ?? false)) {
            // แยก "มีสัญญาณแต่รอผู้ใช้ยืนยัน" ออกจาก "ตัดสินใจไม่ทำอะไร" ให้ชัด
            // ไม่งั้นหน้าเว็บจะแสดงเหมือนกันหมด แล้วผู้ใช้โหมดจริงจะไม่รู้เลยว่า
            // ต้องไปกดยืนยันอะไร — ซึ่งทำให้โหมดจริงไร้ประโยชน์
            $action = ($result['pending'] ?? false) ? 'signal' : 'hold';

            return $this->record($bot, $action, $result['reason'] ?? 'ลงมือไม่สำเร็จ', $risk['level']);
        }

        return $this->record($bot, $signal->action, $signal->reason, $risk['level']);
    }

    /**
     * ปิด/เปิดไม้ — โหมด live ยังไม่ส่งธุรกรรมเอง (ไม่ถือกุญแจผู้ใช้).
     */
    private function execute(AiBotConfig $bot, string $action, float $price, float $budget, string $reason, array $risk, array $meta = []): array
    {
        if ($bot->mode !== 'demo') {
            // บันทึกเป็นสัญญาณรอผู้ใช้ยืนยัน — ไม่แกล้งทำเป็นว่าเทรดไปแล้ว
            $bot->update(['last_signal_at' => now()]);

            // ส่งเหตุผลของกลยุทธ์กลับไปพร้อมป้ายกำกับ ไม่ใช่ข้อความระบบลอยๆ
            // ผู้ใช้ต้องเห็นว่า "บอทอยากให้ยืนยันอะไร" ถึงจะตัดสินใจได้
            $side = $action === Signal::BUY ? 'ซื้อ' : 'ขาย';

            return [
                'ok' => false,
                'pending' => true,
                'reason' => "[รอยืนยัน] บอทเสนอให้{$side} — {$reason}",
            ];
        }

        $context = ['reason' => $reason, 'meta' => $meta, 'risk_level' => $risk['level']];

        return $action === Signal::BUY
            ? $this->broker->buy($bot, $price, $budget, $context)
            : $this->broker->sell($bot, $price, $context);
    }

    /**
     * ตรวจกรอบความเสี่ยงที่ผู้ใช้ตั้งเอง.
     *
     * @return string|null เหตุผลที่ต้องปิดไม้ (null = ยังไม่ต้องปิด)
     */
    private function checkUserRiskLimits(AiBotConfig $bot, AiBotPosition $position, float $price): ?string
    {
        $risk = $bot->risk ?? [];
        $entry = (float) $position->entry_price;

        if ($entry <= 0) {
            return null;
        }

        $changePct = (($price - $entry) / $entry) * 100;

        $stopLoss = (float) ($risk['stop_loss_pct'] ?? 0);
        if ($stopLoss > 0 && $changePct <= -$stopLoss) {
            return sprintf('ถึงจุดตัดขาดทุนที่ตั้งไว้ (%.2f%%)', $changePct);
        }

        $takeProfit = (float) ($risk['take_profit_pct'] ?? 0);
        if ($takeProfit > 0 && $changePct >= $takeProfit) {
            return sprintf('ถึงเป้าทำกำไรที่ตั้งไว้ (+%.2f%%)', $changePct);
        }

        // ขาดทุนสะสมของวันนี้เกินเพดาน → ปิดไม้แล้วพักบอท
        $maxDailyLoss = (float) ($risk['max_daily_loss_usd'] ?? 0);
        if ($maxDailyLoss > 0) {
            $todayPnl = (float) $bot->trades()
                ->whereDate('created_at', today())
                ->where('mode', $bot->mode)
                ->sum('realized_pnl');

            $openPnl = $position->unrealizedPnl($price);

            if (($todayPnl + $openPnl) <= -$maxDailyLoss) {
                $bot->update(['status' => 'paused']);

                return sprintf('ขาดทุนสะสมวันนี้ถึงเพดาน $%.2f — ปิดไม้และพักบอท', $maxDailyLoss);
            }
        }

        return null;
    }

    /** งบของไม้นี้ = ทุนสูงสุดต่อไม้ × ความแรงสัญญาณ × ตัวคูณความเสี่ยง */
    private function budgetFor(AiBotConfig $bot, float $strength, float $riskMultiplier): float
    {
        $maxPosition = (float) (($bot->risk ?? [])['max_position_usd'] ?? 100);

        return round($maxPosition * $strength * $riskMultiplier, 2);
    }

    /** พารามิเตอร์ที่กลยุทธ์ต้องใช้ + ตัวช่วยที่ engine เป็นคนรู้ (เช่นรอบของ DCA) */
    private function paramsFor(AiBotConfig $bot, array $candles, ?AiBotPosition $position): array
    {
        $params = $bot->params ?? [];

        if ($bot->strategy === 'dca') {
            $barsPerHour = self::BARS_PER_HOUR[$bot->timeframe] ?? 1;
            $params['_interval_bars'] = max(1, (int) round(((float) ($params['interval_hours'] ?? 24)) * $barsPerHour));

            $lastBuy = $bot->trades()->where('mode', $bot->mode)->where('side', 'buy')->latest('created_at')->first();
            $params['_bars_since_entry'] = $lastBuy
                ? $this->barsSince($candles, $lastBuy->created_at->getTimestamp() * 1000)
                : PHP_INT_MAX;
        }

        return $params;
    }

    /** นับจำนวนแท่งตั้งแต่เวลาที่กำหนด (เวลาเป็นมิลลิวินาทีเหมือนที่ Binance ส่งมา) */
    private function barsSince(array $candles, int $sinceMs): int
    {
        $count = 0;

        foreach (array_reverse($candles) as $candle) {
            if ((int) $candle['time'] < $sinceMs) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /** @return list<array> แท่งเทียนจริงในรูปแบบตัวเลข */
    private function candles(AiBotConfig $bot): array
    {
        try {
            $raw = $this->market->getKlines($bot->pair, $bot->timeframe, 150);
        } catch (\Throwable $e) {
            Log::warning('AI bot klines failed', ['bot' => $bot->id, 'error' => $e->getMessage()]);

            return [];
        }

        return array_map(fn ($c) => [
            'time' => (int) $c['time'],
            'open' => (float) $c['open'],
            'high' => (float) $c['high'],
            'low' => (float) $c['low'],
            'close' => (float) $c['close'],
            'volume' => (float) $c['volume'],
        ], $raw);
    }

    /** บันทึกผลรอบนี้ไว้ที่บอท เพื่อให้ผู้ใช้เห็นว่าบอทกำลังคิดอะไร */
    private function record(AiBotConfig $bot, string $action, string $reason, string $riskLevel = 'calm'): array
    {
        $bot->update([
            'last_run_at' => now(),
            'last_reason' => $reason,
            'stats' => array_merge($bot->stats ?? [], ['last_action' => $action, 'last_risk' => $riskLevel]),
        ]);

        return ['action' => $action, 'reason' => $reason, 'risk' => $riskLevel];
    }
}
