<?php

namespace App\Services\SaleCrypto;

use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\EvmRpc;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Support\TreasuryWallet;
use App\Support\Wei;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * ค่าตั้งของการซื้อ TPIX ด้วย USDT — ที่เดียวที่ตอบว่า "รับคริปโตได้ไหมตอนนี้ / เชนไหน / โอนเข้าที่ไหน".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมทางคริปโตเดิมถูกถอด (2026-08-21) และอะไรที่ต่างไปในรอบนี้
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. เดิม "จ่ายก่อน แล้วค่อยยื่น tx_hash" — ทุกด่าน (เฟสปิด / เซสชันกระเป๋าหมด / โควตาหมด)
 *    โผล่หลังเงินออกไปแล้ว ไม่มีแถวบันทึก เหลือแค่ hash ใน localStorage ของผู้ซื้อ
 *    → ตอนนี้ด่านทั้งหมดอยู่ตอน "สร้างคำสั่งซื้อ" ก่อนเงินออก และตัวสแกนบันทึกทุกยอดที่เข้ามา
 *      (ยอดไม่ตรง/มาช้า/ผิดเหรียญ = needs_review) ผู้ซื้อไม่ต้องยื่นอะไรเลย
 * 2. กระเป๋ารับเงิน BSC เดิม (0xF1CD…) nonce 0 — พิสูจน์ไม่ได้ว่ามีใครถือคีย์
 *    → keyProven(): ต้องเคยเซ็นธุรกรรมออกมาแล้วอย่างน้อยหนึ่งครั้งบนเชนที่รับ ไม่งั้นไม่เปิดรับเงิน
 * 3. ห้ามใช้ vault ของสะพาน / กระเป๋าที่เซิร์ฟเวอร์ถือคีย์ — ดู internalAddresses()
 *
 * ลำดับการเปิด: SALE_CRYPTO_ENABLED (ติดตั้ง) → รอบขายมี "CRYPTO" ใน accept_currencies (นโยบาย)
 *              → กระเป๋ารับเงินผ่านด่าน → ตัวสแกนของเชนนั้นทำงานอยู่ (ตรวจเงินเข้าไม่ได้ = ไม่รับเงิน)
 */
class SaleCryptoConfig
{
    public const CHANNEL = TokenSale::CRYPTO_CHANNEL;

    public const CURSOR_GROUP = 'sale_crypto_cursor';

    public function __construct(
        private BridgeConfig $bridge,
        private WtpixBridgeConfig $wtpix,
    ) {}

    public function installed(): bool
    {
        return (bool) config('sale_crypto.enabled', false);
    }

    public function accepted(TokenSale $sale): bool
    {
        return in_array(self::CHANNEL, array_map('strtoupper', (array) ($sale->accept_currencies ?? [])), true);
    }

    // =========================================================================
    // เชน
    // =========================================================================

    /**
     * เชนที่ตั้งให้รับชำระ — ข้อมูล USDT/RPC/finality มาจากชุดเดียวกับสะพาน.
     *
     * @return array<int, array{chain_id:int, name:string, short:string, native:string, token:string, symbol:string, decimals:int, explorer:string}>
     */
    public function chains(): array
    {
        $out = [];

        foreach ((array) config('sale_crypto.chains', []) as $id) {
            $id = (int) $id;
            $chain = $this->bridge->chain($id);
            $token = strtolower((string) ($chain['usdt']['address'] ?? ''));

            if (! $chain || ! preg_match('/^0x[0-9a-f]{40}$/', $token)) {
                continue;
            }

            $out[$id] = [
                'chain_id' => $id,
                'name' => (string) $chain['name'],
                'short' => (string) ($chain['short'] ?? $chain['name']),
                'native' => (string) ($chain['native'] ?? ''),
                'token' => $token,
                // ผู้ซื้อเห็น "USDT" เสมอ (บางเชนชื่อสัญญาเป็น USDT0 / USD₮0 แต่คือ Tether ตัวจริงที่สะพานตรวจแล้ว)
                'symbol' => 'USDT',
                'decimals' => (int) ($chain['usdt']['decimals'] ?? 6),
                'explorer' => rtrim((string) ($chain['explorer'] ?? ''), '/'),
            ];
        }

        return $out;
    }

    public function chain(int $chainId): ?array
    {
        return $this->chains()[$chainId] ?? null;
    }

    /** ค่าดิบของสะพาน (rpc / finality / log_chunk / max_blocks_per_run) */
    public function bridgeChain(int $chainId): ?array
    {
        return $this->bridge->chain($chainId);
    }

    public function rpc(int $chainId): ?EvmRpc
    {
        return $this->bridge->rpc($chainId);
    }

    /** @return array<int, array{symbol:string, address:string, decimals:int}> */
    public function watchTokens(int $chainId): array
    {
        $out = [];

        foreach ((array) config("sale_crypto.watch_tokens.{$chainId}", []) as $token) {
            $address = strtolower((string) ($token['address'] ?? ''));
            if (preg_match('/^0x[0-9a-f]{40}$/', $address)) {
                $out[] = ['symbol' => (string) $token['symbol'], 'address' => $address, 'decimals' => (int) $token['decimals']];
            }
        }

        return $out;
    }

    /** ฝุ่นในหน่วยดิบของเหรียญนั้น */
    public function dustRaw(int $decimals): string
    {
        return Wei::toWei((string) config('sale_crypto.dust', '0.01'), $decimals);
    }

    // =========================================================================
    // กระเป๋ารับเงิน
    // =========================================================================

    /** กระเป๋ารับเงินของรอบขาย (พิมพ์เล็ก) — null ถ้าไม่ได้ตั้ง/รูปแบบผิด */
    public function receiveAddress(?TokenSale $sale): ?string
    {
        $address = strtolower(trim((string) ($sale?->sale_wallet_address ?? '')));

        return preg_match('/^0x[0-9a-f]{40}$/', $address) && $address !== '0x'.str_repeat('0', 40) ? $address : null;
    }

    /**
     * ที่อยู่ที่ "ห้าม" เป็นกระเป๋ารับเงินของรอบขาย.
     *
     * vault — ตัวสแกนสะพานถือทุก Transfer เข้า vault เป็นเงินฝากแล้ว mint USDT_TPIX ให้ผู้โอน
     *         ผู้ซื้อจะได้ทั้ง TPIX และ USDT_TPIX (เครดิตซ้ำ) และตัวกระทบยอดนับรายได้ขายเป็นเงินหนุน
     *         พอเจ้าของถอนรายได้ออก supply จะดูเกินเงินหนุนแล้วสะพานหยุดตัวเอง
     * payout/relayer/guardian/กระเป๋าจ่ายเหรียญ — เซิร์ฟเวอร์ถือคีย์ ถ้าเซิร์ฟเวอร์ถูกเจาะ รายได้ทั้งหมดหายด้วย
     * สะพาน wTPIX · กองทุนเสถียรราคา · oracle มาสเตอร์โหนด — เซิร์ฟเวอร์ถือคีย์เหมือนกัน
     *
     * @return array<int, string>
     */
    public function internalAddresses(): array
    {
        $list = [$this->bridge->vaultAddress(), $this->wtpix->vaultAddress()];

        foreach (['payout', 'relayer', 'guardian'] as $role) {
            $list[] = $this->bridge->signerAddress($role);
        }

        foreach (['relayer', 'guardian'] as $role) {
            $list[] = $this->wtpix->signerAddress($role);
        }

        $list[] = config('treasury.hot_wallet.address');
        $list[] = config('stabilizer.wallet.address');
        $list[] = config('masternode.uptime.oracle.address');

        try {
            $list[] = TreasuryWallet::address();
        } catch (\Throwable) {
            // ยังไม่ได้ตั้งกระเป๋าจ่ายเหรียญ — ไม่ใช่ปัญหาของด่านนี้
        }

        $list = array_map(fn ($a) => strtolower((string) $a), $list);

        return array_values(array_unique(array_filter($list, fn ($a) => preg_match('/^0x[0-9a-f]{40}$/', $a))));
    }

    /**
     * กระเป๋ารับเงินเคยเซ็นธุรกรรมออกแล้วไหม (nonce > 0 บนเชนที่รับชำระเชนใดเชนหนึ่ง) — แคช 10 นาที.
     *
     * nonce > 0 พิสูจน์ได้ว่ามีคนถือคีย์ใบนี้จริงและใช้มันได้ — เจ้าของทำได้ง่ายด้วยการส่ง 0 ให้ตัวเองหนึ่งครั้ง
     * อ่านไม่ได้ = ถือว่ายังไม่ผ่าน (fail-closed) แต่แคชผลลบไว้แค่ 1 นาที จะได้ไม่ค้างนานตอน RPC สะดุด
     */
    public function keyProven(string $address): bool
    {
        $key = 'sale_crypto:key_proven:'.strtolower($address);
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }

        $proven = false;
        foreach (array_keys($this->chains()) as $chainId) {
            $nonce = $this->rpc($chainId)?->result('eth_getTransactionCount', [$address, 'latest']);
            if (is_string($nonce) && str_starts_with($nonce, '0x') && bccomp(Wei::hexToInt($nonce), '0', 0) > 0) {
                $proven = true;
                break;
            }
        }

        Cache::put($key, $proven, $proven ? 600 : 60);

        return $proven;
    }

    /**
     * ทำไมกระเป๋ารับเงินของรอบนี้ยังรับเงินไม่ได้ — null = ใช้ได้.
     */
    public function receiveAddressProblem(TokenSale $sale): ?string
    {
        $address = $this->receiveAddress($sale);

        return match (true) {
            $address === null => 'not_set',
            in_array($address, $this->internalAddresses(), true) => 'internal_address',
            ! $this->keyProven($address) => 'key_not_proven',
            default => null,
        };
    }

    // =========================================================================
    // ความพร้อม
    // =========================================================================

    /** ตัวสแกนของเชนนี้สแกนผ่านล่าสุดภายในเวลาที่กำหนด สำหรับกระเป๋าใบนี้ */
    public function scanHealthy(int $chainId, string $address): bool
    {
        $at = SiteSetting::get(self::CURSOR_GROUP, $this->scanOkKey($chainId, $address));
        if (! is_string($at) || $at === '') {
            return false;
        }

        try {
            return now()->diffInMinutes(Carbon::parse($at), true) <= (int) config('sale_crypto.scan_stale_minutes', 10);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * เชนที่รับคำสั่งซื้อใหม่ได้จริงตอนนี้.
     *
     * @return array<int, array>
     */
    public function openChains(TokenSale $sale): array
    {
        if (! $this->installed() || ! $this->accepted($sale) || $this->receiveAddressProblem($sale) !== null) {
            return [];
        }

        $address = $this->receiveAddress($sale);

        return array_filter($this->chains(), fn ($c) => $this->scanHealthy($c['chain_id'], $address));
    }

    public function isAvailable(TokenSale $sale): bool
    {
        return $this->openChains($sale) !== [];
    }

    // =========================================================================
    // เคอร์เซอร์ (site_settings — แคชถูกล้างตอน deploy แล้วต้องไม่ไล่ใหม่)
    // =========================================================================

    public function cursorKey(int $chainId, string $address): string
    {
        return "pay_{$chainId}_".strtolower($address);
    }

    public function scanOkKey(int $chainId, string $address): string
    {
        return "scan_ok_at_{$chainId}_".strtolower($address);
    }

    public function cursor(string $key): ?int
    {
        $value = SiteSetting::get(self::CURSOR_GROUP, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    public function setCursor(string $key, int $nextBlock): void
    {
        // คอลัมน์ type เป็น enum — 'integer' ถูก DB ปฏิเสธ (ดู BridgeConfig::setCursor)
        SiteSetting::set(self::CURSOR_GROUP, $key, (string) $nextBlock, 'number');
    }

    public function markScanOk(int $chainId, string $address): void
    {
        SiteSetting::set(self::CURSOR_GROUP, $this->scanOkKey($chainId, $address), now()->toIso8601String(), 'string');
    }
}
