<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — เอกสารประกอบใบลงทะเบียนผู้สร้างเหรียญ.
 *
 * ประเภทเอกสาร (TYPES):
 *   public  = เปิดให้ผู้ซื้อดูได้ (เป็นภาพลายน้ำ) เมื่อผู้สร้างติ๊กยินยอมรายการนั้น
 *   company = เฉพาะนิติบุคคล — บุคคลธรรมดาไม่ต้องมี และอัปโหลดไม่ได้
 * บัตรผู้มีอำนาจลงนามเป็นของแอดมินเท่านั้น (เจ้าของเลือก "บริษัทเปิด บัตรเฉพาะแอดมิน" 2026-11-06)
 *
 * @property int $id
 * @property string $uuid
 * @property int $creator_registration_id
 * @property string $type
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string|null $original_name
 * @property bool $publish
 * @property int|null $width
 * @property int|null $height
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationDocument extends Model
{
    public const TYPE_COMPANY_CERTIFICATE = 'company_certificate';

    public const TYPE_VAT_CERTIFICATE = 'vat_certificate';

    public const TYPE_SHAREHOLDER_LIST = 'shareholder_list';

    public const TYPE_COMPANY_OTHER = 'company_other';

    public const TYPE_PROJECT_PHOTO = 'project_photo';

    public const TYPE_SIGNATORY_ID = 'signatory_id';

    /** @var array<string, array{public: bool, company: bool, max: int}> */
    public const TYPES = [
        self::TYPE_COMPANY_CERTIFICATE => ['public' => true, 'company' => true, 'max' => 3],
        self::TYPE_VAT_CERTIFICATE => ['public' => true, 'company' => true, 'max' => 2],
        self::TYPE_SHAREHOLDER_LIST => ['public' => true, 'company' => true, 'max' => 3],
        self::TYPE_COMPANY_OTHER => ['public' => true, 'company' => true, 'max' => 4],
        self::TYPE_PROJECT_PHOTO => ['public' => true, 'company' => false, 'max' => 6],
        self::TYPE_SIGNATORY_ID => ['public' => false, 'company' => false, 'max' => 2],
    ];

    /** นิติบุคคลต้องมีอย่างน้อยหนึ่งไฟล์ของประเภทเหล่านี้ก่อนเซ็นข้อตกลง */
    public const REQUIRED_FOR_COMPANY = [self::TYPE_COMPANY_CERTIFICATE];

    public const MAX_FILES = 16;

    public const MAX_KB = 10240;

    /** MIME ที่ตรวจจากเนื้อไฟล์ (finfo) => นามสกุลที่เก็บ */
    public const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public const DISK = 'creator_documents';

    protected $fillable = [
        'uuid',
        'creator_registration_id',
        'type',
        'path',
        'mime',
        'size',
        'sha256',
        'original_name',
        'publish',
        'width',
        'height',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'publish' => 'boolean',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if (blank($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(CreatorRegistration::class, 'creator_registration_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public static function typeIsPublic(string $type): bool
    {
        return (bool) (self::TYPES[$type]['public'] ?? false);
    }

    /** เผยแพร่ได้จริงไหม — ต้องเป็นประเภทสาธารณะ เป็นภาพ (วาดลายน้ำได้) และผู้สร้างยินยอม */
    public function isPubliclyViewable(): bool
    {
        return $this->publish && self::typeIsPublic($this->type) && $this->isImage();
    }
}
