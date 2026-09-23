<?php

namespace App\Console\Commands;

use App\Services\AiBot\Analyst\AnalystCalibration;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — สร้างตาราง "AI มั่นใจเท่านี้ ทายถูกจริงกี่เปอร์เซ็นต์" จากประวัติ.
 *
 *   php artisan aibot:calibrate            (ทุกวัน ตาม schedule)
 *   php artisan aibot:calibrate --days=30
 *
 * ผลถูกเก็บใน cache ให้ AiViewGate อ่านทุกติ๊ก — ดูเหตุผลใน AnalystCalibration
 *
 * Developed by Xman Studio.
 */
class AiBotCalibrate extends Command
{
    protected $signature = 'aibot:calibrate {--days= : ย้อนหลังกี่วัน (ค่าปริยายจาก config)}';

    protected $description = 'สร้างตาราง calibration ของคำตัดสิน AI จากราคาที่เกิดขึ้นจริง';

    public function handle(AnalystCalibration $calibration): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $table = $calibration->rebuild($days);

        $this->components->info(sprintf(
            'calibration %d วัน · วัดที่ +%d ชม. · %d คำตัดสินที่วัดได้ · Brier %s',
            $table['days'],
            $table['horizon'],
            $table['samples'],
            $table['brier'] ?? '—',
        ));

        // คำตัดสินเรื่องอำนาจ — บรรทัดที่เจ้าของต้องเห็นก่อนตาราง (AI แตะเงินได้หรือไม่)
        $skill = $calibration->skill();
        $skill['verdict'] === 'no_skill'
            ? $this->warn('อำนาจของ AI: ไม่มี — '.$skill['reason'])
            : $this->line('อำนาจของ AI: '.($skill['verdict'] === 'skilled' ? 'มี — ' : 'ตามกติการายช่อง — ').$skill['reason']);

        $rows = [];
        foreach ($table['buckets'] as $stance => $buckets) {
            foreach ($buckets as $bucket => $cell) {
                $rows[] = [$stance, $bucket, $cell['n'], $cell['hit_rate'] === null ? '—' : sprintf('%.0f%%', $cell['hit_rate'] * 100), $cell['avg_move_bps'] ?? '—'];
            }
        }

        $this->table(['ท่าที', 'ความมั่นใจ', 'จำนวน', 'ทายถูก', 'ขยับเฉลี่ย (bps)'], $rows);
        $this->line('ช่องที่มีน้อยกว่า '.config('aibot_analyst.calibration.min_samples', 15).' ตัวอย่าง ยังไม่ถูกใช้ตัดสิน (ด่านถอยไปใช้เกณฑ์เดิม)');

        return self::SUCCESS;
    }
}
