<?php

namespace App\Console\Commands;

use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgeRelayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * สั่งงานปลายทางของสะพาน TPIX ⇄ wTPIX — mint wTPIX บน BSC และ release TPIX บนเชน 4289.
 *
 * ต้องอยู่ฝั่ง CLI (เซ็นผ่าน Node — php-fpm ปิด proc_open) · สองทิศคนละล็อก คนละเชน คนละ nonce
 * RPC ของเชนหนึ่งล่มจึงไม่หยุดอีกทิศ
 *
 * ถ้าเจ้าของชี้ WTPIX_BRIDGE_RELAYER_KEYSTORE ไปไฟล์เดียวกับกุญแจของสะพาน USDT บนเชนเดียวกัน
 * (relayer USDT บนเชน 4289 · payout USDT บน BSC) ต้องถือล็อกของคำสั่งฝั่ง USDT ด้วย
 * ไม่งั้นสองคำสั่งเซ็นด้วย nonce เดียวกันพร้อมกัน แล้วธุรกรรมหนึ่งไม่มีวันลงบล็อก
 */
class BridgeWtpixRelay extends Command
{
    protected $signature = 'bridge:wtpix-relay {--direction= : to_bsc หรือ to_tpix (ปริยาย = ทั้งสองทิศ)} {--limit=5 : จำนวนรายการสูงสุดต่อทิศต่อรอบ}';

    protected $description = 'mint wTPIX บน BSC / release TPIX บนเชน TPIX ให้รายการที่ยืนยันแล้ว (และตามผลที่ส่งไปแล้ว)';

    public function handle(WtpixBridgeConfig $config, WtpixBridgeRelayer $relayer, BridgeConfig $usdt): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน TPIX⇄wTPIX ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $only = $this->option('direction');
        if ($only !== null && ! in_array($only, WtpixBridgeTransfer::DIRECTIONS, true)) {
            $this->error('--direction ต้องเป็น to_bsc หรือ to_tpix');

            return self::INVALID;
        }

        $limit = max(1, (int) $this->option('limit'));
        $ours = $config->signerAddress('relayer');

        foreach (WtpixBridgeTransfer::DIRECTIONS as $direction) {
            if ($only !== null && $only !== $direction) {
                continue;
            }

            $locks = [Cache::lock("wtpix-bridge:relay:{$direction}:lock", 600)];

            // กุญแจใบเดียวกับสะพาน USDT บนเชนเดียวกัน = ต้องรอคิว nonce ร่วมกัน
            $shared = $direction === WtpixBridgeTransfer::TO_TPIX
                ? ($ours !== null && $ours === $usdt->signerAddress('relayer') ? 'bridge:relayer:lock' : null)
                : ($ours !== null && $ours === $usdt->signerAddress('payout') ? 'bridge:payout:lock' : null);
            if ($shared !== null) {
                $locks[] = Cache::lock($shared, 900);
            }

            $held = [];
            foreach ($locks as $lock) {
                if (! $lock->get()) {
                    break;
                }
                $held[] = $lock;
            }

            if (count($held) !== count($locks)) {
                foreach ($held as $lock) {
                    $lock->release();
                }
                $this->warn("{$direction}: มีรอบอื่นถือ nonce ของกุญแจนี้อยู่ — ข้ามรอบนี้");

                continue;
            }

            try {
                $summary = $relayer->run($direction, $limit);
                $this->line("{$direction}: ตามผล {$summary['tracked']} · สำเร็จ {$summary['completed']} · เซ็น {$summary['signed']}"
                    .($summary['blocked'] ? " · หยุด: {$summary['blocked']}" : ''));
            } catch (\Throwable $e) {
                // ทิศหนึ่งพังต้องไม่หยุดอีกทิศ — สถานะในฐานข้อมูลถูกบันทึกก่อน broadcast เสมอ รอบหน้าตามต่อได้
                report($e);
                $this->warn("{$direction}: ผิดพลาด — ".mb_substr($e->getMessage(), 0, 200));
            } finally {
                foreach ($held as $lock) {
                    $lock->release();
                }
            }
        }

        return self::SUCCESS;
    }
}
