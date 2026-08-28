<?php

namespace App\Console\Commands;

use App\Models\AiBotPosition;
use App\Models\AiMarketView;
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
        $result = $news->sync($this->priorityCoins());

        $this->info("ข่าวที่อ่านได้ {$result['fetched']} · บันทึกใหม่ {$result['stored']}");
        $this->line('เหรียญที่ดึงรอบนี้: '.implode(' ', $result['coins']));

        if ($result['failed'] !== []) {
            $this->warn('ฟีดที่ดึงไม่ได้: '.implode(', ', $result['failed']));
        }

        // ฟีดล่มทั้งหมด = ด่านข่าวตาบอด ต้องให้ exit code บอก ไม่ใช่เงียบ
        return $result['fetched'] === 0 && $result['failed'] !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * เหรียญที่ต้องมีข่าวสดทุกรอบ ไม่ต้องรอคิวหมุน.
     *
     * เหรียญที่มีเงินของผู้ใช้ค้างอยู่จริงต้องรู้ข่าวก่อนใคร — รอคิวหมุน 90 นาที
     * แปลว่าข่าว hack ของเหรียญที่ถืออยู่อาจมาถึงช้ากว่าราคาที่ไหลไปแล้ว
     *
     * @return list<string>
     */
    private function priorityCoins(): array
    {
        $pairs = AiBotPosition::query()
            ->where('quantity', '>', 0)
            ->pluck('pair')
            ->all();

        // คู่ที่ AI คัดไว้รอบล่าสุดก็ต้องมีข่าวสด — กำลังจะถูกเลือกเข้าไม้
        $shortlist = AiMarketView::latestFor(AiMarketView::SCOPE_STRATEGIC)?->shortlistPairs() ?? [];

        return collect(array_merge($pairs, $shortlist))
            ->map(fn (string $pair) => strtoupper(explode('/', str_replace('-', '/', $pair))[0]))
            ->unique()
            ->values()
            ->all();
    }
}
