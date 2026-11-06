<?php

namespace App\Services\CreatorRegistration;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\CreatorRegistration;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\Kyc\KycGate;
use App\Services\MasterNode\Web3SignatureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * TPIX TRADE — ลงทะเบียนผู้สร้างเหรียญ.
 *
 *   1. เซ็นข้อความยืนยันว่าคุมกระเป๋า (personal_sign + nonce ใช้ครั้งเดียว อายุ 10 นาที)
 *      → เข้าสู่ระบบด้วยกระเป๋า + เปิดใบลงทะเบียน กระเป๋านี้คือกระเป๋ารับเหรียญ
 *   2. กรอกข้อมูลผู้สร้าง/โครงการ (SaveCreatorRegistrationRequest)
 *   3. ต้องผ่าน KYC (ระบบ Thaiprompt ที่มีอยู่ — อ่านผ่าน KycGate::approvedLevel)
 *   4. เซ็นข้อตกลง — ข้อความผูก hash ของข้อตกลงฉบับนั้น + hash ของข้อมูลที่กรอก + nonce
 *   5. แอดมินอนุมัติ (กำหนดส่วนเริ่มโครงการ) หรือปฏิเสธพร้อมเหตุผล
 *
 * ⚠️ ไม่เคยรับ private key ของใคร — ทุกขั้นที่ต้องพิสูจน์ตัวใช้ลายเซ็นจากกระเป๋าของผู้สร้างเอง
 * ⚠️ ข้อความที่เซ็นเป็น ASCII ล้วน — ความยาวใน prefix ของ EIP-191 ต้องตรงกันทุกกระเป๋า
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationService
{
    public const SETTING_GROUP = 'creator_registration';

    /** เปิดแล้ว = สร้างเหรียญได้เฉพาะกระเป๋าที่ใบลงทะเบียนอนุมัติแล้ว */
    public const KEY_REQUIRED = 'creator_registration_required';

    public const KEY_DEFAULT_BPS = 'creator_registration_default_bps';

    public const NONCE_TTL = 600;

    public function __construct(
        private readonly Web3SignatureService $signatures,
        private readonly CreatorAgreement $agreement,
        private readonly KycGate $kyc,
    ) {}

    // =========================================================================
    // ขั้นที่ 1 — พิสูจน์ว่าคุมกระเป๋า
    // =========================================================================

    /** @return array{message: string, nonce: string} */
    public function ownershipChallenge(string $wallet): array
    {
        $wallet = strtolower($wallet);
        $nonce = bin2hex(random_bytes(16));
        $issued = now()->utc()->format('Y-m-d\TH:i:s\Z');

        Cache::put($this->ownershipKey($wallet, $nonce), $issued, self::NONCE_TTL);

        return [
            'message' => self::ownershipMessage($wallet, $this->site(), $nonce, $issued),
            'nonce' => $nonce,
        ];
    }

    /**
     * ตรวจลายเซ็นยืนยันความเป็นเจ้าของ — คืนข้อความที่เซ็น (เก็บเป็นหลักฐาน).
     *
     * nonce ถูกดึงออกจากแคชก่อนตรวจลายเซ็น — ลายเซ็นผิดก็เผา nonce ทิ้ง ยิงเดาซ้ำด้วย nonce เดิมไม่ได้
     *
     * @throws CreatorRegistrationException
     */
    public function verifyOwnership(string $wallet, string $nonce, string $signature): string
    {
        $wallet = strtolower($wallet);
        $issued = Cache::pull($this->ownershipKey($wallet, $nonce));

        if (! is_string($issued)) {
            throw new CreatorRegistrationException('NONCE_EXPIRED', __('ข้อความยืนยันหมดอายุหรือถูกใช้ไปแล้ว — กดเซ็นใหม่อีกครั้ง'));
        }

        $message = self::ownershipMessage($wallet, $this->site(), $nonce, $issued);

        if (! $this->signatures->verify($message, $signature, $wallet)) {
            throw new CreatorRegistrationException('INVALID_SIGNATURE', __('ลายเซ็นไม่ตรงกับกระเป๋านี้'), 403);
        }

        return $message;
    }

    /**
     * เปิดใบลงทะเบียน (หรือคืนใบเดิมของกระเป๋านี้).
     */
    public function start(User $user, string $wallet, string $message, string $signature, ?string $ip): CreatorRegistration
    {
        $wallet = strtolower($wallet);

        $registration = CreatorRegistration::where('wallet_address', $wallet)->first();

        if ($registration === null) {
            return CreatorRegistration::create([
                'user_id' => $user->id,
                'wallet_address' => $wallet,
                'status' => CreatorRegistration::STATUS_DRAFT,
                'ownership_message' => $message,
                'ownership_signature' => $signature,
                'ownership_signed_at' => now(),
                'ownership_ip' => $ip,
            ]);
        }

        // ใบที่ยังแก้ได้ — อัปเดตหลักฐานการคุมกระเป๋าเป็นครั้งล่าสุด
        // ใบที่ส่งแล้ว/อนุมัติแล้วคงหลักฐานเดิมไว้ ตรงกับที่แอดมินตรวจ
        if ($registration->isEditable()) {
            $registration->update([
                'user_id' => $user->id,
                'ownership_message' => $message,
                'ownership_signature' => $signature,
                'ownership_signed_at' => now(),
                'ownership_ip' => $ip,
            ]);
        }

        return $registration;
    }

    // =========================================================================
    // ขั้นที่ 2 — ข้อมูลผู้สร้าง/โครงการ
    // =========================================================================

    /**
     * บันทึกข้อมูล — ใบที่ถูกปฏิเสธกลับเป็นร่าง และลายเซ็นข้อตกลงเดิมถูกล้าง (ข้อมูลเปลี่ยน ลายเซ็นเก่าไม่ครอบแล้ว).
     *
     * @throws CreatorRegistrationException
     */
    public function saveForm(CreatorRegistration $registration, array $data): CreatorRegistration
    {
        if (! $registration->isEditable()) {
            throw new CreatorRegistrationException('NOT_EDITABLE', __('ใบลงทะเบียนนี้ส่งตรวจแล้ว แก้ไขไม่ได้'), 409);
        }

        $registration->update([
            'status' => CreatorRegistration::STATUS_DRAFT,
            'applicant_type' => $data['applicant_type'],
            'project_name' => $data['project_name'],
            'token_symbol' => $data['token_symbol'],
            'form_data' => $data,
            'form_hash' => self::formHash($data),
            'agreement_version' => null,
            'agreement_hash' => null,
            'agreement_message' => null,
            'agreement_signature' => null,
            'agreement_signed_at' => null,
            'agreement_ip' => null,
            'agreement_user_agent' => null,
        ]);

        return $registration->refresh();
    }

    public static function formHash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // =========================================================================
    // ขั้นที่ 3 — KYC (ระบบ Thaiprompt ที่มีอยู่)
    // =========================================================================

    /**
     * ผ่าน KYC แล้วหรือยัง — บังคับเสมอ ไม่ขึ้นกับสวิตช์ด่านของ /admin/kyc
     * (เจ้าของสั่งให้ผู้สร้างเหรียญต้องยืนยันตัวตน ปิดด่านใหญ่แล้วต้องไม่หลุด).
     */
    public function hasKyc(?User $user): bool
    {
        return $this->kyc->approvedLevel($user) !== null;
    }

    // =========================================================================
    // ขั้นที่ 4 — ลงนามข้อตกลง
    // =========================================================================

    /**
     * @return array{message: string, nonce: string, agreement_version: string, agreement_hash: string, form_hash: string}
     *
     * @throws CreatorRegistrationException
     */
    public function agreementChallenge(CreatorRegistration $registration, ?User $user): array
    {
        $this->assertReadyToSign($registration, $user);

        $version = $this->agreement->currentVersion();
        $agreementHash = $this->agreement->hash($version);
        $nonce = bin2hex(random_bytes(16));
        $issued = now()->utc()->format('Y-m-d\TH:i:s\Z');

        Cache::put($this->agreementKey($registration, $nonce), [
            'issued' => $issued,
            'form_hash' => $registration->form_hash,
            'version' => $version,
        ], self::NONCE_TTL);

        return [
            'message' => self::agreementMessage(
                $registration->wallet_address,
                $registration->uuid,
                $version,
                $agreementHash,
                (string) $registration->form_hash,
                $nonce,
                $issued,
            ),
            'nonce' => $nonce,
            'agreement_version' => $version,
            'agreement_hash' => $agreementHash,
            'form_hash' => (string) $registration->form_hash,
        ];
    }

    /**
     * รับลายเซ็นข้อตกลง → ส่งตรวจ.
     *
     * @throws CreatorRegistrationException
     */
    public function submit(CreatorRegistration $registration, ?User $user, string $nonce, string $signature, ?string $ip, ?string $userAgent): CreatorRegistration
    {
        $pending = Cache::pull($this->agreementKey($registration, $nonce));

        if (! is_array($pending)) {
            throw new CreatorRegistrationException('NONCE_EXPIRED', __('ข้อความยืนยันหมดอายุหรือถูกใช้ไปแล้ว — กดเซ็นใหม่อีกครั้ง'));
        }

        $this->assertReadyToSign($registration, $user);

        // ข้อมูลถูกแก้ระหว่างที่กระเป๋าเปิดหน้าเซ็นอยู่ — ลายเซ็นนี้ครอบข้อมูลชุดเก่า
        if (! hash_equals((string) $pending['form_hash'], (string) $registration->form_hash)) {
            throw new CreatorRegistrationException('FORM_CHANGED', __('ข้อมูลใบลงทะเบียนเปลี่ยนไประหว่างเซ็น — ตรวจข้อมูลแล้วเซ็นใหม่อีกครั้ง'), 409);
        }

        $agreementHash = $this->agreement->hash($pending['version']);
        $message = self::agreementMessage(
            $registration->wallet_address,
            $registration->uuid,
            $pending['version'],
            $agreementHash,
            $pending['form_hash'],
            $nonce,
            $pending['issued'],
        );

        if (! $this->signatures->verify($message, $signature, $registration->wallet_address)) {
            throw new CreatorRegistrationException('INVALID_SIGNATURE', __('ลายเซ็นไม่ตรงกับกระเป๋าที่ลงทะเบียน'), 403);
        }

        // compare-and-set: กดส่งซ้ำสองแท็บพร้อมกัน แถวเปลี่ยนได้ครั้งเดียว
        $updated = CreatorRegistration::query()
            ->whereKey($registration->id)
            ->whereIn('status', [CreatorRegistration::STATUS_DRAFT, CreatorRegistration::STATUS_REJECTED])
            ->where('form_hash', $pending['form_hash'])
            ->update([
                'status' => CreatorRegistration::STATUS_SUBMITTED,
                'agreement_version' => $pending['version'],
                'agreement_hash' => $agreementHash,
                'agreement_message' => $message,
                'agreement_signature' => strtolower($signature),
                'agreement_signed_at' => now(),
                'agreement_ip' => $ip,
                'agreement_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'submitted_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'starter_allocation_bps' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new CreatorRegistrationException('NOT_EDITABLE', __('ใบลงทะเบียนนี้ส่งตรวจแล้ว แก้ไขไม่ได้'), 409);
        }

        $registration->refresh();
        $this->notifyAdmins($registration);

        return $registration;
    }

    // =========================================================================
    // ขั้นที่ 5 — หลังบ้าน
    // =========================================================================

    /**
     * @throws CreatorRegistrationException
     */
    public function approve(CreatorRegistration $registration, AdminUser $admin, int $starterBps): CreatorRegistration
    {
        if ($starterBps < 0 || $starterBps > CreatorRegistration::MAX_STARTER_BPS) {
            throw new CreatorRegistrationException('INVALID_ALLOCATION', __('ส่วนเริ่มโครงการต้องอยู่ระหว่าง 0–20% ของซัพพลาย'));
        }

        if ($registration->status !== CreatorRegistration::STATUS_SUBMITTED) {
            throw new CreatorRegistrationException('NOT_PENDING', __('ใบนี้ไม่ได้อยู่ในสถานะรอตรวจ'), 409);
        }

        // KYC อาจถูกยกเลิกหลังส่งใบ (Thaiprompt ถอนผล / ขอลบข้อมูล) — ตรวจซ้ำตอนตัดสิน
        if (! $this->hasKyc($registration->user)) {
            throw new CreatorRegistrationException('KYC_REQUIRED', __('ผู้สร้างยังไม่ผ่านการยืนยันตัวตน (KYC) — อนุมัติไม่ได้'), 409);
        }

        $evidence = $this->verifyEvidence($registration);
        if (! $evidence['agreement_signature_valid'] || ! $evidence['form_hash_matches'] || ! $evidence['agreement_hash_matches']) {
            throw new CreatorRegistrationException('EVIDENCE_INVALID', __('หลักฐานการลงนามตรวจไม่ผ่าน — อนุมัติไม่ได้'), 409);
        }

        $updated = CreatorRegistration::query()
            ->whereKey($registration->id)
            ->where('status', CreatorRegistration::STATUS_SUBMITTED)
            ->update([
                'status' => CreatorRegistration::STATUS_APPROVED,
                'starter_allocation_bps' => $starterBps,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new CreatorRegistrationException('NOT_PENDING', __('ใบนี้ไม่ได้อยู่ในสถานะรอตรวจ'), 409);
        }

        $registration->refresh();
        $this->audit('creator_registration.approve', $registration, ['starter_allocation_bps' => $starterBps]);

        return $registration;
    }

    /**
     * @throws CreatorRegistrationException
     */
    public function reject(CreatorRegistration $registration, AdminUser $admin, string $reason): CreatorRegistration
    {
        $updated = CreatorRegistration::query()
            ->whereKey($registration->id)
            ->where('status', CreatorRegistration::STATUS_SUBMITTED)
            ->update([
                'status' => CreatorRegistration::STATUS_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new CreatorRegistrationException('NOT_PENDING', __('ใบนี้ไม่ได้อยู่ในสถานะรอตรวจ'), 409);
        }

        $registration->refresh();
        // เหตุผลอาจมีชื่อคน — audit log ไม่ถูกล้างตามคำขอลบข้อมูล จึงเก็บแค่ความยาว
        $this->audit('creator_registration.reject', $registration, ['reason_length' => mb_strlen($reason)]);

        return $registration;
    }

    /**
     * ตรวจหลักฐานซ้ำจากสิ่งที่เก็บไว้ — แอดมินเห็นผลนี้ก่อนกดอนุมัติ และ PDF พิมพ์ผลนี้.
     *
     * @return array{ownership_signature_valid: bool, agreement_signature_valid: bool, agreement_hash_matches: bool, form_hash_matches: bool}
     */
    public function verifyEvidence(CreatorRegistration $registration): array
    {
        $wallet = $registration->wallet_address;

        $ownership = filled($registration->ownership_message) && filled($registration->ownership_signature)
            && $this->signatures->verify((string) $registration->ownership_message, (string) $registration->ownership_signature, $wallet);

        $agreementSig = filled($registration->agreement_message) && filled($registration->agreement_signature)
            && $this->signatures->verify((string) $registration->agreement_message, (string) $registration->agreement_signature, $wallet);

        $agreementHash = false;
        if (filled($registration->agreement_version) && $this->agreement->exists((string) $registration->agreement_version)) {
            try {
                $agreementHash = hash_equals($this->agreement->hash($registration->agreement_version), (string) $registration->agreement_hash)
                    && str_contains((string) $registration->agreement_message, 'Agreement SHA-256: '.$registration->agreement_hash);
            } catch (Throwable) {
                $agreementHash = false;
            }
        }

        $data = $registration->form_data;
        $formHash = is_array($data)
            && hash_equals(self::formHash($data), (string) $registration->form_hash)
            && (! $registration->isSigned() || str_contains((string) $registration->agreement_message, 'Application SHA-256: '.$registration->form_hash));

        return [
            'ownership_signature_valid' => $ownership,
            'agreement_signature_valid' => $agreementSig,
            'agreement_hash_matches' => $agreementHash,
            'form_hash_matches' => $formHash,
        ];
    }

    // =========================================================================
    // ด่านสร้างเหรียญ + ค่าตั้ง
    // =========================================================================

    public function isRequired(): bool
    {
        return (bool) SiteSetting::get(self::SETTING_GROUP, self::KEY_REQUIRED, false);
    }

    public function defaultStarterBps(): int
    {
        $bps = (int) SiteSetting::get(self::SETTING_GROUP, self::KEY_DEFAULT_BPS, CreatorRegistration::DEFAULT_STARTER_BPS);

        return max(0, min(CreatorRegistration::MAX_STARTER_BPS, $bps));
    }

    /** กระเป๋านี้สร้างเหรียญได้ไหม — สวิตช์ปิด = ได้ทุกคน (พฤติกรรมเดิม) */
    public function allowsTokenCreation(string $wallet): bool
    {
        if (! $this->isRequired()) {
            return true;
        }

        return CreatorRegistration::query()
            ->where('wallet_address', strtolower($wallet))
            ->where('status', CreatorRegistration::STATUS_APPROVED)
            ->exists();
    }

    // =========================================================================
    // ข้อความที่เซ็น — static เพื่อให้เทสต์และคนตรวจภายนอกสร้างซ้ำได้ตรงตัว
    // =========================================================================

    public static function ownershipMessage(string $wallet, string $site, string $nonce, string $issued): string
    {
        return implode("\n", [
            'TPIX Token Creator registration',
            '',
            'I control this wallet and want to register as a token creator on TPIX TRADE.',
            'This wallet will receive my project starter tokens.',
            'Signing is free and does not send a transaction.',
            '',
            'Site: '.$site,
            'Wallet: '.strtolower($wallet),
            'Nonce: '.$nonce,
            'Issued: '.$issued,
        ]);
    }

    public static function agreementMessage(
        string $wallet,
        string $uuid,
        string $version,
        string $agreementHash,
        string $formHash,
        string $nonce,
        string $issued,
    ): string {
        return implode("\n", [
            'TPIX Token Creator Agreement',
            '',
            'I have read, understood and agree to the TPIX Token Creator Agreement',
            '(the Thai text prevails) and confirm my application data is true.',
            'Signing is free and does not send a transaction.',
            '',
            'Wallet: '.strtolower($wallet),
            'Registration: '.$uuid,
            'Agreement version: '.$version,
            'Agreement SHA-256: '.$agreementHash,
            'Application SHA-256: '.$formHash,
            'Nonce: '.$nonce,
            'Issued: '.$issued,
        ]);
    }

    // =========================================================================
    // ภายใน
    // =========================================================================

    /**
     * @throws CreatorRegistrationException
     */
    private function assertReadyToSign(CreatorRegistration $registration, ?User $user): void
    {
        if (! $registration->isEditable()) {
            throw new CreatorRegistrationException('NOT_EDITABLE', __('ใบลงทะเบียนนี้ส่งตรวจแล้ว แก้ไขไม่ได้'), 409);
        }

        if (blank($registration->form_hash) || ! is_array($registration->form_data)) {
            throw new CreatorRegistrationException('FORM_INCOMPLETE', __('กรอกและบันทึกข้อมูลผู้สร้างให้ครบก่อนเซ็นข้อตกลง'));
        }

        if (! $this->hasKyc($user)) {
            throw new CreatorRegistrationException('KYC_REQUIRED', __('ต้องยืนยันตัวตน (KYC) ผ่าน Thaiprompt ก่อนเซ็นข้อตกลง'), 403);
        }
    }

    private function site(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'tpix.online';
    }

    private function ownershipKey(string $wallet, string $nonce): string
    {
        return "creator_reg:own:{$wallet}:{$nonce}";
    }

    private function agreementKey(CreatorRegistration $registration, string $nonce): string
    {
        return "creator_reg:agree:{$registration->id}:{$nonce}";
    }

    private function notifyAdmins(CreatorRegistration $registration): void
    {
        try {
            app(AdminNotificationService::class)->notifyAll(
                'creator_registration',
                'ใบลงทะเบียนผู้สร้างเหรียญใหม่',
                'โครงการ '.Str::limit((string) $registration->project_name, 60).' ('.$registration->token_symbol.') ส่งใบลงทะเบียนพร้อมลงนามข้อตกลงแล้ว',
                ['uuid' => $registration->uuid, 'url' => '/admin/creator-registrations/'.$registration->uuid],
            );
        } catch (Throwable $e) {
            // แจ้งเตือนล้มต้องไม่ทำให้ใบที่เซ็นแล้วล้มตาม — ใบอยู่ในคิวหลังบ้านอยู่แล้ว
            Log::warning('Creator registration admin notification failed', ['uuid' => $registration->uuid, 'error' => class_basename($e)]);
        }
    }

    private function audit(string $action, CreatorRegistration $registration, array $extra = []): void
    {
        try {
            AuditLog::log($action, $registration, null, array_merge(['uuid' => $registration->uuid], $extra));
        } catch (Throwable $e) {
            Log::warning('Creator registration audit failed', ['uuid' => $registration->uuid, 'error' => class_basename($e)]);
        }
    }
}
