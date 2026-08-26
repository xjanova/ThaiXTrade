<?php

namespace App\Services\MasterNode;

use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NodeRegistryService — ตรวจว่า wallet เป็น masternode/validator ที่ active อยู่จริงไหม
 * (ใช้ตัดสินว่าจะให้ IP ของ operator เข้า Cloudflare allowlist หรือไม่).
 *
 * ลำดับการตัดสิน:
 *   1. genesis_operators ใน config → ผ่านทันที (โหนดตั้งต้นของทีม)
 *   2. สัญญา NodeRegistry deploy แล้ว → **ถือเป็นคำตอบสุดท้าย**
 *      ลงทะเบียนอยู่และ status = Active ถึงจะผ่าน ไม่ผ่าน = ปฏิเสธ (ไม่ตกไปข้อ 3)
 *   3. สัญญายังไม่ deploy → fallback ดู balance (Phase 1)
 *
 * ⚠️ ข้อ 2 ต้องตัดจบ ไม่ตกไปข้อ 3
 *    ของเดิมเรียกเมธอด `isActiveNode()` / `getTier()` ซึ่ง **ไม่มีอยู่ใน V2 เลย**
 *    eth_call จึงคืน null ทุกครั้งแล้วไหลไปข้อ 3 เสมอ ผลคือใครก็ตามที่ *ถือ* TPIX
 *    ครบขั้นต่ำ (ไม่ต้องวางค้ำสักบาท) ก็ขอ allowlist ได้ — ยืมเหรียญมาถือแป๊บเดียวก็พอ
 *
 * Developed by Xman Studio
 */
class NodeRegistryService
{
    public function __construct(private NodeRegistryContract $registry) {}

    /**
     * Lookup operator status — return null ถ้าไม่ใช่ masternode.
     *
     * @return array{tier:string, source:string, status?:string}|null
     *                                                                source = 'genesis'|'registry'|'balance'
     */
    public function lookup(string $walletAddress): ?array
    {
        $wallet = strtolower($walletAddress);

        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            return null;
        }

        // 1. Genesis operators (โหนดตั้งต้นของทีม — ไม่ต้องวางค้ำ)
        $genesis = array_change_key_case(config('masternode.genesis_operators', []), CASE_LOWER);
        if (isset($genesis[$wallet])) {
            return [
                'tier' => $genesis[$wallet],
                'source' => 'genesis',
                'status' => 'active',
            ];
        }

        // 2. สัญญา deploy แล้ว → คำตอบสุดท้าย
        if ($this->registry->isDeployed()) {
            return $this->lookupFromRegistry($wallet);
        }

        // 3. สัญญายังไม่ deploy → fallback ดู balance
        $tierFromBalance = $this->tierByBalance($wallet);

        return $tierFromBalance === null ? null : [
            'tier' => $tierFromBalance,
            'source' => 'balance',
        ];
    }

    /**
     * อ่านสถานะโหนดจากสัญญาจริง — ผ่านเฉพาะ status = Active.
     *
     * @return array{tier:string, source:string, status:string}|null
     */
    private function lookupFromRegistry(string $wallet): ?array
    {
        $cacheKey = "masternode:registry:lookup:{$wallet}";

        $cached = Cache::remember($cacheKey, 60, function () use ($wallet) {
            $node = $this->registry->nodeInfo($wallet);

            // แยก "อ่านไม่ได้" ออกจาก "ไม่ได้ลงทะเบียน" ไม่ได้จาก nodeInfo() ตัวเดียว
            // แต่ทั้งสองกรณีต้องปฏิเสธเหมือนกัน — fail closed
            if ($node === null) {
                return ['result' => null];
            }

            // Active เท่านั้น: slashed / exited / inactive ไม่ควรได้ allowlist
            if ($node['status'] !== 'active') {
                Log::info('Masternode allowlist ปฏิเสธ — โหนดไม่ได้อยู่ในสถานะทำงาน', [
                    'wallet' => $wallet,
                    'status' => $node['status'],
                ]);

                return ['result' => null];
            }

            return ['result' => [
                'tier' => $node['tier'],
                'source' => 'registry',
                'status' => $node['status'],
            ]];
        });

        return $cached['result'] ?? null;
    }

    /**
     * Fallback ตอนสัญญายังไม่ deploy — ใช้ balance เป็นตัวแทนของ stake.
     *
     * เป็นการอนุโลมชั่วคราวเท่านั้น: ถือเหรียญ ≠ วางค้ำ พอ deploy สัญญาแล้ว
     * เส้นทางนี้จะไม่ถูกเรียกอีกเลย
     */
    private function tierByBalance(string $wallet): ?string
    {
        $balanceWei = Cache::remember(
            "masternode:balance:{$wallet}",
            60,
            fn () => $this->getBalanceWei($wallet)
        );

        if ($balanceWei === null) {
            return null;
        }

        // เทียบเป็นสตริงจำนวนเต็มด้วย bcmath — 1 TPIX = 1e18 wei เกิน PHP_INT_MAX ตั้งแต่ 10 TPIX
        $balanceTpix = Wei::format($balanceWei);

        $tiers = config('masternode.tiers', []);
        arsort($tiers);

        foreach ($tiers as $name => $minStake) {
            if (bccomp($balanceTpix, (string) $minStake, 0) >= 0) {
                return $name;
            }
        }

        return null;
    }

    /**
     * eth_getBalance → wei เป็นสตริง (ห้ามผ่าน float).
     *
     * ⚠️ ต้องส่ง User-Agent — rpc.tpix.online อยู่หลัง Cloudflare bot rule ที่ตอบ 403 ให้ client ที่ไม่มี UA
     */
    private function getBalanceWei(string $wallet): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'TPIX-TRADE-Server/1.0 (+https://tpix.online)'])
                ->asJson()
                ->post(config('masternode.registry.rpc_url'), [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getBalance',
                    'params' => [$wallet, 'latest'],
                    'id' => 1,
                ]);

            if (! $response->successful() || $response->json('error')) {
                return null;
            }

            $hex = $response->json('result');

            return is_string($hex) ? Wei::hexToInt($hex) : null;
        } catch (\Throwable $e) {
            Log::warning('eth_getBalance failed', ['wallet' => $wallet, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
