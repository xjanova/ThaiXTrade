<?php

namespace App\Console\Commands;

use App\Models\LaunchpadSale;
use App\Services\Launchpad\LaunchpadOrderException;
use App\Services\Launchpad\LaunchpadRegistrar;
use App\Support\Wei;
use Illuminate\Console\Command;

/**
 * ลงทะเบียนหน้าขายจากเชน (อ่านทุกค่าจากสัญญา) — และอนุมัติ/ซ่อนได้ในคำสั่งเดียว.
 *
 *   php artisan launchpad:register 0xSALE            ลงทะเบียน (สถานะ pending ยังไม่ขึ้นหน้าเว็บ)
 *   php artisan launchpad:register 0xSALE --approve  ลงทะเบียน + อนุมัติขึ้นหน้าเว็บ
 *   php artisan launchpad:register 0xSALE --hide     ซ่อนหน้าขาย (หยุดรับคำสั่งใหม่ คำสั่งที่จ่ายแล้วจะถูกคืนเงิน)
 */
class LaunchpadRegister extends Command
{
    protected $signature = 'launchpad:register {sale : ที่อยู่สัญญา FixedPriceSale บนเชน 4289}
                            {--approve : อนุมัติขึ้นหน้าเว็บ}
                            {--hide : ซ่อน (หยุดรับคำสั่งใหม่)}
                            {--reject : ปฏิเสธ}
                            {--note= : บันทึกของผู้ตรวจ}';

    protected $description = 'ลงทะเบียนหน้าขาย Launchpad จากสัญญาบนเชน (และอนุมัติ/ซ่อน)';

    public function handle(LaunchpadRegistrar $registrar): int
    {
        try {
            ['sale' => $sale, 'created' => $created] = $registrar->register((string) $this->argument('sale'));
        } catch (LaunchpadOrderException $e) {
            $this->error("ลงทะเบียนไม่ได้ ({$e->errorCode}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(($created ? 'ลงทะเบียนใหม่' : 'อัปเดตจากเชน')." #{$sale->id} — {$sale->token_symbol} ({$sale->token_name})");
        $this->table(['ค่า', 'จากเชน'], [
            ['launch id', $sale->launch_id],
            ['sale', $sale->sale_address],
            ['feeder', $sale->feeder_address],
            ['vesting', $sale->vesting_address ?? '—'],
            ['token', $sale->token_address],
            ['ราคา / เหรียญ', $sale->priceUsdt().' USDT'],
            ['เพดานขาย', Wei::format((string) $sale->sale_cap, $sale->token_decimals)],
            ['เข้าพูล', ($sale->pool_share_bps / 100).'%'],
            ['ช่วงขาย', $sale->starts_at->toDateTimeString().' → '.$sale->ends_at->toDateTimeString().' UTC'],
            ['LP ล็อกถึง', $sale->lp_unlock_at->toDateTimeString().' UTC'],
            ['ผู้สร้าง', $sale->creator_address],
            ['สถานะ', $sale->status],
        ]);

        $note = $this->option('note') ? (string) $this->option('note') : null;

        try {
            if ($this->option('approve')) {
                $sale = $registrar->approve($sale, 'cli', $note);
            } elseif ($this->option('hide')) {
                $sale = $registrar->setStatus($sale, LaunchpadSale::STATUS_HIDDEN, 'cli', $note);
            } elseif ($this->option('reject')) {
                $sale = $registrar->setStatus($sale, LaunchpadSale::STATUS_REJECTED, 'cli', $note);
            }
        } catch (LaunchpadOrderException $e) {
            $this->error("เปลี่ยนสถานะไม่ได้ ({$e->errorCode}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("สถานะตอนนี้: {$sale->status}");

        return self::SUCCESS;
    }
}
