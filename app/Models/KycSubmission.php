<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — ใบคำขอยืนยันตัวตน.
 *
 * หนึ่งแถวคือการยื่นหนึ่งครั้ง ไม่ใช่หนึ่งคน — ถูกปฏิเสธแล้วยื่นใหม่ได้
 * ใบล่าสุดของแต่ละคนคือใบที่มีผล (ดู scope latestForUser)
 *
 * ⚠️ ฟิลด์ที่ระบุตัวบุคคลเข้ารหัสด้วย APP_KEY ทั้งหมด
 *    - อย่า where() บนฟิลด์พวกนี้ ค้นไม่เจอแน่นอนเพราะ ciphertext ต่างกันทุกครั้ง
 *      แม้ค่าต้นฉบับเหมือนกัน (Laravel ใส่ IV สุ่มให้ทุกรอบ) — ใช้ national_id_hash แทน
 *    - อย่าใส่ลง log หรือส่งออก API โดยไม่ตั้งใจ ดู toSafeArray()
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $level
 * @property string $status
 * @property string|null $full_name
 * @property string|null $national_id
 * @property string|null $national_id_hash
 * @property Carbon $submitted_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $purge_after
 * @property Carbon|null $purged_at
 *
 * Developed by Xman Studio.
 */
class KycSubmission extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const LEVEL_BASIC = 'basic';

    public const LEVEL_ENHANCED = 'enhanced';

    protected $fillable = [
        'uuid',
        'user_id',
        'level',
        'status',
        'full_name',
        'full_name_en',
        'national_id',
        'id_type',
        'date_of_birth',
        'nationality',
        'address',
        'occupation',
        'phone',
        'national_id_hash',
        'consent_version',
        'consented_at',
        'consent_ip',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'review_note',
        'reject_reason',
        'purge_after',
        'purged_at',
        'purge_reason',
    ];

    /**
     * ซ่อนจาก toArray()/toJson() เป็นค่าตั้งต้น.
     *
     * เผื่อมีใครส่งโมเดลทั้งก้อนกลับไป Inertia หรือ API โดยไม่ได้ตั้งใจ
     * ค่าตั้งต้นที่ปลอดภัยสำคัญกว่าความสะดวก — อยากได้จริงเรียก decrypted() เอง
     */
    protected $hidden = [
        'national_id',
        'national_id_hash',
        'date_of_birth',
        'address',
        'phone',
        'consent_ip',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'full_name_en' => 'encrypted',
            'national_id' => 'encrypted',
            // เก็บเป็นข้อความ Y-m-d ที่เข้ารหัส ไม่ใช่ date
            // Laravel ไม่มี cast `encrypted:date` ให้ใช้
            'date_of_birth' => 'encrypted',
            'address' => 'encrypted',
            'occupation' => 'encrypted',
            'phone' => 'encrypted',
            'consented_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $submission) {
            if (empty($submission->uuid)) {
                $submission->uuid = (string) Str::uuid();
            }
        });
    }

    // === Relations ===

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function deletionRequests(): HasMany
    {
        return $this->hasMany(KycDeletionRequest::class);
    }

    // === Helpers ===

    /**
     * ลายนิ้วมือของเลขบัตรไว้หาคนยื่นซ้ำ โดยไม่ต้องถอดรหัสทั้งตาราง.
     *
     * HMAC ไม่ใช่ hash เปล่า เพราะเลขบัตรประชาชนไทยมีแค่ 13 หลัก
     * sha256 เปล่าไล่ครบทุกความเป็นไปได้ด้วยเครื่องธรรมดาได้ในเวลาไม่นาน
     * ผูกกับ APP_KEY แล้วคนที่ได้แค่ฐานข้อมูลไปจึงไล่ย้อนไม่ได้
     */
    public static function hashNationalId(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return hash_hmac('sha256', strtoupper($normalized), (string) config('app.key'));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * ล้างไปแล้วหรือยัง — ใบที่ล้างแล้วยังอยู่เป็นหลักฐานว่าเคยตรวจ
     * แต่ข้อมูลส่วนบุคคลข้างในหายหมดแล้ว.
     */
    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * แปลงสถานะใบ → สถานะที่ users.kyc_status รับได้.
     *
     * คอลัมน์นั้นมีแค่ none/pending/approved/rejected ซึ่งหน้าสมาชิกกับหน้าโปรไฟล์
     * ใช้อยู่ก่อนแล้ว จึงไม่ขยาย enum เพิ่ม — ยกเลิก/หมดอายุ ถือว่ากลับไปเป็น none
     */
    public function userStatus(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'approved',
            self::STATUS_REJECTED => 'rejected',
            self::STATUS_PENDING => 'pending',
            default => 'none',
        };
    }

    /**
     * ข้อมูลที่ผู้ใช้เห็นเกี่ยวกับใบของตัวเอง.
     *
     * ไม่มี review_note (บันทึกภายใน) และไม่มีเลขบัตรเต็ม
     */
    public function toOwnerArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'level' => $this->level,
            'status' => $this->status,
            'full_name' => $this->full_name,
            'id_type' => $this->id_type,
            'national_id_masked' => $this->maskedNationalId(),
            'nationality' => $this->nationality,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'consent_version' => $this->consent_version,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'purge_after' => $this->purge_after?->toIso8601String(),
            'purged_at' => $this->purged_at?->toIso8601String(),
            'documents' => $this->relationLoaded('documents')
                ? $this->documents->map->toOwnerArray()->all()
                : [],
        ];
    }

    /**
     * โชว์ 4 ตัวท้ายพอให้เจ้าตัวรู้ว่าใบไหน แต่ไม่พอให้คนอื่นเอาไปใช้.
     */
    public function maskedNationalId(): ?string
    {
        $id = $this->national_id;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $tail = substr($id, -4);

        return str_repeat('•', max(0, strlen($id) - 4)).$tail;
    }
}
