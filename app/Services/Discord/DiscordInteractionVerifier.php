<?php

namespace App\Services\Discord;

use Illuminate\Http\Request;

/**
 * TPIX TRADE — ตรวจว่าคำขอที่เข้า /api/v1/discord/interactions มาจาก Discord จริง.
 *
 * Discord เซ็นทุกคำขอด้วย Ed25519 (header X-Signature-Ed25519 + X-Signature-Timestamp)
 * และจะส่งคำขอลายเซ็นปลอมมาทดสอบเป็นระยะ — ถ้าเรารับ Discord จะถอด endpoint ทิ้งเอง
 *
 * endpoint นี้เปิดสาธารณะ ใครก็ยิงได้ — ไม่ผ่านลายเซ็น = ไม่ถึงผู้ช่วย AI (ที่มีค่าใช้จ่ายต่อคำถาม)
 *
 * Developed by Xman Studio.
 */
class DiscordInteractionVerifier
{
    /** กันเอาคำขอเก่าที่ดักได้มายิงซ้ำ */
    private const MAX_AGE_SECONDS = 300;

    public function verify(Request $request, ?string $publicKey): bool
    {
        $signature = (string) $request->header('X-Signature-Ed25519', '');
        $timestamp = (string) $request->header('X-Signature-Timestamp', '');

        if ($publicKey === null || ! preg_match('/^[0-9a-f]{128}$/i', $signature) || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                (string) hex2bin($signature),
                $timestamp.$request->getContent(),
                (string) hex2bin($publicKey),
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
