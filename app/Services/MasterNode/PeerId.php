<?php

namespace App\Services\MasterNode;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * PeerId — ถอด libp2p peer ID ของ polygon-edge (16Uiu2HAm…) กลับเป็นกุญแจของโหนด.
 *
 * polygon-edge 0.9 ใช้กุญแจ libp2p แบบ secp256k1 (network/keystore.go)
 * peer ID ของกุญแจชนิดนี้คือ base58btc( multihash แบบ identity ของ protobuf public key )
 *
 *   0x00 0x25 | 0x08 0x02 0x12 0x21 | <compressed public key 33 ไบต์>
 *   identity  | KeyType=Secp256k1, Data=33 ไบต์
 *
 * public key อยู่ใน peer ID ทั้งก้อน — เว็บจึงรู้ว่ากุญแจไหนเป็นเจ้าของ peer ID นั้น
 * โดยไม่ต้องถามใคร แล้วแปลงเป็น "ที่อยู่โหนด" แบบ Ethereum (keccak ของ public key เต็ม)
 * เพื่อใช้ตรวจลายเซ็น personal_sign ด้วยโค้ดเดิมใน Web3SignatureService ได้เลย
 *
 * ที่ต้องผูกแบบนี้: heartbeat ที่แค่ "บอก" peer ID ใครก็พิมพ์ peer ID ของคนอื่นได้
 * (peer ID เห็นกันทั้งเครือข่าย) แต่ลายเซ็นด้วยกุญแจของ peer ID นั้นปลอมไม่ได้
 *
 * Developed by Xman Studio
 */
class PeerId
{
    /** peer ID ของกุญแจ secp256k1 แบบ identity multihash ขึ้นต้นด้วย 16Uiu2 เสมอ */
    public const PATTERN = '/^16Uiu2[1-9A-HJ-NP-Za-km-z]{40,60}$/';

    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** multihash identity (0x00) + ยาว 37 + protobuf PublicKey{Type: Secp256k1, Data: 33 ไบต์} */
    private const PREFIX = "\x00\x25\x08\x02\x12\x21";

    public static function isValid(string $peerId): bool
    {
        return self::compressedPublicKey($peerId) !== null;
    }

    /**
     * public key แบบบีบอัด (33 ไบต์, hex ไม่มี 0x) — null ถ้าไม่ใช่ peer ID secp256k1 ที่ถูกต้อง.
     */
    public static function compressedPublicKey(string $peerId): ?string
    {
        if (! preg_match(self::PATTERN, $peerId)) {
            return null;
        }

        $bytes = self::base58Decode($peerId);
        if ($bytes === null || strlen($bytes) !== 39 || ! str_starts_with($bytes, self::PREFIX)) {
            return null;
        }

        $key = substr($bytes, 6);
        $first = ord($key[0]);

        return ($first === 0x02 || $first === 0x03) ? bin2hex($key) : null;
    }

    /**
     * ที่อยู่แบบ Ethereum ของกุญแจโหนด (lowercase) — null ถ้าถอดไม่ได้หรือจุดไม่อยู่บนเส้นโค้ง.
     */
    public static function nodeAddress(string $peerId): ?string
    {
        $compressed = self::compressedPublicKey($peerId);
        if ($compressed === null) {
            return null;
        }

        try {
            $ec = new EC('secp256k1');
            $point = $ec->keyFromPublic($compressed, 'hex')->getPublic();
            if (! $point->validate()) {
                return null;
            }
            $uncompressed = $point->encode('hex', false);
        } catch (\Throwable) {
            return null;
        }

        // keccak256(x ‖ y) แล้วเอา 20 ไบต์ท้าย — ตัด prefix 04 ของ uncompressed ออกก่อน
        $hash = Keccak::hash(hex2bin(substr($uncompressed, 2)), 256);

        return '0x'.substr($hash, -40);
    }

    /**
     * base58btc → ไบต์ดิบ (เก็บศูนย์นำหน้าไว้ด้วย — '1' นำหน้าแต่ละตัวคือ 0x00 หนึ่งไบต์).
     *
     * ห้ามแปลงผ่านเลขจำนวนเต็มก้อนเดียวแล้ว hex กลับ: ศูนย์นำหน้าของ multihash identity
     * จะหายไปเงียบ ๆ แล้วทุก peer ID ถอดไม่ผ่าน
     */
    private static function base58Decode(string $input): ?string
    {
        $bytes = [];
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $value = strpos(self::ALPHABET, $input[$i]);
            if ($value === false) {
                return null;
            }

            $carry = $value;
            foreach ($bytes as $j => $byte) {
                $carry += $byte * 58;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $leadingZeros = strspn($input, '1');

        return str_repeat("\x00", $leadingZeros).implode('', array_map('chr', array_reverse($bytes)));
    }
}
