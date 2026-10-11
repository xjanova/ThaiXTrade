<?php

namespace App\Console\Commands;

use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgeScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * สแกนสะพาน TPIX ⇄ wTPIX — Locked/Released บนเชน 4289 และ เผา/mint/เปลี่ยน minter ของ wTPIX บน BSC.
 *
 * รันทุกนาทีแม้สวิตช์หลังบ้านปิด: คนที่ล็อก/เผาระหว่างหยุดต้องถูกบันทึกไว้ และตัวเฝ้า
 * "mint/ปล่อยที่ระบบไม่ได้สั่ง · มีคนเข้าคิวเพิ่ม minter" ต้องเดินตลอดเวลา
 * สองเชนแยกกันเด็ดขาด — RPC เชนหนึ่งล่มไม่หยุดอีกเชน
 */
class BridgeWtpixScan extends Command
{
    protected $signature = 'bridge:wtpix-scan {--side= : สแกนเฉพาะฝั่ง tpix หรือ bsc}';

    protected $description = 'สแกนสะพาน TPIX⇄wTPIX (ล็อกบนเชน TPIX · เผา/mint/minter ของ wTPIX บน BSC)';

    public function handle(WtpixBridgeConfig $config, WtpixBridgeScanner $scanner): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน TPIX⇄wTPIX ยังไม่ได้ติดตั้ง (WTPIX_BRIDGE_ENABLED=false) — ข้าม');

            return self::SUCCESS;
        }

        $side = $this->option('side');
        if ($side !== null && ! in_array($side, ['tpix', 'bsc'], true)) {
            $this->error('--side ต้องเป็น tpix หรือ bsc');

            return self::INVALID;
        }

        $lock = Cache::lock('wtpix-bridge:scan:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบสแกนอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            if ($side === null || $side === 'tpix') {
                $this->report('TPIX (ล็อก)', $this->safely(fn () => $scanner->scanLocks()));
            }
            if ($side === null || $side === 'bsc') {
                $this->report($config->bscName().' (เผา)', $this->safely(fn () => $scanner->scanBurns()));
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /** ฝั่งหนึ่งพัง (exception) ต้องไม่ทำให้อีกฝั่งไม่ถูกสแกน */
    private function safely(callable $scan): array
    {
        try {
            return $scan();
        } catch (\Throwable $e) {
            report($e);

            return ['recorded' => 0, 'from' => null, 'to' => null, 'head' => null, 'incidents' => 0, 'error' => 'exception'];
        }
    }

    private function report(string $label, array $summary): void
    {
        $range = $summary['from'] !== null ? "{$summary['from']}→".($summary['to'] ?? '-') : '-';
        $line = "{$label}: บันทึกใหม่ {$summary['recorded']} · ช่วง {$range} · หัว ".($summary['head'] ?? '-')
            .(($summary['incidents'] ?? 0) > 0 ? " · ⚠️ เหตุ {$summary['incidents']}" : '');

        $summary['error'] ? $this->warn($line.' · '.$summary['error']) : $this->line($line);
    }
}
