<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterNodeController extends Controller
{
    /**
     * Master Node setup & network dashboard page
     */
    public function index()
    {
        return Inertia::render('MasterNode/Index', [
            'stats' => $this->getNetworkStats(),
            'nodes' => [],
            'registryAddress' => config('blockchain.masternode_registry', ''),
        ]);
    }

    /**
     * API: Get network stats
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data' => $this->getNetworkStats(),
        ]);
    }

    /**
     * API: Get nodes for a wallet address
     */
    public function myNodes(Request $request)
    {
        $wallet = strtolower($request->query('wallet', ''));

        if (empty($wallet)) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Wallet address required'],
            ], 400);
        }

        // TODO: Query NodeRegistry contract for operator's node info
        // For now return empty — will be populated when contract is deployed
        return response()->json([
            'success' => true,
            'data' => [],
        ]);
    }

    /**
     * Get network statistics
     * TODO: Read from NodeRegistry contract on-chain
     */
    private function getNetworkStats(): array
    {
        return cache()->remember('masternode:stats', 60, function () {
            // Initial stats — will be populated from on-chain data
            return [
                'total_nodes' => 0,
                'validator_nodes' => 0,
                'sentinel_nodes' => 0,
                'light_nodes' => 0,
                'total_staked' => '0',
                'total_rewards_distributed' => '0',
                'remaining_rewards' => '1,400,000,000',
                'current_year' => 1,
                'block_reward' => '25.5',
                'block_height' => 0,
            ];
        });
    }
}
