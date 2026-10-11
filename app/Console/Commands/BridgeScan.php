<?php

namespace App\Console\Commands;

use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * สแกนเงินฝาก USDT ทุกเชน + คำขอถอน (Burned) บนเชน 4289 แล้วบันทึกลงฐานข้อมูล.
 *
 * รันทุกนาทีจาก scheduler (withoutOverlapping + onOneServer) และล็อกซ้ำอีกชั้นข้างใน
 * เผื่อมีคนรันมือซ้อนกับ cron — แต่ถึงซ้อนกันจริงก็ไม่บันทึกซ้ำ เพราะ unique (เชน, tx, log)
 *
 * ทำงานแม้สวิตช์หลังบ้านปิด — คนที่โอนเข้ามาระหว่างหยุดชั่วคราวต้องถูกบันทึกไว้รอ mint
 */
class BridgeScan extends Command
{
    protected $signature = 'bridge:scan {--chain= : สแกนเฉพาะเชนนี้ (chain id)}';

    protected $description = 'สแกนเงินฝาก USDT (ทุกเชน) และคำขอถอน (เชน TPIX) ของสะพาน';

    public function handle(BridgeConfig $config, BridgeScanner $scanner): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน USDT ยังไม่ได้ติดตั้ง (BRIDGE_ENABLED=false) — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('bridge:scan:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบสแกนอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $only = $this->option('chain') !== null ? (int) $this->option('chain') : null;

            foreach ($config->enabledChains() as $chainId => $chain) {
                if ($only !== null && $only !== $chainId) {
                    continue;
                }

                $this->report($chain['name'], $scanner->scanDeposits($chainId));
            }

            if ($only === null || $only === $config->tpixChainId()) {
                $this->report('TPIX (ถอน)', $scanner->scanWithdrawals());
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function report(string $label, array $summary): void
    {
        $range = $summary['from'] !== null ? "{$summary['from']}→".($summary['to'] ?? '-') : '-';
        $line = "{$label}: บันทึกใหม่ {$summary['recorded']} · ช่วง {$range} · หัว ".($summary['head'] ?? '-');

        $summary['error'] ? $this->warn($line.' · '.$summary['error']) : $this->line($line);
    }
}
