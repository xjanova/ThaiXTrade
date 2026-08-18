<?php

namespace App\Console\Commands;

use App\Models\SalePhase;
use App\Models\TokenSale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ตั้งตารางรอบขายใหม่ โดยนับเฟสแรกจาก "ตอนนี้" เป็นต้นไป.
 *
 * ใช้หลัง regenesis หรือเมื่อรอบขายเดิมหมดอายุไปแล้ว — เลื่อนทุกเฟสให้ต่อกัน
 * เป็นทอด ๆ เฟสแรก active ที่เหลือ upcoming
 *
 * ความปลอดภัย: เฟสที่ขายไปแล้ว (sold > 0) จะไม่ถูกแตะ เพราะการเลื่อนวัน
 * ของรอบที่มีคนซื้อไปแล้วเท่ากับเปลี่ยนเงื่อนไขย้อนหลังกับลูกค้าที่จ่ายเงินมาแล้ว
 */
class SaleReschedule extends Command
{
    protected $signature = 'sale:reschedule
        {--start= : วันเริ่มเฟสแรก (ค่าเริ่มต้น: เดี๋ยวนี้) เช่น 2026-08-20}
        {--days=60 : ความยาวของแต่ละเฟส (วัน)}
        {--sale= : ระบุ id ของรอบขาย (ค่าเริ่มต้น: รอบที่ active อยู่)}
        {--dry-run : แสดงผลลัพธ์ที่จะเกิดขึ้นโดยไม่บันทึกจริง}';

    protected $description = 'ตั้งตารางรอบขายเหรียญใหม่ ให้เฟสแรกเริ่มนับจากตอนนี้';

    public function handle(): int
    {
        $sale = $this->option('sale')
            ? TokenSale::find((int) $this->option('sale'))
            : TokenSale::where('status', 'active')->first();

        if (! $sale) {
            $this->error('ไม่พบรอบขาย — ระบุด้วย --sale=<id> หรือทำให้มีรอบที่ status=active');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        try {
            $cursor = $this->option('start')
                ? \Carbon\Carbon::parse($this->option('start'))->startOfDay()
                : now();
        } catch (\Throwable) {
            $this->error('รูปแบบวันที่ของ --start ไม่ถูกต้อง (ใช้เช่น 2026-08-20)');

            return self::FAILURE;
        }

        $phases = $sale->phases()->orderBy('phase_order')->get();
        if ($phases->isEmpty()) {
            $this->error("รอบขาย \"{$sale->name}\" ยังไม่มีเฟสใด ๆ");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $plan = [];
        $skipped = [];
        $isFirstMovable = true;

        foreach ($phases as $phase) {
            // เฟสที่ขายไปแล้ว = ห้ามเลื่อน (เปลี่ยนเงื่อนไขย้อนหลังกับผู้ที่จ่ายเงินแล้ว)
            if ((float) $phase->sold > 0) {
                $skipped[] = [$phase->phase_order, $phase->name, (float) $phase->sold];

                continue;
            }

            $startsAt = $cursor->copy();
            $endsAt = $cursor->copy()->addDays($days);
            $status = $isFirstMovable ? 'active' : 'upcoming';

            $plan[] = [
                'phase' => $phase,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
            ];

            $cursor = $endsAt->copy();
            $isFirstMovable = false;
        }

        if (empty($plan)) {
            $this->warn('ทุกเฟสขายไปแล้ว — ไม่มีอะไรให้เลื่อน');

            return self::SUCCESS;
        }

        $this->info("รอบขาย: {$sale->name}");
        $this->newLine();
        $this->table(
            ['ลำดับ', 'ชื่อเฟส', 'ราคา (USD)', 'โควตา (TPIX)', 'สถานะใหม่', 'เริ่ม', 'สิ้นสุด'],
            collect($plan)->map(fn ($p) => [
                $p['phase']->phase_order,
                $p['phase']->name,
                rtrim(rtrim((string) $p['phase']->price_usd, '0'), '.'),
                number_format((float) $p['phase']->allocation),
                $p['status'],
                $p['starts_at']->format('Y-m-d H:i'),
                $p['ends_at']->format('Y-m-d H:i'),
            ])->all()
        );

        foreach ($skipped as [$order, $name, $sold]) {
            $this->warn("ข้ามเฟส {$order} \"{$name}\" — ขายไปแล้ว ".number_format($sold, 2).' TPIX');
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('โหมดทดลอง (--dry-run) — ยังไม่บันทึกลงฐานข้อมูล');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                SalePhase::whereKey($p['phase']->id)->update([
                    'starts_at' => $p['starts_at'],
                    'ends_at' => $p['ends_at'],
                    'status' => $p['status'],
                ]);
            }
        });

        // getActiveSale() แคชไว้ 30 วิ — ล้างทันทีไม่งั้นหน้าเว็บยังเห็นของเก่า
        Cache::forget('token_sale:active');

        $this->newLine();
        $this->info('บันทึกแล้ว — เฟสแรกเปิดขายตั้งแต่ '.$plan[0]['starts_at']->format('Y-m-d H:i'));

        return self::SUCCESS;
    }
}
