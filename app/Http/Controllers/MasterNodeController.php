<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * MasterNodeController — จัดการ Master Node setup & dashboard.
 *
 * อ่านข้อมูลจาก NodeRegistry contract บน TPIX Chain.
 * Developed by Xman Studio.
 */
class MasterNodeController extends Controller
{
    /**
     * Master Node setup & network dashboard page.
     */
    public function index()
    {
        return Inertia::render('MasterNode/Index', [
            'stats' => $this->getNetworkStats(),
            'nodes' => [],
            'registryAddress' => config('blockchain.masternode_registry', ''),
            'rpcUrl' => config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online'),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
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
     * API: Get nodes for a wallet address (requires wallet verification).
     */
    public function myNodes(Request $request): JsonResponse
    {
        $wallet = strtolower($request->input('wallet_address', ''));

        // Validate wallet address format
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address format.'],
            ], 422);
        }

        $registryAddress = config('blockchain.masternode_registry');

        if (empty($registryAddress)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'MasterNode registry not deployed yet.',
            ]);
        }

        // Query on-chain: eth_call to NodeRegistry.nodes(wallet)
        try {
            $nodeData = $this->queryNodeFromContract($wallet, $registryAddress);

            return response()->json([
                'success' => true,
                'data' => $nodeData ? [$nodeData] : [],
            ]);
        } catch (\Throwable $e) {
            Log::error('MasterNode query failed', [
                'wallet' => $wallet,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }
    }

    /**
     * Get network statistics.
     * Reads from NodeRegistry contract or cache.
     */
    private function getNetworkStats(): array
    {
        return cache()->remember('masternode:stats', 60, function () {
            $registryAddress = config('blockchain.masternode_registry');

            if (empty($registryAddress)) {
                return $this->getDefaultStats();
            }

            try {
                return $this->queryStatsFromContract($registryAddress);
            } catch (\Throwable $e) {
                Log::error('MasterNode stats query failed', ['error' => $e->getMessage()]);

                return $this->getDefaultStats();
            }
        });
    }

    /**
     * Default stats when contract is not deployed.
     */
    private function getDefaultStats(): array
    {
        return [
            'total_nodes' => 0,
            'validator_nodes' => 0,
            'sentinel_nodes' => 0,
            'light_nodes' => 0,
            'total_staked' => '0',
            'total_rewards_distributed' => '0',
            'remaining_rewards' => '1,400,000,000',
            'current_year' => 1,
            'block_height' => 0,
            'registry_deployed' => false,
        ];
    }

    /**
     * Query NodeRegistry contract for stats via JSON-RPC eth_call.
     */
    private function queryStatsFromContract(string $registryAddress): array
    {
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');

        // getActiveNodes() — function selector: 0x4c3e28d9 (bytes4 of keccak256)
        $totalNodesData = $this->ethCall($rpcUrl, $registryAddress, '0xab49848a'); // totalNodes()
        $totalStakedData = $this->ethCall($rpcUrl, $registryAddress, '0x817b1cd2'); // totalStaked()
        $totalRewardsData = $this->ethCall($rpcUrl, $registryAddress, '0x0e15561a'); // totalRewardsDistributed()

        // Get block height
        $blockResponse = Http::timeout(5)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'method' => 'eth_blockNumber',
            'params' => [],
            'id' => 1,
        ]);

        $blockHeight = 0;
        if ($blockResponse->successful()) {
            $blockHeight = hexdec($blockResponse->json('result', '0x0'));
        }

        return [
            'total_nodes' => hexdec($totalNodesData ?: '0x0'),
            'validator_nodes' => 0,
            'sentinel_nodes' => 0,
            'light_nodes' => 0,
            'total_staked' => $this->weiToEther($totalStakedData ?: '0x0'),
            'total_rewards_distributed' => $this->weiToEther($totalRewardsData ?: '0x0'),
            'remaining_rewards' => '1,400,000,000',
            'current_year' => 1,
            'block_height' => $blockHeight,
            'registry_deployed' => true,
        ];
    }

    /**
     * Query node info for a specific wallet.
     */
    private function queryNodeFromContract(string $wallet, string $registryAddress): ?array
    {
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');

        // nodes(address) — selector + address padded to 32 bytes
        $selector = '0x37b7bf28'; // keccak256("nodes(address)")[:8]
        $paddedAddress = str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT);
        $data = $selector.$paddedAddress;

        $result = $this->ethCall($rpcUrl, $registryAddress, $data);

        if (! $result || $result === '0x' || strlen($result) < 66) {
            return null;
        }

        // Parse struct: (uint8 tier, uint256 stakeAmount, uint256 registeredAt, ...)
        // If stakeAmount = 0, node doesn't exist
        $chunks = str_split(substr($result, 2), 64);
        if (count($chunks) < 3) {
            return null;
        }

        $tier = hexdec($chunks[0]);
        $stakeAmount = $this->weiToEther('0x'.$chunks[1]);

        if ((float) $stakeAmount <= 0) {
            return null;
        }

        $tierNames = [0 => 'None', 1 => 'Light', 2 => 'Sentinel', 3 => 'Validator'];

        return [
            'wallet' => $wallet,
            'tier' => $tierNames[$tier] ?? 'Unknown',
            'tier_id' => $tier,
            'stake_amount' => $stakeAmount,
            'status' => 'active',
        ];
    }

    /**
     * Execute eth_call via JSON-RPC.
     */
    private function ethCall(string $rpcUrl, string $to, string $data): ?string
    {
        $response = Http::timeout(5)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'method' => 'eth_call',
            'params' => [
                ['to' => $to, 'data' => $data],
                'latest',
            ],
            'id' => 1,
        ]);

        if ($response->successful() && ! $response->json('error')) {
            return $response->json('result');
        }

        return null;
    }

    /**
     * Convert wei hex string to ether string.
     */
    private function weiToEther(string $hexWei): string
    {
        $wei = gmp_init($hexWei, 16);
        $ether = gmp_div_q($wei, gmp_init('1000000000000000000'));

        return gmp_strval($ether);
    }
}
