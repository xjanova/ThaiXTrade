<?php

namespace App\Services\MasterNode;

use Illuminate\Support\Facades\DB;

/**
 * UptimeCalculator — ช่องเวลาที่บันทึกไว้ → uptime (bps) ต่อกระเป๋าในรอบหนึ่ง.
 *
 * ช่องหนึ่ง "นับ" เมื่อ
 *   require_peer_seen = true  (ค่าเริ่มต้น):
 *       heartbeat ในช่องนั้นไม่ได้ถูกตัดสินว่าบล็อกผิด/ล้า (block_ok ≠ false)
 *       และ bootnode เห็น peer ID ของ heartbeat นั้นในช่องเดียวกันหรือช่องติดกัน
 *       (block_ok = null คือ RPC ของเราตรวจไม่ได้ — การเห็นที่ bootnode เป็นหลักฐานพอ)
 *   require_peer_seen = false:
 *       heartbeat ในช่องนั้นตรงเชนและใหม่พอ (block_ok = true) — โกงได้ด้วย RPC สาธารณะ ใช้ทดสอบเท่านั้น
 *
 * ช่องที่ "คาดว่าต้องมี" เริ่มนับจากช่องเต็มช่องแรกหลังลงทะเบียน — โหนดที่เพิ่งเข้ามากลางรอบ
 * ไม่ถูกหักช่องที่ยังไม่ได้เป็นสมาชิก
 *
 * รอบที่ "ข้อมูลฝั่งเราขาด" (degraded) — ผู้เรียกต้องไม่ลด uptime ของใครในรอบนั้น:
 *   - bootnode รายงานเข้ามาน้อยกว่า peer_feed_min_coverage ของนาทีในรอบ
 *   - รอบก่อนมีโหนดส่ง heartbeat ≥ 3 กระเป๋า แต่รอบนี้ไม่มีเลยสักแถว (เว็บรับไม่ได้)
 *
 * Developed by Xman Studio
 */
class UptimeCalculator
{
    /** cron ของ bootnode ยิงทุก 1 นาที → ช่อง 5 นาทีควรมี 5 รายงาน */
    private const REPORTS_PER_MINUTE = 1;

    /**
     * @param  array<string,int>  $operators  wallet (lowercase) => registered_at (unix)
     * @return array{
     *     degraded: bool,
     *     reasons: array<int,string>,
     *     feed_coverage: float,
     *     results: array<string, array{expected:int, ok:int, bps:int}>
     * }
     */
    public function compute(int $periodStart, array $operators): array
    {
        $slotSeconds = (int) config('masternode.uptime.slot_seconds', 300);
        $periodSeconds = $this->periodSeconds();
        $firstSlot = intdiv($periodStart, $slotSeconds);
        $lastSlot = $firstSlot + intdiv($periodSeconds, $slotSeconds) - 1;
        $requirePeer = (bool) config('masternode.uptime.require_peer_seen', true);

        $reasons = [];
        $coverage = $requirePeer ? $this->feedCoverage($firstSlot, $lastSlot, $slotSeconds) : 1.0;
        if ($coverage < (float) config('masternode.uptime.peer_feed_min_coverage', 0.8)) {
            $reasons[] = 'peer_feed_gap';
        }
        if ($this->intakeOutage($firstSlot, $lastSlot)) {
            $reasons[] = 'heartbeat_intake_gap';
        }

        $wallets = array_keys($operators);
        $slots = [];
        foreach (array_chunk($wallets, 500) as $chunk) {
            DB::table('masternode_uptime_slots')
                ->whereIn('wallet_address', $chunk)
                ->whereBetween('slot', [$firstSlot, $lastSlot])
                ->get(['wallet_address', 'slot', 'peer_id', 'block_ok'])
                ->each(function ($row) use (&$slots) {
                    $slots[$row->wallet_address][] = $row;
                });
        }

        $seen = [];
        if ($requirePeer) {
            DB::table('masternode_peer_sightings')
                ->whereBetween('slot', [$firstSlot - 1, $lastSlot + 1])
                ->get(['peer_id', 'slot'])
                ->each(function ($row) use (&$seen) {
                    $seen[$row->peer_id][(int) $row->slot] = true;
                });
        }

        $results = [];
        foreach ($operators as $wallet => $registeredAt) {
            // ช่องเต็มช่องแรกหลังลงทะเบียน (ปัดขึ้น)
            $startSlot = max($firstSlot, intdiv($registeredAt + $slotSeconds - 1, $slotSeconds));
            $expected = $lastSlot - $startSlot + 1;
            if ($expected <= 0) {
                continue; // ลงทะเบียนหลังรอบนี้จบ — ไม่มีอะไรให้วัด
            }

            $ok = 0;
            foreach ($slots[$wallet] ?? [] as $row) {
                $slot = (int) $row->slot;
                if ($slot < $startSlot) {
                    continue;
                }
                $blockOk = $row->block_ok === null ? null : (bool) $row->block_ok;

                $credited = $requirePeer
                    ? $blockOk !== false && (
                        isset($seen[$row->peer_id][$slot])
                        || isset($seen[$row->peer_id][$slot - 1])
                        || isset($seen[$row->peer_id][$slot + 1])
                    )
                    : $blockOk === true;

                if ($credited) {
                    $ok++;
                }
            }

            $results[$wallet] = [
                'expected' => $expected,
                'ok' => $ok,
                'bps' => intdiv($ok * 10000, $expected),
            ];
        }

        return [
            'degraded' => $reasons !== [],
            'reasons' => $reasons,
            'feed_coverage' => $coverage,
            'results' => $results,
        ];
    }

    public function periodSeconds(): int
    {
        return max(5, (int) config('masternode.uptime.period_minutes', 60)) * 60;
    }

    /** ต้นรอบล่าสุดที่ปิดแล้ว (unix) */
    public function lastClosedPeriodStart(int $now): int
    {
        $ps = $this->periodSeconds();

        return intdiv($now, $ps) * $ps - $ps;
    }

    /**
     * สัดส่วนนาทีที่ bootnode รายงานเข้ามาจริง (0..1) — หลาย bootnode ใช้ตัวที่รายงานมากสุดต่อช่อง.
     */
    private function feedCoverage(int $firstSlot, int $lastSlot, int $slotSeconds): float
    {
        $perSlot = intdiv($slotSeconds, 60) * self::REPORTS_PER_MINUTE;
        $expected = ($lastSlot - $firstSlot + 1) * $perSlot;

        $got = DB::table('masternode_peer_reports')
            ->whereBetween('slot', [$firstSlot, $lastSlot])
            ->selectRaw('slot, MAX(reports) as reports')
            ->groupBy('slot')
            ->get()
            ->sum(fn ($r) => min((int) $r->reports, $perSlot));

        return $expected > 0 ? $got / $expected : 0.0;
    }

    /**
     * เว็บรับ heartbeat ไม่ได้ทั้งรอบ (ล่ม/deploy พัง) ทั้งที่รอบก่อนมีคนส่งปกติ.
     *
     * ไม่ใช้ "รอบนี้ไม่มี heartbeat เลย" อย่างเดียว — เครือข่ายที่ยังไม่มีใครรันโหนดจริงก็เป็นแบบนั้น
     * และโหนดที่ stake เฉย ๆ ต้องถูกลดเป็น 0 ได้ตั้งแต่รอบแรก
     */
    private function intakeOutage(int $firstSlot, int $lastSlot): bool
    {
        $span = $lastSlot - $firstSlot + 1;
        $now = DB::table('masternode_uptime_slots')->whereBetween('slot', [$firstSlot, $lastSlot])->count();
        if ($now > 0) {
            return false;
        }

        $before = DB::table('masternode_uptime_slots')
            ->whereBetween('slot', [$firstSlot - $span, $firstSlot - 1])
            ->distinct()
            ->count('wallet_address');

        return $before >= 3;
    }
}
