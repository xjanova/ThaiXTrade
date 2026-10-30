<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasterNode\NodeUptimeRecorder;
use App\Services\MasterNode\PeerId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NodePeerReportController — bootnode ของเรารายงานว่าตอนนี้มี peer ไหนต่ออยู่.
 *
 * POST /api/infra/node-peers  (cron tpix-node-peers บนเครื่องเชน ทุก 1 นาที)
 *   { "source": "tpix-bootnode", "peers": ["16Uiu2HAm…", …] }
 *
 * นี่คือหลักฐานชั้นเครือข่ายว่าโหนดรันอยู่จริง — heartbeat บอกได้แค่ว่า "รู้หัวเชน"
 * ซึ่งใครก็อ่านจาก RPC สาธารณะได้ แต่การถือการเชื่อมต่อ libp2p ด้วยกุญแจของ peer ID นั้น
 * เข้ามาที่ bootnode ต้องมีโปรแกรมโหนดรันอยู่
 *
 * เวลาของช่องใช้นาฬิกาเว็บตอนรับ ไม่ใช่เวลาที่เครื่องเชนส่งมา — ฝั่งที่ส่งตัวเลขเข้ามา
 * ต้องไม่ใช่ฝั่งที่เลือกได้ว่าจะลงช่องไหน
 *
 * ความปลอดภัย: Bearer token = TPIX_NODE_PEERS_TOKEN (hash_equals) · ไม่ตั้ง = 503 ไม่ใช่เปิดโล่ง
 *
 * Developed by Xman Studio
 */
class NodePeerReportController extends Controller
{
    /** bootnode ตั้ง --max-inbound-peers ไว้ 150 — เผื่อ bootnode หลายตัว/ขยายทีหลัง */
    private const MAX_PEERS = 2000;

    public function store(Request $request): JsonResponse
    {
        $expected = (string) config('masternode.node_peers_token', '');
        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'not_configured'], 503);
        }

        $token = (string) $request->bearerToken();
        if ($token === '' || ! hash_equals($expected, $token)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $validated = $request->validate([
            'source' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'peers' => ['present', 'array', 'max:'.self::MAX_PEERS],
            'peers.*' => ['string', 'max:64'],
        ]);

        // peer ID ที่ถอดไม่ได้ (ไม่ใช่ secp256k1) ไม่มีทางเป็นโหนดของผู้ stake — ทิ้ง ไม่ล้มทั้งชุด
        $peers = array_values(array_unique(array_filter(
            $validated['peers'],
            fn ($p) => is_string($p) && PeerId::isValid($p),
        )));

        $slot = NodeUptimeRecorder::slotOf(now()->timestamp);
        $now = now();

        DB::transaction(function () use ($peers, $slot, $now, $validated) {
            foreach (array_chunk($peers, 500) as $chunk) {
                DB::table('masternode_peer_sightings')->insertOrIgnore(array_map(fn ($p) => [
                    'peer_id' => $p,
                    'slot' => $slot,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $report = DB::table('masternode_peer_reports')
                ->where('source', $validated['source'])
                ->where('slot', $slot)
                ->first();

            if ($report) {
                DB::table('masternode_peer_reports')->where('id', $report->id)->update([
                    'reports' => (int) $report->reports + 1,
                    'peers' => count($peers),
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('masternode_peer_reports')->insert([
                    'source' => $validated['source'],
                    'slot' => $slot,
                    'reports' => 1,
                    'peers' => count($peers),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return response()->json(['ok' => true, 'slot' => $slot, 'accepted' => count($peers)]);
    }
}
