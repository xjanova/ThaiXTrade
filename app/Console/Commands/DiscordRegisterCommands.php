<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordSetup;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ลงทะเบียนคำสั่ง /ถาม และ /ขายเหรียญ ในเซิร์ฟเวอร์ Discord (กดจากหลังบ้านได้เช่นกัน).
 *
 * Developed by Xman Studio.
 */
class DiscordRegisterCommands extends Command
{
    protected $signature = 'discord:register-commands';

    protected $description = 'Register the /ask and /sale slash commands in the TPIX Discord server';

    public function handle(DiscordSetup $setup): int
    {
        $result = $setup->registerCommands();

        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
