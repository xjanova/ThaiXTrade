<?php

namespace App\Http\Controllers;

use App\Services\MasterNode\NodeRegistryContract;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * MasterNodeController — หน้าตั้งค่า Master Node + แดชบอร์ดเครือข่าย.
 *
 * อ่านสถานะจริงจาก NodeRegistryV2 บน TPIX Chain ผ่าน NodeRegistryContract
 * (ตัวอ่านสัญญาตัวเดียวของทั้งระบบ — selector ตรวจกับ ABI จริงแล้ว + ส่ง User-Agent)
 *
 * Developed by Xman Studio.
 */
class MasterNodeController extends Controller
{
    public function __construct(private NodeRegistryContract $registry) {}

    /**
     * Master Node setup & network dashboard page.
     */
    public function index()
    {
        $stats = $this->getNetworkStats();
        $state = $this->registry->deploymentState();
        $live = $state === true;

        return Inertia::render('MasterNode/Index', [
            'stats' => $stats,
            'tiers' => $this->getTiers(),
            'nodes' => [],
            'registryAddress' => $this->registry->address() ?? '',
            'registryLive' => $live,
            // RPC ล้มตอนตรวจโค้ดสัญญา — ห้ามขึ้นป้าย "รอติดตั้งสัญญา" ทั้งที่อาจมีคนวางค้ำอยู่แล้ว
            'registryUnknown' => $state === null,
            'kycContract' => $live ? $this->registry->kycContract() : null,
            // null = ไม่ทราบ (ยังไม่ deploy / RPC ล้ม / สัญญารุ่นเก่า) — ห้ามตีความว่า "เปิดรับอยู่"
            // ใช้ค่าจาก stats ที่ cache ไว้แล้ว ไม่ยิง RPC ซ้ำ และหน้าเว็บ poll stats อยู่แล้วทุก 30 วิ
            'registrationPaused' => $stats['registration_paused'] ?? null,
            'rpcUrl' => $this->registry->rpcUrl(),
            'chainId' => (int) config('blockchain.tpix_chain_id', 4289),
            'explorerUrl' => $this->explorerUrl(),
        ]);
    }

    /**
     * Master Node setup guide page.
     */
    public function guide()
    {
        return Inertia::render('MasterNode/Guide');
    }

    /**
     * API: Get network stats (public).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getNetworkStats(),
        ]);
    }

    /**
     * API: ค่าคอนฟิกแต่ละชั้นตามที่อยู่บนเชนจริง (min stake / lock / โควตาที่เหลือ).
     *
     * หน้าเว็บใช้ตัวนี้กันผู้ใช้เซ็น tx ที่ revert แน่ ๆ เช่นชั้นเต็มแล้ว
     */
    public function tiers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getTiers(),
        ]);
    }

    /**
     * API: ที่อยู่สัญญา + เชน สำหรับแอปนอกเว็บ (public, read-only).
     *
     * โปรแกรมมาสเตอร์โหนดบน PC และ TPIX Wallet ใช้ endpoint นี้ "ค้นหา" สัญญาที่ deploy จริง
     * แทนการ hardcode ที่อยู่ไว้ในแอป — เชนเคย regenesis แล้วสัญญาหาย ที่อยู่ในแอปที่ลงไปแล้ว
     * แก้ไม่ได้จนกว่าผู้ใช้จะอัปเดต แต่ที่อยู่ที่นี่อัปเดตเองตอนสคริปต์ deploy ลงทะเบียน
     *
     * ⚠️ คีย์ทุกตัวในนี้เป็นสัญญากับแอปใน repo อื่น — เปลี่ยนชื่อ/ลบ = แอปที่แจกไปแล้วพัง
     *    ค่า "ไม่ทราบ" ส่งเป็น null เสมอ (ไม่ใช่ false/0) ให้แอปแยกออกจาก "ทราบแล้วว่าไม่"
     */
    public function config(): JsonResponse
    {
        $data = cache()->remember('masternode:config:public', 30, function () {
            // null = RPC ล้ม อ่านไม่ได้ว่ามีสัญญาหรือไม่ — ไม่ใช่ false ("ทราบแน่ว่ายังไม่ deploy")
            // แอป PC / TPIX Wallet เช็ก registry_live === true อยู่แล้ว null จึงไม่เปลี่ยนพฤติกรรมของแอป
            $state = $this->registry->deploymentState();
            $live = $state === true;

            return [
                'registry_address' => $this->registry->address(),
                'registry_live' => $state,
                'kyc_contract' => $live ? $this->registry->kycContract() : null,
                'registration_paused' => $live ? $this->registry->registrationPaused() : null,
                'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
                'rpc_url' => $this->registry->publicRpcUrl(),
                'explorer_url' => $this->explorerUrl(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * API: Get nodes for a wallet address.
     */
    public function myNodes(Request $request): JsonResponse
    {
        $wallet = strtolower((string) $request->input('wallet_address', ''));

        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address format.'],
            ], 422);
        }

        if (! $this->registry->isDeployed()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'registry_live' => false,
                'message' => 'MasterNode registry not deployed yet.',
            ]);
        }

        $node = $this->registry->nodeInfo($wallet);

        if ($node === null) {
            return response()->json([
                'success' => true,
                'data' => [],
                'registry_live' => true,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [$this->presentNode($node)],
            'registry_live' => true,
        ]);
    }

    // =========================================================================
    //  Private
    // =========================================================================

    /**
     * แปลง struct ดิบจากเชนให้เป็นรูปที่หน้าเว็บใช้ได้ — รวมสถานะล็อกที่คำนวณแล้ว
     * เพื่อไม่ให้ผู้ใช้กด "ถอนเงินต้น" แล้วเจอ revert "Still locked" ดิบ ๆ.
     */
    private function presentNode(array $node): array
    {
        $now = time();
        $pendingWei = $this->registry->pendingReward($node['operator']) ?? '0';
        $claimableWei = $this->registry->claimableNow($node['operator']) ?? '0';

        return [
            'wallet' => $node['operator'],
            'tier' => $node['tier'],
            'tier_id' => $node['tier_id'],
            'status' => $node['status'],
            'status_id' => $node['status_id'],
            'stake_amount' => $node['stake_amount'],
            'stake_amount_wei' => $node['stake_amount_wei'],
            'endpoint' => $node['endpoint'],
            'node_id' => $node['node_id'],
            'uptime_percent' => round($node['uptime'] / 100, 2),
            'registered_at' => $node['registered_at'],
            'unlock_at' => $node['unlock_at'],
            'is_locked' => $node['unlock_at'] > $now,
            'unlock_in_seconds' => max(0, $node['unlock_at'] - $now),
            'total_rewards' => Wei::format($node['total_rewards_wei']),
            'pending_reward' => Wei::format($pendingWei),
            'pending_reward_wei' => $pendingWei,
            // "เคลมได้จริงตอนนี้" ต่างจาก "สะสมได้" — พูลที่ยังไม่ถูกเติมเงินจ่ายได้ 0
            'claimable_now' => Wei::format($claimableWei),
            'claimable_now_wei' => $claimableWei,
        ];
    }

    /**
     * ค่าคอนฟิกชั้นต่าง ๆ — เอาจากเชนถ้า deploy แล้ว ไม่งั้นใช้ค่าใน config.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getTiers(): array
    {
        $onChain = cache()->remember('masternode:tiers:public', 30, fn () => $this->registry->allTierInfo());

        if (! empty($onChain)) {
            return array_values(array_map(fn ($t) => $t + ['source' => 'chain'], $onChain));
        }

        // ยังไม่ deploy — คืนค่าตาม config เพื่อให้หน้าเว็บแสดงชั้นได้ (แต่ปุ่มซื้อจะถูกปิด)
        $indexes = config('masternode.tier_index', []);
        $minStakes = config('masternode.tiers', []);
        $locks = ['Guardian' => 90, 'Sentinel' => 30, 'Light' => 7, 'Validator' => 180];
        $maxNodes = ['Guardian' => 100, 'Sentinel' => 500, 'Light' => 0, 'Validator' => 21];
        $shares = ['Guardian' => 3500, 'Sentinel' => 3000, 'Light' => 1500, 'Validator' => 2000];
        // เพดาน APR ค่าเริ่มต้นของสัญญา — โชว์ได้ว่า "สูงสุดเท่าไร" แต่คาดการณ์จริงต้องรอเชน
        $aprCaps = config('masternode.apr_cap_bps', []);

        $out = [];
        foreach ($indexes as $name => $idx) {
            $out[] = [
                'tier' => $idx,
                'name' => $name,
                'min_stake' => (string) ($minStakes[$name] ?? 0),
                'min_stake_wei' => Wei::toWei((string) ($minStakes[$name] ?? 0)),
                'max_nodes' => $maxNodes[$name] ?? 0,
                'active_nodes' => 0,
                'lock_days' => $locks[$name] ?? 0,
                'slash_percent' => 0,
                'reward_share' => $shares[$name] ?? 0,
                'apr_cap_bps' => isset($aprCaps[$name]) ? (int) $aprCaps[$name] : null,
                // ยังไม่มีสัญญา = ไม่มีอัตราจริงให้คำนวณ ส่ง null ไม่ใช่ 0 (0 แปลว่า "ไม่ได้อะไรเลย")
                'reward_rate_per_node_wei' => null,
                'reward_rate_if_join_wei' => null,
                'current_apr_bps' => null,
                'projected_apr_bps' => null,
                'source' => 'config',
            ];
        }

        usort($out, fn ($a, $b) => $a['tier'] <=> $b['tier']);

        return $out;
    }

    /**
     * สถิติเครือข่าย — ถ้าอ่านเชนไม่ได้ต้องบอกตรง ๆ ว่าอ่านไม่ได้
     * ไม่ใช่คืนเลขศูนย์สวย ๆ ให้ดูเหมือนระบบทำงานปกติ.
     */
    private function getNetworkStats(): array
    {
        return cache()->remember('masternode:stats', 30, function () {
            $blockHeight = $this->registry->blockNumber();
            $deployed = $this->registry->isDeployed();

            $base = [
                // false = ตัวเลขด้านล่างเป็นค่าตั้งต้น ไม่ได้อ่านจากเชน (ยังไม่ deploy / RPC ล้ม)
                // หน้าเว็บต้องโชว์ "—" ไม่ใช่ "0 โหนด" / "พูลว่าง" ราวกับอ่านเชนมาแล้ว
                'stats_available' => false,
                // อ่านยอดพูลรางวัลไม่ได้ ≠ พูลว่าง — แยกไว้ให้หน้าเว็บไม่ประกาศว่า "ยังไม่เติมเงิน" มั่ว ๆ
                'reward_pool_known' => false,
                'total_nodes' => 0,
                'guardian_nodes' => 0,
                'sentinel_nodes' => 0,
                'light_nodes' => 0,
                'validator_nodes' => 0,
                'total_staked' => '0',
                'total_rewards_distributed' => '0',
                'remaining_rewards' => '1400000000',
                'reward_pool_funded' => '0',
                'reward_pool_available' => '0',
                'current_year' => 1,
                'block_height' => $blockHeight,
                'rpc_connected' => $blockHeight > 0,
                'registry_deployed' => $deployed,
                // อยู่ใน stats ด้วยเพราะหน้าเว็บ poll stats ทุก 30 วิอยู่แล้ว — แอดมินกดปิดรับเมื่อไหร่
                // ปุ่มซื้อของคนที่เปิดหน้าค้างไว้ก็ปิดตามโดยไม่ต้องรีเฟรช
                'registration_paused' => null,
                // ที่อยู่สัญญา + Explorer — หน้าแรกทำลิงก์ให้ผู้ใช้กดตรวจสัญญาบนเชนเองได้
                'registry_address' => $deployed ? $this->registry->address() : null,
                'explorer_url' => rtrim((string) config('masternode.explorer_url', 'https://explorer.tpix.online'), '/'),
            ];

            if (! $deployed) {
                return $base;
            }

            // อ่านแยกก่อน getNetworkStats() — สวิตช์นี้ต้องไปถึงหน้าเว็บแม้สถิติอ่านไม่สำเร็จ
            // (ปุ่มซื้อต้องปิดตามทันทีที่แอดมินปิดรับ ไม่ใช่รอให้สถิติกลับมาก่อน)
            $base['registration_paused'] = $this->registry->registrationPaused();

            $stats = $this->registry->networkStats();
            if ($stats === null) {
                return $base;
            }

            $tierCounts = [];
            foreach ($this->registry->allTierInfo() as $tier) {
                $tierCounts[strtolower((string) $tier['name']).'_nodes'] = (int) $tier['active_nodes'];
            }

            $pool = $this->registry->rewardPoolStatus();
            $available = $this->registry->availableRewardFunds();

            return array_merge($base, $tierCounts, [
                'stats_available' => true,
                'reward_pool_known' => $available !== null,
                'total_nodes' => $stats['total_active_nodes'],
                'total_staked' => Wei::format($stats['total_staked_wei']),
                'total_rewards_distributed' => Wei::format($stats['total_rewards_distributed_wei']),
                'remaining_rewards' => Wei::format($stats['remaining_rewards_wei']),
                'reward_per_second' => Wei::format($stats['reward_per_second_wei']),
                // สัญญานับปีจาก 0 หน้าเว็บนับจาก 1 — index 3 = จบโครงการ
                'current_year' => min(3, $stats['current_year_index'] + 1),
                'reward_year_ended' => $stats['current_year_index'] >= 3,
                'reward_pool_funded' => $pool ? Wei::format($pool['total_funded_wei']) : '0',
                'reward_pool_available' => Wei::format($available ?? '0'),
            ]);
        });
    }

    private function explorerUrl(): string
    {
        return rtrim((string) config('masternode.explorer_url', 'https://explorer.tpix.online'), '/');
    }
}
