<?php

namespace App\Services\Discord;

use App\Http\Controllers\Api\AppUpdateController;
use App\Http\Controllers\Api\TpixPriceController;
use App\Http\Controllers\MasterNodeController;
use App\Http\Controllers\ValidatorController;
use App\Models\FactoryToken;
use App\Models\TradingPair;
use App\Services\ContractRegistry;
use App\Services\TpixDexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ข้อมูลสดที่บอท Discord ใช้ (ราคา · สถานะเชน · ลิงก์ทางการ · แอปรุ่นล่าสุด · คู่เทรด DEX).
 *
 * ⚠️ ดึงผ่าน API สาธารณะตัวเดียวกับหน้าเว็บ (เรียก action ของ controller ตรง ๆ ไม่ผ่าน HTTP)
 *    เพื่อให้ตัวเลขในห้อง Discord ตรงกับหน้าเว็บทุกหลัก — ลำดับแหล่งราคา (DEX → กระดาน → ค่าตั้ง → รอบขาย)
 *    และแคชของแต่ละตัวอยู่ที่ controller แล้ว ห้ามเขียนสูตรซ้ำที่นี่ ไม่งั้นวันหนึ่งสองที่จะบอกราคาไม่ตรงกัน
 *
 * ทุกเมธอดไม่ throw — ดึงไม่ได้คืน null / ค่าว่าง แล้วผู้เรียกบอกผู้ใช้ตรง ๆ ว่าอ่านไม่ได้
 *
 * Developed by Xman Studio.
 */
class DiscordLiveData
{
    public function __construct(private readonly ContractRegistry $contracts) {}

    /**
     * ราคา TPIX — source: dex (พูลบนเชน สวอปได้จริง) · trades (กระดานเทรด) · admin (ราคาอ้างอิง ยังไม่มีตลาดจริง).
     *
     * @return array<string, mixed>|null
     */
    public function price(): ?array
    {
        return $this->fromApi(fn () => app(TpixPriceController::class)->price(), 'price');
    }

    /**
     * สถานะเชน: บล็อก/validator จาก ValidatorController + มาสเตอร์โหนดจาก MasterNodeController.
     *
     * @return array{connected: bool, block_height: int, validators: int, last_block_at: ?int, masternodes: ?array<string, mixed>, chain_id: int, rpc: string, explorer: string}
     */
    public function chain(): array
    {
        $validators = $this->fromApi(fn () => app(ValidatorController::class)->stats(), 'validators') ?? [];
        $nodes = $this->fromApi(fn () => app(MasterNodeController::class)->stats(), 'masternodes');

        $height = (int) ($validators['block_height'] ?? 0);

        return [
            // ValidatorController คืนเลขศูนย์ทั้งชุดตอนต่อเชนไม่ได้ — เลขบล็อก 0 = อ่านไม่ได้ ไม่ใช่เชนหยุด
            'connected' => $height > 0,
            'block_height' => $height,
            'validators' => (int) ($validators['active_validators'] ?? 0),
            'last_block_at' => ! empty($validators['last_block_timestamp']) ? (int) $validators['last_block_timestamp'] : null,
            'masternodes' => is_array($nodes) && ! empty($nodes['registry_deployed']) ? $nodes : null,
            'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
            'rpc' => (string) config('blockchain.tpix_public_rpc_url', 'https://rpc.tpix.online'),
            'explorer' => $this->explorer(),
        ];
    }

    /**
     * ลิงก์ทางการ + ที่อยู่สัญญาที่ "มีโค้ดอยู่บนเชนจริง" เท่านั้น
     * (เชนเคย regenesis แล้วที่อยู่ค้างในคอนฟิกทั้งที่สัญญาหายไป — แจกที่อยู่ตายให้คนโอนเข้าไม่ได้).
     *
     * @return array{pages: list<array{0: string, 1: string}>, contracts: list<array{0: string, 1: string}>, bsc_wtpix: ?string, chain_id: int, rpc: string, explorer: string}
     */
    public function links(): array
    {
        $base = rtrim((string) config('app.url'), '/');

        $contracts = [];
        foreach (['wtpix' => 'WTPIX', 'usdt_tpix' => 'USDT (on TPIX Chain)', 'dex_router' => 'TPIX DEX Router', 'dex_factory' => 'TPIX DEX Factory', 'masternode_registry' => 'Master node registry · ทะเบียนมาสเตอร์โหนด'] as $key => $label) {
            try {
                $address = $this->contracts->address($key);
                if ($address !== null && $this->contracts->isLive($key)) {
                    $contracts[] = [$label, $address];
                }
            } catch (\Throwable $e) {
                Log::info('Discord /ลิงก์: ตรวจสัญญาไม่สำเร็จ', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        $bsc = trim((string) config('services.bridge.wtpix_bsc_address', ''));

        return [
            'pages' => [
                ['🌐 Website · เว็บไซต์', $base],
                ['💱 Trade TPIX/USDT · เทรด', $base.'/trade/TPIX-USDT'],
                ['🔄 Swap · สวอป', $base.'/swap'],
                ['💰 Buy TPIX · ซื้อเหรียญ', $base.'/token-sale'],
                ['🖥️ Master nodes · มาสเตอร์โหนด', $base.'/masternode'],
                ['🌉 Bridge · บริดจ์ข้ามเชน', $base.'/bridge'],
                ['📱 Apps & wallet · ดาวน์โหลดแอป', $base.'/download'],
                ['📘 Whitepaper', $base.'/whitepaper'],
                ['🔍 Explorer', $this->explorer()],
            ],
            'contracts' => $contracts,
            'bsc_wtpix' => preg_match('/^0x[0-9a-fA-F]{40}$/', $bsc) ? $bsc : null,
            'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
            'rpc' => (string) config('blockchain.tpix_public_rpc_url', 'https://rpc.tpix.online'),
            'explorer' => $this->explorer(),
        ];
    }

    /**
     * แอปรุ่นล่าสุดที่หน้าดาวน์โหลดแจกอยู่ (ข้อมูลเดียวกับ /api/v1/app/latest และ /chain-latest).
     *
     * @return list<array{product: string, label: string, label_th: string, version: string, name: string, notes: string, published_at: ?string}>
     */
    public function releases(): array
    {
        $out = [];

        $trade = $this->fromApi(fn () => app(AppUpdateController::class)->latest(), 'app');
        if (is_array($trade) && ! empty($trade['version'])) {
            $out[] = $this->release('trade', 'TPIX TRADE app (Android)', 'แอป TPIX TRADE (Android)', $trade);
        }

        $chain = $this->fromApi(fn () => app(AppUpdateController::class)->chainLatest(), 'chain') ?? [];
        foreach (['wallet' => ['TPIX Wallet (Android)', 'TPIX Wallet (Android)'], 'masternode' => ['Master node app (Windows)', 'โปรแกรมมาสเตอร์โหนด (Windows)']] as $product => [$label, $labelTh]) {
            if (is_array($chain[$product] ?? null) && ! empty($chain[$product]['version'])) {
                $out[] = $this->release($product, $label, $labelTh, $chain[$product]);
            }
        }

        return $out;
    }

    /**
     * คู่เทรดบนเชน TPIX ที่มีสภาพคล่องจริง **และเหรียญผ่านการตรวจจากทีมงาน** (dex:sync อัปเดตทุกนาที).
     *
     * ⚠️ dex:sync สร้างคู่ให้ทุกพูลที่มีสภาพคล่อง ใครก็สร้างเหรียญชื่อ "USDT" ใส่สภาพคล่องนิดเดียวได้
     *    ถ้าบอททางการประกาศ "คู่ใหม่ USDT/TPIX" = ช่วยมิจฉาชีพโปรโมต → รับเฉพาะ
     *    TPIX ตัวจริง (native ที่อยู่ 0x0) หรือเหรียญจาก Token Factory ที่แอดมินกดยืนยันแล้ว
     *    และชื่อคู่ต้องเป็นตัวอักษร/ตัวเลขล้วน (ชื่อเหรียญมาจากคนสร้าง ใส่ [ลิงก์](...) มาได้)
     *
     * @return Collection<int, TradingPair>
     */
    public function dexPairs(): Collection
    {
        try {
            $verified = FactoryToken::query()->deployed()->where('is_verified', true)
                ->pluck('contract_address')->map(fn ($a) => strtolower((string) $a))->filter()->all();

            return TradingPair::query()
                ->with('baseToken:id,contract_address')
                ->where('execution_mode', 'onchain')
                ->where('is_active', true)
                ->orderBy('symbol')
                ->limit(200)
                ->get(['id', 'symbol', 'base_token_id', 'is_active', 'created_at'])
                ->filter(function (TradingPair $pair) use ($verified) {
                    $base = strtolower((string) $pair->baseToken?->contract_address);
                    $trusted = $base === TpixDexService::ZERO || ($base !== '' && in_array($base, $verified, true));

                    return $trusted && preg_match('/^[A-Z0-9.]{1,20}-[A-Z0-9.]{1,20}$/', (string) $pair->symbol);
                })
                ->take(50)
                ->values();
        } catch (\Throwable $e) {
            Log::warning('Discord: อ่านคู่เทรด DEX ไม่สำเร็จ', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * ค่าตั้งเครือข่ายสำหรับเพิ่มในวอลเล็ต — อ่านจากคอนฟิกล้วน ไม่ถามเชน (คู่มือประจำห้องสร้างทุกรอบซิงก์).
     *
     * @return array{chain_id: int, rpc: string, explorer: string}
     */
    public function network(): array
    {
        return [
            'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
            'rpc' => (string) config('blockchain.tpix_public_rpc_url', 'https://rpc.tpix.online'),
            'explorer' => $this->explorer(),
        ];
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    private function explorer(): string
    {
        return (string) config('chains.chains.4289.explorer', 'https://explorer.tpix.online');
    }

    /** @param  array<string, mixed>  $data */
    private function release(string $product, string $label, string $labelTh, array $data): array
    {
        return [
            'product' => $product,
            'label' => $label,
            'label_th' => $labelTh,
            'version' => (string) $data['version'],
            'name' => (string) ($data['name'] ?? ''),
            'notes' => (string) ($data['notes'] ?? ''),
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : null,
        ];
    }

    /**
     * เรียก action สาธารณะของ controller แล้วแกะ data ออกมา (ตอบ success=false / พัง = null).
     *
     * @param  callable(): JsonResponse  $call
     * @return array<string, mixed>|null
     */
    private function fromApi(callable $call, string $what): ?array
    {
        try {
            $body = $call()->getData(true);

            return ($body['success'] ?? false) && is_array($body['data'] ?? null) ? $body['data'] : null;
        } catch (\Throwable $e) {
            Log::warning('Discord: อ่านข้อมูลสดไม่สำเร็จ', ['what' => $what, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
