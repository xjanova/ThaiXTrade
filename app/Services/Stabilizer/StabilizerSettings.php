<?php

namespace App\Services\Stabilizer;

use App\Models\SiteSetting;
use App\Services\TpixDexService;

/**
 * ค่าตั้งของ TPIX Stability Reserve — สวิตช์ 2 ชั้น + พารามิเตอร์ของกฎ + กระเป๋า.
 *
 * พารามิเตอร์เก็บใน site_settings กลุ่ม `stabilizer` และถูก "หนีบ" ให้อยู่ในกรอบ min/max
 * ของ config/stabilizer.php เสมอ — ต่อให้ค่าในฐานข้อมูลถูกแก้ตรง ๆ ให้เกินกรอบ บอทก็ไม่ใช้
 *
 * ชื่อคีย์ขึ้นต้นด้วย stabilizer_ ทุกตัว เพราะหน้า /admin/settings แบนค่าทุกกลุ่มลงเป็น
 * map ชั้นเดียวด้วยชื่อคีย์ — ชื่อซ้ำกับกลุ่มอื่นเมื่อไหร่ค่าจะทับกันเงียบ ๆ
 *
 * ⚠️ ห้ามส่ง keystore_path / passphrase ออกไปทาง API สาธารณะเด็ดขาด
 *
 * Developed by Xman Studio
 */
class StabilizerSettings
{
    public const GROUP = 'stabilizer';

    public const SWITCH_KEY = 'stabilizer_enabled';

    public const PAUSE_REASON_KEY = 'stabilizer_pause_reason';

    public const PAUSED_AT_KEY = 'stabilizer_paused_at';

    /** สวิตช์หลักใน .env — ปิดไว้เป็นค่าปริยาย */
    public function envEnabled(): bool
    {
        return (bool) config('stabilizer.enabled', false);
    }

    /** สวิตช์ในหลังบ้าน (kill switch) — ปิดได้ทันทีโดยไม่ต้องแตะเซิร์ฟเวอร์ */
    public function switchOn(): bool
    {
        return filter_var(SiteSetting::get(self::GROUP, self::SWITCH_KEY, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** เทรดได้จริงก็ต่อเมื่อเปิดทั้งสองชั้น */
    public function active(): bool
    {
        return $this->envEnabled() && $this->switchOn();
    }

    /**
     * เปิด/ปิดสวิตช์ในหลังบ้าน — ปิดพร้อมเหตุผลเพื่อให้หน้าสาธารณะบอกได้ว่าหยุดเพราะอะไร.
     */
    public function setSwitch(bool $on, ?string $reason = null): void
    {
        SiteSetting::set(self::GROUP, self::SWITCH_KEY, $on ? '1' : '0', 'boolean');
        SiteSetting::set(self::GROUP, self::PAUSE_REASON_KEY, $on ? '' : (string) $reason, 'string');
        SiteSetting::set(self::GROUP, self::PAUSED_AT_KEY, $on ? '' : now()->toIso8601String(), 'string');
    }

    /** หยุดอัตโนมัติ (ธุรกรรมล้ม/ค้าง) — แอดมินต้องตรวจแล้วเปิดเองเท่านั้น */
    public function autoPause(string $reason): void
    {
        if ($this->switchOn()) {
            $this->setSwitch(false, $reason);
        }
    }

    public function pauseReason(): ?string
    {
        $reason = trim((string) SiteSetting::get(self::GROUP, self::PAUSE_REASON_KEY, ''));

        return $reason === '' ? null : $reason;
    }

    public function pausedAt(): ?string
    {
        $at = trim((string) SiteSetting::get(self::GROUP, self::PAUSED_AT_KEY, ''));

        return $at === '' ? null : $at;
    }

    // ── พารามิเตอร์ ───────────────────────────────────────────────────────

    /** @return array<string, array{default: int|float, min: int|float, max: int|float}> */
    public function bounds(): array
    {
        return (array) config('stabilizer.params', []);
    }

    /**
     * ค่าที่ใช้จริง — ค่าที่แอดมินตั้ง (ถ้ามี) หนีบเข้ากรอบ ไม่งั้นใช้ค่าปริยาย.
     *
     * @return array<string, int|float>
     */
    public function params(): array
    {
        $out = [];
        foreach ($this->bounds() as $name => $bound) {
            $stored = SiteSetting::get(self::GROUP, 'stabilizer_'.$name, null);
            $value = is_numeric($stored) ? $stored + 0 : $bound['default'];
            $out[$name] = $this->clamp($value, $bound);
        }

        return $out;
    }

    /**
     * บันทึกพารามิเตอร์ (เฉพาะชื่อที่รู้จัก) — คืนค่าเก่า/ใหม่ไว้ลง audit log.
     *
     * @param  array<string, mixed>  $values
     * @return array{old: array, new: array}
     */
    public function saveParams(array $values): array
    {
        $old = $this->params();
        $bounds = $this->bounds();

        foreach ($values as $name => $value) {
            if (! isset($bounds[$name]) || ! is_numeric($value)) {
                continue;
            }
            $clamped = $this->clamp($value + 0, $bounds[$name]);
            SiteSetting::set(self::GROUP, 'stabilizer_'.$name, (string) $clamped, 'number');
        }

        return ['old' => $old, 'new' => $this->params()];
    }

    private function clamp(int|float $value, array $bound): int|float
    {
        $value = max($bound['min'], min($bound['max'], $value));

        // ค่าที่กรอบเป็นจำนวนเต็มให้ออกมาเป็นจำนวนเต็ม (วัน/นาที/ชั่วโมง/bps)
        return (is_int($bound['min']) && is_int($bound['max']) && is_int($bound['default']))
            ? (int) round($value)
            : (float) $value;
    }

    // ── กระเป๋า ───────────────────────────────────────────────────────────

    /** ที่อยู่กระเป๋าของกองทุน (ตัวพิมพ์ตามที่ตั้งไว้) — null ถ้ายังไม่ได้ตั้งหรือรูปแบบผิด */
    public function walletAddress(): ?string
    {
        $address = trim((string) config('stabilizer.wallet.address', ''));

        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) ? $address : null;
    }

    public function hasPassphrase(): bool
    {
        return trim((string) config('stabilizer.wallet.passphrase', '')) !== '';
    }

    /**
     * ตรวจไฟล์ keystore โดยไม่ถอดรหัส — อ่านแค่ฟิลด์ address ที่ไม่เป็นความลับ.
     *
     * ห้ามโยน error ออกไป: is_readable() นอก open_basedir ปล่อย warning ที่ Laravel
     * แปลงเป็น 500 ทั้งหน้า (เคยเกิดกับหน้าคลังมาแล้ว)
     *
     * @return array{ok: bool, address: ?string}
     */
    public function inspectKeystore(): array
    {
        $path = trim((string) config('stabilizer.wallet.keystore_path', ''));
        if ($path === '') {
            return ['ok' => false, 'address' => null];
        }

        set_error_handler(static fn () => true);

        try {
            $raw = is_readable($path) ? file_get_contents($path) : false;
        } catch (\Throwable) {
            $raw = false;
        } finally {
            restore_error_handler();
        }

        if (! is_string($raw) || $raw === '') {
            return ['ok' => false, 'address' => null];
        }

        $json = json_decode($raw, true);
        $address = is_array($json) ? strtolower((string) ($json['address'] ?? '')) : '';
        $address = $address === '' ? null : (str_starts_with($address, '0x') ? $address : '0x'.$address);

        return ['ok' => is_array($json), 'address' => $address];
    }

    /**
     * รายการความพร้อมก่อนเปิดสวิตช์ — ทุกข้อต้องผ่าน ไม่งั้นไม่ยอมให้เปิด.
     *
     * @return array{ready: bool, checks: array<int, array{key: string, label: string, ok: bool}>}
     */
    public function readiness(TpixDexService $dex): array
    {
        $wallet = $this->walletAddress();
        $keystore = $this->inspectKeystore();
        $dexReady = $dex->ready();

        $checks = [
            ['key' => 'env_enabled', 'label' => 'เปิด STABILIZER_ENABLED=true ใน .env', 'ok' => $this->envEnabled()],
            ['key' => 'wallet', 'label' => 'ตั้งที่อยู่กระเป๋า STABILIZER_WALLET_ADDRESS', 'ok' => $wallet !== null],
            ['key' => 'keystore', 'label' => 'วางไฟล์ keystore (STABILIZER_KEYSTORE_PATH) ที่เซิร์ฟเวอร์อ่านได้', 'ok' => $keystore['ok']],
            [
                'key' => 'keystore_matches',
                'label' => 'keystore เป็นของกระเป๋าใบเดียวกับที่ประกาศ',
                'ok' => $wallet !== null && $keystore['address'] !== null && strtolower($wallet) === $keystore['address'],
            ],
            ['key' => 'passphrase', 'label' => 'ตั้ง STABILIZER_KEYSTORE_PASS ใน .env', 'ok' => $this->hasPassphrase()],
            ['key' => 'dex', 'label' => 'สัญญา DEX บนเชน TPIX ครบและมีโค้ดจริง', 'ok' => $dexReady],
        ];

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }
}
