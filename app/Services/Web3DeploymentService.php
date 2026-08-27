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
 * Calls Node.js script (scripts/blockchain/create-token.cjs) which
 * uses ethers.js to sign and broadcast the transaction.
 *
 * Developed by Xman Studio
 */
class Web3DeploymentService
{
    public function __construct(private ContractRegistry $contracts) {}

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
        $scriptPath = base_path('scripts/blockchain/create-token.cjs');

        $tokenType = $params['token_type'] ?? 'standard';
        $subOptions = $params['sub_options'] ?? [];

        // Convert human-readable supply to wei
        //
        // NFT นับเป็น "จำนวนใบ" ไม่ใช่ยอดที่คูณทศนิยม — ถ้าคูณไปด้วยจะได้เพดาน
        // จำนวนใบเป็นเลข 10^18 เท่าของที่ผู้ใช้ตั้ง ทำให้เพดานไร้ความหมาย
        // หน้าเว็บตั้ง decimals = 0 ให้เองตอนเลือกหมวด NFT แต่ห้ามพึ่งฝั่งหน้าเว็บอย่างเดียว
        // (เรียก API ตรง ๆ หรือผู้ใช้แก้ decimals เองก็ทะลุมาได้)
        $decimals = (int) ($params['decimals'] ?? 18);
        $isNft = in_array($params['token_type'] ?? '', ['nft', 'nft_collection'], true)
            || ($params['token_category'] ?? '') === 'nft';

        $totalSupplyWei = $isNft
            ? $this->toWholeNumber($params['total_supply'])
            : $this->toWei($params['total_supply'], $decimals);

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
        // ที่อยู่แฟกทอรีมาจาก ContractRegistry (SiteSetting ที่สคริปต์ deploy ลงทะเบียนเอง → .env)
        // จะได้ไม่ต้อง ssh เข้าไปแก้ .env แล้ว config:cache ทุกครั้งที่ deploy ใหม่
        $env = [
            'DEPLOYER_PRIVATE_KEY' => config('blockchain.deployer_private_key'),
            'TOKEN_FACTORY_ADDRESS' => $this->contracts->address('token_factory_v1') ?? '',
            'TOKEN_FACTORY_V2_ADDRESS' => $this->contracts->address('token_factory_v2') ?? '',
            'NFT_FACTORY_ADDRESS' => $this->contracts->address('nft_factory') ?? '',
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

        // ส่ง payload ทาง stdin ไม่ใช่ argv
        //
        // escapeshellarg() บน Windows **ถอดเครื่องหมาย " ออกจากสตริง** JSON จึงเละตั้งแต่ยังไม่ออกจาก PHP
        // (ลองแล้ว: {"a":1} กลายเป็น "{ a :1 }") บน Linux ยังรอด แต่ก็ยังเสี่ยงกับชื่อเหรียญ
        // ที่มีอัญประกาศ อีโมจิ หรืออักษรนอก ASCII
        //
        // ใช้รูปแบบ array ของ Process ด้วย เพื่อไม่ต้องผ่าน shell เลย
        $command = [$nodePath, $scriptPath];

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
                ->input($input)
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
     * ตัดเป็นจำนวนเต็ม — ใช้กับจำนวนใบของ NFT ที่ไม่มีทศนิยม
     */
    private function toWholeNumber(string $amount): string
    {
        $integer = explode('.', trim($amount))[0];
        $integer = preg_replace('/\D/', '', $integer) ?? '';
        $integer = ltrim($integer, '0');

        return $integer === '' ? '0' : $integer;
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
