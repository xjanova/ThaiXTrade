<?php

namespace App\Console\Commands;

use App\Services\AiBot\Backtest\BacktestEngine;
use App\Services\AiBot\Backtest\KlineArchive;
use App\Services\AiBot\Timeframe;
use App\Services\AiBotService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ทดสอบกลยุทธ์กับข้อมูลย้อนหลังจริง ก่อนเอาเงินใครไปเสี่ยง.
 *
 *   php artisan aibot:backtest momentum
 *   php artisan aibot:backtest mean_reversion --pair=ETH/USDT --tf=15m --days=60
 *   php artisan aibot:backtest momentum --params='{"fast_ema":8,"slow_ema":21}'
 *   php artisan aibot:backtest mean_reversion --sweep=oversold=25,30,35 --sweep=rsi_period=7,14
 *   php artisan aibot:backtest breakout --sweep=channel_period=20,55 --walk-forward
 *   php artisan aibot:backtest grid --json > grid.json
 *
 * ═══ วิธีอ่านผล ═══
 *   edge/ไม้ (bps) คือกำไรก่อนหักต้นทุนต่อเงินที่ลง — ต้อง "ชนะต้นทุน" (36 bps) ชัดเจน
 *   ถึงจะมีอะไรให้ค้นต่อ ต่ำกว่านั้นคือแพ้โดยโครงสร้าง จูนเท่าไหร่ก็ไม่รอด (บทเรียนสแกลป์)
 *
 * ═══ walk-forward ═══
 *   จูนบนครึ่งแรก (60%) แล้ววัดบนครึ่งหลังที่ไม่เคยเห็น — ค่าที่ชนะทั้งสองช่วงเท่านั้น
 *   ที่เชื่อได้ ชนะเฉพาะช่วงที่จูนคือ overfit ซึ่งดูดีบนกระดาษแล้วเจ๊งของจริง
 *
 * ⚠️ ผลคือ "กฎล้วน" — ไม่มีด่านข่าวและมุมมอง AI (ไม่มีประวัติย้อนหลังให้เล่นซ้ำ)
 *
 * Developed by Xman Studio.
 */
class AiBotBacktest extends Command
{
    protected $signature = 'aibot:backtest
        {strategy : รหัสกลยุทธ์ เช่น momentum, mean_reversion, breakout, grid, dca, ai_signal}
        {--pair=BTC/USDT : คู่เทรด}
        {--tf= : timeframe (ค่าปริยาย = ตัวแรกที่กลยุทธ์รองรับ)}
        {--days=90 : ย้อนหลังกี่วัน (ไม่รวมช่วงอุ่นเครื่องที่ดึงเพิ่มให้เอง)}
        {--params= : พารามิเตอร์เป็น JSON เช่น {"fast_ema":8}}
        {--risk= : กรอบความเสี่ยงเป็น JSON เช่น {"stop_loss_pct":3}}
        {--sweep=* : จูนหลายค่า key=v1,v2,v3 (ใส่ซ้ำได้หลายคีย์ = ทุกชุดผสม)}
        {--walk-forward : จูนบน 60% แรก วัดบน 40% หลัง}
        {--no-risk-gate : ปิดด่านความเสี่ยงจากราคา (ดูกลยุทธ์ล้วนๆ)}
        {--offline : ห้ามยิงตลาด ใช้เฉพาะแท่งที่มีในคลัง}
        {--json : พิมพ์ผลเป็น JSON แทนตาราง}';

    protected $description = 'ทดสอบกลยุทธ์ AI TRADE กับแท่งเทียนย้อนหลังจริง (กติกาเดียวกับบอทที่เดินสด)';

    public function handle(BacktestEngine $engine, KlineArchive $archive, AiBotService $bots): int
    {
        $code = (string) $this->argument('strategy');
        $spec = $bots->strategy($code);

        if (! $spec) {
            $this->error("ไม่รู้จักกลยุทธ์ '{$code}'");

            return self::FAILURE;
        }

        $timeframe = (string) ($this->option('tf') ?: ($spec['timeframes'][0] ?? '1h'));

        if (! Timeframe::isKnown($timeframe)) {
            $this->error("ไม่รู้จัก timeframe '{$timeframe}'");

            return self::FAILURE;
        }

        $pair = strtoupper((string) $this->option('pair'));
        $days = max(1, (int) $this->option('days'));
        $params = $this->json('params');
        $risk = $this->json('risk');

        if ($params === null || $risk === null) {
            return self::FAILURE;
        }

        // ดึงเผื่อช่วงอุ่นเครื่อง (หน้าต่างสูงสุด 500 แท่ง) ให้ช่วงที่ขอถูกทดสอบเต็ม
        $stepMs = Timeframe::milliseconds($timeframe);
        $toMs = now()->getTimestamp() * 1000;
        $fromMs = $toMs - $days * 86_400_000;
        $warmupMs = 500 * $stepMs;

        try {
            $candles = $archive->range($pair, $timeframe, $fromMs - $warmupMs, $toMs, (bool) $this->option('offline'));
        } catch (\Throwable $e) {
            $this->error('ดึงข้อมูลไม่สำเร็จ: '.$e->getMessage());

            return self::FAILURE;
        }

        $startIndex = $this->firstIndexAtOrAfter($candles, $fromMs);

        if ($candles === [] || count($candles) - $startIndex < 30) {
            $this->error('แท่งเทียนไม่พอ — ได้ '.count($candles).' แท่ง (ลอง --days น้อยลง หรือเอา --offline ออก)');

            return self::FAILURE;
        }

        $options = ['risk_gate' => ! $this->option('no-risk-gate'), 'start_index' => $startIndex];
        $sweeps = $this->sweeps();

        if ($sweeps === null) {
            return self::FAILURE;
        }

        if ($sweeps === [] && ! $this->option('walk-forward')) {
            $result = $engine->run($code, $candles, $timeframe, $params, $risk, $options);

            return $this->report($code, $pair, $timeframe, $result);
        }

        $combos = $this->combos($sweeps, $params);

        if ($this->option('walk-forward')) {
            return $this->walkForward($engine, $code, $pair, $timeframe, $candles, $startIndex, $combos, $risk, $options);
        }

        return $this->sweepReport($engine, $code, $pair, $timeframe, $candles, $combos, $risk, $options);
    }

    // ── รายงาน ───────────────────────────────────────────────────────────────

    private function report(string $code, string $pair, string $timeframe, array $result): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'strategy' => $code, 'pair' => $pair, 'timeframe' => $timeframe,
                'params' => $result['params'], 'risk' => $result['risk'],
                'summary' => $result['summary'], 'trades' => $result['trades'],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $s = $result['summary'];

        $this->components->info(sprintf('%s · %s · %s · %d แท่ง (%s วัน) · อุ่นเครื่อง %d แท่ง', $code, $pair, $timeframe, $s['bars'], $s['days'], $result['warmup']));
        $this->line('params: '.json_encode($result['params'], JSON_UNESCAPED_UNICODE).' · risk: '.json_encode($result['risk']));
        $this->newLine();

        $this->table(['ตัวชี้วัด', 'ค่า', 'อ่านว่า'], [
            ['ไม้ที่ปิด', $s['closed'].' (ชนะ '.($s['win_rate'] ?? '—').'%)', $s['trades_per_day'].' ไม้/วัน · ถือเฉลี่ย '.($s['avg_hold_bars'] ?? '—').' แท่ง'],
            ['realized', $this->money($s['realized_pnl']), 'ค้างเปิด '.$this->money($s['unrealized_pnl'])],
            ['gross ก่อนหักต้นทุน', $this->money($s['gross_pnl']), 'ต้นทุน '.$s['costs'].' (fee '.$s['fees'].' + slip '.$s['slippage'].')'],
            ['edge/ไม้', ($s['edge_bps'] ?? '—').' bps', 'ต้นทุนไป-กลับ '.$s['cost_bps'].' bps — '.$this->edgeVerdict($s)],
            ['profit factor', $s['profit_factor'] ?? '—', 'expectancy '.($s['expectancy'] ?? '—').'/ไม้ · ชนะเฉลี่ย '.$s['avg_win'].' · แพ้เฉลี่ย '.$s['avg_loss']],
            ['max drawdown', $s['max_drawdown'].' ('.$s['max_drawdown_pct'].'%)', 'อยู่ในตลาด '.$s['exposure_pct'].'% ของเวลา'],
            ['ผลตอบแทนพอร์ต', $s['return_pct'].'%', 'ถือเฉยๆ '.($s['buy_hold_pct'] ?? '—').'%'],
            ['รอบคิด', implode(' · ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($s['decisions']), $s['decisions'])), ''],
        ]);

        $this->newLine();
        $this->line($this->verdict($s));

        return self::SUCCESS;
    }

    private function sweepReport(BacktestEngine $engine, string $code, string $pair, string $timeframe, array $candles, array $combos, array $risk, array $options): int
    {
        $rows = [];
        $results = [];

        foreach ($combos as $combo) {
            $result = $engine->run($code, $candles, $timeframe, $combo, $risk, $options);
            $s = $result['summary'];
            $results[] = ['params' => $combo, 'summary' => $s];
            $rows[] = [
                json_encode($combo, JSON_UNESCAPED_UNICODE),
                $s['closed'], ($s['win_rate'] ?? '—').'%', $this->money($s['gross_pnl']), $this->money($s['realized_pnl']),
                $s['edge_bps'] ?? '—', $s['profit_factor'] ?? '—', $s['max_drawdown_pct'].'%', $s['return_pct'].'%',
            ];
        }

        usort($results, fn ($a, $b) => ($b['summary']['expectancy'] ?? -INF) <=> ($a['summary']['expectancy'] ?? -INF));

        if ($this->option('json')) {
            $this->line(json_encode(['strategy' => $code, 'pair' => $pair, 'timeframe' => $timeframe, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info("จูน {$code} · {$pair} · {$timeframe} — ".count($combos).' ชุด');
        $this->table(['params', 'ไม้', 'ชนะ', 'gross', 'realized', 'edge bps', 'PF', 'maxDD', 'return'], $rows);
        $best = $results[0] ?? null;

        if ($best) {
            $this->newLine();
            $this->line('ดีที่สุดตาม expectancy: '.json_encode($best['params'], JSON_UNESCAPED_UNICODE).' → '.$this->verdict($best['summary']));
            $this->line('⚠️ ค่าที่ชนะบนข้อมูลที่ใช้จูน ยังไม่ใช่หลักฐาน — ยืนยันด้วย --walk-forward ก่อนเชื่อ');
        }

        return self::SUCCESS;
    }

    /** จูนบน 60% แรก วัดบน 40% หลัง — ทางเดียวที่แยกฝีมือออกจาก overfit */
    private function walkForward(BacktestEngine $engine, string $code, string $pair, string $timeframe, array $candles, int $startIndex, array $combos, array $risk, array $options): int
    {
        $testBars = count($candles) - $startIndex;
        $split = $startIndex + (int) floor($testBars * 0.6);

        $train = array_slice($candles, 0, $split);
        // ช่วงทดสอบต้องมีช่วงอุ่นเครื่องของตัวเอง (ดึงจากข้อมูลก่อนหน้า) แต่ห้ามให้คะแนนช่วงนั้น
        $test = $candles;
        $testOptions = $options + [];
        $testOptions['start_index'] = $split;

        $ranked = [];

        foreach ($combos as $combo) {
            $summary = $engine->run($code, $train, $timeframe, $combo, $risk, $options)['summary'];
            $ranked[] = ['params' => $combo, 'train' => $summary];
        }

        usort($ranked, fn ($a, $b) => ($b['train']['expectancy'] ?? -INF) <=> ($a['train']['expectancy'] ?? -INF));

        $best = $ranked[0];
        $bestTest = $engine->run($code, $test, $timeframe, $best['params'], $risk, $testOptions)['summary'];

        // เทียบกับค่าปริยายบนช่วงทดสอบเดียวกัน — ถ้าจูนแล้วไม่ดีกว่าค่าปริยาย ก็ไม่ควรเปลี่ยน
        $defaultTest = $engine->run($code, $test, $timeframe, [], $risk, $testOptions)['summary'];

        if ($this->option('json')) {
            $this->line(json_encode([
                'strategy' => $code, 'pair' => $pair, 'timeframe' => $timeframe,
                'best_params' => $best['params'], 'train' => $best['train'], 'test' => $bestTest, 'default_on_test' => $defaultTest,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info("walk-forward {$code} · {$pair} · {$timeframe} — จูน ".($split - $startIndex).' แท่ง · ทดสอบ '.(count($candles) - $split).' แท่ง');
        $this->table(['ชุด', 'params', 'ไม้', 'ชนะ', 'gross', 'edge bps', 'PF', 'return', 'ถือเฉยๆ'], [
            ['จูน (train)', json_encode($best['params'], JSON_UNESCAPED_UNICODE), $best['train']['closed'], ($best['train']['win_rate'] ?? '—').'%', $this->money($best['train']['gross_pnl']), $best['train']['edge_bps'] ?? '—', $best['train']['profit_factor'] ?? '—', $best['train']['return_pct'].'%', ($best['train']['buy_hold_pct'] ?? '—').'%'],
            ['ทดสอบ (test)', 'เหมือนข้างบน', $bestTest['closed'], ($bestTest['win_rate'] ?? '—').'%', $this->money($bestTest['gross_pnl']), $bestTest['edge_bps'] ?? '—', $bestTest['profit_factor'] ?? '—', $bestTest['return_pct'].'%', ($bestTest['buy_hold_pct'] ?? '—').'%'],
            ['ค่าปริยายบน test', 'default', $defaultTest['closed'], ($defaultTest['win_rate'] ?? '—').'%', $this->money($defaultTest['gross_pnl']), $defaultTest['edge_bps'] ?? '—', $defaultTest['profit_factor'] ?? '—', $defaultTest['return_pct'].'%', ''],
        ]);

        $this->newLine();
        $holds = ($bestTest['edge_bps'] ?? -INF) > $bestTest['cost_bps'] && ($bestTest['expectancy'] ?? -INF) > 0;
        $this->line($holds
            ? '✅ ค่าที่จูนยังชนะต้นทุนบนข้อมูลที่ไม่เคยเห็น — เชื่อได้ระดับหนึ่ง (ยังต้อง forward test)'
            : '❌ ค่าที่จูนไม่รอดบนข้อมูลที่ไม่เคยเห็น — เป็น overfit อย่าเอาไปใช้');

        return self::SUCCESS;
    }

    // ── ตัวช่วย ──────────────────────────────────────────────────────────────

    private function edgeVerdict(array $s): string
    {
        if ($s['edge_bps'] === null) {
            return 'ไม่มีไม้ให้วัด';
        }

        return $s['edge_bps'] > $s['cost_bps'] ? 'ชนะต้นทุน' : 'แพ้ต้นทุน';
    }

    private function verdict(array $s): string
    {
        if ($s['closed'] < 10) {
            return '🟡 ยังสรุปไม่ได้ — ปิดไม้แค่ '.$s['closed'].' ไม้ (ต้องมีอย่างน้อย ~30 ถึงจะแยกฝีมือจากความบังเอิญ)';
        }

        if (($s['edge_bps'] ?? -INF) <= $s['cost_bps']) {
            return '❌ edge/ไม้ ('.$s['edge_bps'].' bps) ไม่ชนะต้นทุน ('.$s['cost_bps'].' bps) — แพ้โดยโครงสร้าง ไม่ใช่โชคร้าย';
        }

        if (($s['expectancy'] ?? 0) <= 0) {
            return '❌ ชนะต้นทุนต่อไม้ แต่ expectancy ติดลบ — ไม้แพ้ใหญ่กว่าไม้ชนะมากเกินไป (ดู stop)';
        }

        $vsHold = $s['buy_hold_pct'] === null ? '' : ' · ถือเฉยๆ ได้ '.$s['buy_hold_pct'].'% เทียบพอร์ต '.$s['return_pct'].'%';

        return '✅ ชนะต้นทุนและ expectancy บวก'.$vsHold.' — ยืนยันด้วย --walk-forward และ forward test ก่อนเปิดขาย';
    }

    private function money(float $v): string
    {
        return ($v >= 0 ? '+' : '').number_format($v, 2);
    }

    private function json(string $option): ?array
    {
        $raw = $this->option($option);

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            $this->error("--{$option} ต้องเป็น JSON object");

            return null;
        }

        return $decoded;
    }

    /** @return array<string, list<float|string>>|null */
    private function sweeps(): ?array
    {
        $out = [];

        foreach ((array) $this->option('sweep') as $raw) {
            if (! str_contains((string) $raw, '=')) {
                $this->error("--sweep ต้องเป็น key=v1,v2 ได้ '{$raw}'");

                return null;
            }

            [$key, $values] = explode('=', (string) $raw, 2);
            $out[trim($key)] = array_map(
                fn ($v) => is_numeric($v) ? $v + 0 : trim($v),
                array_filter(array_map('trim', explode(',', $values)), fn ($v) => $v !== ''),
            );
        }

        return $out;
    }

    /** ทุกชุดผสมของค่าที่จูน ทับลงบน params พื้นฐาน */
    private function combos(array $sweeps, array $base): array
    {
        $combos = [$base];

        foreach ($sweeps as $key => $values) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$key => $value];
                }
            }
            $combos = $next;
        }

        return $combos;
    }

    private function firstIndexAtOrAfter(array $candles, int $ms): int
    {
        foreach ($candles as $i => $candle) {
            if ((int) $candle['time'] >= $ms) {
                return $i;
            }
        }

        return count($candles);
    }
}
