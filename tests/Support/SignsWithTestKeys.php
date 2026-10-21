<?php

namespace Tests\Support;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * เซ็นด้วยกุญแจ secp256k1 จริงในเทสต์ — ตัวตรวจลายเซ็นของเว็บจึงถูกทดสอบกับลายเซ็นจริง
 * กุญแจทดสอบเป็นค่าคงที่ (ห้ามใช้กับเงินจริง).
 */
trait SignsWithTestKeys
{
    protected function testKey(int $n): string
    {
        return str_pad(dechex(0xA11CE000 + $n), 64, '0', STR_PAD_LEFT);
    }

    protected function addressOf(string $privHex): string
    {
        $pub = (new EC('secp256k1'))->keyFromPrivate($privHex)->getPublic(false, 'hex');

        return '0x'.substr(Keccak::hash(hex2bin(substr($pub, 2)), 256), -40);
    }

    /** personal_sign (EIP-191) แบบเดียวกับ MetaMask / ethers Wallet.signMessage */
    protected function personalSign(string $privHex, string $message): string
    {
        $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);

        return $this->signDigest($privHex, $hash, 27);
    }

    /** ลายเซ็นดิบบน digest — v เป็น 0/1 แบบที่ polygon-edge ใส่ใน proposerSeal */
    protected function signDigest(string $privHex, string $digestHex, int $vBase = 0): string
    {
        $sig = (new EC('secp256k1'))->keyFromPrivate($privHex)->sign($digestHex, ['canonical' => true]);

        return '0x'.str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad(dechex($vBase + $sig->recoveryParam), 2, '0', STR_PAD_LEFT);
    }
}
