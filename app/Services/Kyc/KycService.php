<?php

namespace App\Services\Kyc;

use App\Models\AdminUser;
use App\Models\KycDocument;
use App\Models\KycSubmission;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TPIX TRADE — รับใบคำขอยืนยันตัวตนและตรวจ.
 *
 * แยกจาก controller เพราะเส้นทางเข้ามามีหลายทาง (เว็บ / API มือถือ / แอดมินทำแทน)
 * แต่กฎต้องเป็นชุดเดียวกันทุกทาง
 *
 * Developed by Xman Studio.
 */
class KycService
{
    public const ERR_ALREADY_PENDING = 'มีใบคำขอที่รอตรวจอยู่แล้ว รอผลก่อนยื่นใหม่';

    public const ERR_ALREADY_APPROVED = 'บัญชีนี้ยืนยันตัวตนผ่านแล้ว';

    public const ERR_RATE_LIMIT = 'ยื่นบ่อยเกินไป กรุณารอ 24 ชั่วโมงแล้วลองใหม่';

    public const ERR_MISSING_DOCS = 'เอกสารไม่ครบตามระดับที่เลือก';

    public const ERR_BAD_FILE = 'ไฟล์ไม่ใช่รูปภาพที่รองรับ';

    public function __construct(
        private KycGate $gate,
    ) {}

    // =========================================================================
    // ยื่นคำขอ
    // =========================================================================

    /**
     * ยื่นใบใหม่พร้อมเอกสาร.
     *
     * @param  array<string, UploadedFile>  $files  คีย์คือประเภทเอกสาร
     *
     * @throws RuntimeException เมื่อยื่นไม่ได้ตามกฎ
     */
    public function submit(User $user, array $data, array $files, ?string $ip = null): KycSubmission
    {
        $level = $data['level'] ?? KycSubmission::LEVEL_BASIC;

        $this->assertCanSubmit($user, $level);
        $this->assertDocumentsComplete($level, $files);

        // ตรวจไฟล์ให้ครบทุกใบ "ก่อน" เปิด transaction
        //
        // ⚠️ ห้ามเขียนไฟล์ลงดิสก์ใน transaction แล้วหวังว่า rollback จะเก็บกวาดให้
        //    rollback ย้อนได้แต่แถวในฐานข้อมูล ไฟล์ที่เขียนไปแล้วยังอยู่
        //    กลายเป็นบัตรประชาชนค้างบนดิสก์ที่ไม่มีแถวไหนอ้างถึง = ไม่มีวันถูกล้าง
        foreach ($files as $type => $file) {
            $this->assertRealImage($file);
        }

        $retentionDays = $this->retentionDays();
        $stored = [];

        try {
            $submission = DB::transaction(function () use ($user, $data, $level, $ip, $retentionDays, $files, &$stored) {
                // ใบเก่าที่ยังรอตรวจถือว่าถูกแทนที่ ไม่ปล่อยให้มีสองใบรอพร้อมกัน
                // ทีมงานจะได้ไม่ตรวจใบที่ผู้ใช้ตั้งใจยกเลิกไปแล้ว
                KycSubmission::query()
                    ->where('user_id', $user->id)
                    ->where('status', KycSubmission::STATUS_PENDING)
                    ->update(['status' => KycSubmission::STATUS_CANCELLED]);

                $nationalId = trim((string) ($data['national_id'] ?? ''));

                $submission = KycSubmission::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'level' => $level,
                    'status' => KycSubmission::STATUS_PENDING,
                    'full_name' => $data['full_name'] ?? null,
                    'full_name_en' => $data['full_name_en'] ?? null,
                    'national_id' => $nationalId !== '' ? $nationalId : null,
                    'id_type' => $data['id_type'] ?? 'national_id',
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'nationality' => $data['nationality'] ?? null,
                    'address' => $data['address'] ?? null,
                    'occupation' => $data['occupation'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'national_id_hash' => $nationalId !== ''
                        ? KycSubmission::hashNationalId($nationalId)
                        : null,
                    'consent_version' => $this->consentVersion(),
                    'consented_at' => now(),
                    'consent_ip' => $ip,
                    'submitted_at' => now(),
                    'purge_after' => now()->addDays($retentionDays),
                ]);

                foreach ($files as $type => $file) {
                    $stored[] = $this->storeDocument($submission, $type, $file);
                }

                $user->forceFill(['kyc_status' => 'pending'])->save();

                return $submission;
            });
        } catch (\Throwable $e) {
            // transaction ล้ม → เก็บไฟล์ที่เพิ่งเขียนไปแล้วออกให้หมด
            // ไม่งั้นเหลือบัตรประชาชนลอยอยู่บนดิสก์โดยไม่มีใครรู้ว่าของใคร
            $this->discardOrphans($stored);

            throw $e;
        }

        return $submission->load('documents');
    }

    /**
     * ยกเลิกใบของตัวเองที่ยังรอตรวจ.
     */
    public function cancel(KycSubmission $submission): void
    {
        if (! $submission->isPending()) {
            return;
        }

        DB::transaction(function () use ($submission) {
            $submission->update(['status' => KycSubmission::STATUS_CANCELLED]);
            $this->syncUserStatus($submission->user);
        });
    }

    // =========================================================================
    // ตรวจ
    // =========================================================================

    public function approve(KycSubmission $submission, AdminUser $admin, ?string $note = null): KycSubmission
    {
        return DB::transaction(function () use ($submission, $admin, $note) {
            $submission->update([
                'status' => KycSubmission::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'review_note' => $note,
                'reject_reason' => null,
                // นับระยะเก็บใหม่จากวันอนุมัติ ไม่ใช่วันยื่น
                // ใบที่ยังใช้สิทธิอยู่ต้องพิสูจน์ได้ตลอดช่วงที่ใช้
                'purge_after' => now()->addDays($this->retentionDays()),
            ]);

            $this->syncUserStatus($submission->user);

            return $submission->fresh();
        });
    }

    public function reject(KycSubmission $submission, AdminUser $admin, string $reason, ?string $note = null): KycSubmission
    {
        return DB::transaction(function () use ($submission, $admin, $reason, $note) {
            $submission->update([
                'status' => KycSubmission::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'reject_reason' => $reason,
                'review_note' => $note,
            ]);

            $this->syncUserStatus($submission->user);

            return $submission->fresh();
        });
    }

    /**
     * ให้ users.kyc_status ตรงกับใบล่าสุดเสมอ.
     *
     * คอลัมน์นั้นเป็นแค่ "เงา" ไว้ให้หน้าเดิมที่ใช้อยู่แล้ว query ได้เร็ว
     * แหล่งความจริงคือใบคำขอ — ด่านจริงอ่านจากใบเท่านั้น (ดู KycGate::approvedLevel)
     */
    public function syncUserStatus(?User $user): void
    {
        if (! $user) {
            return;
        }

        $latest = KycSubmission::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $user->forceFill([
            'kyc_status' => $latest?->userStatus() ?? 'none',
        ])->save();
    }

    // =========================================================================
    // ตรวจสอบเงื่อนไข
    // =========================================================================

    /**
     * @throws RuntimeException
     */
    private function assertCanSubmit(User $user, string $level): void
    {
        $pending = KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('status', KycSubmission::STATUS_PENDING)
            ->exists();

        if ($pending) {
            throw new RuntimeException(self::ERR_ALREADY_PENDING);
        }

        // ผ่านระดับที่จะยื่นอยู่แล้วก็ไม่ต้องยื่นซ้ำ
        // แต่ basic → enhanced ต้องยื่นได้ ไม่งั้นอัประดับไม่ได้เลย
        if ($this->gate->satisfiesLevel($user, $level)) {
            throw new RuntimeException(self::ERR_ALREADY_APPROVED);
        }

        $perDay = (int) config('kyc.limits.submissions_per_day', 3);

        $recent = KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= $perDay) {
            throw new RuntimeException(self::ERR_RATE_LIMIT);
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertDocumentsComplete(string $level, array $files): void
    {
        $required = KycDocument::REQUIRED_BY_LEVEL[$level] ?? KycDocument::REQUIRED_BY_LEVEL[KycSubmission::LEVEL_BASIC];

        foreach ($required as $type) {
            if (! isset($files[$type]) || ! $files[$type] instanceof UploadedFile) {
                throw new RuntimeException(self::ERR_MISSING_DOCS);
            }
        }
    }

    /**
     * ไฟล์นี้เป็นรูปจริงหรือแค่ตั้งชื่อว่า .jpg.
     *
     * ⚠️ ห้ามเชื่อ getClientMimeType() หรือนามสกุลไฟล์ — ผู้ส่งตั้งเองได้ทั้งคู่
     *    ตรวจสามชั้น: finfo อ่านจากเนื้อไฟล์ · getimagesize อ่านหัวรูปจริง ·
     *    ชนิดที่ได้ต้องอยู่ในรายการที่รับ
     *
     * @throws RuntimeException
     */
    private function assertRealImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException(self::ERR_BAD_FILE);
        }

        $maxKb = (int) config('kyc.uploads.max_size_kb', 8192);

        if ($file->getSize() > $maxKb * 1024) {
            throw new RuntimeException("ไฟล์ใหญ่เกิน {$maxKb} KB");
        }

        // getMimeType() ของ Symfony อ่านจากเนื้อไฟล์ (finfo) ไม่ใช่จาก header ที่ client ส่ง
        $mime = (string) $file->getMimeType();
        $allowed = (array) config('kyc.uploads.mimes', []);

        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException(self::ERR_BAD_FILE);
        }

        // ชั้นที่สอง: ต้องถอดขนาดรูปได้จริง
        // ไฟล์ที่ finfo เดาว่าเป็นรูปแต่ getimagesize อ่านไม่ออก มักเป็นไฟล์ลูกผสม
        // ที่ตั้งใจให้ parser คนละตัวอ่านคนละอย่าง
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new RuntimeException(self::ERR_BAD_FILE);
        }
    }

    /**
     * เขียนไฟล์ลง disk ส่วนตัวแล้วบันทึกแถว.
     *
     * ชื่อไฟล์สุ่มใหม่ทั้งหมด ไม่เอาชื่อเดิมของผู้ใช้มาต่อ path
     * ชื่อเดิมอาจมี ../ หรือไบต์ที่ระบบไฟล์ตีความแปลกๆ — เก็บไว้ในคอลัมน์ก็พอ
     */
    private function storeDocument(KycSubmission $submission, string $type, UploadedFile $file): KycDocument
    {
        $mime = (string) $file->getMimeType();
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $disk = (string) config('kyc.uploads.disk', 'kyc');
        $path = sprintf(
            '%d/%s/%s-%s.%s',
            $submission->user_id,
            $submission->uuid,
            $type,
            Str::random(16),
            $ext
        );

        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException(self::ERR_BAD_FILE);
        }

        // ล้าง metadata ด้วยการเข้ารหัสรูปใหม่ ถ้าเครื่องมี GD
        //
        // ได้สองอย่างพร้อมกัน:
        //   1. EXIF หลุดไปด้วยเสมอ ซึ่งมีพิกัด GPS ตอนถ่ายอยู่บ่อยๆ
        //      PDPA บอกให้เก็บเท่าที่จำเป็น — เราขอรูปบัตร ไม่ได้ขอว่าเขาอยู่บ้านไหน
        //   2. ไฟล์ลูกผสมตายไปด้วย เพราะ GD อ่านเฉพาะข้อมูลภาพแล้วเขียนใหม่จากศูนย์
        //      ส่วนที่แนบท้ายมาเพื่อให้ parser อื่นอ่านหายหมด
        //
        // ทำไม่ได้ก็ยังปลอดภัยพอ: ไฟล์อยู่นอก document root จึงเรียกให้รันไม่ได้อยู่แล้ว
        $reencoded = $this->reencode($contents, $mime);

        if ($reencoded !== null) {
            $contents = $reencoded;
        }

        Storage::disk($disk)->put($path, $contents);

        return KycDocument::create([
            'kyc_submission_id' => $submission->id,
            'type' => $type,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]);
    }

    /**
     * เข้ารหัสรูปใหม่ด้วย GD — คืน null ถ้าทำไม่ได้ ให้ผู้เรียกใช้ต้นฉบับต่อ.
     */
    private function reencode(string $contents, string $mime): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        try {
            ob_start();

            $ok = match ($mime) {
                'image/png' => imagepng($image),
                'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, 90) : false,
                default => imagejpeg($image, null, 90),
            };

            $out = (string) ob_get_clean();

            return ($ok && $out !== '') ? $out : null;
        } catch (\Throwable $e) {
            // เคลียร์ output buffer ที่ค้างอยู่ ไม่งั้นไปโผล่ปนกับ response
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            return null;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * เก็บกวาดไฟล์ที่เขียนไปแล้วแต่ transaction ล้ม
     */
    private function discardOrphans(array $documents): void
    {
        foreach ($documents as $doc) {
            if (! $doc instanceof KycDocument) {
                continue;
            }

            try {
                Storage::disk($doc->disk)->delete($doc->path);
            } catch (\Throwable $e) {
                // ลบไม่ได้ก็ต้องรู้ — ไฟล์กำพร้าที่เหลือค้างคือข้อมูลส่วนบุคคลที่ไม่มีใครดูแล
                Log::error('ลบไฟล์ KYC กำพร้าไม่สำเร็จ', [
                    'path' => $doc->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // =========================================================================
    // ค่าตั้งค่า
    // =========================================================================

    public function retentionDays(): int
    {
        $days = (int) SiteSetting::get(
            KycGate::SETTING_GROUP,
            KycGate::KEY_RETENTION,
            (int) config('kyc.retention_days', 1825)
        );

        return max(1, $days);
    }

    public function consentVersion(): string
    {
        return (string) SiteSetting::get(
            KycGate::SETTING_GROUP,
            KycGate::KEY_CONSENT_VERSION,
            (string) config('kyc.consent_version', '1.0')
        );
    }

    /**
     * บัตรใบนี้เคยผูกกับบัญชีอื่นไหม — ธงให้ทีมงานดู ไม่บล็อกอัตโนมัติ.
     *
     * ครอบครัวใช้เครื่องเดียวกันหรือยื่นแทนกันมีจริง ตัดสินแทนคนไม่ได้
     */
    public function duplicateAccountIds(KycSubmission $submission): array
    {
        if (! $submission->national_id_hash) {
            return [];
        }

        return KycSubmission::query()
            ->where('national_id_hash', $submission->national_id_hash)
            ->where('user_id', '!=', $submission->user_id)
            ->whereNull('purged_at')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }
}
