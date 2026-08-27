<?php

namespace App\Services\MasterNode;

use App\Services\ContractRegistry;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NodeRegistryContract — ตัวอ่าน NodeRegistryV2 บน TPIX Chain ตัวเดียวของทั้งระบบ.
 *
 * ก่อนหน้านี้มีโค้ดอ่านสัญญาอยู่ 3 ที่ (MasterNodeController, MasterNodeAdminController,
 * NodeRegistryService) แต่ละที่ hardcode selector กันเอง และ **ผิดทั้งหมด**:
 *   - `totalNodes()` ไม่มีอยู่ในสัญญาเลย
 *   - `totalRewardsDistributed()` ใช้ 0x0e15561a ของจริงคือ 0xee172546
 *   - `nodes(address)` ใช้ 0x37b7bf28 ของจริงคือ 0x189a5a17
 *   - `isActiveNode()` / `getTier()` ไม่มีอยู่ในสัญญา V2
 * แล้วทุกที่ก็ catch error เงียบ ๆ คืนค่า default → หน้าเว็บดู "ทำงานได้" ทั้งที่ไม่เคยคุยกับเชนสำเร็จ
 *
 * ⚠️ ทุก request ต้องมี User-Agent — rpc.tpix.online อยู่หลัง Cloudflare bot rule
 *    ที่ตอบ 403 ให้ client ที่ไม่มี UA (ยืนยันสด 2026-08-27: ไม่มี UA = 403, มี UA = 200)
 *    โค้ดเดิมไม่ส่ง UA เลย → eth_call ทุกครั้งได้ 403 กลับมา
 *
 * Selector ทุกตัวในไฟล์นี้ generate จาก ABI จริงของ NodeRegistryV2
 * (TPIX-Coin/contracts/artifacts/src/masternode/NodeRegistryV2.sol)
 *
 * Developed by Xman Studio
 */
class NodeRegistryContract
{
    // ── Function selectors (ตรวจกับ ABI จริงแล้ว ห้ามเดา) ────────────────
    private const SEL_GET_NETWORK_STATS = '0x4a662f48'; // getNetworkStats()
    private const SEL_GET_NODE_INFO = '0x582115fb';     // getNodeInfo(address)
    private const SEL_GET_TIER_INFO = '0x7ed85ff3';     // getTierInfo(uint8)
    private const SEL_PENDING_REWARD = '0xf40f0f52';    // pendingReward(address)
    private const SEL_CLAIMABLE_NOW = '0x9a78ea4a';     // claimableNow(address)
    private const SEL_AVAILABLE_FUNDS = '0xd0377094';   // availableRewardFunds()
    private const SEL_REWARD_POOL_STATUS = '0x5b521653'; // rewardPoolStatus()
    private const SEL_TOTAL_REWARD_POOL = '0x09c85e24'; // totalRewardPool()
    private const SEL_KYC_CONTRACT = '0x6d3123eb';      // kycContract()
    private const SEL_IS_VALIDATOR = '0xfacd743b';      // isValidator(address)

    /**
     * enum NodeTier { Guardian, Sentinel, Light, Validator }.
     *
     * ⚠️ Guardian = 0 (ไม่ใช่ Validator) — สัญญาเก็บลำดับนี้ไว้เพื่อความเข้ากันได้กับ V1
     * ที่ index 0 เคยชื่อ Validator ใครสลับลำดับนี้ = ผู้ใช้ซื้อผิดชั้นทันที
     */
    public const TIERS = [
        0 => 'Guardian',
        1 => 'Sentinel',
        2 => 'Light',
        3 => 'Validator',
    ];

    /** enum NodeStatus { Inactive, Active, Slashed, Exited, SlashedWithdrawable } */
    public const STATUSES = [
        0 => 'inactive',
        1 => 'active',
        2 => 'slashed',
        3 => 'exited',
        4 => 'slashed_withdrawable',
    ];

    private const USER_AGENT = 'TPIX-TRADE-Server/1.0 (+https://tpix.online)';

    /**
     * ที่อยู่สัญญา — ผ่าน ContractRegistry ที่เดียว.
     *
     * ลำดับ: SiteSetting (สคริปต์ deploy ลงทะเบียนเอง) → .env
     * ทำแบบนี้เพื่อให้หลัง deploy ไม่ต้อง ssh เข้าไปแก้ .env แล้ว config:cache อีก
     */
    public function address(): ?string
    {
        $fromRegistry = app(ContractRegistry::class)->address('masternode_registry');
        if ($fromRegistry !== null) {
            return $fromRegistry;
        }

        // เผื่อคนตั้ง NODE_REGISTRY_ADDRESS ไว้ (ชื่อเก่า) ซึ่ง config/masternode.php resolve ให้
        $addr = trim((string) config('masternode.registry.address', ''));

        return preg_match('/^0x[a-fA-F0-9]{40}$/', $addr) ? $addr : null;
    }

    public function rpcUrl(): string
    {
        return (string) config('masternode.registry.rpc_url', 'https://rpc.tpix.online');
    }

    /**
     * ตั้งที่อยู่ไว้แล้ว **และ** มี bytecode อยู่ที่อยู่นั้นจริง.
     *
     * ที่ต้องเช็ก eth_getCode ด้วยเพราะเชน TPIX เคย regenesis (6 ส.ค. 2026) แล้ว
     * สัญญาที่เคย deploy หายเกลี้ยงทั้งที่ address ยังค้างอยู่ใน .env
     * ถ้าไม่เช็ก ระบบจะยิง eth_call ไปที่ address ว่างเปล่าแล้วได้ '0x' กลับมา
     * แปลว่า "ไม่มีโหนด" แทนที่จะบอกว่า "สัญญาหาย"
     */
    public function isDeployed(): bool
    {
        $address = $this->address();
        if ($address === null) {
            return false;
        }

        return Cache::remember('masternode:registry:deployed:'.strtolower($address), 300, function () use ($address) {
            $code = $this->rpc('eth_getCode', [$address, 'latest']);

            return is_string($code) && strlen($code) > 2;
        });
    }

    /**
     * getNetworkStats() → สถิติทั้งเครือข่ายใน call เดียว.
     *
     * @return array<string,string>|null
     */
    public function networkStats(): ?array
    {
        $result = $this->ethCall(self::SEL_GET_NETWORK_STATS);
        if ($result === null) {
            return null;
        }

        $w = $this->words($result);
        if (count($w) < 6) {
            return null;
        }

        return [
            'total_staked_wei' => Wei::hexToInt($w[0]),
            'total_active_nodes' => (int) Wei::hexToInt($w[1]),
            'total_rewards_distributed_wei' => Wei::hexToInt($w[2]),
            'remaining_rewards_wei' => Wei::hexToInt($w[3]),
            'reward_per_second_wei' => Wei::hexToInt($w[4]),
            // สัญญานับปีจาก 0 (0,1,2 แล้ว 3 = จบโครงการ) — หน้าเว็บโชว์เป็นปีที่ 1-3
            'current_year_index' => (int) Wei::hexToInt($w[5]),
        ];
    }

    /**
     * getTierInfo(tier) → ค่าคอนฟิกของชั้นนั้นบนเชนจริง (ไม่ใช่ค่าที่ hardcode ในหน้าเว็บ).
     *
     * @return array<string,string|int>|null
     */
    public function tierInfo(int $tier): ?array
    {
        if (! isset(self::TIERS[$tier])) {
            return null;
        }

        $result = $this->ethCall(self::SEL_GET_TIER_INFO.$this->padUint($tier));
        if ($result === null) {
            return null;
        }

        $w = $this->words($result);
        if (count($w) < 6) {
            return null;
        }

        return [
            'tier' => $tier,
            'name' => self::TIERS[$tier],
            'min_stake_wei' => Wei::hexToInt($w[0]),
            'min_stake' => Wei::hexToWholeUnits('0x'.$w[0]),
            'max_nodes' => (int) Wei::hexToInt($w[1]),
            'active_nodes' => (int) Wei::hexToInt($w[2]),
            'lock_days' => (int) Wei::hexToInt($w[3]),
            'slash_percent' => (int) Wei::hexToInt($w[4]),   // basis points
            'reward_share' => (int) Wei::hexToInt($w[5]),    // basis points
        ];
    }

    /**
     * ค่าคอนฟิกครบทั้ง 4 ชั้น (cache สั้น ๆ เพราะ activeNodes ขยับได้ตลอด).
     *
     * @return array<int,array<string,string|int>>
     */
    public function allTierInfo(): array
    {
        if (! $this->isDeployed()) {
            return [];
        }

        return Cache::remember('masternode:registry:tiers', 30, function () {
            $out = [];
            foreach (array_keys(self::TIERS) as $tier) {
                $info = $this->tierInfo($tier);
                if ($info !== null) {
                    $out[$tier] = $info;
                }
            }

            return $out;
        });
    }

    /**
     * getNodeInfo(operator) → struct MasterNode ตัวเต็ม.
     *
     * struct มี `string endpoint` อยู่ข้างใน ทำให้ return เป็นชนิด dynamic:
     *   word0        = offset ไปหัว struct (0x20 เสมอ)
     *   word1..13    = head ของ struct (endpoint เป็น "offset" ไม่ใช่ค่า)
     *   ท้ายสุด      = ความยาว + ตัวอักษรของ endpoint
     * โค้ดเดิมอ่านแบบ "หั่น 64 ตัวอักษรแล้วเอา chunk[0] เป็น tier" ซึ่งผิดตั้งแต่ word แรก
     *
     * @return array<string,mixed>|null null = ไม่เคยลงทะเบียน หรืออ่านไม่ได้
     */
    public function nodeInfo(string $operator): ?array
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $operator)) {
            return null;
        }

        $result = $this->ethCall(self::SEL_GET_NODE_INFO.$this->padAddress($operator));
        if ($result === null) {
            return null;
        }

        $w = $this->words($result);
        if (count($w) < 14) {
            return null;
        }

        // struct head เริ่มที่ word 1 (word 0 คือ offset 0x20)
        $head = 1;
        $tierIdx = (int) Wei::hexToInt($w[$head + 1]);
        $statusIdx = (int) Wei::hexToInt($w[$head + 2]);
        $stakedWei = Wei::hexToInt($w[$head + 3]);
        $nodeOperator = '0x'.substr($w[$head], 24);

        // ไม่เคยลงทะเบียน = struct ศูนย์ทั้งก้อน (operator เป็น 0x0)
        // ห้ามตัดสินจาก tier เพราะ tier 0 = Guardian ซึ่งเป็นค่าที่ถูกต้อง
        if ($nodeOperator === '0x'.str_repeat('0', 40)) {
            return null;
        }

        // endpoint: word[head+10] คือ offset (นับจากหัว struct) → หาร 32 ได้ index ของ word
        $endpoint = '';
        $epOffsetBytes = (int) Wei::hexToInt($w[$head + 10]);
        $epLenIdx = $head + intdiv($epOffsetBytes, 32);
        if (isset($w[$epLenIdx])) {
            $epLen = (int) Wei::hexToInt($w[$epLenIdx]);
            if ($epLen > 0 && $epLen <= 1024) {
                $hex = '';
                $needed = (int) ceil($epLen / 32);
                for ($i = 1; $i <= $needed; $i++) {
                    $hex .= $w[$epLenIdx + $i] ?? '';
                }
                $bin = @hex2bin(substr($hex, 0, $epLen * 2));
                $endpoint = $bin === false ? '' : $bin;
            }
        }

        return [
            'operator' => $nodeOperator,
            'tier_id' => $tierIdx,
            'tier' => self::TIERS[$tierIdx] ?? 'Unknown',
            'status_id' => $statusIdx,
            'status' => self::STATUSES[$statusIdx] ?? 'unknown',
            'stake_amount_wei' => $stakedWei,
            'stake_amount' => Wei::hexToWholeUnits('0x'.$w[$head + 3]),
            'registered_at' => (int) Wei::hexToInt($w[$head + 4]),
            'unlock_at' => (int) Wei::hexToInt($w[$head + 5]),
            'last_reward_at' => (int) Wei::hexToInt($w[$head + 6]),
            'total_rewards_wei' => Wei::hexToInt($w[$head + 7]),
            'uptime' => (int) Wei::hexToInt($w[$head + 8]), // basis points 0-10000
            'node_id' => '0x'.$w[$head + 9],
            'endpoint' => $endpoint,
            'pending_unclaimed_wei' => Wei::hexToInt($w[$head + 12]),
        ];
    }

    /** pendingReward(operator) — ยอดที่ "สะสมได้" (อาจมากกว่าที่จ่ายได้จริง) */
    public function pendingReward(string $operator): ?string
    {
        return $this->callUint(self::SEL_PENDING_REWARD.$this->padAddress($operator));
    }

    /** claimableNow(operator) — ยอดที่กดเคลมตอนนี้แล้ว "ได้จริง" (ถูกจำกัดด้วยเงินในพูล) */
    public function claimableNow(string $operator): ?string
    {
        return $this->callUint(self::SEL_CLAIMABLE_NOW.$this->padAddress($operator));
    }

    /** availableRewardFunds() — TPIX ที่พูลจ่ายได้จริงตอนนี้ (ไม่รวมเงินต้นของผู้ stake) */
    public function availableRewardFunds(): ?string
    {
        return $this->callUint(self::SEL_AVAILABLE_FUNDS);
    }

    /**
     * rewardPoolStatus() → (fundedBalance, totalFunded, distributed, scheduleCap).
     *
     * @return array<string,string>|null
     */
    public function rewardPoolStatus(): ?array
    {
        $result = $this->ethCall(self::SEL_REWARD_POOL_STATUS);
        if ($result === null) {
            return null;
        }

        $w = $this->words($result);
        if (count($w) < 4) {
            return null;
        }

        return [
            'funded_balance_wei' => Wei::hexToInt($w[0]),
            'total_funded_wei' => Wei::hexToInt($w[1]),
            'distributed_wei' => Wei::hexToInt($w[2]),
            'schedule_cap_wei' => Wei::hexToInt($w[3]),
        ];
    }

    /** totalRewardPool() — เพดาน emission ตามตาราง (1.4B) ไม่ใช่เงินที่มีจริง */
    public function totalRewardPool(): ?string
    {
        return $this->callUint(self::SEL_TOTAL_REWARD_POOL);
    }

    /**
     * kycContract() — ชั้น Validator ลงทะเบียนไม่ได้เลยถ้ายังไม่ได้ setKYCContract()
     * หน้าเว็บต้องรู้ล่วงหน้าเพื่อไม่ให้ผู้ใช้เซ็น tx ที่ revert แน่นอน.
     */
    public function kycContract(): ?string
    {
        $result = $this->ethCall(self::SEL_KYC_CONTRACT);
        if ($result === null) {
            return null;
        }

        $w = $this->words($result);
        if (empty($w[0])) {
            return null;
        }

        $addr = '0x'.substr($w[0], 24);

        return $addr === '0x'.str_repeat('0', 40) ? null : $addr;
    }

    /** isValidator(operator) — เป็น IBFT2 sealer ที่ active อยู่จริงไหม */
    public function isValidator(string $operator): bool
    {
        $result = $this->ethCall(self::SEL_IS_VALIDATOR.$this->padAddress($operator));
        if ($result === null) {
            return false;
        }

        return Wei::hexToInt($result) !== '0';
    }

    /** eth_blockNumber — ใช้เช็กว่าเชนยังเดินอยู่ */
    public function blockNumber(): int
    {
        $result = $this->rpc('eth_blockNumber', []);

        return is_string($result) ? (int) Wei::hexToInt($result) : 0;
    }

    // =========================================================================
    //  Low level
    // =========================================================================

    /**
     * eth_call ไปที่ registry — คืน null ถ้าสัญญายังไม่ deploy หรือ RPC ล้ม
     */
    private function ethCall(string $data): ?string
    {
        $address = $this->address();
        if ($address === null) {
            return null;
        }

        $result = $this->rpc('eth_call', [
            ['to' => $address, 'data' => $data],
            'latest',
        ]);

        // '0x' = ไม่มี code ที่ address นั้น (สัญญาหายหลัง regenesis) หรือ revert เปล่า
        if (! is_string($result) || strlen($result) <= 2) {
            return null;
        }

        return $result;
    }

    private function callUint(string $data): ?string
    {
        $result = $this->ethCall($data);

        return $result === null ? null : Wei::hexToInt($result);
    }

    /**
     * JSON-RPC ดิบ — ส่ง User-Agent เสมอ ไม่งั้น Cloudflare ตอบ 403 เป็น HTML.
     */
    private function rpc(string $method, array $params): mixed
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post($this->rpcUrl(), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful()) {
                Log::warning('NodeRegistry RPC http error', [
                    'method' => $method,
                    'status' => $response->status(),
                ]);

                return null;
            }

            if ($response->json('error')) {
                Log::warning('NodeRegistry RPC returned error', [
                    'method' => $method,
                    'error' => $response->json('error.message'),
                ]);

                return null;
            }

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('NodeRegistry RPC failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * หั่น hex ผลลัพธ์เป็น word ละ 32 ไบต์ (64 ตัวอักษร).
     *
     * @return array<int,string>
     */
    private function words(string $hex): array
    {
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        if ($hex === '') {
            return [];
        }

        return str_split($hex, 64);
    }

    private function padAddress(string $address): string
    {
        $addr = strtolower($address);
        if (str_starts_with($addr, '0x')) {
            $addr = substr($addr, 2);
        }

        return str_pad($addr, 64, '0', STR_PAD_LEFT);
    }

    private function padUint(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }
}
