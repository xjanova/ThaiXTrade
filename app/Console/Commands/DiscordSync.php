<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordPublisher;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ซิงก์ข่าว วิดีโอ กฎ whitepaper และสถานะการขายเหรียญเข้า Discord.
 *
 * ตั้งเวลาไว้ทุก 10 นาทีใน routes/console.php — ปิดสวิตช์ในหลังบ้าน = คำสั่งนี้ไม่แตะ Discord
 *
 *   php artisan discord:sync            ซิงก์จริง
 *   php artisan discord:sync --dry-run  ดูว่าจะโพสต์/แก้อะไรบ้าง โดยไม่ส่งอะไรออกไป
 *
 * Developed by Xman Studio.
 */
class DiscordSync extends Command
{
    protected $signature = 'discord:sync {--dry-run : แสดงสิ่งที่จะทำ ไม่ส่งอะไรไป Discord}';

    protected $description = 'Sync TPIX news, videos, rules, whitepaper and token-sale status to the Discord server';

    public function handle(DiscordPublisher $publisher): int
    {
        $outcome = $publisher->sync((bool) $this->option('dry-run'));

        if (! $outcome['ran']) {
            $this->line('ข้าม: '.$outcome['reason']);

            return self::SUCCESS;
        }

        $rows = array_map(fn ($r) => [$r['kind'], $r['ref'], $r['action'], $r['channel_id'] ?? '-', $r['error'] ?? ''], $outcome['results']);
        $this->table(['เรื่อง', 'อ้างอิง', 'ผล', 'ห้อง', 'ปัญหา'], $rows);

        $errors = count(array_filter($outcome['results'], fn ($r) => $r['action'] === 'error'));

        // พังบางชิ้นไม่ถือว่าคำสั่งล้ม — รอบหน้าจะลองชิ้นนั้นใหม่เอง (ดูผลได้ที่ /admin/discord)
        return $errors > 0 && $errors === count($outcome['results']) ? self::FAILURE : self::SUCCESS;
    }
}
