<?php

namespace App\Console\Commands;

use App\Models\AiCopyMaster;
use App\Models\AiCopyMedia;
use App\Services\AiBot\Copy\MediaService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — สวิตช์ทีมงานสำหรับไลฟ์/คลิปของมาสเตอร์.
 *
 *   php artisan aibot:copy-media 5 --revoke        ถอนสิทธิ์แปะลิงก์ของมาสเตอร์ #5 (ลิงก์เดิมหายจากหน้าสาธารณะทันที)
 *   php artisan aibot:copy-media 5 --restore       คืนสิทธิ์
 *   php artisan aibot:copy-media --item=12 --hide  ซ่อนลิงก์ #12 รายการเดียว
 *   php artisan aibot:copy-media --item=12 --show  คืนลิงก์ #12
 *
 * Developed by Xman Studio.
 */
class AiBotCopyMedia extends Command
{
    protected $signature = 'aibot:copy-media {master? : รหัสมาสเตอร์}
        {--revoke : ถอนสิทธิ์แปะลิงก์} {--restore : คืนสิทธิ์แปะลิงก์}
        {--item= : รหัสลิงก์} {--hide : ซ่อนลิงก์} {--show : คืนลิงก์}';

    protected $description = 'ถอน/คืนสิทธิ์แปะไลฟ์และคลิปของมาสเตอร์ หรือซ่อน/คืนลิงก์รายรายการ';

    public function handle(MediaService $media): int
    {
        if ($this->option('item') !== null) {
            $item = AiCopyMedia::find((int) $this->option('item'));

            if (! $item || $this->option('hide') === $this->option('show')) {
                $this->error('ไม่พบลิงก์ หรือไม่ได้ระบุ --hide / --show อย่างใดอย่างหนึ่ง');

                return self::FAILURE;
            }

            $this->info("ลิงก์ #{$item->id} → ".$media->setVisible($item, (bool) $this->option('show'))->status);

            return self::SUCCESS;
        }

        $master = AiCopyMaster::find((int) $this->argument('master'));

        if (! $master || $this->option('revoke') === $this->option('restore')) {
            $this->error('ไม่พบมาสเตอร์ หรือไม่ได้ระบุ --revoke / --restore อย่างใดอย่างหนึ่ง');

            return self::FAILURE;
        }

        $master = $media->setRevoked($master, (bool) $this->option('revoke'));
        $this->info("มาสเตอร์ #{$master->id} {$master->display_name}: ".($master->media_revoked_at ? 'ถอนสิทธิ์แปะลิงก์แล้ว' : 'คืนสิทธิ์แปะลิงก์แล้ว'));

        return self::SUCCESS;
    }
}
