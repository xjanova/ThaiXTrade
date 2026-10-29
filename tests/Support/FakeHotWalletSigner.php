<?php

namespace Tests\Support;

use App\Models\TreasuryPayout;
use App\Services\HotWalletSigner;
use Closure;

/**
 * ตัวเซ็นกระเป๋าร้อนปลอม — ไม่มีคีย์ ไม่เรียก Node ไม่ส่งอะไรขึ้นเชนจริง.
 *
 * จดทุกครั้งที่ถูกเรียกให้เซ็น/ส่ง เพื่อให้เทสต์ตรวจได้ว่า "ไม่เซ็นซ้ำ" และ "ส่ง raw เดิมซ้ำ"
 * และตอนส่งจะเช็คว่า hash + raw ถูกบันทึกลงฐานข้อมูลแล้ว (กฎบันทึกก่อนส่ง)
 */
class FakeHotWalletSigner extends HotWalletSigner
{
    /** @var array<int, array{to: string, value: string, nonce: int}> */
    public array $signed = [];

    /** @var string[] raw ที่ถูกส่ง (รวมการส่งซ้ำ) */
    public array $sent = [];

    /** hash ที่ถูกส่งตอนที่ยังไม่มีในฐานข้อมูล — ต้องว่างเสมอ */
    public array $sentBeforePersist = [];

    /** nonce ที่เชนจะให้ (pending) ตอนเซ็นครั้งถัดไป */
    public int $nextNonce = 0;

    public bool $signOk = true;

    /** ok | already_known | broadcast_failed | nonce_used | process_failed */
    public string $sendResult = 'ok';

    /** เรียกระหว่างเซ็น — จำลองอีกรอบแทรกเข้ามากลางคัน */
    public ?Closure $duringSign = null;

    public function __construct(public string $address) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function sign(string $toAddress, string $amountWei, ?int $nonce = null): array
    {
        $nonce ??= $this->nextNonce;
        $this->signed[] = ['to' => $toAddress, 'value' => $amountWei, 'nonce' => $nonce];

        if ($this->duringSign) {
            ($this->duringSign)();
        }

        if (! $this->signOk) {
            return ['ok' => false, 'code' => 'keystore_decrypt_failed', 'error' => 'could not decrypt keystore'];
        }

        // ใส่ลำดับการเซ็นลงไปด้วย — สองรอบที่เซ็นรายการเดียวกันได้ hash ต่างกันเหมือนของจริง (nonce ต่าง)
        $raw = '0x'.bin2hex("raw|{$nonce}|{$toAddress}|{$amountWei}|".count($this->signed));

        return ['ok' => true, 'address' => $this->address, 'nonce' => $nonce, 'txHash' => self::hashOf($raw), 'raw' => $raw];
    }

    public function send(string $rawTx): array
    {
        $this->sent[] = $rawTx;
        $hash = self::hashOf($rawTx);

        if (! TreasuryPayout::where('tx_hash', $hash)->where('raw_tx', $rawTx)->exists()) {
            $this->sentBeforePersist[] = $hash;
        }

        return match ($this->sendResult) {
            'ok' => ['ok' => true, 'txHash' => $hash, 'alreadyKnown' => false],
            'already_known' => ['ok' => true, 'txHash' => $hash, 'alreadyKnown' => true],
            'nonce_used' => ['ok' => false, 'code' => 'nonce_used', 'error' => 'nonce ถูกใช้ไปแล้ว', 'txHash' => $hash],
            'process_failed' => ['ok' => false, 'code' => 'process_failed', 'error' => 'The process exceeded the timeout of 120 seconds.'],
            default => ['ok' => false, 'code' => 'broadcast_failed', 'error' => 'server response 502 Bad Gateway'],
        };
    }

    public static function hashOf(string $raw): string
    {
        return '0x'.hash('sha256', $raw);
    }
}
