<?php

namespace App\Services\Bridge;

use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;

/**
 * ค่าตั้งของสะพาน USDT — ที่เดียวที่ตอบว่า "เปิดอยู่ไหม / วงเงินเท่าไร / ใช้ RPC ไหน / กุญแจอยู่ไหน".
 *
 * ลำดับการหาค่า: site_settings กลุ่ม 'bridge' (แอดมินแก้ที่ /admin/bridge) ชนะ config/bridge.php
 * ที่อยู่สัญญาบนเชน TPIX มาจาก ContractRegistry (สคริปต์ deploy ลงทะเบียนให้เอง)
 *
 * ⚠️ ไม่มีเมธอดไหนในคลาสนี้คืนหรือ log passphrase — readiness บอกแค่ว่า "ตั้งแล้ว/ยังไม่ตั้ง"
 */
class BridgeConfig
{
    public const SETTINGS_GROUP = 'bridge';

    public const CURSOR_GROUP = 'bridge_cursor';

    /** คีย์วงเงินที่แอดมินแก้ได้ (หน่วย USDT เต็ม) */
    public const LIMIT_KEYS = [
        'min_deposit', 'max_deposit_per_tx', 'max_deposit_per_address_daily', 'deposit_fee',
        'min_withdraw', 'max_withdraw_per_tx', 'max_payout_daily',
    ];

    public function __construct(private ContractRegistry $registry) {}

    // =========================================================================
    // สวิตช์
    // =========================================================================

    /** ติดตั้งแล้ว (BRIDGE_ENABLED ใน .env) — ปิด = ไม่สแกน ไม่ mint ไม่จ่าย */
    public function installed(): bool
    {
        return (bool) config('bridge.enabled', false);
    }

    /** สวิตช์หลังบ้าน — ปริยายปิด แอดมินต้องตั้งใจเปิดเอง; ตัวกระทบยอดปิดให้เองเมื่อเงินหนุนขาด */
    public function siteSwitchOn(): bool
    {
        return (bool) SiteSetting::get(self::SETTINGS_GROUP, 'enabled', false);
    }

    /** ทำงานจริงได้ (mint / จ่าย) — ต้องผ่านทั้งสองสวิตช์ */
    public function operational(): bool
    {
        return $this->installed() && $this->siteSwitchOn();
    }

    /**
     * @param  string  $kind  manual = แอดมินกดเอง · auto = ระบบหยุดเองเพราะด่านความปลอดภัย (กระทบยอด / mint ที่ไม่ได้สั่ง)
     */
    public function setSiteSwitch(bool $on, ?string $reason = null, string $kind = 'manual'): void
    {
        SiteSetting::set(self::SETTINGS_GROUP, 'enabled', $on ? '1' : '0', 'boolean');
        SiteSetting::set(self::SETTINGS_GROUP, 'switch_reason', (string) ($reason ?? ''), 'string');
        SiteSetting::set(self::SETTINGS_GROUP, 'switch_kind', $on ? '' : ($kind === 'auto' ? 'auto' : 'manual'), 'string');
        SiteSetting::set(self::SETTINGS_GROUP, 'switch_changed_at', now()->toIso8601String(), 'string');
        Cache::forget('bridge:info:public');
    }

    /** เหตุผลเต็ม (มีชื่อแอดมิน / รายละเอียดเหตุการณ์) — หลังบ้านเท่านั้น ห้ามส่งออกหน้าเว็บสาธารณะ */
    public function switchReason(): ?string
    {
        $reason = trim((string) SiteSetting::get(self::SETTINGS_GROUP, 'switch_reason', ''));

        return $reason === '' ? null : $reason;
    }

    /**
     * เหตุที่ปิดแบบรหัสกลาง ๆ สำหรับหน้าเว็บสาธารณะ — null เมื่อเปิดอยู่.
     *
     * ข้อความเต็มมีชื่อแอดมินและบันทึกเหตุการณ์ ("คีย์ relayer อาจหลุด" / ยอดขาด) ซึ่งไม่ควรอยู่ใน API สาธารณะ
     */
    public function publicPausedCode(): ?string
    {
        if ($this->siteSwitchOn()) {
            return null;
        }

        return SiteSetting::get(self::SETTINGS_GROUP, 'switch_kind', '') === 'auto' ? 'safety_hold' : 'maintenance';
    }

    /** ธุรกรรมที่ส่งไปแล้วไม่ลงบล็อกนานเกินนี้ = ค้าง (แจ้งเตือน + ส่งให้คนดู) */
    public function stuckAfterSeconds(): int
    {
        return max(5, (int) config('bridge.stuck_after_minutes', 30)) * 60;
    }

    /**
     * วงเงินฝากต่อครั้งที่ใช้ได้จริง (หน่วย 6 ทศนิยม) = min(วงเงินของเว็บ, maxPerTx บนสัญญา).
     *
     * เกินวงเงินของเว็บ = รอแอดมินอนุมัติ แต่เกิน maxPerTx บนสัญญา = mint ไม่ได้เลยไม่ว่าใครอนุมัติ
     * เงินจะค้างใน vault จนกว่าจะคืนด้วยมือ — หน้าเว็บจึงต้องห้ามส่งตั้งแต่แรก
     */
    public function effectiveMaxDepositUnits(?string $onchainMaxPerTx): string
    {
        $site = $this->limitUnits('max_deposit_per_tx');

        if ($onchainMaxPerTx === null || ! ctype_digit($onchainMaxPerTx) || bccomp($onchainMaxPerTx, '0', 0) <= 0) {
            return $site;
        }

        return bccomp($onchainMaxPerTx, $site, 0) < 0 ? $onchainMaxPerTx : $site;
    }

    // =========================================================================
    // ที่อยู่
    // =========================================================================

    public function vaultAddress(): string
    {
        return strtolower((string) config('bridge.vault_address'));
    }

    /**
     * USDT_TPIX ที่สะพานเชื่อ — ตรึงใน config/bridge.php ไม่ใช่ทะเบียนสัญญา (ทะเบียนแก้ได้ด้วย bearer token
     * และสะพานจ่ายเงินจริงตาม Burned ของที่อยู่นี้).
     */
    public function usdtTpixAddress(): ?string
    {
        $address = strtolower(trim((string) config('bridge.usdt_tpix_address', '')));

        return preg_match('/^0x[0-9a-f]{40}$/', $address) ? $address : null;
    }

    /** ทะเบียนสัญญาของเว็บชี้ USDT_TPIX ไปที่อื่น (หน้า DEX ใช้ค่านั้น) — สัญญาณว่าทะเบียนถูกแก้ */
    public function usdtTpixRegistryMismatch(): bool
    {
        $registered = $this->registry->address('usdt_tpix');

        return $registered !== null && strtolower($registered) !== $this->usdtTpixAddress();
    }

    public function minterAddress(): ?string
    {
        $address = $this->registry->address('bridge_minter');

        return $address ? strtolower($address) : null;
    }

    /** ที่อยู่ภายในที่โอนเข้า vault แล้วไม่ถือเป็นการฝาก */
    public function internalSenders(): array
    {
        $list = array_map('strtolower', (array) config('bridge.ignore_senders', []));
        $list[] = $this->vaultAddress();

        if ($payout = $this->signerAddress('payout')) {
            $list[] = strtolower($payout);
        }

        return array_values(array_unique(array_filter($list, fn ($a) => preg_match('/^0x[0-9a-f]{40}$/', $a))));
    }

    // =========================================================================
    // เชน
    // =========================================================================

    public function tpixChainId(): int
    {
        return (int) config('bridge.tpix.chain_id', 4289);
    }

    /** เชนต้นทาง/ปลายทางทั้งหมดที่ตั้งไว้ (เปิดหรือปิดก็ตาม) — คีย์ = chain id */
    public function chains(): array
    {
        $out = [];

        foreach ((array) config('bridge.chains', []) as $id => $chain) {
            $out[(int) $id] = $chain + ['chain_id' => (int) $id];
        }

        return $out;
    }

    public function chain(int $chainId): ?array
    {
        return $this->chains()[$chainId] ?? null;
    }

    /** เปิดใช้เชนนี้ไหม — config ปิด = ปิดถาวร · แอดมินปิดเพิ่มเองได้ (ปิดชั่วคราวตอนเชนมีปัญหา) */
    public function chainEnabled(int $chainId): bool
    {
        $chain = $this->chain($chainId);
        if (! $chain || ! ($chain['enabled'] ?? false)) {
            return false;
        }

        return (bool) SiteSetting::get(self::SETTINGS_GROUP, "chain_enabled_{$chainId}", true);
    }

    /** @return array<int, array> */
    public function enabledChains(): array
    {
        return array_filter($this->chains(), fn ($c) => $this->chainEnabled($c['chain_id']));
    }

    public function rpc(int $chainId): ?EvmRpc
    {
        if ($chainId === $this->tpixChainId()) {
            return $this->tpixRpc();
        }

        $chain = $this->chain($chainId);
        if (! $chain) {
            return null;
        }

        return new EvmRpc($chainId, (array) ($chain['rpc'] ?? []), $this->userAgent(), (int) config('bridge.rpc_timeout', 10));
    }

    public function tpixRpc(): EvmRpc
    {
        return new EvmRpc($this->tpixChainId(), (array) config('bridge.tpix.rpc', []), $this->userAgent(), (int) config('bridge.rpc_timeout', 10));
    }

    public function userAgent(): string
    {
        return (string) config('bridge.user_agent', 'TPIX-Bridge/1.0');
    }

    public function explorer(int $chainId): string
    {
        if ($chainId === $this->tpixChainId()) {
            return rtrim((string) config('bridge.tpix.explorer', 'https://explorer.tpix.online'), '/');
        }

        return rtrim((string) ($this->chain($chainId)['explorer'] ?? ''), '/');
    }

    // =========================================================================
    // วงเงิน / ค่าธรรมเนียม (หน่วย 6 ทศนิยม)
    // =========================================================================

    /** วงเงินเป็นหน่วย USDT เต็ม (สตริงทศนิยม) */
    public function limit(string $key): string
    {
        $fromSetting = SiteSetting::get(self::SETTINGS_GROUP, "limit_{$key}");
        $value = is_string($fromSetting) && preg_match('/^\d+(\.\d{1,6})?$/', $fromSetting)
            ? $fromSetting
            : (string) config("bridge.limits.{$key}", '0');

        return $value;
    }

    /** วงเงินเป็นหน่วย 6 ทศนิยม */
    public function limitUnits(string $key): string
    {
        return Wei::toWei($this->limit($key), 6);
    }

    public function limits(): array
    {
        $out = [];
        foreach (self::LIMIT_KEYS as $key) {
            $out[$key] = $this->limit($key);
        }

        return $out;
    }

    public function withdrawFee(int $chainId): string
    {
        $fromSetting = SiteSetting::get(self::SETTINGS_GROUP, "withdraw_fee_{$chainId}");

        if (is_string($fromSetting) && preg_match('/^\d+(\.\d{1,6})?$/', $fromSetting)) {
            return $fromSetting;
        }

        return (string) ($this->chain($chainId)['withdraw_fee'] ?? '1');
    }

    public function withdrawFeeUnits(int $chainId): string
    {
        return Wei::toWei($this->withdrawFee($chainId), 6);
    }

    public function reconcileToleranceUnits(): string
    {
        return Wei::toWei((string) config('bridge.reconcile.tolerance', '1'), 6);
    }

    // =========================================================================
    // กุญแจ (keystore) — อ่านแค่ที่อยู่ในไฟล์ ไม่ถอดรหัส ไม่แตะ passphrase
    // =========================================================================

    public function keystorePath(string $role): string
    {
        return trim((string) config("bridge.signers.{$role}.keystore", ''));
    }

    public function hasPassphrase(string $role): bool
    {
        return filled(config("bridge.signers.{$role}.passphrase"));
    }

    /**
     * ที่อยู่ของ keystore จากฟิลด์ address ในไฟล์ — null ถ้าไม่มีไฟล์/อ่านไม่ได้/ไม่มีฟิลด์.
     *
     * ไม่โยน error เด็ดขาด: is_readable() บนพาธนอก open_basedir ปล่อย warning ที่ Laravel
     * แปลงเป็น exception แล้วหน้าหลังบ้านพังเป็น 500 ทั้งที่แค่ยังไม่ได้วางไฟล์ (ดู TreasuryService)
     */
    public function signerAddress(string $role): ?string
    {
        $path = $this->keystorePath($role);
        if ($path === '') {
            return null;
        }

        return Cache::remember('bridge:keystore-address:'.$role.':'.md5($path), 60, function () use ($path) {
            set_error_handler(static fn () => true);

            try {
                $raw = is_readable($path) ? file_get_contents($path) : false;
            } catch (\Throwable) {
                $raw = false;
            } finally {
                restore_error_handler();
            }

            if (! is_string($raw) || $raw === '') {
                return null;
            }

            $json = json_decode($raw, true);
            $address = trim((string) (is_array($json) ? ($json['address'] ?? '') : ''));
            if ($address === '') {
                return null;
            }

            $address = str_starts_with($address, '0x') ? $address : '0x'.$address;

            return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) ? strtolower($address) : null;
        });
    }

    /** กุญแจบทบาทนี้พร้อมเซ็นไหม (มีไฟล์ อ่านที่อยู่ได้ และตั้ง passphrase แล้ว) */
    public function signerReady(string $role): bool
    {
        return $this->signerAddress($role) !== null && $this->hasPassphrase($role);
    }

    // =========================================================================
    // เคอร์เซอร์ของตัวสแกน
    // =========================================================================

    public function cursor(string $key): ?int
    {
        $value = SiteSetting::get(self::CURSOR_GROUP, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    public function setCursor(string $key, int $nextBlock): void
    {
        // ⚠️ คอลัมน์ type เป็น enum (string/text/boolean/number/json/image) — 'integer' ถูก DB ปฏิเสธ
        SiteSetting::set(self::CURSOR_GROUP, $key, (string) $nextBlock, 'number');
    }
}
