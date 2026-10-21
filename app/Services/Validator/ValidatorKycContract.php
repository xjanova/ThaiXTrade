<?php

namespace App\Services\Validator;

use App\Services\ContractRegistry;
use kornrunner\Keccak;

/**
 * ValidatorKycContract — อ่านสัญญา ValidatorKYC บนเชน (อ่านอย่างเดียว).
 *
 * การเขียน (submitKYC / approveKYC / rejectKYC) ไม่เกิดที่เซิร์ฟเวอร์ —
 * กระเป๋า owner ในเบราว์เซอร์ของแอดมินเป็นคนเซ็น เว็บไม่ถือกุญแจ owner
 * หน้าที่ของคลาสนี้คือบอกสถานะจริงบนเชน และตรวจ tx ที่เบราว์เซอร์ส่งไปแล้ว
 *
 * Developed by Xman Studio
 */
class ValidatorKycContract
{
    /** ลำดับตรงกับ enum KYCStatus ในสัญญา */
    public const STATUSES = ['None', 'ConsentGiven', 'Submitted', 'Approved', 'Rejected', 'Revoked'];

    /** ฟังก์ชันที่แอดมินเซ็นจากเบราว์เซอร์ได้ — ชื่อ action ของเว็บ => signature ใน ABI */
    public const WRITE_FUNCTIONS = [
        'submit' => 'submitKYC(address,bytes32)',
        'approve' => 'approveKYC(address)',
        'reject' => 'rejectKYC(address,string)',
    ];

    /** กันสัญญาที่ถูกปั่นรายชื่อจนหน้าแอดมินค้าง */
    private const MAX_APPLICANTS = 500;

    public function __construct(
        private readonly TpixRpc $rpc,
        private readonly ContractRegistry $registry,
    ) {}

    public function address(): ?string
    {
        return $this->registry->address('validator_kyc');
    }

    public static function selector(string $signature): string
    {
        return '0x'.substr(Keccak::hash($signature, 256), 0, 8);
    }

    public function owner(): ?string
    {
        $words = $this->call('owner()');

        return $words === null ? null : $this->wordAddress($words[0] ?? '');
    }

    /**
     * @return array{applicant:string,status:string,status_id:int,kyc_hash:?string,consent_at:int,submitted_at:int,reviewed_at:int,reviewer:?string,reject_reason:string}|null
     */
    public function record(string $applicant): ?array
    {
        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $applicant)) {
            return null;
        }

        $words = $this->call('getRecord(address)', $this->padAddress($applicant));
        // word 0 = offset ของ tuple (มี string ข้างในจึงเป็น dynamic) · หัว tuple 8 word
        if ($words === null || count($words) < 10) {
            return null;
        }

        $t = intdiv((int) hexdec($words[0]), 32);
        $statusId = (int) hexdec($words[$t + 1]);
        $kycHash = '0x'.$words[$t + 2];

        return [
            'applicant' => strtolower($applicant),
            'status' => self::STATUSES[$statusId] ?? 'Unknown',
            'status_id' => $statusId,
            'kyc_hash' => $kycHash === '0x'.str_repeat('0', 64) ? null : $kycHash,
            'consent_at' => (int) hexdec($words[$t + 3]),
            'submitted_at' => (int) hexdec($words[$t + 4]),
            'reviewed_at' => (int) hexdec($words[$t + 5]),
            'reviewer' => $this->wordAddress($words[$t + 6]),
            'reject_reason' => $this->wordString($words, $t + intdiv((int) hexdec($words[$t + 7]), 32)),
        ];
    }

    /**
     * ผู้สมัครทุกคนที่เคยกดยินยอม พร้อมสถานะ — null = อ่านเชนไม่ได้.
     *
     * @return list<array<string,mixed>>|null
     */
    public function applicants(): ?array
    {
        $count = $this->call('getApplicantCount()');
        if ($count === null) {
            return null;
        }

        $n = min((int) hexdec($count[0] ?? '0'), self::MAX_APPLICANTS);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $words = $this->call('applicants(uint256)', str_pad(dechex($i), 64, '0', STR_PAD_LEFT));
            $address = $words === null ? null : $this->wordAddress($words[0] ?? '');
            if ($address === null) {
                return null;
            }
            $record = $this->record($address);
            if ($record === null) {
                return null;
            }
            $out[] = $record;
        }

        return $out;
    }

    /**
     * ถอด input ของ tx ที่เรียกสัญญานี้ — ใช้ตรวจว่าเบราว์เซอร์ส่งสิ่งที่บอกว่าส่งจริง.
     *
     * @return array{action:string,applicant:string,kyc_hash:?string}|null
     */
    public function decodeWrite(string $input): ?array
    {
        if (! preg_match('/^0x[0-9a-fA-F]{8}([0-9a-fA-F]{64})+$/', $input)) {
            return null;
        }

        $selector = strtolower(substr($input, 0, 10));
        $args = str_split(substr($input, 10), 64);
        foreach (self::WRITE_FUNCTIONS as $action => $signature) {
            if (self::selector($signature) !== $selector) {
                continue;
            }
            $applicant = $this->wordAddress($args[0]);
            if ($applicant === null) {
                return null;
            }

            return [
                'action' => $action,
                'applicant' => $applicant,
                'kyc_hash' => $action === 'submit' && isset($args[1]) ? '0x'.strtolower($args[1]) : null,
            ];
        }

        return null;
    }

    // =========================================================================

    /**
     * @return list<string>|null word ละ 64 hex ไม่มี 0x
     */
    private function call(string $signature, string $args = ''): ?array
    {
        $address = $this->address();
        if ($address === null) {
            return null;
        }

        $result = $this->rpc->ethCall($address, self::selector($signature).$args);
        if ($result === null || (strlen($result) - 2) % 64 !== 0) {
            return null;
        }

        return str_split(strtolower(substr($result, 2)), 64);
    }

    private function padAddress(string $address): string
    {
        return str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    private function wordAddress(string $word): ?string
    {
        if (strlen($word) !== 64) {
            return null;
        }
        $address = '0x'.strtolower(substr($word, 24));

        return $address === '0x'.str_repeat('0', 40) ? null : $address;
    }

    /** @param list<string> $words */
    private function wordString(array $words, int $lenIndex): string
    {
        $len = (int) hexdec($words[$lenIndex] ?? '0');
        if ($len <= 0 || $len > 4096) {
            return '';
        }
        $hex = implode('', array_slice($words, $lenIndex + 1, (int) ceil($len / 32)));
        $bin = @hex2bin(substr($hex, 0, $len * 2));

        return $bin === false ? '' : $bin;
    }
}
