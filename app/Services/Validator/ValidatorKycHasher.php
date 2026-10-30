<?php

namespace App\Services\Validator;

use App\Models\KycSubmission;
use App\Models\User;
use App\Models\WalletConnection;
use kornrunner\Keccak;

/**
 * ValidatorKycHasher — โยงผู้สมัคร Validator (กระเป๋า) กับใบ KYC ของเว็บ แล้วทำ hash ที่จะขึ้นเชน.
 *
 * hash = keccak256 ของ:
 *     tpix-validator-kyc-v1
 *     <uuid ของใบ KYC>
 *     <level> <source> <external_ref>
 *     <เวลาอนุมัติ ISO-8601 UTC>
 *     <sha256 ของเอกสารแต่ละชิ้น เรียงตามตัวอักษร>
 *
 * - ไม่มีข้อมูลส่วนตัวในต้นทาง (uuid สุ่ม, sha256 ของไฟล์ที่เข้ารหัสอยู่แล้ว) — PDPA
 * - คำนวณซ้ำได้จากฐานข้อมูลตราบที่ใบยังไม่ถูกล้าง → ย้อนพิสูจน์ได้ว่า hash บนเชนมาจากใบไหน
 * - ใบที่ถูกล้างแล้ว (purged) ใช้ไม่ได้ เพราะเอกสารหายแล้วพิสูจน์ไม่ได้
 *
 * Developed by Xman Studio
 */
class ValidatorKycHasher
{
    private const DOMAIN = 'tpix-validator-kyc-v1';

    /**
     * บัญชีเว็บที่ผูกกระเป๋านี้ไว้ (ผูกได้ต่อเมื่อเซ็นพิสูจน์ความเป็นเจ้าของแล้วเท่านั้น).
     */
    public function userForWallet(string $wallet): ?User
    {
        $wallet = strtolower($wallet);

        return User::where('wallet_address', $wallet)->first()
            ?? WalletConnection::where('wallet_address', $wallet)->latest('id')->first()?->user;
    }

    /**
     * ใบ KYC ที่อนุมัติแล้วและยังไม่ถูกล้างของกระเป๋านี้ — ระดับ enhanced มาก่อน basic.
     */
    public function approvedSubmissionFor(string $wallet): ?KycSubmission
    {
        $user = $this->userForWallet($wallet);
        if ($user === null) {
            return null;
        }

        return KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('status', KycSubmission::STATUS_APPROVED)
            ->whereNull('purged_at')
            ->orderByRaw('CASE WHEN level = ? THEN 0 ELSE 1 END', [KycSubmission::LEVEL_ENHANCED])
            ->orderByDesc('reviewed_at')
            ->first();
    }

    /**
     * สรุปสถานะ KYC เว็บสำหรับหน้าแอดมิน — ไม่มีข้อมูลส่วนตัว.
     *
     * @return array{user_id:?int,status:string,level:?string,uuid:?string,doc_hash:?string,reviewed_at:?string}
     */
    public function siteKycSummary(string $wallet): array
    {
        $user = $this->userForWallet($wallet);
        if ($user === null) {
            return ['user_id' => null, 'status' => 'no_account', 'level' => null, 'uuid' => null, 'doc_hash' => null, 'reviewed_at' => null];
        }

        $approved = $this->approvedSubmissionFor($wallet);
        if ($approved !== null) {
            return [
                'user_id' => $user->id,
                'status' => 'approved',
                'level' => $approved->level,
                'uuid' => $approved->uuid,
                'doc_hash' => $this->hash($approved),
                'reviewed_at' => $approved->reviewed_at?->toIso8601String(),
            ];
        }

        $latest = KycSubmission::where('user_id', $user->id)->latest('id')->first();

        return [
            'user_id' => $user->id,
            // ใบที่ถูกล้างแล้วพิสูจน์อะไรไม่ได้อีก — ห้ามโชว์ว่า "ผ่าน"
            'status' => $latest?->purged_at !== null ? 'purged' : ($latest?->status ?? 'none'),
            'level' => $latest?->level,
            'uuid' => $latest?->uuid,
            'doc_hash' => null,
            'reviewed_at' => null,
        ];
    }

    public function hash(KycSubmission $submission): string
    {
        // อ่านจากฐานข้อมูลเสมอ — โมเดลในหน่วยความจำอาจยังไม่มีค่า default ของคอลัมน์ (เช่น source)
        // แล้วได้ hash คนละค่ากับที่คำนวณซ้ำทีหลัง = ย้อนพิสูจน์ไม่ได้
        $submission = $submission->exists ? ($submission->fresh() ?? $submission) : $submission;

        $docs = $submission->documents()
            ->whereNull('purged_at')
            ->pluck('sha256')
            ->filter()
            ->map(fn ($h) => strtolower((string) $h))
            ->sort()
            ->values()
            ->all();

        $lines = [
            self::DOMAIN,
            (string) $submission->uuid,
            implode(' ', [$submission->level, $submission->source ?? '', $submission->external_ref ?? '']),
            $submission->reviewed_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z') ?? '',
            ...$docs,
        ];

        return '0x'.Keccak::hash(implode("\n", $lines), 256);
    }
}
