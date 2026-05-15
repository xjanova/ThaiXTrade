<?php

namespace App\Services\MasterNode;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NodeRegistryService — ตรวจสอบว่า wallet เป็น masternode/validator ที่ active บน chain มั้ย
 *
 * Resolution order:
 *   1. ถ้ามี genesis_operators ใน config → match wallet กับ tier ที่ระบุ (Phase 1 fallback)
 *   2. ถ้า NodeRegistry contract deployed (config('masternode.registry.address') !== null) → eth_call isActiveNode + getTier
 *   3. Fallback: เรียก eth_getBalance — ถ้า balance >= tier minimum → allow
 *
 * Developed by Xman Studio
 */
class NodeRegistryService
{
    /**
     * eth_call selector สำหรับ NodeRegistry.isActiveNode(address)
     * keccak256("isActiveNode(address)") → 0x???????? (4 bytes)
     */
    private const SELECTOR_IS_ACTIVE = '0xfb7a7c81';

    /**
     * eth_call selector สำหรับ NodeRegistry.getTier(address)
     */
    private const SELECTOR_GET_TIER = '0x6e9960c3';

    /**
     * Lookup operator status — return null ถ้าไม่ใช่ masternode
     *
     * @return array{tier:string, source:string}|null  source = 'genesis'|'registry'|'balance'
     */
    public function lookup(string $walletAddress): ?array
    {
        $wallet = strtolower($walletAddress);

        // 1. Genesis operators (hardcoded — Phase 1)
        $genesis = config('masternode.genesis_operators', []);
        $genesisLower = array_change_key_case($genesis, CASE_LOWER);
        if (isset($genesisLower[$wallet])) {
            return [
                'tier' => $genesisLower[$wallet],
                'source' => 'genesis',
            ];
        }

        // 2. NodeRegistry contract (Phase 2 — when deployed)
        $registryAddress = config('masternode.registry.address');
        if ($registryAddress) {
            $tierFromRegistry = $this->queryRegistry($wallet, $registryAddress);
            if ($tierFromRegistry !== null) {
                return [
                    'tier' => $tierFromRegistry,
                    'source' => 'registry',
                ];
            }
        }

        // 3. Fallback: balance check (any wallet with enough TPIX)
        $tierFromBalance = $this->tierByBalance($wallet);
        if ($tierFromBalance !== null) {
            return [
                'tier' => $tierFromBalance,
                'source' => 'balance',
            ];
        }

        return null;
    }

    /**
     * Phase 2: query NodeRegistry contract via eth_call
     */
    private function queryRegistry(string $wallet, string $registryAddress): ?string
    {
        $cacheKey = "masternode:registry:{$wallet}";

        return Cache::remember($cacheKey, 60, function () use ($wallet, $registryAddress) {
            try {
                // eth_call isActiveNode(wallet)
                $isActive = $this->ethCall($registryAddress, self::SELECTOR_IS_ACTIVE.$this->padAddress($wallet));
                if ($isActive === null || hexdec(substr($isActive, -2)) === 0) {
                    return null;
                }

                // eth_call getTier(wallet) → uint8 (0=Light, 1=Sentinel, 2=Guardian, 3=Validator)
                $tierHex = $this->ethCall($registryAddress, self::SELECTOR_GET_TIER.$this->padAddress($wallet));
                if ($tierHex === null) {
                    return null;
                }

                $tierIdx = hexdec(substr($tierHex, -64));
                $tiers = ['Light', 'Sentinel', 'Guardian', 'Validator'];

                return $tiers[$tierIdx] ?? 'Light';
            } catch (\Throwable $e) {
                Log::warning('NodeRegistry queryRegistry failed', [
                    'wallet' => $wallet,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Phase 1 fallback: ดู balance — ถ้าพอตามขั้น tier ก็ allow
     * เป็น proxy สำหรับ stake (assumption: wallet ที่ run masternode ควรมี TPIX อย่างน้อย minStake)
     */
    private function tierByBalance(string $wallet): ?string
    {
        $cacheKey = "masternode:balance:{$wallet}";
        $balanceWei = Cache::remember($cacheKey, 60, fn () => $this->getBalance($wallet));

        if ($balanceWei === null) {
            return null;
        }

        $balanceTpix = (int) ($balanceWei / 1e18);
        $tiers = config('masternode.tiers', []);

        // เลือก tier สูงสุดที่ผ่านเกณฑ์
        arsort($tiers);
        foreach ($tiers as $name => $minStake) {
            if ($balanceTpix >= $minStake) {
                return $name;
            }
        }

        // ต่ำกว่า Light minimum → ไม่ allow
        $minBalance = (int) config('masternode.registry.fallback_min_balance_tpix', 100000);
        return $balanceTpix >= $minBalance ? 'Light' : null;
    }

    /**
     * eth_getBalance via JSON-RPC
     */
    private function getBalance(string $wallet): ?float
    {
        try {
            $response = Http::timeout(10)->post(config('masternode.registry.rpc_url'), [
                'jsonrpc' => '2.0',
                'method' => 'eth_getBalance',
                'params' => [$wallet, 'latest'],
                'id' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $hex = $response->json('result');
            if (!$hex || !is_string($hex)) {
                return null;
            }

            return (float) hexdec($hex);
        } catch (\Throwable $e) {
            Log::warning('eth_getBalance failed', ['wallet' => $wallet, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generic eth_call
     */
    private function ethCall(string $to, string $data): ?string
    {
        try {
            $response = Http::timeout(10)->post(config('masternode.registry.rpc_url'), [
                'jsonrpc' => '2.0',
                'method' => 'eth_call',
                'params' => [
                    ['to' => $to, 'data' => $data],
                    'latest',
                ],
                'id' => 1,
            ]);

            return $response->successful() ? $response->json('result') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function padAddress(string $address): string
    {
        // อย่าใช้ ltrim('0x') — มัน strip char '0'/'x' ทุกตัว ไม่ใช่แค่ prefix
        $addr = strtolower($address);
        if (str_starts_with($addr, '0x')) {
            $addr = substr($addr, 2);
        }

        return str_pad($addr, 64, '0', STR_PAD_LEFT);
    }
}
