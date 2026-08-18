<?php

namespace App\Services\Kyc;

use App\Models\AdminUser;
use App\Models\KycDeletionRequest;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ล้างข้อมูลยืนยันตัวตน (PDPA).
 *
 * "ลบ" ในที่นี้แปลว่าลบจริง ไม่ใช่ตั้งธงว่าลบแล้ว
 * ไฟล์หายจากดิสก์ ฟิลด์ที่ระบุตัวบุคคลเป็น null เหลือแต่โครงว่าเคยมีการตรวจ
 *
 * ทำไมไม่ลบทั้งแถว: ต้องตอบได้ว่าบัญชีนี้เคยผ่านการตรวจเมื่อไหร่ ใครตรวจ
 * ซึ่งเป็นข้อมูลของ "เรา" ไม่ใช่ของเจ้าตัว และหน่วยงานกำกับอาจขอดูย้อนหลัง
 * แต่ตัวตนของคนในนั้นหายหมดแล้ว — เอากลับมาไม่ได้แม้จะได้ฐานข้อมูลกับ APP_KEY ไป
 *
 * Developed by Xman Studio.
 */
class KycPurgeService
{
    /**
     * ฟิลด์ที่ต้องล้าง — ทุกอย่างที่ชี้กลับไปหาตัวคนได้.
     *
     * ⚠️ national_id_hash ต้องอยู่ในนี้ด้วย
     *    เลขบัตรไทยมีแค่ 13 หลักและรูปแบบตายตัว เก็บ HMAC ไว้เท่ากับยังตอบได้ว่า
     *    "ใบนี้ใช่ของนายคนนี้ไหม" เมื่อมีเลขมาเทียบ ซึ่งยังเป็นข้อมูลส่วนบุคคลอยู่
     */
    private const PERSONAL_FIELDS = [
        'full_name',
        'full_name_en',
        'national_id',
        'national_id_hash',
        'date_of_birth',
        'nationality',
        'address',
        'occupation',
        'phone',
        'consent_ip',
    ];

    /**
     * ล้างใบเดียว — คืนจำนวนไฟล์ที่ลบได้จริง.
     */
    public function purgeSubmission(KycSubmission $submission, string $reason = 'retention'): int
    {
        if ($submission->isPurged()) {
            return 0;
        }

        // ลบไฟล์ "ก่อน" แตะฐานข้อมูล
        //
        // ⚠️ ลำดับนี้กลับด้านไม่ได้ — ล้างแถวก่อนแล้วลบไฟล์พลาด จะเหลือไฟล์บัตรประชาชน
        //    บนดิสก์ที่ไม่มีแถวไหนชี้ถึงอีกแล้ว ไม่มีใครรู้ว่ามันคือของใครและไม่มีวันถูกล้าง
        //    ทำกลับกัน (ไฟล์หายแต่แถวยังอยู่) รอบหน้ายังตามล้างต่อได้
        $deleted = 0;

        foreach ($submission->documents as $document) {
            if ($document->purgeFile()) {
                $deleted++;
            }
        }

        DB::transaction(function () use ($submission, $reason) {
            $blank = array_fill_keys(self::PERSONAL_FIELDS, null);

            $submission->forceFill($blank + [
                'purged_at' => now(),
                'purge_reason' => $reason,
            ])->save();
        });

        Log::info('ล้างข้อมูล KYC แล้ว', [
            'submission_uuid' => $submission->uuid,
            'reason' => $reason,
            'files_deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * ล้างทุกใบของผู้ใช้คนหนึ่ง.
     */
    public function purgeUser(User $user, string $reason = 'user_request'): int
    {
        $deleted = 0;

        $submissions = KycSubmission::query()
            ->where('user_id', $user->id)
            ->whereNull('purged_at')
            ->with('documents')
            ->get();

        foreach ($submissions as $submission) {
            $deleted += $this->purgeSubmission($submission, $reason);
        }

        // ล้างข้อมูลแล้วสิทธิที่ได้จากการตรวจต้องหายไปด้วย
        //
        // ⚠️ ถ้าไม่รีเซ็ตตรงนี้ จะเหลือบัญชีที่ผ่าน KYC ค้างอยู่โดยไม่มีเอกสารรองรับ
        //    ซึ่งแปลว่าใครขอลบข้อมูลก็ได้สิทธิถาวรโดยที่เราพิสูจน์อะไรไม่ได้เลย
        //    KycGate อ่านจากใบที่ยังไม่ถูกล้างอยู่แล้ว บรรทัดนี้แค่ให้คอลัมน์เงาตรงกัน
        $user->forceFill(['kyc_status' => 'none'])->save();

        return $deleted;
    }

    /**
     * ใบที่ครบกำหนดเก็บแล้ว — คำสั่ง kyc:purge เรียกอันนี้.
     *
     * ไม่แตะใบที่ยังรอตรวจ แม้จะครบกำหนด เพราะแปลว่าคิวตรวจค้างนานผิดปกติ
     * ลบทิ้งเงียบๆ = ผู้ใช้รอผลที่ไม่มีวันมา ต้องให้คนเห็นปัญหาก่อน
     */
    public function runRetention(int $limit = 200): array
    {
        $due = KycSubmission::query()
            ->whereNull('purged_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->where('status', '!=', KycSubmission::STATUS_PENDING)
            ->with('documents')
            ->limit($limit)
            ->get();

        $files = 0;

        foreach ($due as $submission) {
            $files += $this->purgeSubmission($submission, 'retention');
        }

        return [
            'submissions' => $due->count(),
            'files' => $files,
        ];
    }

    // =========================================================================
    // คำขอลบข้อมูลจากเจ้าของข้อมูล
    // =========================================================================

    public function requestDeletion(User $user, ?string $reason = null, ?string $ip = null): KycDeletionRequest
    {
        // มีคำขอค้างอยู่แล้วก็ใช้ใบเดิม กดซ้ำไม่ควรสร้างคิวซ้ำ
        $existing = KycDeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', KycDeletionRequest::STATUS_PENDING)
            ->first();

        if ($existing) {
            return $existing;
        }

        $latest = KycSubmission::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return KycDeletionRequest::create([
            'user_id' => $user->id,
            'kyc_submission_id' => $latest?->id,
            'reason' => $reason,
            'status' => KycDeletionRequest::STATUS_PENDING,
            'requested_at' => now(),
            'requested_ip' => $ip,
        ]);
    }

    public function completeDeletion(KycDeletionRequest $request, AdminUser $admin, ?string $note = null): KycDeletionRequest
    {
        $user = $request->user;

        $files = $user ? $this->purgeUser($user, 'user_request') : 0;

        $request->update([
            'status' => KycDeletionRequest::STATUS_COMPLETED,
            'handled_by' => $admin->id,
            'handled_at' => now(),
            'handler_note' => $note,
            'files_deleted' => $files,
        ]);

        return $request->fresh();
    }

    /**
     * ปฏิเสธคำขอ — ต้องมีเหตุผลเสมอ.
     *
     * PDPA ให้ปฏิเสธได้เฉพาะกรณีที่มีฐานทางกฎหมายให้เก็บต่อ
     * ไม่ใช่เพราะไม่สะดวกลบ เหตุผลที่กรอกจึงต้องส่งกลับให้เจ้าของข้อมูลเห็น
     */
    public function rejectDeletion(KycDeletionRequest $request, AdminUser $admin, string $note): KycDeletionRequest
    {
        $request->update([
            'status' => KycDeletionRequest::STATUS_REJECTED,
            'handled_by' => $admin->id,
            'handled_at' => now(),
            'handler_note' => $note,
        ]);

        return $request->fresh();
    }

    // =========================================================================
    // สิทธิเข้าถึงข้อมูลของตัวเอง
    // =========================================================================

    /**
     * รวมข้อมูล KYC ทั้งหมดของผู้ใช้ให้ดาวน์โหลดไปเก็บได้.
     *
     * PDPA ให้สิทธิขอสำเนาข้อมูลของตัวเอง — รวมถึงต้องบอกได้ว่าใครเข้าถึงบ้าง
     * จึงแนบประวัติการเปิดดูของทีมงานไปด้วย
     */
    public function exportForUser(User $user): array
    {
        $submissions = KycSubmission::query()
            ->where('user_id', $user->id)
            ->with(['documents.views.admin:id,name', 'reviewer:id,name'])
            ->orderBy('id')
            ->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'wallet_address' => $user->wallet_address,
                'kyc_status' => $user->kyc_status,
            ],
            'submissions' => $submissions->map(function (KycSubmission $s) {
                return [
                    'uuid' => $s->uuid,
                    'level' => $s->level,
                    'status' => $s->status,
                    'full_name' => $s->full_name,
                    'full_name_en' => $s->full_name_en,
                    'id_type' => $s->id_type,
                    'national_id' => $s->national_id,
                    'date_of_birth' => $s->date_of_birth,
                    'nationality' => $s->nationality,
                    'address' => $s->address,
                    'occupation' => $s->occupation,
                    'phone' => $s->phone,
                    'consent_version' => $s->consent_version,
                    'consented_at' => $s->consented_at?->toIso8601String(),
                    'submitted_at' => $s->submitted_at?->toIso8601String(),
                    'reviewed_at' => $s->reviewed_at?->toIso8601String(),
                    'reviewed_by' => $s->reviewer?->name,
                    'reject_reason' => $s->reject_reason,
                    'purge_after' => $s->purge_after?->toIso8601String(),
                    'purged_at' => $s->purged_at?->toIso8601String(),
                    'documents' => $s->documents->map(fn ($d) => [
                        'type' => $d->type,
                        'original_name' => $d->original_name,
                        'size' => $d->size,
                        'uploaded_at' => $d->created_at?->toIso8601String(),
                        'purged_at' => $d->purged_at?->toIso8601String(),
                        // ใครเปิดดูเอกสารใบนี้บ้าง
                        'accessed_by' => $d->views->map(fn ($v) => [
                            'admin' => $v->admin?->name ?? 'ทีมงาน',
                            'viewed_at' => $v->viewed_at?->toIso8601String(),
                        ])->all(),
                    ])->all(),
                ];
            })->all(),
        ];
    }
}
