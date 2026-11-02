<?php

namespace App\Console\Commands;

use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgeFeeQuoter;
use App\Support\Wei;
use Illuminate\Console\Command;

/**
 * ประกาศค่าธรรมเนียมขาออก (TPIX → BSC) ใหม่ = ค่าแก๊ส mint จริง + ส่วนเพิ่ม แปลงเป็น TPIX ด้วย TWAP.
 *
 * ตัวสแกนคิดค่าธรรมเนียมจากราคาที่ประกาศ ณ เวลาบล็อกที่ล็อก — ตัวนี้หยุดนานเกิน stale_minutes
 * = หน้าเว็บและตัวสแกนใช้เพดานแทน (ไม่ใช้ราคาเก่าที่อาจไม่พอค่าแก๊ส)
 */
class BridgeWtpixFeeQuote extends Command
{
    protected $signature = 'bridge:wtpix-fee-quote';

    protected $description = 'ประกาศค่าธรรมเนียมสะพาน TPIX → wTPIX ตามค่าแก๊ส BSC จริง';

    public function handle(WtpixBridgeConfig $config, WtpixBridgeFeeQuoter $quoter): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน TPIX⇄wTPIX ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $quote = $quoter->refresh();
        $line = 'ค่าธรรมเนียม '.Wei::format((string) $quote->fee_wei, 18).' TPIX · '.$quote->source
            .($quote->raw_fee_wei !== null ? ' · ค่าแก๊สจริง+ส่วนเพิ่ม '.Wei::format((string) $quote->raw_fee_wei, 18).' TPIX' : '')
            .($quote->note ? " · {$quote->note}" : '');

        $quote->source === 'market' ? $this->info($line) : $this->warn($line);

        return self::SUCCESS;
    }
}
