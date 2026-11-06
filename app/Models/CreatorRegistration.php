<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — ใบลงทะเบียนผู้สร้างเหรียญ (Token Factory creator).
 *
 * สถานะ: draft → submitted → approved
 *                       ↘ rejected → (แก้ข้อมูล) → draft → submitted …
 *
 * อนุมัติแล้วถึงสร้างเหรียญได้ (เมื่อหลังบ้านเปิดสวิตช์ creator_registration_required)
 * ส่วนเริ่มโครงการ starter_allocation_bps ผลิตเข้ากระเป๋านี้ตรงๆ ตอนสร้างเหรียญ
 * แพลตฟอร์มไม่ถือเหรียญลูกของใครไว้แทน (เจ้าของตัดสิน 2026-11-06)
 *
 * ⚠️ form_data เข้ารหัสด้วย APP_KEY — มีชื่อ เบอร์ ที่อยู่ ของผู้สร้าง
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $wallet_address
 * @property string $status
 * @property string|null $applicant_type
 * @property string|null $project_name
 * @property string|null $token_symbol
 * @property int|null $factory_token_id
 * @property array|null $form_data
 * @property string|null $form_hash
 * @property string|null $documents_hash
 * @property string|null $ownership_message
 * @property string|null $ownership_signature
 * @property Carbon|null $ownership_signed_at
 * @property string|null $ownership_ip
 * @property string|null $agreement_version
 * @property string|null $agreement_hash
 * @property string|null $agreement_message
 * @property string|null $agreement_signature
 * @property Carbon|null $agreement_signed_at
 * @property string|null $agreement_ip
 * @property string|null $agreement_user_agent
 * @property Carbon|null $submitted_at
 * @property int|null $starter_allocation_bps
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 *
 * Developed by Xman Studio.
 */
class CreatorRegistration extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_COMPANY = 'company';

    /** เพดานส่วนเริ่มโครงการ — ตรงกับกฎบนเชน "≥80% ของซัพพลายต้องล็อก" */
    public const MAX_STARTER_BPS = 2000;

    /** ค่าตั้งต้น 10% (เจ้าของเลือก 2026-11-06) — หลังบ้านเปลี่ยนได้ */
    public const DEFAULT_STARTER_BPS = 1000;

    protected $fillable = [
        'uuid',
        'user_id',
        'wallet_address',
        'status',
        'applicant_type',
        'project_name',
        'token_symbol',
        'factory_token_id',
        'form_data',
        'form_hash',
        'documents_hash',
        'ownership_message',
        'ownership_signature',
        'ownership_signed_at',
        'ownership_ip',
        'agreement_version',
        'agreement_hash',
        'agreement_message',
        'agreement_signature',
        'agreement_signed_at',
        'agreement_ip',
        'agreement_user_agent',
        'submitted_at',
        'starter_allocation_bps',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    /** ส่งทั้งก้อนออก API โดยไม่ตั้งใจ ต้องไม่หลุดข้อมูลส่วนตัวที่ถอดรหัสแล้ว */
    protected $hidden = ['form_data'];

    protected function casts(): array
    {
        return [
            'form_data' => 'encrypted:array',
            'ownership_signed_at' => 'datetime',
            'agreement_signed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'starter_allocation_bps' => 'integer',
            'factory_token_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if (blank($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
            $row->wallet_address = strtolower($row->wallet_address);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CreatorRegistrationDocument::class)->orderBy('id');
    }

    /**
     * เหรียญที่ขึ้นเชนแล้วซึ่งใบนี้ผูกไว้ (ลงทะเบียนผ่านลิงก์เชิญ) — null = ใบปกติ ผูกผ่านกระเป๋า.
     */
    public function factoryToken(): BelongsTo
    {
        return $this->belongsTo(FactoryToken::class);
    }

    public function isCompany(): bool
    {
        return $this->applicant_type === self::TYPE_COMPANY;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    /** แก้ข้อมูลได้เฉพาะตอนยังไม่ส่ง หรือถูกปฏิเสธแล้ว — ใบที่รอตรวจ/อนุมัติแล้วห้ามขยับ */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function isSigned(): bool
    {
        return filled($this->agreement_signature) && $this->agreement_signed_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
