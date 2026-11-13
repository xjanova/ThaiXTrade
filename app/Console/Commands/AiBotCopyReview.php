<?php

namespace App\Console\Commands;

use App\Models\AiCopyReview;
use App\Services\AiBot\Copy\ReviewService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — เครื่องมือทีมงานสำหรับรีวิวมาสเตอร์ที่ถูกรายงาน.
 *
 *   php artisan aibot:copy-review                 รายการรีวิวที่ถูกรายงาน (มากไปน้อย)
 *   php artisan aibot:copy-review 12 --hide       ซ่อนรีวิว #12 (ไม่โชว์ ไม่นับในค่าเฉลี่ย)
 *   php artisan aibot:copy-review 12 --show       คืนรีวิว #12
 *
 * การรายงานจากผู้ใช้ไม่ซ่อนรีวิวเอง — มาสเตอร์ที่มีหลายกระเป๋าจะรุมรายงานรีวิวที่ติให้หายได้
 * คนเป็นผู้ตัดสินตรงนี้
 *
 * Developed by Xman Studio.
 */
class AiBotCopyReview extends Command
{
    protected $signature = 'aibot:copy-review {id? : รหัสรีวิว} {--hide : ซ่อนรีวิว} {--show : คืนรีวิวที่ซ่อนไว้}';

    protected $description = 'ดูรีวิวมาสเตอร์ที่ถูกรายงาน และซ่อน/คืนรีวิว';

    public function handle(ReviewService $reviews): int
    {
        $id = $this->argument('id');

        if ($id === null) {
            $rows = AiCopyReview::where('reports', '>', 0)->with('master')->orderByDesc('reports')->limit(50)->get()
                ->map(fn (AiCopyReview $r) => [$r->id, $r->master?->display_name, $r->stars, $r->reports, $r->status, mb_strimwidth((string) $r->comment, 0, 60, '…')]);

            $this->table(['#', 'มาสเตอร์', 'ดาว', 'รายงาน', 'สถานะ', 'ข้อความ'], $rows->all());

            return self::SUCCESS;
        }

        $review = AiCopyReview::find((int) $id);

        if (! $review) {
            $this->error("ไม่พบรีวิว #{$id}");

            return self::FAILURE;
        }

        if ($this->option('hide') === $this->option('show')) {
            $this->error('ระบุ --hide หรือ --show อย่างใดอย่างหนึ่ง');

            return self::FAILURE;
        }

        $review = $reviews->setVisible($review, (bool) $this->option('show'));
        $this->info("รีวิว #{$review->id} → {$review->status}");

        return self::SUCCESS;
    }
}
