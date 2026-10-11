<?php

namespace App\Console\Commands;

use App\Services\Stabilizer\StabilityReportBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * stabilizer:report — รายงานสาธารณะรายวันของ TPIX Stability Reserve.
 *
 * ปริยาย = รายงานของ "เมื่อวาน" (scheduler เรียกหลังเที่ยงคืน) · รันซ้ำวันเดิมได้ (เขียนทับ)
 *
 * Developed by Xman Studio
 */
class StabilizerReport extends Command
{
    protected $signature = 'stabilizer:report {--date= : report date (Y-m-d), default yesterday}';

    protected $description = 'Build the daily public report of the TPIX Stability Reserve';

    public function handle(StabilityReportBuilder $builder): int
    {
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'))
            : now()->subDay();

        if (! $date) {
            $this->error('invalid --date, expected Y-m-d');

            return self::FAILURE;
        }

        $report = $builder->build($date);
        $this->line('stabilizer report '.$report->report_date->format('Y-m-d')
            .' · decisions '.($report->stats['decisions'] ?? 0)
            .' · AI summary '.($report->summary_en ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
