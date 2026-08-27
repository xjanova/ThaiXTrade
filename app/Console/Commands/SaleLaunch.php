<?php

namespace App\Console\Commands;

use App\Services\SaleLaunchService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * เปิดรอบขายเหรียญ — เฟสแรกเริ่มนับจากวันที่เปิดจริง.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * เจ้าของกำหนด: "พร้อมจำหน่ายเมื่อไหร่ ก็เริ่มเฟสการขายใหม่แต่แรกวันนั้น"
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมวันของแต่ละเฟสถูกตั้งตายตัวไว้ล่วงหน้า แล้วระบบไม่เคยพร้อมขายตามวันนั้น
 * เฟสแรกจึงหมดอายุไปเงียบ ๆ โดยยังไม่เคยขายได้เลยสักบาท (ค้างมา 3 เดือน)
 *
 * ตัวนี้ใช้ได้สองแบบ:
 *
 *   sale:launch                    เปิดเดี๋ยวนี้ (ตรวจความพร้อมก่อน)
 *   sale:launch --auto             โหมดอัตโนมัติ — รันจากตารางเวลาทุกชั่วโมง
 *                                  เปิดให้เองทันทีที่ระบบพร้อมจริง ถ้าเจ้าของ
 *                                  ติดอาวุธไว้ที่ /admin/token-sales
 *
 * ⚠️ --force ใช้ได้เฉพาะคนกดเอง โหมด --auto ห้ามข้ามด่านความพร้อมเด็ดขาด
 *    เพราะการเปิดรับเงินทั้งที่ยังไม่มีเหรียญให้จ่าย = รับเงินแล้วไม่ได้ของ
 *
 * Developed by Xman Studio.
 */
class SaleLaunch extends Command
{
    protected $signature = 'sale:launch
        {--start= : วันเริ่มเฟสแรก (ค่าเริ่มต้น: เดี๋ยวนี้) เช่น 2026-09-01}
        {--sale= : id ของรอบขาย (ค่าเริ่มต้น: รอบที่ active อยู่)}
        {--days= : บังคับให้ทุกเฟสยาวเท่ากันกี่วัน (ค่าเริ่มต้น: ใช้ความยาวของแต่ละเฟส)}
        {--auto : โหมดอัตโนมัติ — เปิดเมื่อติดอาวุธไว้ + ระบบพร้อม + ยังไม่เคยเปิด}
        {--force : ข้ามด่านความพร้อม (ใช้ไม่ได้กับ --auto)}
        {--dry-run : แสดงตารางที่จะได้โดยไม่บันทึกจริง}';

    protected $description = 'เปิดรอบขายเหรียญ TPIX โดยให้เฟสแรกเริ่มนับจากวันที่เปิดจริง';

    public function handle(SaleLaunchService $launcher): int
    {
        $auto = (bool) $this->option('auto');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($auto && $force) {
            $this->error('--force ใช้กับ --auto ไม่ได้ — ตัวเปิดอัตโนมัติต้องผ่านด่านความพร้อมเสมอ');

            return self::FAILURE;
        }

        $sale = $launcher->targetSale($this->option('sale') ? (int) $this->option('sale') : null);

        if (! $sale) {
            // โหมดอัตโนมัติเงียบไว้ ไม่ใช่ความผิดพลาด — แค่ยังไม่มีรอบขาย
            $auto ? $this->line('ยังไม่มีรอบขายที่เปิดอยู่') : $this->error('ไม่พบรอบขาย — ระบุด้วย --sale=<id>');

            return $auto ? self::SUCCESS : self::FAILURE;
        }

        // ── โหมดอัตโนมัติ: ด่านสามชั้นก่อนแตะอะไร ────────────────────────
        if ($auto) {
            if (! $launcher->autoLaunchArmed()) {
                $this->line('ยังไม่ได้เปิดสวิตช์ "เปิดขายอัตโนมัติ" — ไม่ทำอะไร');

                return self::SUCCESS;
            }

            if ($launcher->alreadyLaunched($sale)) {
                $this->line('รอบขายนี้เปิดไปแล้วเมื่อ '.$sale->launched_at?->format('Y-m-d H:i').' — ไม่เปิดซ้ำ');

                return self::SUCCESS;
            }
        }

        // ── วันเริ่ม ─────────────────────────────────────────────────────
        try {
            $start = $this->option('start')
                ? Carbon::parse((string) $this->option('start'))->startOfDay()
                : now();
        } catch (\Throwable) {
            $this->error('รูปแบบวันที่ของ --start ไม่ถูกต้อง (ใช้เช่น 2026-09-01)');

            return self::FAILURE;
        }

        $flatDays = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        // ── ความพร้อม ────────────────────────────────────────────────────
        $readiness = $launcher->readiness($sale);
        $this->renderReadiness($readiness);

        if (! $readiness['ready']) {
            if ($auto) {
                // ไม่ใช่ความผิดพลาด — แค่ยังไม่ถึงเวลา รอบหน้าค่อยเช็คใหม่
                $this->comment('ยังไม่พร้อม — จะตรวจใหม่รอบหน้า');

                return self::SUCCESS;
            }

            if (! $force) {
                $this->error('ยังเปิดขายไม่ได้ — แก้ข้อที่ยังไม่ผ่านก่อน หรือใช้ --force ถ้ายืนยันจะเปิดทั้งที่ยังไม่ครบ');

                return self::FAILURE;
            }

            $this->warn('--force: เปิดขายทั้งที่ยังไม่ผ่านครบ — '.implode(' · ', $readiness['blocking']));
        }

        // ── แผนตาราง ─────────────────────────────────────────────────────
        $plan = $launcher->plan($sale, $start, $flatDays);

        if ($plan['rows'] === []) {
            $this->warn('ทุกเฟสขายไปแล้ว — ไม่มีอะไรให้ตั้งวันใหม่');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("รอบขาย: {$sale->name}");
        $this->table(
            ['ลำดับ', 'เฟส', 'ราคา (USD)', 'โควตา (TPIX)', 'ยาว (วัน)', 'สถานะใหม่', 'เริ่ม', 'สิ้นสุด'],
            collect($plan['rows'])->map(fn ($r) => [
                $r['phase']->phase_order,
                $r['phase']->name,
                rtrim(rtrim((string) $r['phase']->price_usd, '0'), '.'),
                number_format((float) $r['phase']->allocation),
                $r['days'],
                $r['status'],
                $r['starts_at']->format('Y-m-d H:i'),
                $r['ends_at']->format('Y-m-d H:i'),
            ])->all()
        );

        foreach ($plan['skipped'] as $s) {
            $this->warn("ข้ามเฟส {$s['phase']->phase_order} \"{$s['phase']->name}\" — {$s['reason']}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('โหมดทดลอง (--dry-run) — ยังไม่บันทึกลงฐานข้อมูล');

            return self::SUCCESS;
        }

        /*
         * ── บันทึก ───────────────────────────────────────────────────────
         *
         * launch() ตรวจความพร้อมซ้ำอีกรอบโดยตั้งใจ (ถามยอดกระเป๋าใหม่)
         * ถ้าสถานะเพิ่งพลิกไปไม่พร้อมระหว่างนี้ มันจะปฏิเสธเอง — fail-closed
         * ส่งแค่ $force ไปพอ ไม่ต้องบอกมันว่าเราตรวจผ่านมาแล้ว
         */
        $result = $launcher->launch($sale, $start, $flatDays, $force);

        if (! $result['ok']) {
            $this->error($result['message'] ?? 'เปิดรอบขายไม่สำเร็จ');

            return self::FAILURE;
        }

        if ($auto) {
            Log::info('sale:launch --auto เปิดรอบขายอัตโนมัติ', [
                'sale_id' => $sale->id,
                'start' => $start->toIso8601String(),
            ]);
        }

        $this->newLine();
        $this->info($result['message']);
        $this->info('รอบขายสิ้นสุด '.$sale->fresh()->ends_at?->format('Y-m-d H:i'));

        return self::SUCCESS;
    }

    /**
     * @param  array{ready:bool, checks:array<int,array<string,mixed>>, blocking:array<int,string>}  $readiness
     */
    private function renderReadiness(array $readiness): void
    {
        $this->line('ความพร้อมก่อนเปิดขาย');
        $this->table(
            ['', 'รายการ', 'รายละเอียด'],
            collect($readiness['checks'])->map(fn (array $c) => [
                $c['ok'] ? 'ผ่าน' : 'ยังไม่ผ่าน',
                $c['label'],
                $c['ok'] ? $c['detail'] : $c['detail'].' → '.$c['fix'],
            ])->all()
        );
    }
}
