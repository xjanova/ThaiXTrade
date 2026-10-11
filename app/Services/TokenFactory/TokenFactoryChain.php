<?php

namespace App\Services\TokenFactory;

use Elliptic\EC;
use Illuminate\Support\Facades\Cache;
use kornrunner\Keccak;

/**
 * ข้อเท็จจริงบนเชนที่โรงงานต้องรู้ก่อนรับคำขอ.
 *
 * ฟังก์ชัน create* ของแฟกทอรีเป็น onlyOwner — ถ้ากระเป๋าที่เซิร์ฟเวอร์ใช้เซ็น
 * (DEPLOYER_PRIVATE_KEY) ไม่ใช่ owner() ของแฟกทอรี ทุกการสร้างจะ revert
 * เดิมไม่มีใครตรวจ หน้าเว็บบอก "พร้อม" ผู้ใช้จ่ายค่าธรรมเนียมแล้วไปพังตอน deploy
 *
 * Developed by Xman Studio
 */
class TokenFactoryChain
{
    /** owner() */
    private const OWNER_SELECTOR = '0x8da5cb5b';

    private const CACHE_TTL = 300;

    public function __construct(private readonly TpixRpcClient $rpc) {}

    /**
     * ที่อยู่ของกระเป๋าที่เซิร์ฟเวอร์ใช้เซ็น — คำนวณจาก DEPLOYER_PRIVATE_KEY (ไม่เคยส่งคีย์ออกไปไหน).
     *
     * cache ด้วย HMAC ของคีย์ (ไม่ใช่ตัวคีย์) เพราะ secp256k1 แบบ pure PHP ช้า
     * และหน้าเว็บเรียกตรวจความพร้อมทุกครั้งที่โหลด
     */
    public function deployerAddress(): ?string
    {
        $key = strtolower(trim((string) config('blockchain.deployer_private_key', '')));
        $key = str_starts_with($key, '0x') ? substr($key, 2) : $key;

        if (! preg_match('/^[0-9a-f]{64}$/', $key)) {
            return null;
        }

        $fingerprint = hash_hmac('sha256', $key, (string) config('app.key'));

        return Cache::rememberForever("token_factory:deployer:{$fingerprint}", function () use ($key) {
            $ec = new EC('secp256k1');
            $public = $ec->keyFromPrivate($key, 'hex')->getPublic(false, 'hex');

            return '0x'.substr(Keccak::hash(hex2bin(substr($public, 2)), 256), -40);
        });
    }

    /**
     * owner() ของสัญญา — null เมื่ออ่านไม่ได้ (ไม่มีสัญญา / RPC ล่ม / คืนค่าแปลก).
     */
    public function ownerOf(string $contract): ?string
    {
        $contract = strtolower($contract);

        $cached = Cache::get("token_factory:owner:{$contract}");
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $result = $this->rpc->call('eth_call', [['to' => $contract, 'data' => self::OWNER_SELECTOR], 'latest']);
        } catch (\RuntimeException) {
            return null;
        }

        if (! is_string($result) || ! preg_match('/^0x[0-9a-fA-F]{64}$/', $result)) {
            return null;
        }

        $owner = '0x'.strtolower(substr($result, -40));
        Cache::put("token_factory:owner:{$contract}", $owner, self::CACHE_TTL);

        return $owner;
    }

    /** กระเป๋าของเซิร์ฟเวอร์เป็น owner ของแฟกทอรีนี้หรือไม่ */
    public function deployerOwns(string $contract): bool
    {
        $deployer = $this->deployerAddress();
        $owner = $this->ownerOf($contract);

        return $deployer !== null && $owner !== null && hash_equals($owner, $deployer);
    }
}
