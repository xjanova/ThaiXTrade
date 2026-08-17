<?php

namespace App\Console\Commands;

use App\Services\AiBot\NewsFeedService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ดึงข่าวตลาดเข้าระบบทุก 15 นาที.
 *
 * ข่าวคือชั้นที่บอกได้ว่า "ทำไม" ตลาดถึงขยับ — ยิ่งดึงถี่ยิ่งจับเหตุการณ์แพนิค
 * ได้ทันก่อนที่ราคาจะไหลจนสุด
 *
 * ⚠️ ต้องมี cron ฐาน `* * * * * php artisan schedule:run` บนเซิร์ฟเวอร์
 *    ถ้า cron นั้นหาย scheduler ทั้งระบบจะเงียบโดยไม่มี error ให้เห็น
 *    (เคยเกิดมาแล้วในโปรเจกต์พี่น้อง — ตรวจด้วย `php artisan schedule:list`)
 *
 * Developed by Xman Studio.
 */
class AiBotSyncNews extends Command
{
    protected $signature = 'aibot:sync-news';

    protected $description = 'ดึงข่าวตลาดจาก RSS แล้วให้คะแนนความเสี่ยง';

    public function handle(NewsFeedService $news): int
    {
        $result = $news->sync();

        $this->info("ข่าวที่อ่านได้ {$result['fetched']} · บันทึกใหม่ {$result['stored']}");

        if ($result['failed'] !== []) {
            $this->warn('ฟีดที่ดึงไม่ได้: '.implode(', ', $result['failed']));
        }

        // ฟีดล่มทั้งหมด = ด่านข่าวตาบอด ต้องให้ exit code บอก ไม่ใช่เงียบ
        return $result['fetched'] === 0 && $result['failed'] !== [] ? self::FAILURE : self::SUCCESS;
    }
}
