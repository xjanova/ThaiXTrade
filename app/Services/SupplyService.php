<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Computes TPIX circulating / total / max supply on demand.
 *
 * Strategy 'onchain' (default): queries TPIX RPC for the current balance of each
 * genesis-locked address, subtracts from total supply, and returns the result.
 * This is fully reproducible by third parties (CoinGecko, CMC, DeFiLlama)
 * directly against the RPC — no trust required.
 *
 * Strategy 'manual': returns an admin-override figure (env var TPIX_CIRCULATING_OVERRIDE).
 *
 * Results are cached for SUPPLY_CACHE_TTL seconds (default 60) to protect RPC.
 *
 * Developed by Xman Studio.
 */
class SupplyService
{
    /**
     * Get full supply snapshot (human-readable TPIX units, not wei).
     *
     * @return array{
     *   total: string,
     *   max: string,
     *   circulating: string,
     *   locked: string,
     *   breakdown: list<array{address:string,label:string,category:string,balance:string}>,
     *   strategy: string,
     *   rpc: string,
     *   updated_at: string
     * }
     */
    public function snapshot(): array
    {
        $ttl = (int) config('supply.cache_ttl', 60);

        return Cache::remember('tpix:supply:snapshot', $ttl, function () {
            $total = (string) config('supply.total_supply');
            $max = (string) config('supply.max_supply');
            $strategy = (string) config('supply.strategy', 'onchain');
            $rpcUrl = (string) config('supply.rpc_url');

            if ($strategy === 'manual') {
                $circulating = (string) (config('supply.circulating_override') ?? $total);

                return [
                    'total' => $total,
                    'max' => $max,
                    'circulating' => $circulating,
                    'locked' => bcsub($total, $circulating, 0),
                    'breakdown' => [],
                    'strategy' => 'manual',
                    'rpc' => $rpcUrl,
                    'updated_at' => now()->toIso8601String(),
                ];
            }

            // on-chain strategy: query RPC for each locked address
            $lockedAddresses = (array) config('supply.locked_addresses', []);
            $breakdown = [];
            $totalLocked = '0';

            foreach ($lockedAddresses as $entry) {
                $balance = $this->getBalance($rpcUrl, $entry['address']);
                $totalLocked = bcadd($totalLocked, $balance, 0);

                $breakdown[] = [
                    'address' => $entry['address'],
                    'label' => $entry['label'] ?? 'Unknown',
                    'category' => $entry['category'] ?? 'other',
                    'balance' => $balance,
                ];
            }

            $circulating = bcsub($total, $totalLocked, 0);
            if (bccomp($circulating, '0', 0) === -1) {
                // Should never happen, but guard against negative if config is stale
                $circulating = '0';
            }

            return [
                'total' => $total,
                'max' => $max,
                'circulating' => $circulating,
                'locked' => $totalLocked,
                'breakdown' => $breakdown,
                'strategy' => 'onchain',
                'rpc' => $rpcUrl,
                'updated_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Query eth_getBalance for one address, return the balance in TPIX units (not wei).
     */
    private function getBalance(string $rpcUrl, string $address): string
    {
        try {
            $response = Http::timeout(10)->asJson()->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_getBalance',
                'params' => [$address, 'latest'],
                'id' => 1,
            ]);

            if (! $response->successful()) {
                Log::warning('SupplyService: RPC returned non-2xx', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);
                return '0';
            }

            $hex = (string) ($response->json('result') ?? '0x0');

            return $this->hexWeiToTpix($hex);
        } catch (\Throwable $e) {
            Log::warning('SupplyService: balance fetch failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return '0';
        }
    }

    /**
     * Convert hex wei string ("0x...") to whole-TPIX decimal string.
     * Uses bcmath to avoid float precision loss on 18-decimal values.
     */
    private function hexWeiToTpix(string $hex): string
    {
        $hex = ltrim($hex, '0x');
        if ($hex === '' || $hex === '0') return '0';

        // Convert hex → decimal string via bcmath (handles arbitrary precision)
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcmul($dec, '16', 0);
            $dec = bcadd($dec, (string) hexdec($hex[$i]), 0);
        }

        // Divide by 10^18 to get whole-TPIX (integer division — fractional TPIX are
        // insignificant for supply reporting, so we floor)
        $decimals = (int) config('supply.decimals', 18);
        $divisor = bcpow('10', (string) $decimals, 0);

        return bcdiv($dec, $divisor, 0);
    }
}
