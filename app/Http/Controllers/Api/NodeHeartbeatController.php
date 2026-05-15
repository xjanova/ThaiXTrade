<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NodeHeartbeatRequest;
use App\Models\MasternodeAllowlist;
use App\Services\MasterNode\CloudflareWafService;
use App\Services\MasterNode\NodeRegistryService;
use App\Services\MasterNode\Web3SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NodeHeartbeatController — รับ heartbeat จาก masternode-ui และจัดการ Cloudflare allowlist
 *
 * Flow:
 *   1. Validate input shape (NodeHeartbeatRequest)
 *   2. Anti-replay: timestamp ใน window
 *   3. Verify delegation signature: wallet sign delegation message → ออกมาเป็น wallet จริง
 *   4. Verify heartbeat signature: delegate-key sign heartbeat message → ออกมาเป็น delegate_address ตรง
 *   5. Verify wallet เป็น masternode (genesis / registry / balance)
 *   6. Get client IP (CF-Connecting-IP > X-Forwarded-For > getRemoteAddr)
 *   7. Add Cloudflare rule + persist row (idempotent — update CF rule ถ้ามีอยู่แล้ว)
 *   8. Return ttl + cf_rule_id
 *
 * Developed by Xman Studio
 */
class NodeHeartbeatController extends Controller
{
    public function __construct(
        private Web3SignatureService $sigService,
        private NodeRegistryService $registryService,
        private CloudflareWafService $cfService,
    ) {}

    public function heartbeat(NodeHeartbeatRequest $request): JsonResponse
    {
        $data = $request->validated();
        $wallet = strtolower($data['wallet']);
        $delegateAddress = strtolower($data['delegate_address']);

        // 1. Anti-replay (a) — timestamp ต้องอยู่ใน window ±N seconds
        $window = (int) config('masternode.heartbeat.timestamp_window', 300);
        $reqTimestamp = (int) $data['timestamp'];
        if (abs(time() - $reqTimestamp) > $window) {
            return response()->json([
                'error' => 'timestamp_out_of_window',
                'message' => "Timestamp drift exceeds {$window}s",
            ], 401);
        }

        // 1. Anti-replay (b) — timestamp ต้อง monotonic increase ต่อ wallet
        // กัน attacker capture sig จาก network แล้ว replay จาก IP อื่นใน 5min window
        // (เดิมจะ overwrite IP ของ operator → hijack allowlist slot)
        $prevSignedTs = (int) (MasternodeAllowlist::where('wallet_address', $wallet)
            ->value('last_signed_timestamp') ?? 0);
        if ($reqTimestamp <= $prevSignedTs) {
            return response()->json([
                'error' => 'timestamp_not_monotonic',
                'message' => 'Heartbeat timestamp must be strictly greater than previous',
                'previous' => $prevSignedTs,
            ], 401);
        }

        // 2. Verify delegation signature (wallet ลงนาม "I authorize delegate")
        $delegationMessage = $this->sigService->buildDelegationMessage(
            $wallet,
            $delegateAddress,
            (int) $data['delegation_expires_at']
        );

        if (!$this->sigService->verify($delegationMessage, $data['delegation_signature'], $wallet)) {
            return response()->json([
                'error' => 'invalid_delegation',
                'message' => 'Delegation signature does not match wallet',
                'expected_message' => $delegationMessage,
            ], 401);
        }

        // 3. Verify heartbeat signature (delegate-key ลงนาม heartbeat)
        $heartbeatMessage = $this->sigService->buildHeartbeatMessage($wallet, (int) $data['timestamp']);
        if (!$this->sigService->verify($heartbeatMessage, $data['signature'], $delegateAddress)) {
            return response()->json([
                'error' => 'invalid_heartbeat_signature',
                'message' => 'Heartbeat signature does not match delegate address',
            ], 401);
        }

        // 4. Verify wallet เป็น masternode
        $registryInfo = $this->registryService->lookup($wallet);
        if ($registryInfo === null) {
            return response()->json([
                'error' => 'not_registered',
                'message' => 'Wallet is not registered as masternode/validator',
            ], 403);
        }

        $tier = $registryInfo['tier'];

        // 5. Anti-spam: ห้าม renew บ่อยเกิน
        $minRenew = (int) config('masternode.heartbeat.min_renew_interval_seconds', 60);
        $existing = MasternodeAllowlist::where('wallet_address', $wallet)->first();
        if ($existing && $existing->last_heartbeat &&
            now()->diffInSeconds($existing->last_heartbeat) < $minRenew) {
            return response()->json([
                'error' => 'renew_too_soon',
                'message' => "Wait at least {$minRenew}s between heartbeats",
                'next_allowed_at' => $existing->last_heartbeat->addSeconds($minRenew)->timestamp,
            ], 429);
        }

        // 6. Get client IP — ไว้ใจ CF-Connecting-IP เฉพาะเมื่อ request มาจาก Cloudflare จริงๆ
        //
        // ป้องกัน IP spoofing: ถ้า origin server ถูก bypass (เข้า direct ไม่ผ่าน CF) attacker
        // ส่ง header "CF-Connecting-IP: <any ip>" เพื่อ allowlist IP ปลอมได้
        //
        // ทางแก้: trust CF-Connecting-IP เฉพาะเมื่อ CF-Ray header มาด้วย (CF ใส่ทุก request)
        // + Production ควรตั้ง origin firewall ให้รับเฉพาะ Cloudflare IP ranges อีกชั้น
        $cfRay = $request->header('CF-Ray');
        $trustCfHeader = config('masternode.cloudflare.trust_cf_headers', true);

        if ($trustCfHeader && $cfRay && $request->header('CF-Connecting-IP')) {
            $ip = $request->header('CF-Connecting-IP');
        } else {
            // fallback: ใช้ Laravel's resolved IP (เคารพ TrustedProxies middleware)
            $ip = $request->ip();
        }

        // Validate IP format อีกชั้น (กัน garbage)
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'error' => 'invalid_client_ip',
                'message' => 'Could not determine valid client IP',
            ], 400);
        }

        $ttl = (int) config('masternode.heartbeat.ttl_seconds', 3600);
        $allowedUntil = now()->addSeconds($ttl);

        // 7. Add CF rule + persist (transaction)
        return DB::transaction(function () use (
            $wallet,
            $delegateAddress,
            $data,
            $ip,
            $tier,
            $allowedUntil,
            $ttl,
            $existing
        ) {
            $cfRuleId = null;

            // ถ้ามี entry เก่า + IP เดิม + rule ยังอยู่ → reuse rule (แค่ update DB)
            if ($existing && $existing->ip_address === $ip && $existing->cf_rule_id) {
                $cfRuleId = $existing->cf_rule_id;
            } else {
                // ลบ rule เก่าถ้ามี (IP เปลี่ยน)
                if ($existing && $existing->cf_rule_id) {
                    $this->cfService->deleteRule($existing->cf_rule_id);
                }

                // เพิ่ม rule ใหม่
                $newRule = $this->cfService->addAllowRule($ip, $wallet, $tier);
                $cfRuleId = $newRule['id'] ?? null;
            }

            // Note: ใช้ explicit increment เพื่อหลีกเลี่ยง DB::raw ใน insert path
            // (DB::raw('heartbeat_count + 1') จะ fail ถ้า column ยังไม่มี value ตอน insert)
            $row = MasternodeAllowlist::firstOrNew(['wallet_address' => $wallet]);
            $row->delegate_address = $delegateAddress;
            $row->delegation_signature = $data['delegation_signature'];
            $row->delegation_expires_at = date('Y-m-d H:i:s', (int) $data['delegation_expires_at']);
            $row->ip_address = $ip;
            $row->tier = $tier;
            $row->cf_rule_id = $cfRuleId;
            $row->allowed_until = $allowedUntil;
            $row->last_heartbeat = now();
            $row->last_signed_timestamp = (int) $data['timestamp'];
            $row->heartbeat_count = ($row->heartbeat_count ?? 0) + 1;
            $row->status = 'active';
            $row->save();

            Log::channel(config('masternode.log_channel', 'daily'))->info('masternode.heartbeat', [
                'wallet' => $wallet,
                'tier' => $tier,
                'ip' => $ip,
                'cf_rule_id' => $cfRuleId,
                'allowed_until' => $allowedUntil->toIso8601String(),
                'heartbeat_count' => $row->heartbeat_count,
            ]);

            return response()->json([
                'ok' => true,
                'wallet' => $wallet,
                'tier' => $tier,
                'ip' => $ip,
                'cf_rule_id' => $cfRuleId,
                'allowed_until' => $allowedUntil->timestamp,
                'next_renew_at' => now()->addSeconds(min(1800, (int) ($ttl / 2)))->timestamp,
            ]);
        });
    }

    /**
     * GET /api/v1/node/status — ให้ masternode-ui เช็คสถานะตัวเองว่ายัง allowlist อยู่มั้ย
     *
     * Public endpoint — ห้าม return IP จริงของ operator (privacy + กัน DDoS reconnaissance)
     * Caller verify ว่าตัวเองคือ allowlist ผ่าน is_active + match กับ IP ของตัวเองเอง
     */
    public function status(string $wallet, \Illuminate\Http\Request $request)
    {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return response()->json(['error' => 'invalid_wallet'], 400);
        }

        $row = MasternodeAllowlist::where('wallet_address', strtolower($wallet))->first();

        if (!$row) {
            return response()->json(['exists' => false]);
        }

        // เช็คว่า caller มี IP ตรงกับ allowlist เพื่อ confirm ตัวเอง (โดยไม่ leak IP จริงให้คนอื่น)
        $cfRay = $request->header('CF-Ray');
        $trustCfHeader = config('masternode.cloudflare.trust_cf_headers', true);
        $callerIp = ($trustCfHeader && $cfRay && $request->header('CF-Connecting-IP'))
            ? $request->header('CF-Connecting-IP')
            : $request->ip();

        return response()->json([
            'exists' => true,
            'wallet' => $row->wallet_address,
            'tier' => $row->tier,
            // ห้าม return ip_address จริง — leak ทำให้ DDoS attacker ยิงตรง origin ได้
            'ip_matches_caller' => $row->ip_address === $callerIp,
            'allowed_until' => $row->allowed_until?->timestamp,
            'last_heartbeat' => $row->last_heartbeat?->timestamp,
            'heartbeat_count' => $row->heartbeat_count,
            'status' => $row->status,
            'is_active' => $row->status === 'active' && $row->allowed_until > now(),
        ]);
    }
}
