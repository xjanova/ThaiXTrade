<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โซนเวลาของแอปต้องมาจาก APP_TIMEZONE เสมอ.
 *
 * ⚠️ 2026-09-23: อัป Laravel 11 → 12 แล้ว framework เลิกอ่าน env('APP_TIMEZONE')
 *    (ฮาร์ดโค้ด 'UTC' แทน) โปรเจกต์นี้ไม่มี config/app.php ของตัวเอง ทั้งแอปจึง
 *    เปลี่ยนจากเวลาไทยเป็น UTC เงียบๆ — บอท AI TRADE ทุกตัวหยุด ~7 ชม. และเวลา
 *    ในฐานข้อมูลปนสองโซน ชุดเทสต์ทั้งชุดผ่านหมดเพราะไม่มีเทสต์ไหนเช็กโซนเวลา
 *
 * ชุดนี้ตรึงว่า config ของเรายังผูกกับ env — การอัปเกรดครั้งหน้าที่ทำให้ค่า
 * หลุดจาก env ต้องแดงที่นี่ ไม่ใช่ไปรู้ตอนบอทบน prod หยุดเดิน
 *
 * Developed by Xman Studio.
 */
class AppTimezoneConfigTest extends TestCase
{
    #[Test]
    public function โซนเวลาของแอปตามค่า_app_timezone_ใน_env(): void
    {
        $this->assertSame(env('APP_TIMEZONE', 'UTC'), config('app.timezone'));

        // ค่าใน config อย่างเดียวไม่พอ — PHP ต้องถูกตั้งตามด้วย (now()/date() ใช้ค่านี้)
        $this->assertSame(config('app.timezone'), date_default_timezone_get());
    }

    #[Test]
    public function ไฟล์_config_อ่าน_env_จริงไม่ใช่ค่าตายตัว(): void
    {
        /*
         * ยิงค่าที่ไม่มีใครใช้ (Asia/Tokyo) แล้วโหลดไฟล์ config ตรงๆ — ถ้าไฟล์หาย
         * หรือมีคนเปลี่ยนเป็นค่าตายตัว เทสต์นี้แดงทันทีไม่ว่า .env ของเครื่องจะเป็นอะไร
         */
        $path = base_path('config/app.php');
        $this->assertFileExists($path, 'ห้ามลบ config/app.php — Laravel 12 ไม่อ่าน APP_TIMEZONE ให้แล้ว');

        $backup = [$_ENV['APP_TIMEZONE'] ?? null, $_SERVER['APP_TIMEZONE'] ?? null];
        $_ENV['APP_TIMEZONE'] = $_SERVER['APP_TIMEZONE'] = 'Asia/Tokyo';

        try {
            $config = require $path;
            $this->assertSame('Asia/Tokyo', $config['timezone']);
        } finally {
            // คืนค่าเดิม — เทสต์อื่นในโปรเซสเดียวกันอ่าน env ชุดเดียวกันนี้
            if ($backup[0] === null) {
                unset($_ENV['APP_TIMEZONE']);
            } else {
                $_ENV['APP_TIMEZONE'] = $backup[0];
            }

            if ($backup[1] === null) {
                unset($_SERVER['APP_TIMEZONE']);
            } else {
                $_SERVER['APP_TIMEZONE'] = $backup[1];
            }
        }
    }
}
