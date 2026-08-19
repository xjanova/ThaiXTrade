<?php

namespace App\Console\Commands;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
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
    protected $signature = 'aibot:tick {--bot= : รันเฉพาะบอทตามรหัส}
                            {--strategy= : รันเฉพาะกลยุทธ์เดียว (ใช้แยกวอร์กเกอร์ต่อกลยุทธ์)}';

    protected $description = 'รันบอท AI TRADE ที่กำลังทำงานหนึ่งรอบ';

    public function handle(BotRunner $runner): int
    {
        /*
         * เรียงตามระดับแพลน — VIP ได้คิวก่อน ตามที่หน้าเช่าโฆษณาไว้
         *
         * เดิมดึงมาเฉยๆ ไม่มี orderBy เลย ลำดับจึงเป็นไปตามที่ฐานข้อมูลคืนมา
         * (โดยมากคือ id จากน้อยไปมาก) = คนสมัครก่อนได้ก่อน ไม่เกี่ยวกับที่จ่าย
         */
        $query = AiBotConfig::runnable()
            ->cloudExecuted()
            ->withPlanRank()
            ->orderByDesc('plan_rank')
            ->orderBy('last_run_at');   // ในระดับเดียวกัน ตัวที่รอนานสุดได้ก่อน

        if ($botId = $this->option('bot')) {
            $query->where('id', $botId);
        }

        /*
         * แยกวอร์กเกอร์ต่อกลยุทธ์
         *
         * ตัวจับเวลาเรียกกลยุทธ์ละหนึ่งคำสั่ง แต่ละคำสั่งมี withoutOverlapping
         * เป็นของตัวเอง — กลยุทธ์ที่ช้าหรือค้าง (เช่นตลาดตอบช้า) จึงไม่บล็อก
         * กลยุทธ์อื่นทั้งแถว ซึ่งเดิมเป็นแบบนั้นเพราะใช้ล็อกก้อนเดียวกันหมด
         *
         * ผลพลอยได้ที่สำคัญกว่า: บันทึก/สถิติของแต่ละกลยุทธ์แยกกันสะอาด
         * เอาไปเทียบกันได้ตรงๆ ว่ากลยุทธ์ไหนทำได้ดีกว่าในสภาพตลาดเดียวกัน
         */
        if ($strategy = $this->option('strategy')) {
            $query->where('strategy', $strategy);
        }

        $bots = $query->get()->filter(fn (AiBotConfig $bot) => $this->isDue($bot));

        if ($bots->isEmpty()) {
            $this->info('ไม่มีบอทคลาวด์ที่ถึงรอบในตอนนี้'.($strategy ? " (กลยุทธ์ {$strategy})" : ''));

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

    /**
     * ถึงรอบของบอทตัวนี้หรือยัง — รอบถี่แค่ไหนขึ้นกับระดับแพลน.
     *
     * ตัวจับเวลาเรียกคำสั่งนี้ทุกนาที แล้วให้แต่ละบอทตัดสินเองว่าถึงรอบหรือยัง
     * ทำแบบนี้แทนการตั้ง schedule แยกต่อระดับ เพราะ schedule แยกจะซ้อนกันเอง
     * (VIP ทุก 1 นาที + ทุกคนทุก 5 นาที = นาทีที่ 5 บอท VIP เดินสองรอบ เปิดไม้ซ้ำ)
     *
     * ค่าปริยาย 5 นาทีสำหรับระดับที่ไม่ได้ระบุ = พฤติกรรมเดิม ไม่มีใครช้าลง
     */
    private function isDue(AiBotConfig $bot): bool
    {
        if (! $bot->last_run_at) {
            return true;
        }

        $intervals = config('aibot.tick_interval_minutes', []);
        $rank = (int) ($bot->plan_rank ?? 0);
        $tier = array_search($rank, AiBotPlan::TIER_RANK, true) ?: 'free';
        $minutes = (int) ($intervals[$tier] ?? 5);

        return $bot->last_run_at->diffInMinutes(now()) >= max(1, $minutes);
    }
}
