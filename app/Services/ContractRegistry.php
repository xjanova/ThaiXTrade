<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ContractRegistry — ที่อยู่สัญญาบนเชน อยู่ที่เดียวของทั้งระบบ.
 *
 * ลำดับการหาค่า:
 *   1. SiteSetting กลุ่ม 'contracts' — สคริปต์ deploy เขียนเข้ามาเอง (หรือแอดมินวางเอง)
 *   2. config/.env — ค่าที่ตั้งไว้แต่เดิม ยังใช้ได้ ไม่ต้องรื้อ
 *
 * ทำไมต้องมี:
 * เดิมต้อง ssh เข้าเซิร์ฟเวอร์ไปแก้ .env แล้วรัน config:cache ทุกครั้งที่ deploy สัญญาใหม่
 * ซึ่งเป็นขั้นตอนที่ลืมง่ายที่สุด — deploy สำเร็จแต่เว็บยังไม่รู้จักสัญญา แล้วก็เงียบ
 * ตอนนี้สคริปต์ deploy ยิงมาลงทะเบียนเองได้ เหลือขั้นตอนเดียวคือ "deploy"
 *
 * ⚠️ ค่าใน SiteSetting ชนะ .env เสมอ — ตั้งใจให้เป็นแบบนั้น เพราะเป็นค่าที่ใหม่กว่า
 *    ถ้าอยากกลับไปใช้ .env ให้ลบค่าในกลุ่ม contracts ทิ้ง
 *
 * Developed by Xman Studio
 */
class ContractRegistry
{
    public const GROUP = 'contracts';

    /**
     * สัญญาที่ระบบรู้จัก — key => [ชื่อที่คนอ่านเข้าใจ, config path ที่ใช้เป็น fallback].
     *
     * @var array<string,array{label:string,config:?string}>
     */
    public const CONTRACTS = [
        'masternode_registry' => [
            'label' => 'NodeRegistryV2 (มาสเตอร์โหนด)',
            'config' => 'blockchain.masternode_registry',
        ],
        'validator_kyc' => [
            'label' => 'ValidatorKYC (ด่าน KYC ชั้น Validator)',
            'config' => null,
        ],
        'token_factory_v2' => [
            'label' => 'TPIXTokenFactoryV2 (สร้างเหรียญ ERC-20)',
            'config' => 'blockchain.factory_v2_address',
        ],
        'nft_factory' => [
            'label' => 'TPIXNFTFactory (สร้าง NFT)',
            'config' => 'blockchain.nft_factory_address',
        ],
        'token_factory_v1' => [
            'label' => 'TPIXTokenFactory (ตัวเก่า)',
            'config' => 'blockchain.factory_address',
        ],
        /*
         * ── TPIX DEX (AMM แบบ Uniswap V2 บนเชน 4289) ──────────────────────────
         * สี่ตัวนี้ต้องครบและมีโค้ดอยู่บนเชนจริง หน้าเทรดคู่บนเชน TPIX ถึงจะเปิด
         * (ดู dexReady()) — ขาดตัวเดียว = ยังถือว่า "รอ deploy" ทั้งชุด
         */
        'wtpix' => [
            'label' => 'WTPIX (TPIX ห่อเป็น ERC-20 สำหรับ DEX)',
            'config' => 'blockchain.dex.wtpix',
        ],
        'usdt_tpix' => [
            'label' => 'USDT_TPIX (USDT บนเชน TPIX)',
            'config' => 'blockchain.dex.usdt',
        ],
        'dex_factory' => [
            'label' => 'TPIXDEXFactory (ทะเบียนพูลสภาพคล่อง)',
            'config' => 'blockchain.dex.factory',
        ],
        'dex_router' => [
            'label' => 'TPIXDEXRouter02 (สวอป + เติม/ถอนสภาพคล่อง)',
            'config' => 'blockchain.dex.router',
        ],
    ];

    /** คีย์ของสัญญาที่ประกอบกันเป็น DEX — ครบทุกตัวถึงเปิดเทรดบนเชน TPIX ได้ */
    public const DEX_KEYS = ['wtpix', 'usdt_tpix', 'dex_factory', 'dex_router'];

    private const USER_AGENT = 'TPIX-TRADE-Server/1.0 (+https://tpix.online)';

    /**
     * ที่อยู่ของสัญญา — null ถ้ายังไม่ได้ตั้ง หรือรูปแบบไม่ถูกต้อง.
     */
    public function address(string $key): ?string
    {
        if (! isset(self::CONTRACTS[$key])) {
            return null;
        }

        $fromSetting = trim((string) SiteSetting::get(self::GROUP, $key, ''));
        if ($this->looksLikeAddress($fromSetting)) {
            return $fromSetting;
        }

        $configPath = self::CONTRACTS[$key]['config'];
        if ($configPath === null) {
            return null;
        }

        $fromConfig = trim((string) config($configPath, ''));

        return $this->looksLikeAddress($fromConfig) ? $fromConfig : null;
    }

    /**
     * ตั้งที่อยู่สัญญา (สคริปต์ deploy หรือแอดมินเรียก).
     *
     * @return array{ok:bool, previous:?string, message:?string}
     */
    public function set(string $key, string $address, bool $force = false): array
    {
        if (! isset(self::CONTRACTS[$key])) {
            return ['ok' => false, 'previous' => null, 'message' => "ไม่รู้จักสัญญาชื่อ {$key}"];
        }

        $address = trim($address);
        if (! $this->looksLikeAddress($address)) {
            return ['ok' => false, 'previous' => null, 'message' => 'รูปแบบที่อยู่ไม่ถูกต้อง'];
        }

        $previous = $this->address($key);
        $previousHash = $this->storedCodeHash($key);
        $newHash = $this->codeHash($address);

        /*
         * ── ด่านลายนิ้วมือ bytecode ────────────────────────────────────────────
         *
         * เดิมตรวจแค่ `eth_getCode !== 0x` ซึ่งพิสูจน์ได้แค่ว่า "มีสัญญาสักตัวอยู่"
         * ไม่ได้พิสูจน์ว่าเป็น "สัญญาตัวจริงของเรา" ถ้า CONTRACT_REGISTRY_TOKEN รั่ว
         * ผู้โจมตี deploy สัญญาปลอม (ฟรี เพราะเชนค่าแก๊ส 0) แล้ว POST ที่อยู่ปลอมเข้ามา
         * หน้าเว็บก็จะพาเงินผู้ใช้ไปเข้าสัญญาของเขา โดยไม่ต้องแฮ็กเชนเลย
         *
         * ใช้แบบ trust-on-first-use ไม่ใช่รายการ hash ตายตัว เพราะตอนนี้ยังไม่มี
         * สัญญาไหน deploy ขึ้นเชนได้เลย (deployed-contracts.json ยังว่าง) จึงไม่มี
         * hash ที่ "รู้ว่าถูก" ให้ใส่ล่วงหน้า
         *
         * กติกา: จดลายนิ้วมือตอนลงทะเบียนครั้งแรก หลังจากนั้นถ้าจะย้ายไปที่อยู่ใหม่
         * bytecode ต้องเหมือนเดิม (redeploy สัญญาตัวเดิม = hash เท่ากัน ผ่านได้)
         * ถ้าต่างต้องส่ง force มาโดยตั้งใจ ซึ่งจะถูกบันทึกเป็น warning
         */
        if (! $force && $previous !== null && $previousHash !== null) {
            if ($newHash === null) {
                return [
                    'ok' => false,
                    'previous' => $previous,
                    'message' => "ที่อยู่ {$address} ไม่มี bytecode อยู่บนเชน",
                ];
            }

            if (! hash_equals($previousHash, $newHash)) {
                Log::warning('ContractRegistry: ปฏิเสธการย้ายไปสัญญาที่ bytecode ไม่ตรงของเดิม', [
                    'contract' => $key,
                    'previous' => $previous,
                    'attempted' => $address,
                ]);

                return [
                    'ok' => false,
                    'previous' => $previous,
                    'message' => 'bytecode ไม่ตรงกับสัญญาที่ลงทะเบียนไว้เดิม '
                        .'ถ้าตั้งใจเปลี่ยนเป็นสัญญาคนละตัวจริง ให้ส่ง force=true',
                ];
            }
        }

        SiteSetting::set(self::GROUP, $key, $address);
        if ($newHash !== null) {
            SiteSetting::set(self::GROUP, $this->codeHashKey($key), $newHash);
        }
        $this->forget($key);

        $forcedOverDifferentCode = $force
            && $previousHash !== null
            && $newHash !== null
            && ! hash_equals($previousHash, $newHash);

        if ($forcedOverDifferentCode) {
            Log::warning('ContractRegistry: บังคับเปลี่ยนไปสัญญาที่ bytecode ต่างจากเดิม', [
                'contract' => $key,
                'previous' => $previous,
                'address' => $address,
            ]);
        } else {
            Log::info('ContractRegistry: ตั้งที่อยู่สัญญาใหม่', [
                'contract' => $key,
                'previous' => $previous,
                'address' => $address,
            ]);
        }

        return ['ok' => true, 'previous' => $previous, 'message' => null];
    }

    /**
     * ลายนิ้วมือของ bytecode ที่อยู่นั้น — null ถ้าไม่มีสัญญาอยู่.
     *
     * ใช้ sha256 ไม่ใช่ keccak เพราะต้องการแค่ตัวเปรียบเทียบ ไม่ได้เอาไปใช้บนเชน
     */
    public function codeHash(string $address): ?string
    {
        $code = $this->rpc('eth_getCode', [$address, 'latest']);

        if (! is_string($code) || strlen($code) <= 2) {
            return null;
        }

        return hash('sha256', strtolower($code));
    }

    private function codeHashKey(string $key): string
    {
        return $key.'__codehash';
    }

    private function storedCodeHash(string $key): ?string
    {
        $stored = SiteSetting::get(self::GROUP, $this->codeHashKey($key));

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * มี bytecode อยู่ที่อยู่นั้นจริงไหม.
     *
     * ตั้งที่อยู่ไว้ไม่ได้แปลว่ามีสัญญาอยู่ — เชน TPIX เคย regenesis (6 ส.ค. 2026)
     * แล้วสัญญาหายเกลี้ยงทั้งที่ address ยังค้างในคอนฟิก ถ้าไม่เช็กจะกลายเป็น
     * "ระบบดูปกติ แต่ทุกธุรกรรมเงียบหาย"
     */
    public function isLive(string $key): bool
    {
        $address = $this->address($key);
        if ($address === null) {
            return false;
        }

        return Cache::remember(
            "contracts:live:{$key}:".strtolower($address),
            300,
            fn () => $this->hasCode($address)
        );
    }

    /**
     * ถามเชนตรง ๆ ว่ามีโค้ดที่อยู่นี้ไหม (ไม่ผ่าน cache).
     */
    public function hasCode(string $address): bool
    {
        $code = $this->rpc('eth_getCode', [$address, 'latest']);

        return is_string($code) && strlen($code) > 2;
    }

    /**
     * สถานะของสัญญาทุกตัว — ใช้บนหน้าแอดมินและตอบ API.
     *
     * @return array<string,array{key:string,label:string,address:?string,live:bool,source:string}>
     */
    public function status(): array
    {
        $out = [];

        foreach (self::CONTRACTS as $key => $meta) {
            $address = $this->address($key);
            $fromSetting = $this->looksLikeAddress(trim((string) SiteSetting::get(self::GROUP, $key, '')));

            $out[$key] = [
                'key' => $key,
                'label' => $meta['label'],
                'address' => $address,
                'live' => $address !== null && $this->isLive($key),
                'source' => $address === null ? 'none' : ($fromSetting ? 'registry' : 'env'),
            ];
        }

        return $out;
    }

    /**
     * ที่อยู่ชุด DEX พร้อมธง ready — หน้าเทรด/สวอปบนเชน TPIX และ AI TRADE ใช้ตัวเดียวกัน.
     *
     * ready = ทั้ง 4 ตัวมีที่อยู่ **และ** เชนยืนยันว่ามี bytecode อยู่จริง
     * (เชนเคย regenesis แล้วสัญญาหายทั้งที่ที่อยู่ยังค้างในคอนฟิก — ตั้งไว้เฉย ๆ ไม่พอ)
     *
     * @return array{ready:bool, chainId:int, rpc:string, WTPIX:?string, USDT:?string, FACTORY:?string, ROUTER:?string, missing:string[]}
     */
    public function dexConfig(): array
    {
        $map = [
            'WTPIX' => 'wtpix',
            'USDT' => 'usdt_tpix',
            'FACTORY' => 'dex_factory',
            'ROUTER' => 'dex_router',
        ];

        $out = [
            'ready' => true,
            'chainId' => (int) config('blockchain.tpix_chain_id', 4289),
            'rpc' => (string) config('blockchain.tpix_public_rpc_url', config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online')),
            'missing' => [],
        ];

        foreach ($map as $field => $key) {
            $address = $this->address($key);
            $out[$field] = $address;

            if ($address === null || ! $this->isLive($key)) {
                $out['ready'] = false;
                $out['missing'][] = $key;
            }
        }

        return $out;
    }

    /** DEX บนเชน TPIX พร้อมให้เทรดจริงหรือยัง (ครบ 4 สัญญาและมีโค้ดบนเชน). */
    public function dexReady(): bool
    {
        return $this->dexConfig()['ready'];
    }

    public function forget(string $key): void
    {
        $address = $this->address($key);
        if ($address !== null) {
            Cache::forget("contracts:live:{$key}:".strtolower($address));
        }
        if (in_array($key, self::DEX_KEYS, true)) {
            Cache::forget('dex:config:public');
            Cache::forget('dex:pairs:public');
        }
        Cache::forget('masternode:stats');
        Cache::forget('admin:masternode:stats');
        Cache::forget('masternode:tiers:public');
        Cache::forget('masternode:registry:tiers');
    }

    private function looksLikeAddress(string $value): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $value);
    }

    /**
     * JSON-RPC — ต้องส่ง User-Agent เสมอ ไม่งั้น Cloudflare ตอบ 403 ให้ (ยืนยันสด 2026-08-27).
     */
    private function rpc(string $method, array $params): mixed
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post(config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online'), [
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
            Log::warning('ContractRegistry RPC failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
