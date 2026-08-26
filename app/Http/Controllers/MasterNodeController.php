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
        return Inertia::render('MasterNode/Index', [
            'stats' => $this->getNetworkStats(),
            'tiers' => $this->getTiers(),
            'nodes' => [],
            'registryAddress' => $this->registry->address() ?? '',
            'registryLive' => $this->registry->isDeployed(),
            'kycContract' => $this->registry->isDeployed() ? $this->registry->kycContract() : null,
            'rpcUrl' => $this->registry->rpcUrl(),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
            'explorerUrl' => rtrim((string) config('masternode.explorer_url', 'https://explorer.tpix.online'), '/'),
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
            ];

            if (! $deployed) {
                return $base;
            }

            $stats = $this->registry->networkStats();
            if ($stats === null) {
                return $base;
            }

            $tierCounts = [];
            foreach ($this->registry->allTierInfo() as $tier) {
                $tierCounts[strtolower((string) $tier['name']).'_nodes'] = (int) $tier['active_nodes'];
            }

            $pool = $this->registry->rewardPoolStatus();

            return array_merge($base, $tierCounts, [
                'total_nodes' => $stats['total_active_nodes'],
                'total_staked' => Wei::format($stats['total_staked_wei']),
                'total_rewards_distributed' => Wei::format($stats['total_rewards_distributed_wei']),
                'remaining_rewards' => Wei::format($stats['remaining_rewards_wei']),
                'reward_per_second' => Wei::format($stats['reward_per_second_wei']),
                // สัญญานับปีจาก 0 หน้าเว็บนับจาก 1 — index 3 = จบโครงการ
                'current_year' => min(3, $stats['current_year_index'] + 1),
                'reward_year_ended' => $stats['current_year_index'] >= 3,
                'reward_pool_funded' => $pool ? Wei::format($pool['total_funded_wei']) : '0',
                'reward_pool_available' => Wei::format($this->registry->availableRewardFunds() ?? '0'),
            ]);
        });
    }
}
