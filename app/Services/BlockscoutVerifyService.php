<?php

namespace App\Services;

use App\Models\FactoryToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BlockscoutVerifyService.
 *
 * Auto-verify deployed contracts บน Blockscout Explorer
 * Phase 2: รองรับ contract ทุกประเภท (ERC-20, NFT, Governance, etc.)
 *
 * ใช้ Blockscout API v2 สำหรับ flattened-code verification.
 *
 * Developed by Xman Studio
 */
class BlockscoutVerifyService
{
    private string $apiUrl;

    private bool $enabled;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('blockchain.explorer_api_url', 'https://explorer.tpix.online/api'), '/');
        $this->enabled = (bool) config('blockchain.explorer_verify_enabled', true);
    }

    /**
     * Phase 2: Contract type → info mapping
     * แต่ละ type ใช้ contract name + flattened file ต่างกัน
     */
    private function getContractInfo(string $tokenType): array
    {
        return match ($tokenType) {
            'standard', 'mintable', 'burnable', 'mintable_burnable' => [
                'name' => $this->hasV2Contract() ? 'FactoryERC20V2' : 'FactoryERC20',
                'flat' => $this->hasV2Contract() ? 'FactoryERC20V2.flat.sol' : 'FactoryERC20.flat.sol',
                'compiler' => $this->hasV2Contract() ? 'v0.8.24+commit.e11b9ed9' : 'v0.8.20+commit.a1b79de6',
                'evm' => $this->hasV2Contract() ? 'cancun' : 'paris',
            ],
            'utility' => [
                'name' => 'UtilityToken',
                'flat' => 'UtilityToken.flat.sol',
                'compiler' => 'v0.8.24+commit.e11b9ed9',
                'evm' => 'cancun',
            ],
            'reward' => [
                'name' => 'RewardToken',
                'flat' => 'RewardToken.flat.sol',
                'compiler' => 'v0.8.24+commit.e11b9ed9',
                'evm' => 'cancun',
            ],
            'governance' => [
                'name' => 'GovernanceToken',
                'flat' => 'GovernanceToken.flat.sol',
                'compiler' => 'v0.8.24+commit.e11b9ed9',
                'evm' => 'cancun',
            ],
            'stablecoin' => [
                'name' => 'StablecoinToken',
                'flat' => 'StablecoinToken.flat.sol',
                'compiler' => 'v0.8.24+commit.e11b9ed9',
                'evm' => 'cancun',
            ],
            'nft' => [
                'name' => 'FactoryERC721',
                'flat' => 'FactoryERC721.flat.sol',
                'compiler' => 'v0.8.24+commit.e11b9ed9',
                'evm' => 'cancun',
            ],
            'nft_collection' => [
                'name' => 'NFTCollection',
                'flat' => 'NFTCollection.flat.sol',
                'compiler' => 'v0.8.24+commit.e11b9ed9',
                'evm' => 'cancun',
            ],
            default => [
                'name' => 'FactoryERC20',
                'flat' => 'FactoryERC20.flat.sol',
                'compiler' => 'v0.8.20+commit.a1b79de6',
                'evm' => 'paris',
            ],
        };
    }

    /**
     * Check ว่ามี V2 contract deploy แล้วหรือยัง
     */
    private function hasV2Contract(): bool
    {
        return ! empty(config('blockchain.factory_v2_address'));
    }

    /**
     * Verify factory-deployed token/NFT บน Blockscout.
     *
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function verifyFactoryToken(string $contractAddress, FactoryToken $token): array
    {
        if (! $this->enabled) {
            return ['success' => false, 'error' => 'Verification disabled'];
        }

        // รอให้ Blockscout index contract ก่อน
        sleep(5);

        try {
            return $this->verifyViaApiV2($contractAddress, $token);
        } catch (\Throwable $e) {
            Log::error('BlockscoutVerifyService: verification exception', [
                'contract' => $contractAddress,
                'token_type' => $token->token_type,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify contract ผ่าน Blockscout API v2
     */
    private function verifyViaApiV2(string $contractAddress, FactoryToken $token): array
    {
        $url = "{$this->apiUrl}/v2/smart-contracts/{$contractAddress}/verification/via/flattened-code";

        // Phase 2: ดึง contract info ตาม token_type
        $contractInfo = $this->getContractInfo($token->token_type);
        $sourceCode = $this->loadFlattenedSource($contractInfo['flat']);

        if (empty($sourceCode)) {
            return ['success' => false, 'error' => "Flattened source not found: {$contractInfo['flat']}"];
        }

        $payload = [
            'compiler_version' => $contractInfo['compiler'],
            'source_code' => $sourceCode,
            'is_optimization_enabled' => true,
            'optimization_runs' => 200,
            'contract_name' => $contractInfo['name'],
            'evm_version' => $contractInfo['evm'],
            'autodetect_constructor_args' => true,
            'constructor_args' => '',
        ];

        Log::info('BlockscoutVerifyService: submitting verification', [
            'url' => $url,
            'contract' => $contractAddress,
            'contract_name' => $contractInfo['name'],
            'token_type' => $token->token_type,
        ]);

        $response = Http::timeout(60)
            ->post($url, $payload);

        if ($response->successful()) {
            $data = $response->json();

            if (isset($data['is_verified']) && $data['is_verified']) {
                return ['success' => true];
            }

            if (isset($data['message'])) {
                Log::info('BlockscoutVerifyService: response', ['message' => $data['message']]);

                return ['success' => true, 'message' => $data['message']];
            }

            return ['success' => true];
        }

        $errorBody = $response->json() ?? [];
        $errorMsg = $errorBody['message'] ?? $response->body();

        Log::warning('BlockscoutVerifyService: API returned error', [
            'status' => $response->status(),
            'error' => $errorMsg,
            'contract' => $contractAddress,
            'contract_name' => $contractInfo['name'],
        ]);

        return ['success' => false, 'error' => "HTTP {$response->status()}: {$errorMsg}"];
    }

    /**
     * Load flattened source code ของ contract.
     * Phase 2: รองรับหลาย contract types
     */
    private function loadFlattenedSource(string $filename): string
    {
        // Flat files อยู่ใน build/flat/ (ย้ายออกจาก contracts/ เพื่อไม่กวน compilation)
        $flattenedPath = base_path("build/flat/{$filename}");

        if (file_exists($flattenedPath)) {
            return file_get_contents($flattenedPath);
        }

        // Fallback: ลองหาใน contracts/factory/
        $buildPath = base_path("contracts/factory/{$filename}");
        if (file_exists($buildPath)) {
            return file_get_contents($buildPath);
        }

        Log::warning("BlockscoutVerifyService: flattened source not found", [
            'file' => $filename,
            'tried' => [$flattenedPath, $buildPath],
        ]);

        return '';
    }
}
