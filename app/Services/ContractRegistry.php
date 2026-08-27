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
    ];

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
    public function set(string $key, string $address): array
    {
        if (! isset(self::CONTRACTS[$key])) {
            return ['ok' => false, 'previous' => null, 'message' => "ไม่รู้จักสัญญาชื่อ {$key}"];
        }

        $address = trim($address);
        if (! $this->looksLikeAddress($address)) {
            return ['ok' => false, 'previous' => null, 'message' => 'รูปแบบที่อยู่ไม่ถูกต้อง'];
        }

        $previous = $this->address($key);

        SiteSetting::set(self::GROUP, $key, $address);
        $this->forget($key);

        Log::info('ContractRegistry: ตั้งที่อยู่สัญญาใหม่', [
            'contract' => $key,
            'previous' => $previous,
            'address' => $address,
        ]);

        return ['ok' => true, 'previous' => $previous, 'message' => null];
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

    public function forget(string $key): void
    {
        $address = $this->address($key);
        if ($address !== null) {
            Cache::forget("contracts:live:{$key}:".strtolower($address));
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
