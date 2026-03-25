<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * MasterNodeAdminController — Admin dashboard สำหรับจัดการ MasterNode.
 *
 * แสดงสถานะ on-chain จริงจาก NodeRegistry contract บน TPIX Chain.
 * Developed by Xman Studio.
 */
class MasterNodeAdminController extends Controller
{
    /**
     * MasterNode admin dashboard page.
     */
    public function index(): InertiaResponse
    {
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');
        $registryAddress = config('blockchain.masternode_registry', '');

        return Inertia::render('Admin/MasterNode/Index', [
            'stats' => $this->getNetworkStats($rpcUrl, $registryAddress),
            'registryAddress' => $registryAddress,
            'rpcUrl' => $rpcUrl,
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
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');
        $registryAddress = config('blockchain.masternode_registry', '');

        return response()->json([
            'success' => true,
            'data' => $this->getNetworkStats($rpcUrl, $registryAddress),
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
     * Update MasterNode config (registry address, etc).
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $request->validate([
            'registry_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        // Note: registry address ควรตั้งผ่าน .env เพราะเป็น config
        // แต่บันทึก note ไว้ใน SiteSetting สำหรับ reference
        SiteSetting::set('trading', 'masternode_registry_note', $request->input('registry_address', ''));

        return response()->json([
            'success' => true,
            'message' => 'Config saved. Update MASTERNODE_REGISTRY_ADDRESS in .env for actual deployment.',
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function getNetworkStats(string $rpcUrl, string $registryAddress): array
    {
        return cache()->remember('admin:masternode:stats', 30, function () use ($rpcUrl, $registryAddress) {
            $blockHeight = $this->getBlockHeight($rpcUrl);

            if (empty($registryAddress)) {
                return [
                    'total_nodes' => 0,
                    'validator_nodes' => 0,
                    'sentinel_nodes' => 0,
                    'light_nodes' => 0,
                    'total_staked' => '0',
                    'total_rewards_distributed' => '0',
                    'remaining_rewards' => '1,400,000,000',
                    'block_height' => $blockHeight,
                    'registry_deployed' => false,
                    'rpc_connected' => $blockHeight > 0,
                ];
            }

            try {
                $totalStaked = $this->ethCallUint256($rpcUrl, $registryAddress, '0x817b1cd2'); // totalStaked()
                $totalRewards = $this->ethCallUint256($rpcUrl, $registryAddress, '0x0e15561a'); // totalRewardsDistributed()

                return [
                    'total_nodes' => 0,
                    'validator_nodes' => 0,
                    'sentinel_nodes' => 0,
                    'light_nodes' => 0,
                    'total_staked' => $totalStaked,
                    'total_rewards_distributed' => $totalRewards,
                    'remaining_rewards' => number_format(1_400_000_000 - (float) $totalRewards, 0),
                    'block_height' => $blockHeight,
                    'registry_deployed' => true,
                    'rpc_connected' => true,
                ];
            } catch (\Throwable $e) {
                Log::error('Admin MasterNode stats query failed', ['error' => $e->getMessage()]);

                return [
                    'total_nodes' => 0,
                    'validator_nodes' => 0,
                    'sentinel_nodes' => 0,
                    'light_nodes' => 0,
                    'total_staked' => '0',
                    'total_rewards_distributed' => '0',
                    'remaining_rewards' => '1,400,000,000',
                    'block_height' => $blockHeight,
                    'registry_deployed' => ! empty($registryAddress),
                    'rpc_connected' => $blockHeight > 0,
                ];
            }
        });
    }

    private function getBlockHeight(string $rpcUrl): int
    {
        try {
            $response = Http::timeout(5)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_blockNumber',
                'params' => [],
                'id' => 1,
            ]);

            if ($response->successful()) {
                return (int) hexdec($response->json('result', '0x0'));
            }
        } catch (\Throwable) {}

        return 0;
    }

    private function ethCallUint256(string $rpcUrl, string $to, string $data): string
    {
        $response = Http::timeout(5)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'method' => 'eth_call',
            'params' => [['to' => $to, 'data' => $data], 'latest'],
            'id' => 1,
        ]);

        if ($response->successful() && ! $response->json('error')) {
            $hex = $response->json('result', '0x0');
            $wei = gmp_init($hex, 16);
            $ether = gmp_div_q($wei, gmp_init('1000000000000000000'));

            return gmp_strval($ether);
        }

        return '0';
    }
}
