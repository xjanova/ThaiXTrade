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
    private const CACHE_KEY = 'tpix:supply:snapshot';

    /**
     * TTL (วินาที) เมื่อดึงยอดจาก RPC ไม่ครบ — สั้นกว่าปกติเพื่อให้ตัวเลขที่
     * คลาดเคลื่อนอยู่บนหน้าสาธารณะสั้นที่สุด
     */
    private const DEGRADED_CACHE_TTL = 15;

    /**
     * Get full supply snapshot (human-readable TPIX units, not wei).
     *
     * `degraded` = true แปลว่าอย่างน้อยหนึ่งที่อยู่ดึงยอดจาก RPC ไม่สำเร็จ และใช้
     * ยอด genesis จาก config แทน — ใช้ภายในเท่านั้น ไม่ได้เผยแพร่ผ่าน API
     *
     * @return array{
     *   total: string,
     *   max: string,
     *   circulating: string,
     *   locked: string,
     *   breakdown: list<array{address:string,label:string,category:string,balance:string}>,
     *   strategy: string,
     *   rpc: string,
     *   degraded: bool,
     *   updated_at: string
     * }
     */
    public function snapshot(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $snapshot = $this->build();

        $ttl = (int) config('supply.cache_ttl', 60);
        if ($snapshot['degraded']) {
            $ttl = min($ttl, self::DEGRADED_CACHE_TTL);
        }

        Cache::put(self::CACHE_KEY, $snapshot, $ttl);

        return $snapshot;
    }

    /**
     * Build a fresh snapshot (ไม่ผ่าน cache).
     *
     * @return array{total:string,max:string,circulating:string,locked:string,breakdown:list<array{address:string,label:string,category:string,balance:string}>,strategy:string,rpc:string,degraded:bool,updated_at:string}
     */
    private function build(): array
    {
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
                'degraded' => false,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        // on-chain strategy: query RPC for each locked address
        $lockedAddresses = (array) config('supply.locked_addresses', []);
        $breakdown = [];
        $totalLocked = '0';
        $degraded = false;

        foreach ($lockedAddresses as $entry) {
            $balance = $this->getBalance($rpcUrl, $entry['address']);

            if ($balance === null) {
                // RPC ล้มเหลว: ห้ามนับเป็น 0 เด็ดขาด เพราะจะทำให้ locked ต่ำกว่าจริง
                // และดัน circulating สูงเกินจริง ซึ่งถูกเสิร์ฟตรงให้ CoinGecko / CMC /
                // DeFiLlama ใช้ยอด genesis จาก config แทน แล้วทำเครื่องหมาย degraded
                $balance = (string) ($entry['initial'] ?? '0');
                $degraded = true;
            }

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
            'degraded' => $degraded,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Query eth_getBalance for one address, return the balance in TPIX units (not wei).
     *
     * คืน null เมื่อดึงยอดไม่สำเร็จ — ผู้เรียกต้องตัดสินใจเองว่าจะใช้ค่าอะไรแทน
     * ห้ามคืน '0' เพราะแยกไม่ออกระหว่าง "กระเป๋าว่างจริง" กับ "ถาม RPC ไม่ได้"
     */
    private function getBalance(string $rpcUrl, string $address): ?string
    {
        try {
            $response = Http::timeout((int) config('supply.rpc_timeout', 5))
                ->connectTimeout((int) config('supply.rpc_connect_timeout', 3))
                // Cloudflare หน้า rpc.tpix.online ตอบ 403 ให้ request ที่ไม่มี
                // User-Agent — ระบุเองไว้ ไม่พึ่งค่า default ของ Guzzle
                ->withHeaders(['User-Agent' => 'ThaiXTrade-SupplyService/1.0 (+https://tpix.online)'])
                ->asJson()
                ->post($rpcUrl, [
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

                return null;
            }

            $hex = $response->json('result');

            if (! is_string($hex) || ! preg_match('/^0x[0-9a-fA-F]*$/', $hex)) {
                // JSON-RPC error object หรือ payload ผิดรูป — ไม่ใช่ยอดเงินเป็นศูนย์
                Log::warning('SupplyService: RPC returned no usable result', [
                    'address' => $address,
                    'error' => $response->json('error.message'),
                ]);

                return null;
            }

            return $this->hexWeiToTpix($hex);
        } catch (\Throwable $e) {
            Log::warning('SupplyService: balance fetch failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
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
