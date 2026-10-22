<?php

namespace App\Services\MasterNode;

use App\Models\MasternodeNode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NodeUptimeRecorder — บันทึก heartbeat รุ่น 2 ที่ผ่านการตรวจลายเซ็นแล้ว.
 *
 *   1. ผูกกระเป๋า ↔ กุญแจโหนด (peer ID) — 1 ต่อ 1
 *   2. ช่องเวลา 5 นาที: บันทึกว่าบล็อกที่โหนดเห็นตรงเชนไหม
 *   3. สถานะล่าสุด + ประเทศ/เมือง (ค้น GeoIP ใหม่เฉพาะตอน IP เปลี่ยน)
 *
 * การตัดสินว่าช่องไหน "นับ" อยู่ที่ UptimeCalculator ตอนปิดรอบ — เพราะรายชื่อ peer
 * จาก bootnode อาจมาถึงหลัง heartbeat ในช่องเดียวกัน
 *
 * Developed by Xman Studio
 */
class NodeUptimeRecorder
{
    /** กุญแจโหนดที่ผูกกับกระเป๋าอื่นแต่เงียบไปนานเท่านี้ ย้ายมาผูกกระเป๋าใหม่ได้ (ย้ายเครื่อง/เปลี่ยนกระเป๋า) */
    private const REBIND_AFTER_SECONDS = 86400;

    public function __construct(private GeoIpService $geo) {}

    public static function slotOf(int $unix): int
    {
        return intdiv($unix, (int) config('masternode.uptime.slot_seconds', 300));
    }

    /**
     * ตรวจการผูกก่อนรับ heartbeat — null = ผ่าน, string = รหัสปฏิเสธ.
     *
     *   peer_bound_to_other_wallet  กุญแจโหนดนี้ผูกกับกระเป๋าอื่นที่ยังส่ง heartbeat อยู่
     *                               (เครื่องเดียวรับรางวัลแทนหลายกระเป๋าไม่ได้)
     *   stale_delegation            กระเป๋านี้ย้ายไปกุญแจโหนดตัวใหม่แล้ว — เครื่องเก่าที่ยังเปิดอยู่
     *                               ถือการมอบอำนาจที่เก่ากว่า จึงถูกปฏิเสธ
     */
    public function bindingConflict(string $wallet, string $peerId, int $delegationExpiresAt): ?string
    {
        $other = MasternodeNode::where('peer_id', $peerId)->where('wallet_address', '!=', $wallet)->first();
        if ($other && $other->last_seen_at && $other->last_seen_at->gt(now()->subSeconds(self::REBIND_AFTER_SECONDS))) {
            return 'peer_bound_to_other_wallet';
        }

        // เครื่องเก่าที่เงียบเกิน 24 ชม. ย้ายออกได้เสมอ — ไม่งั้นถ้าเว็บลดเพดานอายุการมอบอำนาจลง
        // เครื่องใหม่จะได้วันหมดอายุที่ "เก่ากว่า" แล้วติดอยู่ตรงนี้จนการมอบอำนาจเดิมหมดอายุ
        $mine = MasternodeNode::where('wallet_address', $wallet)->first();
        if ($mine && $mine->peer_id !== $peerId && $mine->delegation_expires_at
            && $delegationExpiresAt <= $mine->delegation_expires_at->timestamp
            && $mine->last_seen_at && $mine->last_seen_at->gt(now()->subSeconds(self::REBIND_AFTER_SECONDS))) {
            return 'stale_delegation';
        }

        return null;
    }

    /**
     * @param  array{wallet:string, peer_id:string, node_address:string, delegation_expires_at:int,
     *               block_number:int, tier:string, app_version:?string, peer_count:?int}  $hb
     * @param  array{status:string, lag:int|null, head:int|null}  $check  ผลจาก ChainHeadService::verify
     * @return array{slot:int, block_ok:bool|null}
     */
    public function record(array $hb, array $check, string $ip): array
    {
        $now = now();
        $slot = self::slotOf($now->timestamp);
        $blockOk = match ($check['status']) {
            ChainHeadService::OK => true,
            ChainHeadService::UNVERIFIED => null,
            default => false,
        };

        DB::transaction(function () use ($hb, $check, $now) {
            // กุญแจโหนดเคยผูกกระเป๋าอื่นที่เงียบเกิน 24 ชม. (ผ่าน bindingConflict มาแล้ว) — ปลดก่อน
            MasternodeNode::where('peer_id', $hb['peer_id'])
                ->where('wallet_address', '!=', $hb['wallet'])
                ->delete();

            $node = MasternodeNode::firstOrNew(['wallet_address' => $hb['wallet']]);
            $node->fill([
                'peer_id' => $hb['peer_id'],
                'node_address' => $hb['node_address'],
                'delegation_expires_at' => date('Y-m-d H:i:s', $hb['delegation_expires_at']),
                'tier' => $hb['tier'],
                'app_version' => $hb['app_version'],
                'peer_count' => $hb['peer_count'],
                'last_seen_at' => $now,
                'last_block_number' => $hb['block_number'],
                'last_block_lag' => $check['lag'],
                'last_check' => $check['status'],
            ]);
            $node->first_seen_at ??= $now;
            $node->save();
        });

        $this->recordSlot($hb['wallet'], $slot, $hb['peer_id'], $hb['block_number'], $blockOk);
        $this->updateGeo($hb['wallet'], $ip);

        return ['slot' => $slot, 'block_ok' => $blockOk];
    }

    /**
     * หนึ่งแถวต่อกระเป๋าต่อช่อง — heartbeat ไหนในช่องตรงเชน ช่องนั้นถือว่าตรงเชน.
     */
    private function recordSlot(string $wallet, int $slot, string $peerId, int $blockNumber, ?bool $blockOk): void
    {
        $existing = DB::table('masternode_uptime_slots')
            ->where('wallet_address', $wallet)
            ->where('slot', $slot)
            ->first();

        if ($existing === null) {
            try {
                DB::table('masternode_uptime_slots')->insert([
                    'wallet_address' => $wallet,
                    'slot' => $slot,
                    'peer_id' => $peerId,
                    'block_number' => $blockNumber,
                    'block_ok' => $blockOk,
                    'heartbeats' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // heartbeat สองตัวในช่องเดียวกันมาพร้อมกัน — อีกตัวใส่ไปก่อน ไปรวมผลด้านล่าง
                $existing = DB::table('masternode_uptime_slots')
                    ->where('wallet_address', $wallet)
                    ->where('slot', $slot)
                    ->first();
            }
        }

        $old = $existing->block_ok === null ? null : (bool) $existing->block_ok;
        $merged = match (true) {
            $blockOk === true || $old === true => true,
            $blockOk === false || $old === false => false,
            default => null,
        };

        DB::table('masternode_uptime_slots')->where('id', $existing->id)->update([
            // peer ID ของ heartbeat ที่ตรงเชน มีน้ำหนักกว่า — ใช้ตัวนั้นไปเทียบกับรายชื่อ bootnode
            'peer_id' => $blockOk === true || $old !== true ? $peerId : $existing->peer_id,
            'block_number' => max($blockNumber, (int) $existing->block_number),
            'block_ok' => $merged,
            'heartbeats' => (int) $existing->heartbeats + 1,
            'updated_at' => now(),
        ]);
    }

    /** ค้นประเทศ/เมืองใหม่เฉพาะตอน IP เปลี่ยน — ล้มก็ไม่ทำให้ heartbeat ล้ม */
    private function updateGeo(string $wallet, string $ip): void
    {
        try {
            // hash พร้อม app key — ตาราง (IP → hash) ทำล่วงหน้าไม่ได้ถ้าไม่รู้คีย์
            $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
            $node = MasternodeNode::where('wallet_address', $wallet)->first();
            // ยังไม่ได้โหลดฐาน GeoLite2 — อย่าจำ hash ไว้ ไม่งั้นพอโหลดฐานแล้วจะไม่ค้นจนกว่า IP เปลี่ยน
            if ($node === null || $node->geo_ip_hash === $ipHash || ! $this->geo->available()) {
                return;
            }

            $geo = $this->geo->locate($ip);
            $node->fill([
                'country_code' => $geo['country_code'] ?? null,
                'country_name' => $geo['country_name'] ?? null,
                'city' => $geo['city'] ?? null,
                'latitude' => $geo['latitude'] ?? null,
                'longitude' => $geo['longitude'] ?? null,
                'geo_ip_hash' => $ipHash,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('masternode geo update failed', ['wallet' => $wallet, 'error' => $e->getMessage()]);
        }
    }
}
