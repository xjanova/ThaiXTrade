<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\SiteSetting;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\EvmRpc;
use App\Services\ContractRegistry;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;

/**
 * ค่าตั้งของสะพาน TPIX ⇄ wTPIX — ที่เดียวที่ตอบว่า "เปิดอยู่ไหม / สัญญาไหน / วงเงินเท่าไร / กุญแจอยู่ไหน".
 *
 * site_settings กลุ่ม 'wtpix_bridge' (แอดมินแก้ที่ /admin/bridge/wtpix) ชนะ config/wtpix_bridge.php
 * ยกเว้นที่อยู่สัญญา — ตรึงใน .env เท่านั้น (ทะเบียนสัญญาใช้เทียบอย่างเดียว ดู vaultRegistryMismatch)
 *
 * ⚠️ ไม่มีเมธอดไหนคืนหรือ log passphrase — signerKey() ส่งต่อให้ BridgeSigner เท่านั้น
 */
class WtpixBridgeConfig
{
    public const SETTINGS_GROUP = 'wtpix_bridge';

    public const CURSOR_GROUP = 'wtpix_bridge_cursor';

    /** คีย์ในทะเบียนสัญญา (deploy-bridge-vault.js ลงทะเบียนให้) — ห้ามเป็น bridge_vault ซึ่งเป็นของ USDT */
    public const REGISTRY_KEY = 'wtpix_bridge_vault';

    /** คีย์วงเงินที่แอดมินแก้ได้ (หน่วย TPIX เต็ม) */
    public const LIMIT_KEYS = [
        'min_lock', 'max_lock_per_tx', 'max_lock_per_address_daily', 'lock_fee',
        'min_burn', 'max_burn_per_tx', 'max_burn_per_address_daily', 'burn_fee',
    ];

    public const PUBLIC_CACHE_KEY = 'wtpix-bridge:info:public';

    public function __construct(private ContractRegistry $registry) {}

    // =========================================================================
    // สวิตช์
    // =========================================================================

    /** ติดตั้งแล้ว (WTPIX_BRIDGE_ENABLED) — ปิด = ไม่สแกน ไม่ mint ไม่ปล่อย */
    public function installed(): bool
    {
        return (bool) config('wtpix_bridge.enabled', false);
    }

    /** สวิตช์หลังบ้าน — ปริยายปิด · ตัวกระทบยอด/ตัวเฝ้าเหตุปิดให้เองเมื่อเจอสิ่งผิดปกติ */
    public function siteSwitchOn(): bool
    {
        return (bool) SiteSetting::get(self::SETTINGS_GROUP, 'enabled', false);
    }

    public function operational(): bool
    {
        return $this->installed() && $this->siteSwitchOn();
    }

    public function setSiteSwitch(bool $on, ?string $reason = null): void
    {
        SiteSetting::set(self::SETTINGS_GROUP, 'enabled', $on ? '1' : '0', 'boolean');
        SiteSetting::set(self::SETTINGS_GROUP, 'switch_reason', (string) ($reason ?? ''), 'string');
        SiteSetting::set(self::SETTINGS_GROUP, 'switch_changed_at', now()->toIso8601String(), 'string');
        Cache::forget(self::PUBLIC_CACHE_KEY);
    }

    public function switchReason(): ?string
    {
        $reason = trim((string) SiteSetting::get(self::SETTINGS_GROUP, 'switch_reason', ''));

        return $reason === '' ? null : $reason;
    }

    // =========================================================================
    // ที่อยู่สัญญา (ตรึงใน .env)
    // =========================================================================

    private function pinned(string $key): ?string
    {
        $address = strtolower(trim((string) config("wtpix_bridge.{$key}", '')));

        return preg_match('/^0x[0-9a-f]{40}$/', $address) && $address !== '0x'.str_repeat('0', 40) ? $address : null;
    }

    /** TPIXBridgeVault บนเชน 4289 — ตัวสแกนเชื่อ Locked ของที่อยู่นี้แล้ว mint wTPIX จริง */
    public function vaultAddress(): ?string
    {
        return $this->pinned('vault_address');
    }

    /** wTPIX บน BSC — ตัวสแกนเชื่อ Transfer(x, 0x0) ของที่อยู่นี้แล้วปล่อย TPIX จริง */
    public function wtpixAddress(): ?string
    {
        return $this->pinned('wtpix_address');
    }

    public function minterAddress(): ?string
    {
        return $this->pinned('minter_address');
    }

    /** ที่อยู่ที่ทะเบียนสัญญาของเว็บจดไว้ (ค่าใน site_settings เท่านั้น ไม่รวม fallback .env) */
    public function vaultRegistryAddress(): ?string
    {
        $raw = strtolower(trim((string) SiteSetting::get(ContractRegistry::GROUP, self::REGISTRY_KEY, '')));

        return preg_match('/^0x[0-9a-f]{40}$/', $raw) ? $raw : null;
    }

    /** ทะเบียนชี้ vault ไปที่อื่น = สัญญาณว่าทะเบียนถูกแก้ (หรือ .env ยังเป็นของเก่า) — หยุดจนกว่าคนจะตรวจ */
    public function vaultRegistryMismatch(): bool
    {
        $registered = $this->vaultRegistryAddress();

        return $registered !== null && $registered !== $this->vaultAddress();
    }

    /** ที่อยู่ภายในที่ล็อก/เผาแล้วไม่ถือเป็นคำขอข้ามสะพาน */
    public function internalSenders(): array
    {
        $list = array_map('strtolower', (array) config('wtpix_bridge.ignore_senders', []));
        $list = array_merge($list, array_filter([$this->vaultAddress(), $this->minterAddress(), $this->wtpixAddress()]));

        return array_values(array_unique(array_filter($list, fn ($a) => preg_match('/^0x[0-9a-f]{40}$/', $a))));
    }

    // =========================================================================
    // เชน
    // =========================================================================

    public function tpixChainId(): int
    {
        return (int) config('wtpix_bridge.tpix.chain_id', 4289);
    }

    /** ส่งไปนานเท่านี้แล้วยังไม่ลงบล็อก = ค้าง ต้องบอกแอดมิน */
    public function stuckAfterSeconds(): int
    {
        return max(5, (int) config('wtpix_bridge.stuck_after_minutes', 30)) * 60;
    }

    public function bscChainId(): int
    {
        return (int) config('wtpix_bridge.bsc.chain_id', 56);
    }

    public function bscName(): string
    {
        return (string) config('wtpix_bridge.bsc.name', 'BNB Smart Chain');
    }

    public function bsc(): array
    {
        return (array) config('wtpix_bridge.bsc', []);
    }

    public function tpixConfirmations(): int
    {
        return max(2, (int) config('wtpix_bridge.tpix.confirmations', 2));
    }

    public function tpixRpc(): EvmRpc
    {
        return new EvmRpc($this->tpixChainId(), (array) config('wtpix_bridge.tpix.rpc', []), $this->userAgent(), $this->timeout());
    }

    public function bscRpc(): EvmRpc
    {
        return new EvmRpc($this->bscChainId(), (array) config('wtpix_bridge.bsc.rpc', []), $this->userAgent(), $this->timeout());
    }

    public function rpc(int $chainId): ?EvmRpc
    {
        return match ($chainId) {
            $this->tpixChainId() => $this->tpixRpc(),
            $this->bscChainId() => $this->bscRpc(),
            default => null,
        };
    }

    public function explorer(int $chainId): string
    {
        $base = match ($chainId) {
            $this->tpixChainId() => (string) config('wtpix_bridge.tpix.explorer', 'https://explorer.tpix.online'),
            $this->bscChainId() => (string) config('wtpix_bridge.bsc.explorer', ''),
            default => '',
        };

        return rtrim($base, '/');
    }

    public function userAgent(): string
    {
        return (string) config('wtpix_bridge.user_agent', 'TPIX-Bridge/1.0');
    }

    private function timeout(): int
    {
        return max(1, (int) config('wtpix_bridge.rpc_timeout', 10));
    }

    /**
     * ต้นทาง/ปลายทางของแต่ละทิศ ณ ค่าตั้งปัจจุบัน.
     *
     * @return array{source_chain: int, source_contract: ?string, dest_chain: int, dest_contract: ?string}
     */
    public function route(string $direction): array
    {
        return $direction === WtpixBridgeTransfer::TO_BSC
            ? ['source_chain' => $this->tpixChainId(), 'source_contract' => $this->vaultAddress(), 'dest_chain' => $this->bscChainId(), 'dest_contract' => $this->minterAddress()]
            : ['source_chain' => $this->bscChainId(), 'source_contract' => $this->wtpixAddress(), 'dest_chain' => $this->tpixChainId(), 'dest_contract' => $this->vaultAddress()];
    }

    // =========================================================================
    // วงเงิน / ค่าธรรมเนียม (หน่วย TPIX เต็ม → wei)
    // =========================================================================

    public function limit(string $key): string
    {
        $fromSetting = SiteSetting::get(self::SETTINGS_GROUP, "limit_{$key}");

        return is_string($fromSetting) && preg_match('/^\d{1,12}(\.\d{1,18})?$/', $fromSetting)
            ? $fromSetting
            : (string) config("wtpix_bridge.limits.{$key}", '0');
    }

    public function limitWei(string $key): string
    {
        return Wei::toWei($this->limit($key), 18);
    }

    public function limits(): array
    {
        $out = [];
        foreach (self::LIMIT_KEYS as $key) {
            $out[$key] = $this->limit($key);
        }

        return $out;
    }

    /** คีย์วงเงินของแต่ละทิศ (ล็อกบน TPIX / เผาบน BSC) */
    public function limitKey(string $direction, string $kind): string
    {
        $side = $direction === WtpixBridgeTransfer::TO_BSC ? 'lock' : 'burn';

        return match ($kind) {
            'min' => "min_{$side}",
            'max_tx' => "max_{$side}_per_tx",
            'daily' => "max_{$side}_per_address_daily",
            'fee' => "{$side}_fee",
        };
    }

    public function minGasBalanceWei(): string
    {
        return Wei::toWei((string) config('wtpix_bridge.bsc.min_gas_balance', '0.002'), 18);
    }

    public function reconcileToleranceWei(): string
    {
        return Wei::toWei((string) config('wtpix_bridge.reconcile.tolerance', '1'), 18);
    }

    // =========================================================================
    // กุญแจ (keystore) — อ่านแค่ที่อยู่ในไฟล์ ไม่ถอดรหัส ไม่แตะ passphrase
    // =========================================================================

    public function keystorePath(string $role): string
    {
        return trim((string) config("wtpix_bridge.signers.{$role}.keystore", ''));
    }

    public function hasPassphrase(string $role): bool
    {
        return filled(config("wtpix_bridge.signers.{$role}.passphrase"));
    }

    /**
     * ที่อยู่ของ keystore จากฟิลด์ address ในไฟล์ — null ถ้าไม่มีไฟล์/อ่านไม่ได้.
     *
     * ไม่โยน error: is_readable() บนพาธนอก open_basedir ปล่อย warning ที่ Laravel แปลงเป็น exception
     * แล้วหน้าหลังบ้านพังเป็น 500 ทั้งที่แค่ยังไม่ได้วางไฟล์
     */
    public function signerAddress(string $role): ?string
    {
        $path = $this->keystorePath($role);
        if ($path === '') {
            return null;
        }

        return Cache::remember('wtpix-bridge:keystore-address:'.$role.':'.md5($path), 60, function () use ($path) {
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

    public function signerReady(string $role): bool
    {
        return $this->signerAddress($role) !== null && $this->hasPassphrase($role);
    }

    /**
     * ข้อมูลกุญแจสำหรับ BridgeSigner::signWithKey — passphrase ไปถึงโปรเซส node ทาง env เท่านั้น.
     *
     * @return array{label: string, keystore: string, passphrase: ?string, address: ?string}
     */
    public function signerKey(string $role): array
    {
        return [
            'label' => "wtpix:{$role}",
            'keystore' => $this->keystorePath($role),
            'passphrase' => config("wtpix_bridge.signers.{$role}.passphrase"),
            'address' => $this->signerAddress($role),
        ];
    }

    // =========================================================================
    // เคอร์เซอร์ของตัวสแกน — ผูกกับ (เชน, สัญญา) เปลี่ยนสัญญา = เริ่มนับใหม่ที่หัวปัจจุบัน
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

    public function lockCursorKey(): string
    {
        return 'lock_'.$this->tpixChainId().'_'.($this->vaultAddress() ?? 'none');
    }

    public function burnCursorKey(): string
    {
        return 'burn_'.$this->bscChainId().'_'.($this->wtpixAddress() ?? 'none');
    }
}
