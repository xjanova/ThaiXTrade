<?php

namespace App\Console\Commands;

use App\Services\Stabilizer\StabilizerService;
use Illuminate\Console\Command;

/**
 * stabilizer:run — หนึ่งรอบของ TPIX Stability Reserve (scheduler เรียกทุก 5 นาที).
 *
 * ต้องรันจาก CLI เท่านั้นเพราะการเซ็นเรียก ethers ผ่าน Node (php-fpm ปิด proc_open)
 * --dry-run = ดูว่ากฎจะตัดสินใจอย่างไรตอนนี้ ไม่บันทึก ไม่เซ็น ไม่ส่ง
 *
 * Developed by Xman Studio
 */
class StabilizerRun extends Command
{
    protected $signature = 'stabilizer:run {--dry-run : evaluate the rules only — no record, no signing, no broadcast}';

    protected $description = 'Run one cycle of the rule-based TPIX Stability Reserve';

    public function handle(StabilizerService $service): int
    {
        $result = $service->run((bool) $this->option('dry-run'));

        $status = $result['status'];
        $decision = $result['decision'] ?? null;

        $line = "stabilizer: {$status}";
        if (is_array($decision)) {
            $line .= ' · '.($decision['action'] ?? '?').' / '.($decision['reason_code'] ?? '?');
        }
        if (! empty($result['tx_hash'])) {
            $line .= ' · tx '.$result['tx_hash'];
        }
        $this->line($line);

        if ($this->option('dry-run') && is_array($decision)) {
            $this->line(json_encode($decision['details'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // เซ็นไม่ผ่าน = ต้องมีคนดู (ระบบหยุดตัวเองแล้ว) — ให้ cron เห็นเป็นความล้มเหลว
        return $status === 'sign_failed' ? self::FAILURE : self::SUCCESS;
    }
}
