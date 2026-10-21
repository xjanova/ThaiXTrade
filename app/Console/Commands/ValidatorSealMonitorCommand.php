<?php

namespace App\Console\Commands;

use App\Models\ValidatorSealStat;
use App\Services\Validator\SealMonitor;
use Illuminate\Console\Command;

/**
 * อ่านบล็อกใหม่ แล้วนับว่า validator แต่ละตัวเสนอ/เซ็นรับรองบล็อกไหม — รันทุก 1 นาทีผ่าน scheduler.
 *
 * ดูรายละเอียดใน SealMonitor · ผลขึ้นหน้า /admin/validators และคาดแดงหลังบ้าน
 */
class ValidatorSealMonitorCommand extends Command
{
    protected $signature = 'validators:seal-monitor {--max= : อ่านย้อนได้มากสุดกี่บล็อกต่อรอบ (ค่าเริ่มต้นจาก config/validators.php)}';

    protected $description = 'เฝ้าดูการปิดบล็อกของ validator แต่ละตัว (ผู้เสนอ / ผู้เซ็นรับรอง / คิวที่พลาด)';

    public function handle(SealMonitor $monitor): int
    {
        $max = $this->option('max') ?: config('validators.seal_monitor.max_per_run', SealMonitor::MAX_PER_RUN);
        $result = $monitor->run(max(1, (int) $max));

        if ($result['error'] !== null) {
            // ไม่ใช่ความล้มเหลวของคำสั่ง — เชนดับเป็นหน้าที่ของ chain-watchdog
            $this->warn('อ่านเชนไม่ได้รอบนี้: '.$result['error']);

            return self::SUCCESS;
        }

        $this->info(sprintf('บล็อก %d · อ่านเพิ่ม %d · validator %d ตัว · เงียบ %d', $result['head'], $result['scanned'], $result['set_size'], count($result['silent'])));

        $this->table(
            ['validator', 'ในชุด', 'เซ็นล่าสุด', 'เสนอล่าสุด', 'เซ็น %', 'เสนอ', 'พลาดคิว'],
            ValidatorSealStat::orderByDesc('in_set')->orderBy('set_index')->get()->map(fn (ValidatorSealStat $s) => [
                $s->address,
                $s->in_set ? 'ใช่' : '-',
                $s->last_signed_block ?? '-',
                $s->last_proposed_block ?? '-',
                $s->signRate() === null ? '-' : $s->signRate().'%',
                $s->window_proposed,
                $s->window_missed_turns,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
