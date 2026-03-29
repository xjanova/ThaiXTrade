<?php

namespace App\Services;

use App\Models\FactoryToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BlockscoutVerifyService.
 *
 * Auto-verify deployed ERC-20 contracts บน Blockscout Explorer
 * ใช้ Blockscout API v2 สำหรับ standard-input verification.
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
     * Verify factory-deployed ERC-20 token บน Blockscout.
     *
     * ใช้ flattened source verification เพราะ contract ถูก deploy ผ่าน factory
     * (CREATE2) ไม่สามารถใช้ standard input ได้ตรงๆ
     *
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function verifyFactoryToken(string $contractAddress, FactoryToken $token): array
    {
        if (! $this->enabled) {
            return ['success' => false, 'error' => 'Verification disabled'];
        }

        // รอให้ Blockscout index contract ก่อน (อาจใช้เวลา)
        sleep(5);

        // ลอง verify ด้วย Blockscout API v2
        try {
            return $this->verifyViaApiV2($contractAddress, $token);
        } catch (\Throwable $e) {
            Log::error('BlockscoutVerifyService: verification exception', [
                'contract' => $contractAddress,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify contract ผ่าน Blockscout API v2
     * POST /api/v2/smart-contracts/{address_hash}/verification/via/flattened-code
     */
    private function verifyViaApiV2(string $contractAddress, FactoryToken $token): array
    {
        $url = "{$this->apiUrl}/v2/smart-contracts/{$contractAddress}/verification/via/flattened-code";

        // สร้าง flattened source code สำหรับ FactoryERC20
        $sourceCode = $this->generateFlattenedSource($token);

        // Map token type → constructor args
        $tokenType = config('blockchain.token_types.'.$token->token_type, 0);
        $mintable = in_array($tokenType, [1, 3]);
        $burnable = in_array($tokenType, [2, 3]);

        // Encode constructor arguments (ABI-encoded)
        $constructorArgs = $this->encodeConstructorArgs(
            $token->name,
            $token->symbol,
            $token->decimals,
            $token->total_supply,
            $token->creator_address,
            $mintable,
            $burnable
        );

        $payload = [
            'compiler_version' => 'v0.8.20+commit.a1b79de6',
            'source_code' => $sourceCode,
            'is_optimization_enabled' => true,
            'optimization_runs' => 200,
            'contract_name' => 'FactoryERC20',
            'evm_version' => 'paris',
            'autodetect_constructor_args' => true,
            'constructor_args' => $constructorArgs,
        ];

        Log::info('BlockscoutVerifyService: submitting verification', [
            'url' => $url,
            'contract' => $contractAddress,
            'contract_name' => 'FactoryERC20',
        ]);

        $response = Http::timeout(60)
            ->post($url, $payload);

        if ($response->successful()) {
            $data = $response->json();

            // Blockscout returns the smart contract data on success
            if (isset($data['is_verified']) && $data['is_verified']) {
                return ['success' => true];
            }

            // อาจยัง pending verification
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
        ]);

        return ['success' => false, 'error' => "HTTP {$response->status()}: {$errorMsg}"];
    }

    /**
     * สร้าง flattened source code ของ FactoryERC20 contract.
     *
     * รวม OpenZeppelin imports ทั้งหมดเป็นไฟล์เดียว
     * เพื่อให้ Blockscout สามารถ verify ได้
     */
    private function generateFlattenedSource(FactoryToken $token): string
    {
        // อ่าน flattened file ที่ pre-generate ไว้ (จาก hardhat flatten)
        $flattenedPath = base_path('contracts/factory/FactoryERC20.flat.sol');

        if (file_exists($flattenedPath)) {
            return file_get_contents($flattenedPath);
        }

        // Fallback: ส่ง source ตรง (ต้องมี OZ imports resolved)
        // ในกรณีนี้ Blockscout จะลอง resolve imports เอง
        $sourcePath = base_path('contracts/factory/FactoryERC20.sol');
        if (file_exists($sourcePath)) {
            return file_get_contents($sourcePath);
        }

        throw new \RuntimeException('FactoryERC20 source code not found for verification');
    }

    /**
     * ABI-encode constructor arguments ของ FactoryERC20.
     *
     * constructor(string name_, string symbol_, uint8 decimals_,
     *             uint256 totalSupply_, address owner_, bool mintable_, bool burnable_)
     */
    private function encodeConstructorArgs(
        string $name,
        string $symbol,
        int $decimals,
        string $totalSupply,
        string $ownerAddress,
        bool $mintable,
        bool $burnable
    ): string {
        // Convert total supply to wei
        $totalSupplyWei = $this->toWei($totalSupply, $decimals);

        // ABI encoding ใช้ ethers.js approach — ส่ง hex string
        // Blockscout จะ auto-detect ถ้าตั้ง autodetect_constructor_args = true
        // ส่ง raw hex เพื่อ manual matching ถ้า auto-detect ไม่ทำงาน

        // สำหรับ dynamic types (string) ต้อง encode complex ABI
        // ให้ Blockscout auto-detect แทน → return empty
        return '';
    }

    /**
     * Convert human-readable amount to wei.
     */
    private function toWei(string $amount, int $decimals): string
    {
        $parts = explode('.', $amount);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        return ltrim($integer.$fraction, '0') ?: '0';
    }
}
