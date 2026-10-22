<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasternodeNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/masternode/live-nodes — โหนดที่ส่ง heartbeat รุ่น 2 มาในช่วงล่าสุด.
 *
 * สาธารณะ: แสดงแค่ระดับประเทศ/เมือง (+พิกัดของเมือง ปัด 0.1°) — ไม่มี IP, ไม่มี peer ID
 * uptime_24h_bps = ค่าเฉลี่ยของผลรายรอบใน 24 ชม. ที่ผ่านมา (null = ยังไม่มีรอบที่ปิด)
 *
 * แอปมาสเตอร์โหนดใช้วาดแผนที่ — cache 60 วิ ไม่ต้องยิงฐานข้อมูลทุกคนที่เปิดหน้าแผนที่
 *
 * Developed by Xman Studio
 */
class MasternodeLiveNodesController extends Controller
{
    /** เห็นล่าสุดภายในเท่านี้ถึงเรียกว่าออนไลน์ (heartbeat ทุก 2 นาที → พลาดได้ 4 ครั้ง) */
    private const ONLINE_SECONDS = 600;

    /** แสดงโหนดที่เงียบไปไม่เกิน 24 ชม. (ออฟไลน์แต่ยังเพิ่งเห็น) */
    private const LIST_SECONDS = 86400;

    public function index(): JsonResponse
    {
        $data = cache()->remember('masternode:live-nodes', 60, function () {
            $nodes = MasternodeNode::where('last_seen_at', '>=', now()->subSeconds(self::LIST_SECONDS))
                ->orderByDesc('last_seen_at')
                ->limit(1000)
                ->get();

            $uptime = DB::table('masternode_uptime_periods')
                ->whereIn('wallet_address', $nodes->pluck('wallet_address'))
                ->where('period_start', '>=', now()->subDay()->timestamp)
                ->selectRaw('wallet_address, AVG(uptime_bps) as avg_bps')
                ->groupBy('wallet_address')
                ->pluck('avg_bps', 'wallet_address');

            $onlineAfter = now()->subSeconds(self::ONLINE_SECONDS);
            $list = $nodes->map(function (MasternodeNode $n) use ($uptime, $onlineAfter) {
                $avg = $uptime[$n->wallet_address] ?? null;

                return $n->toPublicArray($avg === null ? null : (int) floor((float) $avg))
                    + ['online' => $n->last_seen_at !== null && $n->last_seen_at->gte($onlineAfter)];
            })->values();

            return [
                'nodes' => $list,
                'online' => $list->where('online', true)->count(),
                'countries' => $list->pluck('country_code')->filter()->unique()->count(),
                'generated_at' => now()->timestamp,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
