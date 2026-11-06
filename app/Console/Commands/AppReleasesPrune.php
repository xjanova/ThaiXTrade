<?php

namespace App\Console\Commands;

use App\Services\AppReleaseRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * app-releases:prune — เก็บ release ของแต่ละแอปไว้แค่ N รุ่นล่าสุด (ค่าเริ่มต้น 5).
 *
 * ลบตัว Release บน GitHub (ไฟล์แนบหายไปด้วย) ไม่แตะ tag
 * กติกาการนับ/สิ่งที่ห้ามลบอยู่ใน AppReleaseRetention
 *
 *   php artisan app-releases:prune --dry-run     ดูรายการก่อน ไม่ลบ
 *   php artisan app-releases:prune               ถามยืนยันก่อนลบ
 *   php artisan app-releases:prune --force       ลบเลย (scheduler ใช้ตัวนี้)
 *
 * Developed by Xman Studio
 */
class AppReleasesPrune extends Command
{
    protected $signature = 'app-releases:prune
        {--keep= : เก็บกี่รุ่นล่าสุดต่อแอป (ค่าเริ่มต้นจาก GITHUB_RELEASE_KEEP หรือ 5)}
        {--limit=0 : ลบได้มากสุดกี่ตัวในรอบนี้ (0 = ไม่จำกัด)}
        {--dry-run : แสดงรายการอย่างเดียว ไม่ลบ}
        {--force : ไม่ต้องถามยืนยัน}';

    protected $description = 'Keep only the newest N GitHub releases per app (trade, wallet, master node, web) and delete the rest';

    public function handle(AppReleaseRetention $retention): int
    {
        $keepOption = $this->option('keep');
        $keep = $keepOption !== null
            ? (string) $keepOption
            : (string) config('services.github.release_keep', AppReleaseRetention::DEFAULT_KEEP);

        // ค่าว่างหรือเพี้ยนต้องหยุด — keep=0 แปลว่าลบทุกตัว
        if (! ctype_digit($keep) || (int) $keep < 1) {
            $this->error("--keep ต้องเป็นจำนวนเต็มบวก ได้ '{$keep}'");

            return self::FAILURE;
        }
        $keep = (int) $keep;

        if (! config('services.github.token')) {
            $this->warn('ยังไม่ได้ตั้ง GITHUB_TOKEN — ข้าม (ไม่มีสิทธิ์ลบ release)');

            return self::SUCCESS;
        }

        $plan = $retention->plan($keep);

        foreach ($plan['errors'] as $error) {
            $this->warn($error);
        }

        foreach ($plan['scanned'] as $repo => $count) {
            $mine = count(array_filter($plan['delete'], fn ($d) => $d['repo'] === $repo));
            $this->line("{$repo}: มี {$count} release จะลบ {$mine}");
        }

        $total = count($plan['delete']);
        $bytes = array_sum(array_column($plan['delete'], 'bytes'));

        if ($total === 0) {
            $this->info("ไม่มีของเก่าให้ลบ (เก็บแอปละ {$keep} รุ่นล่าสุด)");

            return $plan['errors'] ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['repo', 'กลุ่ม', 'tag', 'ปล่อยเมื่อ', 'ไฟล์', 'ขนาด'],
            array_map(fn ($d) => [
                $d['repo'], $d['group'], $d['tag'], $d['published_at'], $d['assets'], self::mb($d['bytes']),
            ], $plan['delete']),
        );
        $this->line("รวมจะลบ {$total} release ไฟล์ ".self::mb($bytes).' (ไม่แตะ tag)');

        if ($this->option('dry-run')) {
            $this->info('--dry-run แสดงรายการอย่างเดียว ไม่ได้ลบ');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("ลบ {$total} release นี้ถาวร?")) {
            $this->line('ยกเลิก ไม่ได้ลบอะไร');

            return self::SUCCESS;
        }

        $result = $retention->execute($plan, (int) $this->option('limit'), function (array $item, bool $ok) {
            $ok
                ? $this->line("  ลบ {$item['repo']} {$item['tag']} แล้ว")
                : $this->warn("  ลบ {$item['repo']} {$item['tag']} ไม่สำเร็จ");
        });

        // หน้าแอดมินแคชรายการไว้ 5 นาที ล้างให้เห็นผลทันที
        Cache::forget('admin_app_releases');

        $this->info("ลบสำเร็จ {$result['deleted']} จาก {$total} คืนพื้นที่ ".self::mb($result['bytes']));
        if ($result['stopped']) {
            $this->warn($result['stopped']);
        }

        return $result['failed'] > 0 || $plan['errors'] ? self::FAILURE : self::SUCCESS;
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1).' MB';
    }
}
