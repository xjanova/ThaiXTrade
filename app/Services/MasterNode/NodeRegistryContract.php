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

    // ── NodeRegistryV2 รุ่นแก้เพดาน APR (2026-10) ─────────────────────────────
    // สัญญาที่ deploy ก่อนหน้านี้ไม่มีสามตัวนี้ → eth_call revert → อ่านได้ null
    // ห้ามแปลง null เป็น "ไม่ได้ปิดรับ" หรือ "APR 0%" เอง — ปล่อยให้ผู้เรียกบอกว่า "ไม่ทราบ"
    private const SEL_REGISTRATION_PAUSED = '0x9abbbd3b'; // registrationPaused()
    private const SEL_TIER_APR_CAP_BPS = '0xf8bc005d';    // tierAprCapBps(uint8)
    private const SEL_NODE_REWARD_RATE = '0x4dedaa7a';    // nodeRewardRate(uint8)

    /** 365 วันเป็นวินาที — ตรงกับ `365 days` ที่สัญญาใช้คิดเพดาน APR */
    private const SECONDS_PER_YEAR = '31536000';

    /** setTierAprCap() รับได้ 1..5000 bps — ค่านอกช่วงนี้แปลว่าไม่ได้คุยกับสัญญาที่คิดว่าคุยอยู่ */
    private const MAX_APR_CAP_BPS = 5000;

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

    /** ค่าใน cache แทน "อ่านสถานะ deploy ไม่ได้" — แยกจาก false (ทราบแน่ว่าไม่มีสัญญา) */
    private const STATE_UNKNOWN = 'unknown';

    /** นานแค่ไหนที่จำว่า "ไม่ทราบ" — สั้นพอให้หายเองเมื่อ RPC กลับมา */
    private const UNKNOWN_STATE_TTL = 15;

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
     * RPC ที่ส่งให้ "แอปนอกเบราว์เซอร์" (โปรแกรมมาสเตอร์โหนดบน PC + TPIX Wallet) ใช้.
     *
     * แยกจาก rpcUrl() เพราะ rpc.tpix.online ตอบ 403 ให้ client ที่ไม่ใช่เบราว์เซอร์
     * (ไม่มี UA / Go-http-client / curl) ส่วน rpc1.tpix.online ไม่มีกฎนี้
     */
    public function publicRpcUrl(): string
    {
        return (string) config('masternode.registry.public_rpc_url', 'https://rpc1.tpix.online');
    }

    /**
     * ตั้งที่อยู่ไว้แล้ว **และ** มี bytecode อยู่ที่อยู่นั้นจริง.
     *
     * ที่ต้องเช็ก eth_getCode ด้วยเพราะเชน TPIX เคย regenesis (6 ส.ค. 2026) แล้ว
     * สัญญาที่เคย deploy หายเกลี้ยงทั้งที่ address ยังค้างอยู่ใน .env
     * ถ้าไม่เช็ก ระบบจะยิง eth_call ไปที่ address ว่างเปล่าแล้วได้ '0x' กลับมา
     * แปลว่า "ไม่มีโหนด" แทนที่จะบอกว่า "สัญญาหาย"
     *
     * RPC ล้ม = false ที่นี่ (ไม่ทราบ ≠ deploy แล้ว) — ใครต้องแยก "ไม่ทราบ" ออกจาก
     * "ทราบแน่ว่าไม่มีสัญญา" ให้ใช้ deploymentState()
     */
    public function isDeployed(): bool
    {
        return $this->deploymentState() === true;
    }

    /**
     * สถานะการ deploy แบบสามค่า.
     *
     *   true  = ตั้งที่อยู่แล้วและมี bytecode ที่ที่อยู่นั้น
     *   false = ทราบแน่ว่าไม่มี (ยังไม่ได้ตั้งที่อยู่ / eth_getCode ตอบ '0x')
     *   null  = ไม่ทราบ (RPC ล้ม / ตอบ error) — ห้ามตีความเป็น "ยังไม่ deploy"
     *
     * เดิม cache ผล "RPC ล้ม" เป็น false ไว้ 5 นาที: RPC สะดุดครั้งเดียว หน้าเว็บขึ้น
     * "สัญญายังไม่ติดตั้ง", /config ตอบ registry_live=false และที่แย่ที่สุดคือ
     * NodeRegistryService ตกไป fallback เช็ก balance → ใครถือเหรียญครบก็ได้ allowlist
     */
    public function deploymentState(): ?bool
    {
        $address = $this->address();
        if ($address === null) {
            return false;
        }

        $key = 'masternode:registry:deployed:'.strtolower($address);
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }
        if ($cached === self::STATE_UNKNOWN) {
            return null;
        }

        $code = $this->rpc('eth_getCode', [$address, 'latest']);
        if (! is_string($code)) {
            // จำ "ไม่ทราบ" ไว้สั้น ๆ พอ — RPC ที่ล่มอยู่จะได้ไม่โดนยิงซ้ำทุก request (timeout 8 วิ/ครั้ง)
            Cache::put($key, self::STATE_UNKNOWN, self::UNKNOWN_STATE_TTL);

            return null;
        }

        $deployed = strlen($code) > 2;
        Cache::put($key, $deployed, 300);

        return $deployed;
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

        $minStakeWei = Wei::hexToInt($w[0]);

        // เพดาน APR + อัตรารางวัลต่อโหนดเป็นของสัญญารุ่นใหม่ — อ่านไม่ได้ = null (ไม่ทราบ)
        // ไม่ทำให้ค่าคอนฟิกหลักของชั้นหายไปด้วย เพราะ minStake/โควตายังต้องใช้กันกด tx ที่ revert
        $aprCap = $this->tierAprCapBps($tier);
        $rate = $this->nodeRewardRate($tier);

        return [
            'tier' => $tier,
            'name' => self::TIERS[$tier],
            // สัญญาบังคับ msg.value == minStake เป๊ะ — หน้าเว็บ/แอปต้องส่งตัวเลขนี้ตรง ๆ ห้ามปัด
            'min_stake_wei' => $minStakeWei,
            'min_stake' => Wei::hexToWholeUnits('0x'.$w[0]),
            'max_nodes' => (int) Wei::hexToInt($w[1]),
            'active_nodes' => (int) Wei::hexToInt($w[2]),
            'lock_days' => (int) Wei::hexToInt($w[3]),
            'slash_percent' => (int) Wei::hexToInt($w[4]),   // basis points
            'reward_share' => (int) Wei::hexToInt($w[5]),    // basis points
            'apr_cap_bps' => $aprCap,
            'reward_rate_per_node_wei' => $rate['current_per_node_wei'] ?? null,
            'reward_rate_if_join_wei' => $rate['if_one_more_joins_wei'] ?? null,
            // APR ของโหนดที่อยู่แล้ว (ตอนนี้) กับของโหนดใหม่ถ้าเข้าตอนนี้ — ที่ uptime 100%
            'current_apr_bps' => $rate ? $this->aprBps($rate['current_per_node_wei'], $minStakeWei) : null,
            'projected_apr_bps' => $rate ? $this->aprBps($rate['if_one_more_joins_wei'], $minStakeWei) : null,
        ];
    }

    /**
     * registrationPaused() — เจ้าของสัญญาปิดรับ "ลงทะเบียนใหม่" ชั่วคราว.
     *
     * ปิดแค่ registerNode เท่านั้น claim/deregister/withdraw ไม่เคยถูกบล็อก
     * (เงินของคนที่อยู่ในระบบแล้วต้องออกได้เสมอ)
     *
     * @return bool|null null = ยังไม่ deploy / RPC ล้ม / สัญญารุ่นเก่าที่ไม่มีฟังก์ชันนี้
     */
    public function registrationPaused(): ?bool
    {
        $result = $this->ethCall(self::SEL_REGISTRATION_PAUSED);
        if ($result === null) {
            return null;
        }

        // bool ที่ ABI-encode ถูกต้องมีได้แค่ 0 กับ 1 — ค่าอื่นแปลว่าถอดผิดที่/ผิดสัญญา
        return match (Wei::hexToInt($result)) {
            '0' => false,
            '1' => true,
            default => null,
        };
    }

    /**
     * tierAprCapBps(tier) — APR สูงสุดที่ "หนึ่งโหนด" ของชั้นนี้จะได้ (bps ของ minStake).
     *
     * ที่มา: ก่อนมีเพดาน โหนด Light ตัวเดียว (วางค้ำ 10K) จะได้ 15% ของ emission ปีแรก 600M
     * = ราว 900,000% APR แล้วดูดพูลจนหมด ตอนนี้ต่อโหนดได้
     * min(emission ของชั้น / จำนวนโหนด, minStake × เพดาน / 365 วัน) แล้วคูณ uptime
     */
    public function tierAprCapBps(int $tier): ?int
    {
        if (! isset(self::TIERS[$tier])) {
            return null;
        }

        $result = $this->ethCall(self::SEL_TIER_APR_CAP_BPS.$this->padUint($tier));
        if ($result === null) {
            return null;
        }

        $bps = Wei::hexToInt($result);
        if (bccomp($bps, '1', 0) < 0 || bccomp($bps, (string) self::MAX_APR_CAP_BPS, 0) > 0) {
            return null;
        }

        return (int) $bps;
    }

    /**
     * nodeRewardRate(tier) → (currentPerNode, ifOneMoreJoins) เป็น wei ต่อวินาทีของ "หนึ่งโหนด"
     * ที่ uptime 100% — ตอนนี้ กับ ถ้ามีโหนดเข้ามาเพิ่มอีกหนึ่งตัว (คือคนที่กำลังจะซื้อ).
     *
     * @return array{current_per_node_wei:string, if_one_more_joins_wei:string}|null
     */
    public function nodeRewardRate(int $tier): ?array
    {
        if (! isset(self::TIERS[$tier])) {
            return null;
        }

        $result = $this->ethCall(self::SEL_NODE_REWARD_RATE.$this->padUint($tier));
        if ($result === null) {
            return null;
        }

        $w = $this->words($result);
        if (count($w) < 2) {
            return null;
        }

        return [
            'current_per_node_wei' => Wei::hexToInt($w[0]),
            'if_one_more_joins_wei' => Wei::hexToInt($w[1]),
        ];
    }

    /**
     * แปลงอัตรารางวัล (wei/วินาที) เป็น APR หน่วย bps ของเงินวางค้ำ — คิดด้วย bcmath ล้วน.
     *
     * rate × 31,536,000 × 10,000 / minStakeWei (ปัดลง)
     * ห้ามใช้ float: wei ของ 10M TPIX = 1e25 เกินความแม่นของ double ไปไกล
     * ปัดลงเสมอเพื่อไม่โฆษณาผลตอบแทนเกินจริงแม้แต่ 0.01%
     */
    private function aprBps(string $ratePerSecondWei, string $minStakeWei): ?int
    {
        if (bccomp($minStakeWei, '0', 0) <= 0) {
            return null;
        }

        $yearly = bcmul($ratePerSecondWei, self::SECONDS_PER_YEAR, 0);
        $bps = bcdiv(bcmul($yearly, '10000', 0), $minStakeWei, 0);

        // อัตราผิดปกติจนเกิน int (ไม่ควรเกิดเพราะมีเพดาน) — บอกว่า "ไม่ทราบ" ดีกว่าโชว์เลขมั่ว
        return strlen($bps) > 15 ? null : (int) $bps;
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
