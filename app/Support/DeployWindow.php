<?php

namespace App\Support;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * หน้าต่าง deploy — ช่วงที่งานตามตารางต้องหยุดรอ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมต้องมี
 * ═══════════════════════════════════════════════════════════════════════════
 * auto-deploy.yml ทำ `git reset` → composer → build → migrate → config:cache ตามลำดับ
 * ระหว่างนั้น cron ยังเรียก `schedule:run` ทุกนาที = โค้ดใหม่วิ่งบน schema เก่า
 * และแคชคอนฟิกของรอบก่อน
 *
 *   2026-10-22  validators:seal-monitor รันก่อน migrate สร้างตาราง
 *   2026-10-29  tpix:treasury-payouts ล้มสองรอบ (15:08, 15:09) Unknown column 'nonce'
 *               ตอน deploy v1.1.263 — ล้มแบบปิดตัวเอง เงินไม่ขยับ แต่ครั้งหน้าอาจเป็น
 *               คำสั่งที่เขียนไปครึ่งทางแล้วค่อยล้ม
 *
 * deploy วางไฟล์ธงไว้ก่อน `git reset` และลบทิ้งเมื่อโค้ด schema และแคชตรงกันแล้ว
 * (trap ลบให้แม้ deploy ล้มกลางทาง) routes/console.php ผูก skip() ให้ทุกงาน
 *
 * ทำไมไม่ใช้ `php artisan down`: หน้าเว็บกับ API จะตอบ 503 ตลอดช่วงนั้น (~100 วินาที
 * ต่อรอบ deploy) ทั้งที่ปัญหาอยู่ฝั่ง scheduler อย่างเดียว และ scheduler เช็กโหมดซ่อม
 * ครั้งเดียวตอนเริ่มรอบ ส่วน skip() ถูกเช็กทีละงานก่อนรันจริง
 *
 * ⚠️ ธงที่เก่ากว่า MAX_AGE_SECONDS ถูกมองข้าม — deploy ถูก command_timeout ตัดที่ 7 นาที
 *    ธงที่ค้างเกินนั้นแปลว่า trap ไม่ได้ทำงาน (เครื่องรีบูต / โปรเซสถูก kill -9)
 *    ถ้าไม่มีเพดานนี้ สะพานกับการจ่ายเงินจะหยุดเงียบไปจนกว่าจะมีคนเข้าไปลบไฟล์เอง
 *
 * Developed by Xman Studio.
 */
class DeployWindow
{
    /** ต้องตรงกับ DEPLOY_FLAG ใน .github/workflows/auto-deploy.yml */
    public const FLAG = 'framework/deploy-in-progress';

    public const MAX_AGE_SECONDS = 900;

    /**
     * งานที่รอบถัดไปห่างเกินนี้ ถ้าโดนข้ามจะถูกจดไว้ให้ deploy แจ้งเตือน
     * (งานรายนาที/ราย 5 นาทีไม่ต้องจด เดี๋ยวก็ถึงรอบใหม่เอง).
     */
    private const NOTE_SKIPPED_AFTER_SECONDS = 45 * 60;

    private static bool $staleWarned = false;

    public static function flagPath(): string
    {
        return storage_path(self::FLAG);
    }

    public static function skippedPath(): string
    {
        return self::flagPath().'.skipped';
    }

    /**
     * deploy กำลังทำอยู่ไหม.
     */
    public static function isActive(): bool
    {
        $path = self::flagPath();

        // schedule:run รอบเดียวเช็กหลายงานต่อกัน — ไม่ล้าง stat cache = เห็นธงที่เพิ่งวางช้าไปทั้งรอบ
        clearstatcache(true, $path);
        $modified = @filemtime($path);

        if ($modified === false) {
            return false;
        }

        if (time() - $modified > self::MAX_AGE_SECONDS) {
            if (! self::$staleWarned) {
                self::$staleWarned = true;
                Log::warning('deploy window: ไฟล์ธงค้างเกินอายุ ไม่หยุดงานตามตารางแล้ว — ลบทิ้งได้', [
                    'flag' => $path,
                    'age_seconds' => time() - $modified,
                ]);
            }

            return false;
        }

        return true;
    }

    /**
     * ใช้เป็น skip() ของงานตามตาราง — true = ข้ามรอบนี้.
     */
    public static function holdsBack(Event $event): bool
    {
        if (! self::isActive()) {
            return false;
        }

        $now = Date::now();

        if ($event->nextRunDate($now)->getTimestamp() - $now->getTimestamp() > self::NOTE_SKIPPED_AFTER_SECONDS) {
            @file_put_contents(self::skippedPath(), self::rerunCommand($event).PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        return true;
    }

    /**
     * คำสั่งที่แอดมินพิมพ์รันเองได้ เช่น `php artisan aibot:analyze --scope='strategic'`.
     */
    private static function rerunCommand(Event $event): string
    {
        if ($event->command === null) {
            return (string) $event->description;
        }

        return 'php artisan '.Str::after($event->command, Application::artisanBinary().' ');
    }
}
