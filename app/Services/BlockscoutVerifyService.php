<?php

namespace App\Services;

use App\Models\FactoryToken;
use App\Services\TokenFactory\TpixRpcClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BlockscoutVerifyService — ยืนยันซอร์สของเหรียญที่แฟกทอรีสร้าง บน Blockscout ด้วย standard JSON input.
 *
 * ทำไมเปลี่ยนจาก flattened-code:
 *   ของเดิมส่ง evm "cancun" ไม่มี viaIR และใช้ไฟล์ flatten ใน build/flat ที่คอมไพล์กับ
 *   OpenZeppelin คนละรุ่น — bytecode ไม่มีวันตรง ยืนยันไม่ผ่านสักเหรียญ
 *   standard JSON input คือ "อินพุตของ solc ทั้งก้อน" (ซอร์ส + settings ครบ evmVersion/viaIR/optimizer)
 *   ที่ใช้คอมไพล์จริงใน TPIX-Coin → ตรงแน่นอน
 *
 * ไฟล์อินพุตอยู่ที่ resources/contracts/verify/<ContractName>.json (ทีมสัญญาเป็นคน generate ดู README ในโฟลเดอร์)
 * ไม่มีไฟล์ = บันทึกว่า "skipped: missing standard input" — ไม่ทำให้การ deploy ล้ม
 *
 * Developed by Xman Studio
 */
class BlockscoutVerifyService
{
    /** token_type → ชื่อสัญญา (ชื่อไฟล์ใน resources/contracts/verify) */
    public const CONTRACTS = [
        'standard' => 'FactoryERC20V2',
        'mintable' => 'FactoryERC20V2',
        'burnable' => 'FactoryERC20V2',
        'mintable_burnable' => 'FactoryERC20V2',
        'utility' => 'UtilityToken',
        'reward' => 'RewardToken',
        'governance' => 'GovernanceToken',
        'stablecoin' => 'StablecoinToken',
        'nft' => 'FactoryERC721',
        'nft_collection' => 'NFTCollection',
    ];

    public const SKIPPED_MISSING_INPUT = 'skipped: missing standard input';

    private string $apiUrl;

    private bool $enabled;

    public function __construct(private ContractRegistry $contracts)
    {
        $this->apiUrl = rtrim((string) config('blockchain.explorer_api_url', 'https://explorer.tpix.online/api'), '/');
        $this->enabled = filter_var(config('blockchain.explorer_verify_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * ชื่อสัญญาของเหรียญนี้.
     *
     * ERC-20 ที่สร้างจากแฟกทอรี V1 (ตัวเก่า) เป็นสัญญา FactoryERC20 ไม่ใช่ V2
     * ดูจากที่สคริปต์บันทึกไว้ก่อน ถ้าไม่มีให้ดูทะเบียนสัญญา (ไม่ใช่ config อย่างเดียว)
     */
    public function contractNameFor(FactoryToken $token): ?string
    {
        $name = self::CONTRACTS[$token->token_type] ?? null;

        if ($name === 'FactoryERC20V2') {
            $version = $token->metadata['factory_version'] ?? null;
            $usedV1 = $version === 'v1'
                || ($version === null && $this->contracts->address('token_factory_v2') === null
                    && $this->contracts->address('token_factory_v1') !== null);

            return $usedV1 ? 'FactoryERC20' : 'FactoryERC20V2';
        }

        return $name;
    }

    public function standardInputPath(string $contractName): string
    {
        $dir = (string) config('blockchain.explorer_standard_input_dir', resource_path('contracts/verify'));

        return rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$contractName.'.json';
    }

    /**
     * ส่งคำขอยืนยันซอร์ส.
     *
     * @return array{status: string, message: ?string, contract_name: ?string}
     *               status: submitted | already_verified | skipped | error
     */
    public function submit(FactoryToken $token): array
    {
        if (! $this->enabled) {
            return ['status' => 'skipped', 'message' => 'skipped: verification disabled', 'contract_name' => null];
        }

        $address = strtolower((string) $token->contract_address);
        if (! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            return ['status' => 'error', 'message' => 'ไม่มีที่อยู่สัญญา', 'contract_name' => null];
        }

        $contractName = $this->contractNameFor($token);
        if ($contractName === null) {
            return ['status' => 'skipped', 'message' => 'skipped: unknown contract type', 'contract_name' => null];
        }

        $path = $this->standardInputPath($contractName);
        $spec = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($spec) || empty($spec['compiler_version']) || empty($spec['contract_name']) || ! is_array($spec['input'] ?? null)) {
            Log::warning('BlockscoutVerifyService: ไม่มี standard input ของสัญญา', ['contract' => $contractName, 'path' => $path]);

            return ['status' => 'skipped', 'message' => self::SKIPPED_MISSING_INPUT, 'contract_name' => $contractName];
        }

        if ($this->isVerified($address) === true) {
            return ['status' => 'already_verified', 'message' => null, 'contract_name' => $contractName];
        }

        $url = "{$this->apiUrl}/v2/smart-contracts/{$address}/verification/via/standard-input";

        try {
            $response = Http::timeout(60)
                ->withHeaders(['User-Agent' => TpixRpcClient::USER_AGENT, 'Accept' => 'application/json'])
                ->asMultipart()
                ->attach('files[0]', json_encode($spec['input'], JSON_UNESCAPED_SLASHES), $contractName.'.json', ['Content-Type' => 'application/json'])
                ->post($url, [
                    'compiler_version' => (string) $spec['compiler_version'],
                    'contract_name' => (string) $spec['contract_name'],
                    'autodetect_constructor_args' => 'true',
                    'license_type' => 'mit',
                ]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'ติดต่อ explorer ไม่ได้: '.$e->getMessage(), 'contract_name' => $contractName];
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? mb_substr($response->body(), 0, 300);

            Log::warning('BlockscoutVerifyService: explorer ปฏิเสธคำขอ', [
                'status' => $response->status(),
                'contract' => $address,
                'message' => $message,
            ]);

            return ['status' => 'error', 'message' => "HTTP {$response->status()}: {$message}", 'contract_name' => $contractName];
        }

        return ['status' => 'submitted', 'message' => $response->json('message'), 'contract_name' => $contractName];
    }

    /**
     * explorer ยืนยันซอร์สแล้วหรือยัง — null = ถามไม่ได้.
     */
    public function isVerified(string $address): ?bool
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => TpixRpcClient::USER_AGENT, 'Accept' => 'application/json'])
                ->get("{$this->apiUrl}/v2/smart-contracts/".strtolower($address));
        } catch (\Throwable) {
            return null;
        }

        if ($response->status() === 404) {
            return false;
        }

        if (! $response->successful()) {
            return null;
        }

        return (bool) ($response->json('is_verified') ?? false);
    }
}
