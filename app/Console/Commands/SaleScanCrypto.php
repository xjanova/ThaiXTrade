<?php

namespace App\Console\Commands;

use App\Services\SaleCrypto\CryptoOrderService;
use App\Services\SaleCrypto\CryptoPaymentCrediter;
use App\Services\SaleCrypto\CryptoPaymentScanner;
use App\Services\SaleCrypto\SaleCryptoConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * สแกน USDT ที่โอนเข้ากระเป๋ารับเงินของรอบขาย แล้วออกเหรียญให้คำสั่งซื้อที่จับคู่ได้ — ไม่มีขั้นแอดมิน.
 *
 * รันทุกนาทีจาก scheduler (withoutOverlapping + onOneServer) และล็อกซ้ำอีกชั้นข้างใน
 * เผื่อมีคนรันมือซ้อนกับ cron — ถึงซ้อนกันจริงก็ไม่บันทึก/ออกเหรียญซ้ำ (unique ทุกชั้น ดู CryptoPaymentCrediter)
 *
 * ทำงานแม้รอบขายเลิกรับ CRYPTO แล้ว — คนที่โอนเข้ามาหลังปิดต้องถูกบันทึกไว้คืนเงิน ไม่ใช่หายเงียบ
 * จบเองทันทีเมื่อ SALE_CRYPTO_ENABLED=false (ไม่มีต้นทุนตอนยังไม่เปิด)
 */
class SaleScanCrypto extends Command
{
    protected $signature = 'sale:scan-crypto {--chain= : สแกนเฉพาะเชนนี้ (chain id)}';

    protected $description = 'ตรวจ USDT ที่โอนเข้ากระเป๋ารับเงินของรอบขาย แล้วออกเหรียญให้คำสั่งซื้อที่จ่ายครบ';

    public function handle(SaleCryptoConfig $config, CryptoPaymentScanner $scanner, CryptoPaymentCrediter $crediter, CryptoOrderService $orders): int
    {
        if (! $config->installed()) {
            $this->line('การซื้อด้วยคริปโตยังไม่ได้ติดตั้ง (SALE_CRYPTO_ENABLED=false) — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('sale:scan-crypto:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบสแกนอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $sweep = $orders->sweep();
            $only = $this->option('chain') !== null ? (int) $this->option('chain') : null;

            foreach ($config->chains() as $chainId => $chain) {
                if ($only !== null && $only !== $chainId) {
                    continue;
                }

                $s = $scanner->scanChain($chainId);
                $range = $s['from'] !== null ? "{$s['from']}→".($s['to'] ?? '-') : '-';
                $line = "{$chain['name']}: บันทึกใหม่ {$s['recorded']} · ช่วง {$range} · หัว ".($s['head'] ?? '-');
                $s['error'] ? $this->warn($line.' · '.$s['error']) : $this->line($line);
            }

            // ตัดสินทุกรายการที่ค้าง (รวมที่ค้างจากรอบก่อนเพราะเครดิตล้มกลางคัน)
            $result = $crediter->processPending();
            $this->line("ออกเหรียญ {$result['credited']} · รอทีมงานดู {$result['needs_review']} · ล้ม (ลองใหม่รอบหน้า) {$result['failed']}"
                ." · หมดเวลา {$sweep['expired']} · ปล่อยยอดเฉพาะ {$sweep['released']}");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
