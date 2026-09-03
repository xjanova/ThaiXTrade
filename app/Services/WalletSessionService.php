<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — เซสชันกระเป๋าแบบยาวสำหรับแอปมือถือ.
 *
 * เดิมการยืนยันกระเป๋าได้แค่แถวในแคช `wallet_verified:{address}` อายุ 4 ชั่วโมงและ
 * ผูกกับ IP — เหมาะกับเบราว์เซอร์ แต่มือถือเปลี่ยน IP ตลอด (Wi‑Fi ↔ 4G) และเปิดแอป
 * วันละหลายรอบ ผลคือผู้ใช้ต้องไปเซ็นที่ TPIX Wallet ใหม่แทบทุกครั้งที่เปิดแอป
 * ทั้งที่เป็นแอปของเราเองทั้งคู่ ("เชื่อมแล้วก็ควรเชื่อมค้างไว้ได้เลย")
 *
 * โทเคนนี้ออกให้เฉพาะคำขอที่ประกาศตัวว่าเป็นแอป (client=mobile) หลังเซ็นผ่านแล้ว
 * แอปเก็บใน secure storage แล้วส่งหัว `X-Wallet-Session` ทุกคำขอ — middleware
 * รับเป็นหลักฐานความเป็นเจ้าของแทนแคช+IP · เก็บเฉพาะ hash ของโทเคน ฐานข้อมูล/แคช
 * รั่วก็เอาไปใช้ไม่ได้ · เพิกถอนได้ทีละใบ (ตอนผู้ใช้ตัดการเชื่อมต่อ)
 *
 * Developed by Xman Studio.
 */
class WalletSessionService
{
    public const HEADER = 'X-Wallet-Session';

    /** อายุโทเคน — ยาวพอให้ "เชื่อมครั้งเดียว" แต่ไม่ถาวร */
    public const TTL_DAYS = 30;

    private const PREFIX = 'wallet_session:';

    /**
     * ออกโทเคนใหม่ให้กระเป๋านี้.
     *
     * @return array{token: string, expires_at: string}
     */
    public function issue(string $address, int $chainId, ?string $ip = null, string $client = 'mobile'): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays(self::TTL_DAYS);

        Cache::put(self::PREFIX.$this->fingerprint($token), [
            'address' => strtolower($address),
            'chain_id' => $chainId,
            'client' => $client,
            'ip' => $ip,
            'issued_at' => now()->toIso8601String(),
        ], $expiresAt);

        return ['token' => $token, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** ที่อยู่กระเป๋าที่โทเคนนี้เป็นตัวแทน — null ถ้าไม่รู้จัก/หมดอายุ/รูปแบบผิด */
    public function resolve(?string $token): ?string
    {
        if (! is_string($token) || ! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $data = Cache::get(self::PREFIX.$this->fingerprint($token));

        return is_array($data) && isset($data['address']) ? (string) $data['address'] : null;
    }

    /** โทเคนนี้ใช้แทนกระเป๋าที่ระบุได้ไหม */
    public function authorizes(?string $token, string $address): bool
    {
        $owner = $this->resolve($token);

        return $owner !== null && $owner === strtolower($address);
    }

    public function revoke(?string $token): void
    {
        if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token)) {
            Cache::forget(self::PREFIX.$this->fingerprint($token));
        }
    }

    private function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }
}
