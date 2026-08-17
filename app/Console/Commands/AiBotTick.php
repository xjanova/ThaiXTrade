<?php

namespace App\Console\Commands;

use App\Models\AiBotConfig;
use App\Services\AiBot\BotRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ให้บอท "ที่ซื้อคลาวด์ไว้" คิดหนึ่งรอบ.
 *
 * ⚠️ รันเฉพาะบอทของแพลนที่ execution = cloud เท่านั้น
 *    บอทของแพลนฟรีเป็น execution = browser ต้องให้หน้าเว็บของผู้ใช้เป็นคนสั่งเดินเอง
 *    (POST /api/v1/ai-bot/bots/{id}/tick) ปิดแท็บแล้วต้องหยุดจริงๆ
 *
 *    ถ้าลืมกรองตรงนี้ ผู้ใช้ฟรีจะได้คลาวด์ฟรีไปด้วย แล้วแพลนที่เสียเงินก็ไม่เหลือจุดขาย
 *
 * บอทตัวหนึ่งพังต้องไม่ลาก bot ตัวอื่นล้มไปด้วย — จับ exception ต่อตัว
 *
 * Developed by Xman Studio.
 */
class AiBotTick extends Command
{
    protected $signature = 'aibot:tick {--bot= : รันเฉพาะบอทตามรหัส}';

    protected $description = 'รันบอท AI TRADE ที่กำลังทำงานหนึ่งรอบ';

    public function handle(BotRunner $runner): int
    {
        $query = AiBotConfig::runnable()->cloudExecuted();

        if ($botId = $this->option('bot')) {
            $query->where('id', $botId);
        }

        $bots = $query->get();

        if ($bots->isEmpty()) {
            $this->info('ไม่มีบอทคลาวด์ที่กำลังทำงาน');

            return self::SUCCESS;
        }

        $counts = ['buy' => 0, 'sell' => 0, 'hold' => 0, 'error' => 0];

        foreach ($bots as $bot) {
            try {
                $result = $runner->tick($bot);
                $counts[$result['action']] = ($counts[$result['action']] ?? 0) + 1;

                $this->line("#{$bot->id} {$bot->pair} [{$bot->strategy}] → {$result['action']} · {$result['reason']}");
            } catch (\Throwable $e) {
                $counts['error']++;
                Log::error('AI bot tick failed', ['bot' => $bot->id, 'error' => $e->getMessage()]);
                $this->error("#{$bot->id} ล้มเหลว: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'รวม %d ตัว — ซื้อ %d · ขาย %d · ถือ %d · ผิดพลาด %d',
            $bots->count(),
            $counts['buy'],
            $counts['sell'],
            $counts['hold'],
            $counts['error'],
        ));

        return self::SUCCESS;
    }
}
