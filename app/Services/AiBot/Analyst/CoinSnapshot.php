<?php

namespace App\Services\AiBot\Analyst;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\MarketRegime;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — "เครื่องมือ" ที่ AI ยังไม่เคยมี: ภาพราคาของเหรียญหนึ่งตัวในตัวเลขไม่กี่ตัว.
 *
 * ออดิท 2 ก.ย. 2026: AI ได้แค่ราคาปัจจุบัน + %24 ชม. + จำนวนข่าว แล้วต้องทาย 4-24 ชม.
 * ข้างหน้า — เหมือนให้คนอ่านพาดหัวข่าวโดยไม่ให้ดูกราฟ buy 21 ครั้งราคาไปต่อ −44 bps
 * ตัวนี้เติมสิ่งที่นักวิเคราะห์คนดูก่อนเสมอ: เทรนด์ใหญ่ · ระยะจากจุดสูง/ต่ำ ·
 * ความผันผวน · RSI · efficiency ratio · funding rate
 *
 * ทุกอย่างมาจากแท่ง 4 ชม. 200 แท่ง (~33 วัน) — พอสำหรับ EMA/ระยะ 30 วัน และ
 * หนึ่งคำขอต่อเหรียญ แคช 30 นาที (รอบใหญ่ห่าง 4 ชม.) · ล้มเหลว = ค่า null ไม่ใช่ throw
 * เพราะรอบวิเคราะห์ต้องเดินต่อได้แม้ตลาดตอบช้าไปสองสามเหรียญ
 *
 * Developed by Xman Studio.
 */
class CoinSnapshot
{
    public function __construct(private readonly MarketDataService $market) {}

    /**
     * @return array{
     *     change_7d_pct: float|null, change_30d_pct: float|null,
     *     from_30d_high_pct: float|null, from_30d_low_pct: float|null,
     *     atr_pct_4h: float|null, rsi_4h: float|null, trend: string|null, er: float|null,
     *     funding_rate_pct: float|null
     * }
     */
    public function for(string $symbol): array
    {
        $key = 'aibot:snapshot:'.strtoupper($symbol);

        return Cache::remember($key, now()->addMinutes(30), function () use ($symbol) {
            $snapshot = $this->technicals($symbol);
            $snapshot['funding_rate_pct'] = $this->market->getFundingRate("{$symbol}/USDT");

            return $snapshot;
        });
    }

    /** คำนวณจากแท่ง 4 ชม. — pure หลังจากได้แท่งมาแล้ว (ทดสอบด้วยแท่งปลอมได้) */
    public function technicals(string $symbol): array
    {
        $empty = [
            'change_7d_pct' => null, 'change_30d_pct' => null,
            'from_30d_high_pct' => null, 'from_30d_low_pct' => null,
            'atr_pct_4h' => null, 'rsi_4h' => null, 'trend' => null, 'er' => null,
        ];

        try {
            $raw = $this->market->getKlines("{$symbol}/USDT", '4h', 200);
        } catch (\Throwable) {
            return $empty;
        }

        if (! is_array($raw) || count($raw) < 50) {
            return $empty;
        }

        // ตัดแท่งที่ยังวิ่งอยู่ทิ้ง เหมือน BotRunner — ราคาปิดของมันเปลี่ยนทุกวินาที
        $candles = array_map(fn ($c) => [
            'close' => (float) $c['close'], 'high' => (float) $c['high'], 'low' => (float) $c['low'],
        ], array_slice($raw, 0, -1));

        $closes = array_column($candles, 'close');
        $count = count($closes);
        $close = $closes[$count - 1];

        $window30d = array_slice($candles, -180);   // 180 แท่ง × 4 ชม. = 30 วัน
        $high30 = max(array_column($window30d, 'high'));
        $low30 = min(array_column($window30d, 'low'));

        $regime = MarketRegime::assess($candles);
        $rsi = Indicators::last(Indicators::rsi($closes, 14));

        return [
            'change_7d_pct' => round(Indicators::changePct($closes, min(42, $count - 1)), 2),
            'change_30d_pct' => round(Indicators::changePct($closes, min(180, $count - 1)), 2),
            'from_30d_high_pct' => $high30 > 0 ? round(($close / $high30 - 1) * 100, 2) : null,
            'from_30d_low_pct' => $low30 > 0 ? round(($close / $low30 - 1) * 100, 2) : null,
            'atr_pct_4h' => $regime['atr_pct'],
            'rsi_4h' => $rsi === null ? null : round($rsi, 1),
            'trend' => $regime['trend'],
            'er' => $regime['er'],
        ];
    }
}
