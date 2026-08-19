<?php

namespace App\Console\Commands;

use App\Services\AiBot\Signal;
use App\Services\AiBot\StrategyRegistry;
use App\Services\AiBotService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ยิงกลยุทธ์ใส่สภาพตลาดที่รู้คำตอบอยู่แล้ว แล้วดูว่ามันตัดสินใจอะไร.
 *
 * มีไว้ให้เจ้าของกดทดสอบเองซ้ำได้ทุกเมื่อ ก่อนตัดสินใจเปิดขาย —
 * ไม่ต้องรอให้ตลาดจริงวิ่งผ่านสถานการณ์นั้น และได้ผลเหมือนเดิมทุกครั้งที่รัน
 *
 *   php artisan aibot:probe            → ทุกกลยุทธ์ ทุกสถานการณ์
 *   php artisan aibot:probe grid       → เฉพาะกลยุทธ์เดียว
 *   php artisan aibot:probe --repeat=5 → รันซ้ำ 5 รอบ เช็คว่าผลคงที่ไม่แกว่ง
 *
 * ⚠️ ตัวนี้ทดสอบ "การตัดสินใจของกลยุทธ์" อย่างเดียว ไม่ได้ทดสอบด่านความเสี่ยง
 *    หรือการตัดเครดิต ซึ่งอยู่ใน BotRunner คนละชั้นกัน
 *
 * Developed by Xman Studio.
 */
class AiBotProbe extends Command
{
    protected $signature = 'aibot:probe {strategy? : รหัสกลยุทธ์ เช่น grid, dca, ai_signal}
                            {--repeat=1 : รันซ้ำกี่รอบ (ใช้เช็คว่าผลคงที่)}
                            {--with-position : ทดสอบตอนที่ถือของอยู่แล้ว (ดูว่าออกไม้ได้ไหม)}
                            {--assert : ล้มเหลวถ้ามีกลยุทธ์ไหนตัดสินใจไม่คงที่ หรือไม่เคยลงมือเลย}';

    protected $description = 'ยิงกลยุทธ์ AI TRADE ใส่สภาพตลาดจำลอง แล้วรายงานว่าตัดสินใจอะไร';

    public function handle(StrategyRegistry $registry, AiBotService $bots): int
    {
        $only = $this->argument('strategy');
        $repeat = max(1, (int) $this->option('repeat'));
        $withPosition = (bool) $this->option('with-position');

        $strategies = collect(config('aibot.strategies', []))
            ->when($only, fn ($c) => $c->where('code', $only));

        if ($strategies->isEmpty()) {
            $this->error("ไม่รู้จักกลยุทธ์ '{$only}'");

            return self::FAILURE;
        }

        $this->line('ต้นทุนเข้า-ออกหนึ่งรอบของโหมดทดลอง: '.round($bots->roundTripCostBps(), 2).' bps');
        $this->newLine();

        $unstable = [];
        $never = [];

        foreach ($strategies as $spec) {
            $code = $spec['code'];
            $strategy = $registry->find($code);

            if (! $strategy) {
                $this->warn("ข้าม {$code} — ไม่มีคลาสรองรับ");

                continue;
            }

            $this->components->info($code.' — '.($spec['description_th'] ?? ''));

            // ใช้ค่าปริยายจริงจาก config ไม่ใช่ค่าที่เราคิดเอง
            $params = $bots->sanitizeParams($code, []);
            $position = $withPosition ? ['qty' => 1.0, 'entry' => 100.0] : null;

            $rows = [];
            $decided = null;

            foreach ($this->scenarios() as $name => $candles) {
                $actions = [];

                for ($i = 0; $i < $repeat; $i++) {
                    $signal = $strategy->decide($candles, $params, $position);
                    $actions[] = $signal->action;
                    $last = $signal;
                }

                /*
                 * ผลต้องเหมือนกันทุกรอบ — กลยุทธ์ต้องเป็นฟังก์ชันของข้อมูลล้วน
                 * ถ้าแกว่งแปลว่ามีอะไรสุ่มหรืออิงเวลาแอบอยู่ ซึ่งทำให้ผลย้อนหลัง
                 * เชื่อไม่ได้เลยและหาสาเหตุยากมากตอนขึ้นจริง
                 */
                $stable = count(array_unique($actions)) === 1;

                if (! $stable) {
                    $unstable[] = "{$code} / {$name}";
                }

                if (in_array($last->action, [Signal::BUY, Signal::SELL], true)) {
                    $decided = $last->action;
                }

                $rows[] = [
                    $name,
                    $this->paint($last->action),
                    $stable ? '✓' : '⚠ ไม่คงที่',
                    mb_strimwidth($last->reason, 0, 58, '…'),
                ];
            }

            $this->table(['สถานการณ์', 'ตัดสินใจ', 'คงที่', 'เหตุผล'], $rows);

            /*
             * กลยุทธ์ที่ตอบ "ถือ" ทุกฉากคือกลยุทธ์ที่ลูกค้าจ่ายเงินแล้วไม่ได้อะไรเลย
             *
             * ยกเว้นอาร์บิทราจที่ปิดไว้โดยตั้งใจ (รอพูล DEX) — ตัวนั้นตอบ hold
             * ทุกฉากอย่างถูกต้อง และ StrategyAvailability กันไม่ให้สร้างบอทอยู่แล้ว
             */
            if ($code !== 'arbitrage' && ! in_array($decided, [Signal::BUY, Signal::SELL], true)) {
                $never[] = $code;
            }
        }

        if ($unstable !== []) {
            $this->newLine();
            $this->error('พบการตัดสินใจที่ไม่คงที่: '.implode(' · ', $unstable));

            return self::FAILURE;
        }

        if ($this->option('assert') && $never !== []) {
            $this->newLine();
            $this->error('กลยุทธ์ที่ไม่เคยลงมือเลยสักฉาก: '.implode(' · ', array_unique($never)));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($withPosition
            ? 'ครบทุกสถานการณ์ (โหมดถือของอยู่) — ดูว่ามีสัญญาณขายตอนที่ควรขายไหม'
            : 'ครบทุกสถานการณ์ — ลองซ้ำด้วย --with-position เพื่อดูฝั่งขาย');

        return self::SUCCESS;
    }

    private function paint(string $action): string
    {
        return match ($action) {
            Signal::BUY => '<fg=green>ซื้อ</>',
            Signal::SELL => '<fg=red>ขาย</>',
            default => '<fg=gray>ถือ</>',
        };
    }

    /**
     * สภาพตลาดที่รู้คำตอบอยู่แล้วว่าควรได้อะไร.
     *
     * ตั้งใจให้ยาว 300 แท่ง เพราะกลยุทธ์ที่ต้องใช้ข้อมูลยาว (momentum ที่ slow_ema สูง,
     * breakout ที่ channel_period กว้าง) จะได้ไม่ตอบว่า "ข้อมูลไม่พอ" ซึ่งเป็นคนละเรื่อง
     * กับการตัดสินใจผิด และทำให้ผลอ่านไม่ออกว่ากลยุทธ์คิดยังไงกันแน่
     *
     * @return array<string, list<array<string, float|int>>>
     */
    private function scenarios(): array
    {
        return [
            'ขาขึ้นสม่ำเสมอ' => $this->candles($this->trend(300, 100.0, 0.4)),
            'ขาลงสม่ำเสมอ' => $this->candles($this->trend(300, 200.0, -0.4)),
            'ออกข้างในกรอบ' => $this->candles($this->range(300, 100.0, 2.0)),
            'ย่อลึกในกรอบ' => $this->candles(array_merge($this->range(280, 100.0, 2.0), $this->trend(20, 100.0, -0.5))),
            'เด้งขึ้นจากกรอบ' => $this->candles(array_merge($this->range(280, 100.0, 2.0), $this->trend(20, 100.0, 0.5))),
            'ราคานิ่งสนิท' => $this->candles(array_fill(0, 300, 100.0)),
            'ตลาดพังฉับพลัน' => $this->candles(array_merge($this->range(280, 100.0, 1.0), $this->trend(20, 100.0, -2.5))),

            /*
             * สองอันนี้เจาะจงมาก เพราะบางกลยุทธ์ยิงเฉพาะ "จังหวะ" ไม่ใช่ "สภาพ"
             *
             * momentum เข้าตอน EMA ตัดขึ้นเท่านั้น — ขาขึ้นที่วิ่งมานานแล้วจะไม่มี
             * การตัดให้เห็นอีก ถ้าไม่มีฉากตัดขึ้นจะแยกไม่ออกระหว่าง "รออย่างถูกต้อง"
             * กับ "ยิงไม่ได้เลยตลอดกาล" ซึ่งเป็นคนละเรื่องกันคนละขั้ว
             */
            /*
             * ⚠️ ต้องขึ้น "แท่งเดียว" ที่ท้ายสุดเท่านั้น
             *
             * ให้ขึ้นหลายแท่งแล้วการตัดจะเกิดที่แท่งแรกของการขึ้น พอมาดูแท่งสุดท้าย
             * EMA เร็วอยู่เหนือมานานแล้ว = ไม่ใช่ "การตัด" อีกต่อไป แล้วจะสรุปผิดว่า
             * กลยุทธ์ยิงไม่ได้ ทั้งที่มันแค่ไม่ไล่ราคาซ้ำ ซึ่งเป็นพฤติกรรมที่ถูกต้อง
             */
            'EMA ตัดขึ้นพอดี' => $this->candles(array_merge(array_fill(0, 290, 100.0), [104.0])),

            // ตัดขึ้นไปแล้วและวิ่งต่อ — ต้อง "ไม่" ซื้อซ้ำ (กันไล่ราคา)
            'ตัดขึ้นแล้ววิ่งต่อ' => $this->candles(array_merge(array_fill(0, 285, 100.0), $this->trend(8, 104.0, 1.0))),

            /*
             * ตัดลงพอดีที่แท่งสุดท้าย — ฉากที่พิสูจน์ว่า "ออกไม้ได้จริง"
             *
             * คำถามที่น่ากลัวที่สุดของบอทคือ "มันขายเป็นไหม" — ถ้าไม่มีฉากนี้
             * ทุกกลยุทธ์จะขึ้นว่า "ถือต่อ" หมดแล้วแยกไม่ออกว่าเพราะยังไม่ถึงจังหวะ
             * หรือเพราะมันออกไม่เป็นเลย
             */
            'EMA ตัดลงพอดี' => $this->candles(array_merge($this->trend(290, 100.0, 0.2), [140.0 * 0.86])),

            // สเปรดแคบจริงๆ — ฉากเดียวที่สแกลป์ควรทำงานได้ตามที่โฆษณา
            'แกว่งแคบมาก (สเปรดต่ำ)' => $this->candles($this->range(300, 100.0, 0.05), 0.0002),
        ];
    }

    /** @return list<float> */
    private function trend(int $bars, float $start, float $step): array
    {
        $closes = [];

        for ($i = 0; $i < $bars; $i++) {
            $closes[] = max(1.0, $start + $i * $step);
        }

        return $closes;
    }

    /** แกว่งขึ้นลงรอบราคากลาง — ไม่มีทิศทาง */
    private function range(int $bars, float $center, float $width): array
    {
        $closes = [];

        for ($i = 0; $i < $bars; $i++) {
            $closes[] = $center + sin($i / 3) * $width;
        }

        return $closes;
    }

    /**
     * แปลงราคาปิดเป็นแท่งเทียนเต็มรูปแบบ.
     *
     * เวลาเดินทีละชั่วโมงแบบคงที่ และวอลุ่มคงที่ — ตัวแปรเดียวที่เปลี่ยนคือราคา
     * เพื่อให้ผลที่ได้อธิบายได้ว่ามาจากอะไร ไม่ใช่เดาว่าวอลุ่มมีผลด้วยหรือเปล่า
     *
     * ⚠️ ระยะ high-low มีผลกับกลยุทธ์ที่ใช้ ATR (สแกลป์ · breakout) โดยตรง —
     *    ตั้งกว้างไว้แล้วสแกลป์จะตอบ "ผันผวนสูงเกินไป" ทุกฉากจนดูเหมือนพัง
     *    ทั้งที่มันแค่ทำงานตามที่ออกแบบ จึงต้องปรับได้ต่อฉาก
     *
     * @param  list<float>  $closes
     * @param  float  $wick  ระยะไส้เทียนเทียบราคา (0.004 = ±0.4%)
     * @return list<array<string, float|int>>
     */
    private function candles(array $closes, float $wick = 0.004): array
    {
        $candles = [];

        foreach ($closes as $i => $close) {
            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $close * (1 - $wick / 4),
                'high' => $close * (1 + $wick),
                'low' => $close * (1 - $wick),
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        return $candles;
    }
}
