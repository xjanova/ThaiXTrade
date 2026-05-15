<?php

namespace App\Services\MasterNode;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * Web3SignatureService — verify Ethereum personal_sign signatures
 *
 * รองรับ format มาตรฐาน MetaMask / WalletConnect:
 *   - Message: "<text>"
 *   - Sign as personal_sign: keccak256("\x19Ethereum Signed Message:\n" + len(text) + text)
 *   - Output: 65-byte signature (r 32 + s 32 + v 1) ใน hex string
 *
 * Library: kornrunner/keccak + simphal/elliptic-php
 *
 * Developed by Xman Studio
 */
class Web3SignatureService
{
    /**
     * Recover Ethereum address ที่ sign message นี้
     *
     * @param string $message Plain text message ที่ user sign
     * @param string $signature 0x-prefixed hex (130 chars after 0x = 65 bytes)
     * @return string Address (0x... lowercase) หรือ '' ถ้า verify ไม่ผ่าน
     */
    public function recoverAddress(string $message, string $signature): string
    {
        try {
            // 1. Strip 0x prefix — ห้ามใช้ ltrim('0x') เพราะมัน strip char '0'/'x' ทุกตัวที่นำหน้า
            //    sig ที่ r ขึ้นต้นด้วย '0' (1/16) จะถูกตัด leading zero → verify fail สุ่ม
            if (!str_starts_with(strtolower($signature), '0x')) {
                return '';
            }
            $sig = substr($signature, 2);
            if (strlen($sig) !== 130) {
                return '';
            }

            $r = substr($sig, 0, 64);
            $s = substr($sig, 64, 64);
            $v = hexdec(substr($sig, 128, 2));

            // EIP-155: v ในบาง wallet เป็น 0/1 ก็มี — normalize เป็น 27/28
            if ($v < 27) {
                $v += 27;
            }

            // 2. Hash message ตามมาตรฐาน personal_sign:
            //    keccak256("\x19Ethereum Signed Message:\n" + len + message)
            $prefix = "\x19Ethereum Signed Message:\n".strlen($message);
            $hash = Keccak::hash($prefix.$message, 256);

            // 3. ecrecover via secp256k1
            $ec = new EC('secp256k1');
            $publicKey = $ec->recoverPubKey($hash, ['r' => $r, 's' => $s], $v - 27);
            $publicKeyHex = $publicKey->encode('hex');

            // 4. Address = last 20 bytes of keccak256(publicKey ตัด prefix 04)
            //    publicKeyHex มี 130 chars (uncompressed: 04 + x32 + y32)
            $publicKeyNoPrefix = substr($publicKeyHex, 2);
            $addressHash = Keccak::hash(hex2bin($publicKeyNoPrefix), 256);
            $address = '0x'.substr($addressHash, -40);

            return strtolower($address);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Verify ว่า signature ตรงกับ wallet address ที่คาดหวัง
     */
    public function verify(string $message, string $signature, string $expectedAddress): bool
    {
        $recovered = $this->recoverAddress($message, $signature);
        if ($recovered === '') {
            return false;
        }

        return strtolower($recovered) === strtolower($expectedAddress);
    }

    /**
     * Build delegation message ที่ใช้ user sign ครั้งเดียว (ผ่าน wallet)
     *   "tpix-masternode-delegate:<wallet>:<delegateAddress>:<expiresAt>"
     */
    public function buildDelegationMessage(string $walletAddress, string $delegateAddress, int $expiresAt): string
    {
        $prefix = config('masternode.delegation.message_prefix');

        return sprintf(
            '%s:%s:%s:%d',
            $prefix,
            strtolower($walletAddress),
            strtolower($delegateAddress),
            $expiresAt
        );
    }

    /**
     * Build heartbeat message ที่ delegate-key sign ทุกครั้งที่ heartbeat
     *   "tpix-masternode-heartbeat:<wallet>:<timestamp>"
     */
    public function buildHeartbeatMessage(string $walletAddress, int $timestamp): string
    {
        $prefix = config('masternode.delegation.heartbeat_message_prefix');

        return sprintf(
            '%s:%s:%d',
            $prefix,
            strtolower($walletAddress),
            $timestamp
        );
    }
}
