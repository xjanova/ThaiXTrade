<?php

namespace App\Console\Commands;

use App\Services\MasterNode\NodeRegistryContract;
use App\Services\MasterNode\UptimeCalculator;
use App\Services\MasterNode\UptimeOracleService;
use App\Services\MasterNode\UptimeOracleSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MasternodeUptimeOracle — ปิดรอบ uptime ของมาสเตอร์โหนดแล้วตั้งค่าบนเชน.
 *
 * Schedule: ทุกชั่วโมง นาทีที่ 2 (รอ heartbeat/รายชื่อ peer ของช่องสุดท้ายเข้ามาครบก่อน)
 *
 * ยังไม่ได้ตั้งกุญแจของเราเป็น uptimeOracle บนเชน = โหมดเงา (คำนวณ + บันทึก ไม่ส่ง)
 * ดูขั้นตอนก่อนเรียก setUptimeOracle ใน docs/MASTERNODE_UPTIME_ORACLE.md
 *
 * Usage:
 *   php artisan masternode:uptime-oracle                    # รอบล่าสุดที่ปิดแล้ว
 *   php artisan masternode:uptime-oracle --dry-run          # คำนวณให้ดู ไม่บันทึก ไม่ส่ง
 *   php artisan masternode:uptime-oracle --period=1761130800
 *   php artisan masternode:uptime-oracle --show-address     # ที่อยู่กุญแจ oracle เทียบกับบนเชน
 *
 * Developed by Xman Studio
 */
class MasternodeUptimeOracle extends Command
{
    protected $signature = 'masternode:uptime-oracle
        {--dry-run : Compute and print only — no database writes, no transactions}
        {--period= : Unix start of the period to process (default: the last closed period)}
        {--show-address : Decrypt the oracle keystore and compare its address with uptimeOracle() on chain}';

    protected $description = 'Turn master-node heartbeats into per-period uptime and report it on chain (updateUptimeBatch)';

    public function handle(
        UptimeOracleService $oracle,
        UptimeCalculator $calculator,
        UptimeOracleSigner $signer,
        NodeRegistryContract $registry,
    ): int {
        if ($this->option('show-address')) {
            return $this->showAddress($signer, $registry);
        }

        $periodSeconds = $calculator->periodSeconds();
        $lastClosed = $calculator->lastClosedPeriodStart(time());
        $period = $this->option('period');
        if ($period !== null) {
            $period = (int) $period;
            if ($period % $periodSeconds !== 0 || $period > $lastClosed) {
                $this->error("--period ต้องเป็นต้นรอบ (หาร {$periodSeconds} ลงตัว) ของรอบที่ปิดแล้ว (≤ {$lastClosed})");

                return self::FAILURE;
            }
        }
        $periodStart = $period ?? $lastClosed;

        try {
            $summary = $oracle->run($periodStart, (bool) $this->option('dry-run'));
        } catch (\Throwable $e) {
            Log::error('masternode:uptime-oracle failed', ['period' => $periodStart, 'error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'รอบ %s (%d) · โหมด %s%s · โหนด %d · ส่ง %d สำเร็จ %d ล้ม %d · bootnode รายงานครบ %.0f%%',
            date('Y-m-d H:i', $periodStart).' UTC',
            $periodStart,
            $summary['mode'],
            $summary['shadow_reason'] ? " ({$summary['shadow_reason']})" : '',
            $summary['nodes'],
            $summary['changed'],
            $summary['confirmed'],
            $summary['failed'],
            $summary['feed_coverage'] * 100,
        ));
        if ($summary['degraded']) {
            $this->warn('ข้อมูลฝั่งเราขาดในรอบนี้ ('.implode(', ', $summary['reasons']).') — ไม่ลด uptime ของใคร');
        }

        if ($summary['results'] !== []) {
            $this->table(
                ['wallet', 'ok/expected', 'measured', 'on chain', 'target'],
                collect($summary['results'])->map(fn ($r, $w) => [
                    $w,
                    "{$r['ok']}/{$r['expected']}",
                    $this->pct($r['bps']),
                    $this->pct($r['onchain']),
                    $this->pct($r['target']),
                ])->values()->all(),
            );
        }

        Log::info('masternode:uptime-oracle', collect($summary)->except('results')->all());

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function showAddress(UptimeOracleSigner $signer, NodeRegistryContract $registry): int
    {
        if (! $signer->configured()) {
            $this->error('ยังไม่ได้ตั้ง MASTERNODE_ORACLE_KEYSTORE_PATH / _PASS / _ADDRESS');

            return self::FAILURE;
        }

        $r = $signer->address();
        if (! ($r['ok'] ?? false)) {
            $this->error('ถอด keystore ไม่ได้: '.($r['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $mine = strtolower((string) $r['address']);
        $onchain = $registry->uptimeOracle();
        $this->line("กุญแจ oracle ในเว็บ : {$mine}");
        $this->line('uptimeOracle() บนเชน: '.($onchain ?? 'อ่านไม่ได้ (สัญญารุ่นเก่า/RPC ล่ม)'));
        $this->line('สัญญา NodeRegistry  : '.($registry->address() ?? '-'));

        if ($onchain === $mine) {
            $this->info('ตรงกัน — ระบบส่ง uptime ขึ้นเชนจริงตั้งแต่รอบถัดไป');
        } else {
            $this->warn('ยังไม่ตรง — ระบบอยู่โหมดเงา (ดูเงื่อนไขก่อนเรียก setUptimeOracle ใน docs/MASTERNODE_UPTIME_ORACLE.md)');
        }

        return self::SUCCESS;
    }

    private function pct(int $bps): string
    {
        return number_format($bps / 100, 2).'%';
    }
}
