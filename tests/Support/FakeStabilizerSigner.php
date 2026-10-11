<?php

namespace Tests\Support;

use App\Models\StabilityTransaction;
use App\Services\Stabilizer\StabilizerTxSigner;
use kornrunner\Keccak;

/**
 * ตัวเซ็นปลอมของ Stability Reserve — ไม่มีคีย์ ไม่ส่งอะไรขึ้นเชนจริง.
 *
 * จดทุกครั้งที่ถูกเรียกให้เซ็น/ส่ง เพื่อให้เทสต์ตรวจได้ว่า "ไม่เซ็นซ้ำ" และ "ส่ง raw เดิมซ้ำ"
 * และตอนส่งจะเช็คว่า tx hash ถูกบันทึกลงฐานข้อมูลแล้ว (กฎบันทึกก่อนส่ง)
 */
class FakeStabilizerSigner implements StabilizerTxSigner
{
    /** @var array<int, array{to: string, data: string, value: string, gas: int, nonce: int}> */
    public array $signed = [];

    /** @var string[] raw ที่ถูกส่ง */
    public array $sent = [];

    /** hash ที่ถูกส่งตอนที่ยังไม่มีในฐานข้อมูล — ต้องว่างเสมอ */
    public array $sentBeforePersist = [];

    public bool $available = true;

    public bool $signOk = true;

    /** ok | broadcast_failed | nonce_used */
    public string $sendResult = 'ok';

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sign(string $to, string $data, string $valueWei, int $gasLimit, int $nonce): array
    {
        $this->signed[] = ['to' => $to, 'data' => $data, 'value' => $valueWei, 'gas' => $gasLimit, 'nonce' => $nonce];

        if (! $this->signOk) {
            return ['ok' => false, 'code' => 'keystore_decrypt_failed', 'error' => 'could not decrypt keystore'];
        }

        $raw = '0x'.bin2hex("raw|{$nonce}|{$to}|{$valueWei}|").substr($data, 2);

        return ['ok' => true, 'nonce' => $nonce, 'txHash' => self::hashOf($raw), 'raw' => $raw];
    }

    public function send(string $rawTx): array
    {
        $this->sent[] = $rawTx;
        $hash = self::hashOf($rawTx);

        if (! StabilityTransaction::where('tx_hash', $hash)->exists()) {
            $this->sentBeforePersist[] = $hash;
        }

        return match ($this->sendResult) {
            'ok' => ['ok' => true, 'txHash' => $hash, 'alreadyKnown' => false],
            'nonce_used' => ['ok' => false, 'code' => 'nonce_used', 'txHash' => $hash, 'error' => 'nonce already used'],
            default => ['ok' => false, 'code' => 'broadcast_failed', 'error' => 'rpc timeout'],
        };
    }

    public static function hashOf(string $raw): string
    {
        return '0x'.Keccak::hash(hex2bin(substr($raw, 2)), 256);
    }
}
