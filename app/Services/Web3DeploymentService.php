<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Web3DeploymentService.
 *
 * Deploys ERC-20 tokens via TPIXTokenFactory contract on TPIX Chain.
 * Calls Node.js script (scripts/blockchain/create-token.js) which
 * uses ethers.js to sign and broadcast the transaction.
 */
class Web3DeploymentService
{
    /**
     * Deploy a new ERC-20 token via the factory contract.
     *
     * @param  array  $params  Token parameters:
     *                         - name: string
     *                         - symbol: string
     *                         - decimals: int (0-18)
     *                         - total_supply: string (human-readable, e.g. "1000000")
     *                         - creator_address: string (0x... wallet that receives tokens)
     *                         - token_type: string (standard|mintable|burnable|mintable_burnable)
     * @return array ['success' => bool, 'contractAddress' => ?string, 'txHash' => ?string, 'error' => ?string]
     */
    public function deployToken(array $params): array
    {
        $nodePath = config('blockchain.node_path', 'node');
        $scriptPath = base_path('scripts/blockchain/create-token.js');

        // Convert human-readable supply to wei
        $decimals = $params['decimals'] ?? 18;
        $totalSupplyWei = $this->toWei($params['total_supply'], $decimals);

        // Map token type string to integer
        $tokenTypeInt = config('blockchain.token_types.'.$params['token_type'], 0);

        // Build input JSON for Node.js script
        $input = json_encode([
            'name' => $params['name'],
            'symbol' => $params['symbol'],
            'decimals' => (int) $decimals,
            'totalSupply' => $totalSupplyWei,
            'tokenOwner' => $params['creator_address'],
            'tokenType' => $tokenTypeInt,
        ]);

        // Environment variables (secrets passed via env, not CLI args)
        $env = [
            'DEPLOYER_PRIVATE_KEY' => config('blockchain.deployer_private_key'),
            'TOKEN_FACTORY_ADDRESS' => config('blockchain.factory_address'),
            'TPIX_RPC_URL' => config('blockchain.tpix_rpc_url'),
        ];

        // Validate config
        if (empty($env['DEPLOYER_PRIVATE_KEY'])) {
            return ['success' => false, 'error' => 'DEPLOYER_PRIVATE_KEY not configured'];
        }
        if (empty($env['TOKEN_FACTORY_ADDRESS'])) {
            return ['success' => false, 'error' => 'TOKEN_FACTORY_ADDRESS not configured'];
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
            'creator' => $params['creator_address'],
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

                // Try to parse JSON error from stdout
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
        // Handle decimal amounts
        $parts = explode('.', $amount);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        // Pad or truncate fraction to match decimals
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        // Combine and remove leading zeros
        $wei = ltrim($integer.$fraction, '0') ?: '0';

        return $wei;
    }
}
