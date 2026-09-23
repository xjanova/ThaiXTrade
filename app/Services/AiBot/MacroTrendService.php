<?php

namespace App\Services\AiBot;

use App\Services\MarketDataService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — แนวโน้มใหญ่ของตลาดสำหรับบอทที่เดินสด (ตรรกะอยู่ที่ MacroTrend).
 *
 * บอททุกตัวถามทุกติ๊ก แต่คำตอบเปลี่ยนได้วันละครั้ง (ตอนแท่งรายวันปิด 00:00 UTC)
 * จึงแคชไว้ — และหมดอายุตรงรอยต่อวันพอดี ไม่งั้นบอทแท่ง 4 ชม. ที่ตัดสินตอน 00:00 UTC
 * จะเห็นสถานะของเมื่อวานไปอีกครึ่งชั่วโมง (ต่างจาก backtest ที่เห็นแท่งใหม่ทันที)
 *
 * ⚠️ ดึงข้อมูลไม่ได้ = up null = ไม่กรอง (ถอยไปใช้กฎเดิม) ไม่ใช่ "ขาลง"
 *    ตลาดตอบช้าครั้งเดียวต้องไม่ทำให้บอททุกตัวหยุดเปิดไม้พร้อมกัน — แคชความล้มเหลว
 *    แค่ 1 นาทีแล้วลองใหม่
 *
 * Developed by Xman Studio.
 */
class MacroTrendService
{
    public function __construct(private readonly MarketDataService $market) {}

    /**
     * @return array{up: bool|null, close: float|null, ema: float|null, above_pct: float|null, symbol: string, period: int}
     */
    public function current(): array
    {
        $symbol = (string) config('aibot.macro.symbol', 'BTC/USDT');
        $period = (int) config('aibot.macro.ema_period', 50);
        $key = "aibot:macro:{$symbol}:{$period}";

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->compute($symbol, $period) + ['symbol' => $symbol, 'period' => $period];

        $ttl = $result['up'] === null
            ? 60
            : max(60, min(1800, now('UTC')->copy()->addDay()->startOfDay()->diffInSeconds(now('UTC'), true)));

        Cache::put($key, $result, $ttl);

        return $result;
    }

    private function compute(string $symbol, int $period): array
    {
        try {
            // +1 เผื่อแท่งของวันที่กำลังวิ่งซึ่งถูกตัดทิ้งด้านล่าง
            $raw = $this->market->getKlines($symbol, '1d', MacroTrend::WINDOW + 1);
        } catch (\Throwable $e) {
            Log::warning('AI bot macro trend fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            $raw = [];
        }

        $nowMs = now()->getTimestamp() * 1000;

        // แท่งที่ยังไม่ปิด (วันนี้) ต้องไม่ถูกนับ — ราคาปิดของมันเปลี่ยนทุกวินาที
        $closed = array_values(array_filter(
            (array) $raw,
            fn ($c) => is_array($c) && isset($c['time'], $c['close']) && (int) $c['time'] + 86_400_000 <= $nowMs,
        ));

        return MacroTrend::assess($closed, $period);
    }
}
