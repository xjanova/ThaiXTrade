<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordModerator;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — อ่านผลของ AutoMod แล้วไล่ระดับโทษสมาชิกที่ทำผิดซ้ำ (ทุก 5 นาที).
 *
 * โหมดตั้งที่ /admin/discord: ปิด · แจ้งเตือนอย่างเดียว · ลงโทษจริง
 *
 * Developed by Xman Studio.
 */
class DiscordModerate extends Command
{
    protected $signature = 'discord:moderate';

    protected $description = 'Escalate repeat AutoMod offenders in the TPIX Discord server (timeout → kick → ban)';

    public function handle(DiscordModerator $moderator): int
    {
        $outcome = $moderator->run();

        if (! $outcome['ran']) {
            $this->line('ข้าม: '.$outcome['reason']);

            return self::SUCCESS;
        }

        $this->info("ความผิดใหม่ {$outcome['strikes']} ครั้ง · ตัดสิน ".count($outcome['actions']).' ราย');

        foreach ($outcome['actions'] as $a) {
            $this->line("  {$a['user_id']} → {$a['action']} ({$a['mode']}, คะแนน {$a['score']}) {$a['status']}".($a['error'] ? " · {$a['error']}" : ''));
        }

        return self::SUCCESS;
    }
}
