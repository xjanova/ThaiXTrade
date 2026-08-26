<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\MasterNode\NodeRegistryContract;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * MasterNodeAdminController — แดชบอร์ดหลังบ้านของ MasterNode.
 *
 * อ่านสถานะจริงจาก NodeRegistryV2 ผ่าน NodeRegistryContract ตัวเดียวกับหน้าเว็บ
 * (เดิมไฟล์นี้ hardcode selector เองแล้วผิด: totalRewardsDistributed ใช้ 0x0e15561a
 *  ทั้งที่ของจริงคือ 0xee172546 และไม่ส่ง User-Agent จึงโดน Cloudflare 403 ทุกครั้ง)
 *
 * Developed by Xman Studio.
 */
class MasterNodeAdminController extends Controller
{
    public function __construct(private NodeRegistryContract $registry) {}

    /**
     * MasterNode admin dashboard page.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/MasterNode/Index', [
            'stats' => $this->getNetworkStats(),
            'registryAddress' => $this->registry->address() ?? '',
            'rpcUrl' => $this->registry->rpcUrl(),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
            'settings' => [
                'masternode_enabled' => (bool) SiteSetting::get('trading', 'masternode_enabled', true),
            ],
        ]);
    }

    /**
     * API: Refresh MasterNode stats (AJAX polling).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getNetworkStats(),
        ]);
    }

    /**
     * Toggle MasterNode system on/off.
     */
    public function toggle(Request $request): JsonResponse
    {
        $enabled = (bool) $request->input('enabled', true);
        SiteSetting::set('trading', 'masternode_enabled', $enabled);

        return response()->json([
            'success' => true,
            'data' => ['masternode_enabled' => $enabled],
        ]);
    }

    /**
     * Update MasterNode config (registry address note).
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $request->validate([
            'registry_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        // registry address ตั้งผ่าน .env เท่านั้น (MASTERNODE_REGISTRY_ADDRESS)
        // ที่เก็บใน SiteSetting เป็นแค่บันทึกช่วยจำ ไม่มีผลกับระบบ
        SiteSetting::set('trading', 'masternode_registry_note', $request->input('registry_address', ''));

        return response()->json([
            'success' => true,
            'message' => 'บันทึกแล้ว — ต้องตั้ง MASTERNODE_REGISTRY_ADDRESS ใน .env จึงจะมีผลจริง',
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function getNetworkStats(): array
    {
        return cache()->remember('admin:masternode:stats', 30, function () {
            $blockHeight = $this->registry->blockNumber();
            $address = $this->registry->address();
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
                'reward_schedule_cap' => '1400000000',
                'current_year' => 1,
                'block_height' => $blockHeight,
                'rpc_connected' => $blockHeight > 0,
                'registry_deployed' => $deployed,
                // แยกให้ชัดว่า "ยังไม่ได้ตั้ง address" ต่างจาก "ตั้งแล้วแต่ไม่มีโค้ดที่ address นั้น"
                // กรณีหลังเกิดตอนเชน regenesis แล้ว .env ยังค้างที่อยู่เก่าไว้
                'registry_address_set' => $address !== null,
                'registry_code_missing' => $address !== null && ! $deployed,
                'kyc_contract' => null,
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
                'current_year' => min(3, $stats['current_year_index'] + 1),
                'reward_year_ended' => $stats['current_year_index'] >= 3,
                'reward_pool_funded' => $pool ? Wei::format($pool['total_funded_wei']) : '0',
                'reward_schedule_cap' => $pool ? Wei::format($pool['schedule_cap_wei']) : '1400000000',
                // เงินที่พูลจ่ายได้จริง = balance - เงินต้นผู้วางค้ำ
                // ถ้าเป็น 0 ทั้งที่มีโหนดทำงานอยู่ = ยังไม่ได้เติมพูล ผู้ใช้กดรับรางวัลไม่ได้
                'reward_pool_available' => Wei::format($this->registry->availableRewardFunds() ?? '0'),
                'kyc_contract' => $this->registry->kycContract(),
            ]);
        });
    }
}
