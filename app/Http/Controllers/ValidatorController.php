<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Models\ValidatorApplication;
use App\Services\MasterNode\NodeRegistryContract;
use App\Services\MasterNode\Web3SignatureService;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * ValidatorController — จัดการ Validator Management System.
 *
 * อ่านข้อมูล validators จาก IBFT2 extraData บน TPIX Chain.
 *
 * ⚠️ ทุกตัวเลขที่หน้า /validators และ /api/v1/validators/* ส่งออกไปต้องมาจากเชน
 *    (บล็อก หรือ NodeRegistryV2) เท่านั้น อ่านไม่ได้ = null ("ไม่ทราบ")
 *    เดิมเมืองไทย 5 เมืองถูกแจกให้ validator ตามลำดับ, online=true, uptime=99.5
 *    และ total_staked = จำนวน validator × 10M ทั้งที่สัญญามีโหนด active 0 ตัว
 *    นักลงทุนเห็นตัวเลขพวกนั้นเป็นของจริง
 *
 * Developed by Xman Studio.
 */
class ValidatorController extends Controller
{
    /**
     * จำนวนบล็อกย้อนหลังที่ใช้ดูว่า validator ตัวไหนยังเซ็นบล็อกอยู่.
     *
     * polygon-edge จำกัด JSON-RPC batch ไว้ 20 คำขอ (--json-rpc-batch-request-limit)
     * บล็อกละ ~2 วิ = ราว 40 วินาทีล่าสุด
     */
    private const SEAL_WINDOW = 20;

    /** rpc.tpix.online ตอบ 403 ให้คำขอที่ไม่มี User-Agent (ดู NodeRegistryContract) */
    private const USER_AGENT = 'TPIX-TRADE-Server/1.0 (+https://tpix.online)';

    /** อายุข้อความให้เซ็นตอนสมัคร — นานพอให้เปิดกระเป๋าแล้วอ่านก่อนกด */
    private const APPLY_NONCE_TTL = 600;

    public function __construct(
        private NodeRegistryContract $registry,
        private Web3SignatureService $signatures,
    ) {
        //
    }

    /**
     * Validator dashboard page.
     */
    public function index()
    {
        return Inertia::render('Validators/Index', [
            'stats' => $this->getValidatorStats(),
            'validators' => $this->getValidatorList(),
            'tiers' => $this->chainTiers(),
            'rpcUrl' => config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online'),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
            'map' => $this->mapConfig(),
        ]);
    }

    /**
     * ค่าตั้งชั้นไทล์ของแผนที่ (ดู config/map.php).
     *
     * คีย์อยู่ใน .env ไม่ได้อยู่ในโค้ด — repo นี้เป็นสาธารณะ
     * ถ้ายังไม่ได้ตั้ง จะส่ง tileUrl ว่าง แล้วหน้าเว็บวาดแค่แผนที่ประเทศในเครื่อง
     */
    private function mapConfig(): array
    {
        // คีย์ตั้งจากหลังบ้าน /admin/settings แท็บ "แผนที่" (ตาราง site_settings กลุ่ม map)
        $key = trim((string) SiteSetting::get('map', 'map_tile_key', ''));
        $template = trim((string) SiteSetting::get('map', 'map_tile_url', ''));

        if ($key !== '' && $template !== '') {
            // ต่อคีย์ให้เอง แอดมินจะได้กรอกแค่คีย์ ไม่ต้องรู้รูปแบบ URL
            $tileUrl = $template.(str_contains($template, '?') ? '&' : '?').'key='.rawurlencode($key);

            return [
                'tileUrl' => $tileUrl,
                'tileSubdomains' => (string) SiteSetting::get('map', 'map_tile_subdomains', 'abcd'),
                'tileMaxZoom' => (int) SiteSetting::get('map', 'map_tile_max_zoom', 15),
                'tileAttribution' => (string) SiteSetting::get('map', 'map_tile_attribution', ''),
            ];
        }

        /*
         * ยังไม่ได้ตั้งคีย์ในหลังบ้าน — ถอยไปใช้ค่าใน .env (config/map.php)
         *
         * ถ้าว่างทั้งคู่จะส่ง tileUrl ว่าง แล้วหน้าเว็บ/โปรแกรมวาดแค่แผนที่ประเทศ
         * ที่เก็บไว้ในโปรเจกต์ — ตั้งใจให้เป็นแบบนี้ ดีกว่าโชว์ไทล์ที่มีลายน้ำ
         * "API KEY REQUIRED" พาดทั้งแผนที่ (CARTO บังคับคีย์ตั้งแต่ ส.ค. 2026)
         */
        return [
            'tileUrl' => (string) config('map.tile_url', ''),
            'tileSubdomains' => (string) config('map.tile_subdomains', 'abcd'),
            'tileMaxZoom' => (int) config('map.tile_max_zoom', 15),
            'tileAttribution' => (string) config('map.tile_attribution', ''),
        ];
    }

    /**
     * API: ค่าตั้งแผนที่ (public) — โปรแกรมมาสเตอร์โหนดบนเดสก์ท็อปเรียกใช้
     *
     * แยกออกมาเป็น endpoint เพื่อให้เปลี่ยนผู้ให้บริการไทล์หรือหมุนคีย์ได้
     * โดยไม่ต้องปล่อยโปรแกรมรุ่นใหม่ และไม่ต้องฝังคีย์ลงไฟล์ .exe
     */
    public function mapSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->mapConfig(),
        ]);
    }

    /**
     * Validator application form page.
     */
    public function apply()
    {
        return Inertia::render('Validators/Apply', [
            'tiers' => $this->chainTiers(),
            'rpcUrl' => config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online'),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
        ]);
    }

    /**
     * API: Get validator stats (public).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getValidatorStats(),
        ]);
    }

    /**
     * API: Get all validators with their info.
     */
    public function list(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getValidatorList(),
        ]);
    }

    /**
     * API: ข้อความให้กระเป๋าเซ็นก่อนส่งใบสมัคร.
     *
     * ข้อความผูกกับกระเป๋า + ชั้น + endpoint + nonce ครั้งเดียว — ลายเซ็นของใบหนึ่ง
     * เอาไปใช้กับใบอื่น ชั้นอื่น หรือยิงซ้ำไม่ได้
     */
    public function applicationChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'tier' => ['required', 'string', 'in:validator,guardian,sentinel,light'],
            'endpoint' => ['nullable', 'url', 'max:255'],
        ]);

        $wallet = strtolower($validated['wallet_address']);
        $nonce = bin2hex(random_bytes(16));
        $issuedAt = now()->toIso8601String();

        Cache::put($this->applyNonceKey($wallet, $nonce), $issuedAt, self::APPLY_NONCE_TTL);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $this->applicationMessage($wallet, $validated['tier'], $validated['endpoint'] ?? null, $nonce, $issuedAt),
                'nonce' => $nonce,
                'expires_in' => self::APPLY_NONCE_TTL,
            ],
        ]);
    }

    /**
     * Store a validator application.
     *
     * ⚠️ เดิมไม่ต้องพิสูจน์อะไรเลย ใครก็ส่งใบสมัครในนามกระเป๋าของใครก็ได้
     *    และพอแอดมินกดอนุมัติ ประเทศ/พิกัดที่กรอกเองจะไปทับตำแหน่งของ validator ตัวจริง
     *    ตอนนี้ต้องมีลายเซ็นของกระเป๋านั้น และใบสมัครไม่ถูกเอาไปแสดงในรายชื่อสาธารณะอีกแล้ว
     */
    public function submitApplication(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'tier' => ['required', 'string', 'in:validator,guardian,sentinel,light'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            // ประเทศที่ผู้สมัครแจ้งเอง — ให้แอดมินดูประกอบการพิจารณา ไม่ใช่ตำแหน่งโหนดบนแผนที่
            'country_code' => ['required', 'string', 'size:2'],
            'country_name' => ['required', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_telegram' => ['nullable', 'string', 'max:100'],
            'hardware_specs' => ['nullable', 'string', 'max:1000'],
            'motivation' => ['nullable', 'string', 'max:2000'],
            'nonce' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
        ]);

        $wallet = strtolower($validated['wallet_address']);

        // ใช้ได้ครั้งเดียว — ดึงออกจากแคชก่อนตรวจลายเซ็น ลายเซ็นที่หลุดไปจะยิงซ้ำไม่ได้
        $issuedAt = Cache::pull($this->applyNonceKey($wallet, $validated['nonce']));
        if (! is_string($issuedAt)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_NONCE',
                    'message' => 'The signing request expired or was already used. Please sign again.',
                ],
            ], 422);
        }

        $message = $this->applicationMessage($wallet, $validated['tier'], $validated['endpoint'] ?? null, $validated['nonce'], $issuedAt);
        if (! $this->signatures->verify($message, $validated['signature'], $wallet)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_SIGNATURE',
                    'message' => 'The signature does not match this wallet address.',
                ],
            ], 403);
        }

        // wallet_address เป็น unique ทุกสถานะ — ใบที่ถูกปฏิเสธก็ชนด้วย (เดิมตกไปเป็น 500)
        $existing = ValidatorApplication::where('wallet_address', $wallet)->first();
        if ($existing) {
            $rejected = $existing->status === ValidatorApplication::STATUS_REJECTED;

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $rejected ? 'APPLICATION_REJECTED' : 'DUPLICATE_APPLICATION',
                    'message' => $rejected
                        ? 'An earlier application from this wallet was rejected. Please contact the team to reopen it.'
                        : 'An application already exists for this wallet address.',
                ],
            ], 409);
        }

        try {
            $application = ValidatorApplication::create([
                'wallet_address' => $wallet,
                'tier' => $validated['tier'],
                'endpoint' => $validated['endpoint'] ?? null,
                'country_code' => strtoupper($validated['country_code']),
                'country_name' => $validated['country_name'],
                // ไม่เก็บพิกัดที่ผู้สมัครกรอกเอง — เดิมเป็นจุดกึ่งกลางประเทศที่หน้าเว็บเติมให้
                'latitude' => null,
                'longitude' => null,
                'contact_email' => $validated['contact_email'] ?? null,
                'contact_telegram' => $validated['contact_telegram'] ?? null,
                'hardware_specs' => $validated['hardware_specs'] ?? null,
                'motivation' => $validated['motivation'] ?? null,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $application->id,
                    'status' => 'pending',
                    'message' => 'Validator application submitted successfully.',
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Validator application submission failed', [
                'wallet' => $wallet,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SUBMISSION_FAILED',
                    'message' => 'Failed to submit application. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Check rewards for a specific validator address.
     *
     * ⚠️ เดิม pending_rewards / total_earned = ยอดเงินในกระเป๋า และ stake_amount
     *    มาจากตารางราคาชั้นตามใบสมัคร ไม่ใช่สิ่งที่วางค้ำไว้จริง
     *    ตอนนี้รางวัล/เงินวางค้ำอ่านจาก NodeRegistryV2 ไม่ได้ลงทะเบียน = null
     */
    public function checkRewards(Request $request): JsonResponse
    {
        $wallet = strtolower((string) $request->input('address', $request->input('wallet_address', '')));

        // Validate wallet address format
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address format.'],
            ], 422);
        }

        try {
            $balance = $this->rpc('eth_getBalance', [$wallet, 'latest']);
            $chain = $this->chainSnapshot();
            $inSet = $chain !== null && in_array($wallet, $chain['validators'], true);

            $node = $this->registry->isDeployed() ? $this->registry->nodeInfo($wallet) : null;
            $pending = $node !== null ? $this->registry->pendingReward($wallet) : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'wallet_address' => $wallet,
                    'active' => $inSet || ($node['status'] ?? null) === 'active',
                    // null = อ่านเชนไม่ได้ ไม่ใช่ "ไม่ได้อยู่ในชุด"
                    'in_validator_set' => $chain === null ? null : $inSet,
                    'tier' => $node !== null ? strtolower((string) $node['tier']) : ($inSet ? 'validator' : null),
                    'registry_status' => $node['status'] ?? null,
                    'pending_rewards' => $pending !== null ? Wei::format($pending) : null,
                    'total_earned' => $node !== null ? Wei::format($node['total_rewards_wei']) : null,
                    'stake_amount' => $node['stake_amount'] ?? null,
                    'balance' => is_string($balance) ? $this->weiToEther($balance) : null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Validator rewards check failed', [
                'wallet' => $wallet,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'QUERY_FAILED',
                    'message' => 'Failed to check rewards. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Get validator statistics — ทุกค่ามาจากบล็อกหรือ NodeRegistryV2.
     */
    private function getValidatorStats(): array
    {
        return cache()->remember('validator:stats', 30, function () {
            $chain = $this->chainSnapshot();
            if ($chain === null) {
                return $this->getDefaultStats();
            }

            $staking = $this->stakingStats();

            return [
                'chain_reachable' => true,
                // ถอด extraData ไม่ได้ = ไม่ทราบ ไม่ใช่ "0 validator" (เชนที่ยังออกบล็อกมี validator เสมอ)
                'total_validators' => count($chain['validators']) ?: null,
                // ตัวที่เซ็น commit อย่างน้อยหนึ่งบล็อกใน SEAL_WINDOW บล็อกล่าสุด
                'active_validators' => $chain['seal_window'] > 0
                    ? count(array_filter($chain['seals_signed'], fn ($n) => $n > 0))
                    : null,
                'seal_window' => $chain['seal_window'] ?: null,
                'block_height' => $chain['block_height'],
                'consensus' => 'IBFT2',
                'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
                'block_time' => $chain['block_time'],
                'gas_price' => $chain['gas_price_wei'],
                'last_block_timestamp' => $chain['block_timestamp'],
                'total_staked' => $staking['total_staked'],
                'staked_source' => 'NodeRegistryV2',
                'current_year' => $staking['current_year'],
            ];
        });
    }

    /**
     * Stats when the chain is unreachable — ไม่ทราบ = null ไม่ใช่ 0.
     */
    private function getDefaultStats(): array
    {
        return [
            'chain_reachable' => false,
            'total_validators' => null,
            'active_validators' => null,
            'seal_window' => null,
            'block_height' => null,
            'consensus' => 'IBFT2',
            'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
            'block_time' => null,
            'gas_price' => null,
            'last_block_timestamp' => null,
            'total_staked' => null,
            'staked_source' => 'NodeRegistryV2',
            'current_year' => null,
        ];
    }

    /**
     * ยอดวางค้ำทั้งเครือข่ายจาก NodeRegistryV2.getNetworkStats().
     *
     * IBFT validator ไม่ได้วางค้ำอะไรในสัญญา — ห้ามคูณจำนวน validator ด้วยขั้นต่ำของชั้นเอง
     * ยังไม่ deploy / อ่านไม่ได้ = null
     *
     * @return array{total_staked: ?string, current_year: ?int}
     */
    private function stakingStats(): array
    {
        $stats = $this->registry->isDeployed() ? $this->registry->networkStats() : null;
        if ($stats === null) {
            return ['total_staked' => null, 'current_year' => null];
        }

        return [
            'total_staked' => Wei::format($stats['total_staked_wei']),
            // สัญญานับปีจาก 0 หน้าเว็บนับจาก 1 (เหมือน MasterNodeController)
            'current_year' => min(3, $stats['current_year_index'] + 1),
        ];
    }

    /**
     * ค่าคอนฟิกแต่ละชั้นตามที่อยู่บน NodeRegistryV2 — อ่านไม่ได้ = [] แล้วหน้าเว็บโชว์ "—".
     *
     * @return array<string, array<string, mixed>>
     */
    private function chainTiers(): array
    {
        $out = [];
        foreach ($this->registry->allTierInfo() as $tier) {
            $out[strtolower((string) $tier['name'])] = [
                'min_stake' => $tier['min_stake'],
                'lock_days' => $tier['lock_days'],
                'reward_share_bps' => $tier['reward_share'],
                'apr_cap_bps' => $tier['apr_cap_bps'],
                'max_nodes' => $tier['max_nodes'],
                'active_nodes' => $tier['active_nodes'],
            ];
        }

        return $out;
    }

    /**
     * Get list of all validators from chain.
     *
     * ตำแหน่ง/ประเทศเป็น null จนกว่าจะมีระบบรายงานตำแหน่งจริงจากตัวโหนด
     * ใบสมัครที่แอดมินอนุมัติแล้วไม่ถูกเอามาทับข้อมูลของ validator อีก
     * (ผู้สมัครกรอกประเทศเองและเดิมไม่ต้องเซ็นยืนยันกระเป๋าด้วยซ้ำ)
     */
    private function getValidatorList(): array
    {
        return cache()->remember('validator:list', 30, function () {
            $chain = $this->chainSnapshot();
            if ($chain === null) {
                return [];
            }

            $addresses = $chain['validators'];

            $balances = $this->rpcBatch(array_map(
                fn ($address) => ['eth_getBalance', [$address, 'latest']],
                $addresses
            ));

            // IBFT validator ยังไม่ได้ลงทะเบียนในสัญญา (ถึง 2026-10) — ถ้าวันหนึ่งลงทะเบียน ค่าจริงจะขึ้นเอง
            $registryLive = $this->registry->isDeployed();

            $validators = [];
            foreach ($addresses as $index => $address) {
                $node = $registryLive ? $this->registry->nodeInfo($address) : null;
                $signed = $chain['seals_signed'][$address] ?? null;

                $validators[] = [
                    'address' => $address,
                    'tier' => 'validator',
                    // อยู่ในชุด validator ของบล็อกล่าสุด
                    'status' => 'active',
                    // เซ็น commit อย่างน้อยหนึ่งบล็อกใน seal_window บล็อกล่าสุด / null = ถอดลายเซ็นไม่ได้
                    'online' => $signed === null ? null : $signed > 0,
                    'seals_signed' => $signed,
                    'seal_window' => $chain['seal_window'] ?: null,
                    'last_signed_block' => $chain['last_signed_block'][$address] ?? null,
                    // ยังไม่มีการวัด uptime ของโหนด — ไม่ใส่ตัวเลขแทน
                    'uptime' => null,
                    'country_code' => null,
                    'country_name' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'location_known' => false,
                    'endpoint' => ($node['endpoint'] ?? '') !== '' ? $node['endpoint'] : null,
                    'stake_amount' => $node['stake_amount'] ?? null,
                    'registry_status' => $node['status'] ?? null,
                    'rewards' => $node !== null ? Wei::format($node['total_rewards_wei']) : null,
                    'balance' => is_string($balances[$index] ?? null) ? $this->weiToEther($balances[$index]) : null,
                    'index' => $index,
                ];
            }

            return $validators;
        });
    }

    /**
     * ภาพเชนล่าสุดที่ stats / list / rewards ใช้ร่วมกัน.
     *
     * @return array{block_height:int, block_timestamp:int, validators:list<string>, seal_window:int,
     *               seals_signed:array<string,int>, last_signed_block:array<string,?int>,
     *               block_time:?float, gas_price_wei:?string}|null null = อ่านบล็อกล่าสุดไม่ได้
     */
    private function chainSnapshot(): ?array
    {
        $key = 'validator:chain-snapshot';
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['data'];
        }

        $data = $this->readChainSnapshot();

        // อ่านไม่ได้ก็จำไว้สั้น ๆ — RPC ที่ล่มอยู่จะได้ไม่โดนยิงซ้ำทุกครั้งที่มีคนเปิดหน้า
        Cache::put($key, ['data' => $data], $data !== null ? 30 : 10);

        return $data;
    }

    private function readChainSnapshot(): ?array
    {
        $latest = $this->rpc('eth_getBlockByNumber', ['latest', false]);
        if (! is_array($latest) || empty($latest['number'])) {
            return null;
        }

        $height = (int) hexdec($latest['number']);
        $validators = $this->decodeIstanbulExtra($latest['extraData'] ?? '')['validators'];

        // บล็อกย้อนหลัง + gas price ใน batch เดียว
        $calls = [['eth_gasPrice', []]];
        for ($n = $height - 1; $n > $height - self::SEAL_WINDOW && $n >= 0; $n--) {
            $calls[] = ['eth_getBlockByNumber', ['0x'.dechex($n), false]];
        }
        $results = $this->rpcBatch($calls);
        $gasPrice = array_shift($results);

        $blocks = [$height => $latest];
        foreach ($results as $block) {
            if (is_array($block) && ! empty($block['number'])) {
                $blocks[(int) hexdec($block['number'])] = $block;
            }
        }

        $signed = array_fill_keys($validators, 0);
        $lastSigned = array_fill_keys($validators, null);
        $window = 0;

        foreach ($blocks as $number => $block) {
            $extra = $this->decodeIstanbulExtra($block['extraData'] ?? '');
            if ($extra['committed_bitmap'] === null) {
                continue;   // ไม่ใช่ BLS หรือถอดไม่ได้ — ไม่นับบล็อกนี้ ดีกว่าเดา
            }
            $window++;

            // บิตที่ i หมายถึง validator ลำดับ i ในชุดของ "บล็อกนั้น" (ชุดเปลี่ยนได้จากการโหวต)
            foreach ($extra['validators'] as $i => $address) {
                if (array_key_exists($address, $signed) && $this->bitIsSet($extra['committed_bitmap'], $i)) {
                    $signed[$address]++;
                    $lastSigned[$address] = max($lastSigned[$address] ?? 0, $number);
                }
            }
        }

        $oldest = min(array_keys($blocks));
        $blockTime = $oldest < $height
            ? round((hexdec($latest['timestamp'] ?? '0x0') - hexdec($blocks[$oldest]['timestamp'] ?? '0x0')) / ($height - $oldest), 2)
            : null;

        return [
            'block_height' => $height,
            'block_timestamp' => (int) hexdec($latest['timestamp'] ?? '0x0'),
            'validators' => $validators,
            'seal_window' => $window,
            'seals_signed' => $window > 0 ? $signed : [],
            'last_signed_block' => $lastSigned,
            'block_time' => $blockTime !== null && $blockTime > 0 ? $blockTime : null,
            'gas_price_wei' => is_string($gasPrice) ? Wei::hexToInt($gasPrice) : null,
        ];
    }

    /** บิตที่ $bit (นับจากขวา) ของเลขฐาน 16 */
    private function bitIsSet(string $hex, int $bit): bool
    {
        $pos = strlen($hex) - 1 - intdiv($bit, 4);
        if ($pos < 0 || ! ctype_xdigit($hex[$pos])) {
            return false;
        }

        return ((hexdec($hex[$pos]) >> ($bit % 4)) & 1) === 1;
    }

    private function rpcUrl(): string
    {
        return (string) config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online');
    }

    /** JSON-RPC ครั้งเดียว — null เมื่อ HTTP ล้มหรือโหนดตอบ error */
    private function rpc(string $method, array $params): mixed
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post($this->rpcUrl(), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful() || $response->json('error')) {
                return null;
            }

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('Validator RPC failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * JSON-RPC batch — คืนผลตามลำดับคำขอ ช่องไหนล้มเป็น null.
     *
     * @param  list<array{0:string,1:array}>  $calls
     * @return list<mixed>
     */
    private function rpcBatch(array $calls): array
    {
        $out = array_fill(0, count($calls), null);
        if ($calls === []) {
            return $out;
        }

        $payload = [];
        foreach (array_values($calls) as $id => [$method, $params]) {
            $payload[] = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => $id];
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post($this->rpcUrl(), $payload);

            // โหนดที่ไม่รับ batch ตอบเป็น object error ก้อนเดียว — ถือว่าไม่ได้อะไรเลย
            $items = $response->successful() ? $response->json() : null;
            if (! is_array($items) || ! array_is_list($items)) {
                return $out;
            }

            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (is_int($id) && array_key_exists($id, $out) && ! isset($item['error'])) {
                    $out[$id] = $item['result'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Validator RPC batch failed', ['calls' => count($calls), 'error' => $e->getMessage()]);
        }

        return $out;
    }

    private function applyNonceKey(string $wallet, string $nonce): string
    {
        return "validator_apply_nonce:{$wallet}:{$nonce}";
    }

    /**
     * ข้อความที่กระเป๋าเซ็น (EIP-191 personal_sign) — ASCII ล้วน
     * ความยาวที่ใส่ใน prefix จะได้ตรงกันทุกกระเป๋า.
     */
    private function applicationMessage(string $wallet, string $tier, ?string $endpoint, string $nonce, string $issuedAt): string
    {
        return implode("\n", [
            'TPIX Chain node application',
            '',
            'I control this wallet and apply to run a TPIX node.',
            'Signing is free and does not send a transaction.',
            '',
            'Wallet: '.$wallet,
            'Tier: '.$tier,
            'Endpoint: '.($endpoint ?: 'none'),
            'Nonce: '.$nonce,
            'Issued: '.$issuedAt,
        ]);
    }

    /**
     * ถอด extraData ของบล็อก polygon-edge IBFT.
     *
     * รูปแบบ (polygon-edge 0.9 — consensus/ibft/signer/extra.go):
     *   - 32 bytes vanity (64 hex chars)
     *   - RLP list: [validators, proposerSeal, committedSeals, parentCommittedSeals, round]
     *   - validators = [addr, ...] (ECDSA) หรือ [[addr, blsPubKey], ...] (BLS — เชน TPIX ใช้แบบนี้)
     *   - committedSeals แบบ BLS = [bitmap, aggregatedSignature 96 bytes]
     *     บิตที่ i = validator ลำดับ i เซ็น commit ของบล็อกนี้ (big.Int.SetBit)
     *     เช่นบล็อก 0x30f2f7 (2026-10-22) bitmap 0x0b = ลำดับ 0, 1, 3 เซ็น ลำดับ 2 ไม่ได้เซ็น
     *
     * committed_bitmap = null เมื่อไม่ใช่ BLS หรือถอดไม่ได้
     *
     * @return array{validators: list<string>, committed_bitmap: ?string}
     */
    private function decodeIstanbulExtra(string $extraData): array
    {
        $empty = ['validators' => [], 'committed_bitmap' => null];

        if (empty($extraData) || strlen($extraData) < 66) {
            return $empty;
        }

        // Remove 0x prefix
        $data = substr($extraData, 2);

        // Skip 32 bytes vanity (64 hex chars)
        $rlpData = substr($data, 64);

        if (empty($rlpData)) {
            return $empty;
        }

        try {
            // Decode the outer RLP list
            $decoded = $this->rlpDecodeList($rlpData);

            if (empty($decoded) || ! is_array($decoded[0])) {
                return $empty;
            }

            // First element is the validators list
            // Supports both IBFT2 (flat: [addr, addr, ...]) and
            // BLS (nested: [[addr, blsPubKey], [addr, blsPubKey], ...])
            $validators = [];
            foreach ($decoded[0] as $validatorEntry) {
                if (is_array($validatorEntry)) {
                    // BLS format: [address, blsPublicKey]
                    $addr = $validatorEntry[0] ?? '';
                    if (is_string($addr) && strlen($addr) === 40) {
                        $validators[] = '0x'.strtolower($addr);
                    }
                } elseif (is_string($validatorEntry) && strlen($validatorEntry) === 40) {
                    // IBFT2 format: plain address
                    $validators[] = '0x'.strtolower($validatorEntry);
                }
            }

            // ECDSA seals เป็นลายเซ็น 65 bytes หลายตัว ระบุตัวคนเซ็นไม่ได้ถ้าไม่ ecrecover — ไม่เดา
            $seals = $decoded[2] ?? null;
            $bitmap = null;
            if (is_array($seals) && count($seals) === 2
                && is_string($seals[0]) && strlen($seals[0]) <= 64
                && is_string($seals[1]) && strlen($seals[1]) === 192) {
                $bitmap = $seals[0];
            }

            return ['validators' => $validators, 'committed_bitmap' => $bitmap];
        } catch (\Throwable $e) {
            Log::error('Failed to extract validators from extraData', [
                'error' => $e->getMessage(),
                'extraData_length' => strlen($extraData),
            ]);

            return $empty;
        }
    }

    /**
     * Minimal RLP decoder for IBFT2 extraData.
     * Decodes the top-level list and first nested list (validators).
     *
     * @return array Decoded elements
     */
    private function rlpDecodeList(string $hex): array
    {
        $bytes = hex2bin($hex);
        if ($bytes === false || strlen($bytes) === 0) {
            return [];
        }

        $offset = 0;
        $result = $this->rlpDecodeItem($bytes, $offset);

        return is_array($result) ? $result : [];
    }

    /**
     * Decode a single RLP item from bytes at the given offset.
     *
     * @return mixed Decoded item (string for single values, array for lists)
     */
    private function rlpDecodeItem(string $bytes, int &$offset): mixed
    {
        if ($offset >= strlen($bytes)) {
            return '';
        }

        $prefix = ord($bytes[$offset]);

        // Single byte [0x00, 0x7f]
        if ($prefix < 0x80) {
            $offset++;

            return bin2hex(chr($prefix));
        }

        // Short string [0x80, 0xb7] — length = prefix - 0x80
        if ($prefix <= 0xB7) {
            $length = $prefix - 0x80;
            $offset++;
            $data = substr($bytes, $offset, $length);
            $offset += $length;

            return bin2hex($data);
        }

        // Long string [0xb8, 0xbf] — length of length = prefix - 0xb7
        if ($prefix <= 0xBF) {
            $lengthOfLength = $prefix - 0xB7;
            $offset++;
            $length = 0;
            for ($i = 0; $i < $lengthOfLength; $i++) {
                $length = ($length << 8) | ord($bytes[$offset + $i]);
            }
            $offset += $lengthOfLength;
            $data = substr($bytes, $offset, $length);
            $offset += $length;

            return bin2hex($data);
        }

        // Short list [0xc0, 0xf7] — length = prefix - 0xc0
        if ($prefix <= 0xF7) {
            $length = $prefix - 0xC0;
            $offset++;
            $end = $offset + $length;
            $items = [];
            while ($offset < $end) {
                $items[] = $this->rlpDecodeItem($bytes, $offset);
            }

            return $items;
        }

        // Long list [0xf8, 0xff] — length of length = prefix - 0xf7
        $lengthOfLength = $prefix - 0xF7;
        $offset++;
        $length = 0;
        for ($i = 0; $i < $lengthOfLength; $i++) {
            $length = ($length << 8) | ord($bytes[$offset + $i]);
        }
        $offset += $lengthOfLength;
        $end = $offset + $length;
        $items = [];
        while ($offset < $end) {
            $items[] = $this->rlpDecodeItem($bytes, $offset);
        }

        return $items;
    }

    /**
     * Convert wei hex string to ether string.
     *
     * เดิมใช้ gmp_* ซึ่งเซิร์ฟเวอร์ไม่มี ext-gmp → โยน \Error ที่ catch ไม่ติด
     * Wei::hexToWholeUnits() หารปัดลงเหมือน gmp_div_q ทุกประการ
     */
    private function weiToEther(string $hexWei): string
    {
        return Wei::hexToWholeUnits($hexWei);
    }
}
