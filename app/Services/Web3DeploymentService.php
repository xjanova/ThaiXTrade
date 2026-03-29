<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Web3DeploymentService.
 *
 * Deploys tokens via TPIX Factory contracts on TPIX Chain.
 * Phase 2: รองรับ ERC-20 ทุกประเภท + ERC-721 (NFT)
 *
 * Calls Node.js script (scripts/blockchain/create-token.js) which
 * uses ethers.js to sign and broadcast the transaction.
 *
 * Developed by Xman Studio
 */
class Web3DeploymentService
{
    /**
     * Deploy a new token via the factory contract.
     *
     * @param  array  $params  Token parameters:
     *                         - name: string
     *                         - symbol: string
     *                         - decimals: int (0-18)
     *                         - total_supply: string (human-readable, e.g. "1000000")
     *                         - creator_address: string (0x... wallet that receives tokens)
     *                         - token_type: string (standard|mintable|...|utility|reward|governance|stablecoin|nft|nft_collection)
     *                         - token_category: string (fungible|nft|special)
     *                         - sub_options: array (optional, Phase 2 advanced features)
     * @return array ['success' => bool, 'contractAddress' => ?string, 'txHash' => ?string, 'error' => ?string]
     */
    public function deployToken(array $params): array
    {
        $nodePath = config('blockchain.node_path', 'node');
        $scriptPath = base_path('scripts/blockchain/create-token.js');

        $tokenType = $params['token_type'] ?? 'standard';
        $subOptions = $params['sub_options'] ?? [];

        // Convert human-readable supply to wei
        $decimals = $params['decimals'] ?? 18;
        $totalSupplyWei = $this->toWei($params['total_supply'], $decimals);

        // Build input JSON for Node.js script
        // Phase 2: ส่ง tokenType เป็น string + subOptions เต็ม
        $input = json_encode([
            'name' => $params['name'],
            'symbol' => $params['symbol'],
            'decimals' => (int) $decimals,
            'totalSupply' => $totalSupplyWei,
            'tokenOwner' => $params['creator_address'],
            'tokenType' => $tokenType,
            'subOptions' => $subOptions,
            'logoUrl' => $params['logo_url'] ?? '',
        ]);

        // Environment variables (secrets passed via env, not CLI args)
        // Phase 2: ส่ง V2 factory addresses ด้วย
        $env = [
            'DEPLOYER_PRIVATE_KEY' => config('blockchain.deployer_private_key'),
            'TOKEN_FACTORY_ADDRESS' => config('blockchain.factory_address'),
            'TOKEN_FACTORY_V2_ADDRESS' => config('blockchain.factory_v2_address'),
            'NFT_FACTORY_ADDRESS' => config('blockchain.nft_factory_address'),
            'TPIX_RPC_URL' => config('blockchain.tpix_rpc_url'),
        ];

        // Validate config
        if (empty($env['DEPLOYER_PRIVATE_KEY'])) {
            return ['success' => false, 'error' => 'DEPLOYER_PRIVATE_KEY not configured'];
        }

        // ต้องมี factory address อย่างน้อย 1 ตัว
        $hasFactory = ! empty($env['TOKEN_FACTORY_ADDRESS'])
            || ! empty($env['TOKEN_FACTORY_V2_ADDRESS'])
            || ! empty($env['NFT_FACTORY_ADDRESS']);

        if (! $hasFactory) {
            return ['success' => false, 'error' => 'No factory address configured'];
        }

        $command = sprintf(
            '%s %s %s',
            escapeshellarg($nodePath),
            escapeshellarg($scriptPath),
            escapeshellarg($input)
        );

        Log::info('Deploying token via factory', [
            'name' => $params['name'],
            'symbol' => $params['symbol'],
            'type' => $tokenType,
            'creator' => $params['creator_address'],
            'has_sub_options' => ! empty($subOptions),
        ]);

        try {
            $result = Process::timeout(120)
                ->env($env)
                ->run($command);

            $output = trim($result->output());

            if (! $result->successful()) {
                $errorOutput = $result->errorOutput();
                Log::error('Token deployment script failed', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => $errorOutput,
                    'stdout' => $output,
                ]);

                $decoded = json_decode($output, true);
                if ($decoded && isset($decoded['error'])) {
                    return ['success' => false, 'error' => $decoded['error']];
                }

                return ['success' => false, 'error' => $errorOutput ?: 'Deployment script failed'];
            }

            $decoded = json_decode($output, true);

            if (! $decoded || ! isset($decoded['success'])) {
                Log::error('Invalid deployment script output', ['output' => $output]);

                return ['success' => false, 'error' => 'Invalid script output'];
            }

            if ($decoded['success']) {
                Log::info('Token deployed successfully', [
                    'symbol' => $params['symbol'],
                    'contract' => $decoded['contractAddress'],
                    'txHash' => $decoded['txHash'],
                    'factoryVersion' => $decoded['factoryVersion'] ?? 'unknown',
                    'category' => $decoded['category'] ?? 'unknown',
                ]);
            }

            return $decoded;
        } catch (\Exception $e) {
            Log::error('Token deployment exception', [
                'error' => $e->getMessage(),
                'symbol' => $params['symbol'],
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Convert human-readable amount to wei (smallest unit).
     */
    private function toWei(string $amount, int $decimals): string
    {
        $parts = explode('.', $amount);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        $wei = ltrim($integer.$fraction, '0') ?: '0';

        return $wei;
    }
}
