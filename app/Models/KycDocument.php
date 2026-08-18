<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * TPIX TRADE — ไฟล์แนบของใบคำขอยืนยันตัวตน.
 *
 * แถวนี้เก็บแค่ที่อยู่ไฟล์ ตัวไฟล์อยู่บน disk `kyc` ซึ่งไม่มี URL สาธารณะ
 * อ่านได้ทางเดียวคือผ่าน controller ที่ตรวจสิทธิ์แล้วบันทึกการเปิดดู
 *
 * @property int $id
 * @property int $kyc_submission_id
 * @property string $type
 * @property string $disk
 * @property string $path
 * @property string|null $sha256
 * @property Carbon|null $purged_at
 *
 * Developed by Xman Studio.
 */
class KycDocument extends Model
{
    use HasFactory;

    public const TYPE_ID_FRONT = 'id_card_front';

    public const TYPE_ID_BACK = 'id_card_back';

    public const TYPE_SELFIE = 'selfie_with_id';

    public const TYPE_ADDRESS = 'address_proof';

    public const TYPE_BANK_BOOK = 'bank_book';

    /**
     * ประเภทที่ต้องมีครบถึงจะยื่นได้ แยกตามระดับ.
     *
     * ไม่ขอหน้าสมุดบัญชีในระดับปกติ — เราไม่ได้รับโอนเงินบาทให้ใคร
     * ขอข้อมูลที่ไม่ได้ใช้ = เก็บความเสี่ยงไว้เปล่าๆ ซึ่ง PDPA ก็ห้ามอยู่แล้ว
     */
    public const REQUIRED_BY_LEVEL = [
        KycSubmission::LEVEL_BASIC => [
            self::TYPE_ID_FRONT,
            self::TYPE_SELFIE,
        ],
        KycSubmission::LEVEL_ENHANCED => [
            self::TYPE_ID_FRONT,
            self::TYPE_ID_BACK,
            self::TYPE_SELFIE,
            self::TYPE_ADDRESS,
        ],
    ];

    public const ALL_TYPES = [
        self::TYPE_ID_FRONT,
        self::TYPE_ID_BACK,
        self::TYPE_SELFIE,
        self::TYPE_ADDRESS,
        self::TYPE_BANK_BOOK,
    ];

    protected $fillable = [
        'kyc_submission_id',
        'type',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'sha256',
        'purged_at',
    ];

    /**
     * path คือที่อยู่จริงบนดิสก์ — ไม่มีเหตุผลให้หลุดไปฝั่งเบราว์เซอร์.
     */
    protected $hidden = [
        'path',
        'disk',
        'sha256',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    // === Relations ===

    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(KycDocumentView::class);
    }

    // === Helpers ===

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function exists(): bool
    {
        return ! $this->isPurged() && Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * ลบไฟล์จริงออกจากดิสก์แล้วทำเครื่องหมายไว้.
     *
     * คืนค่า true เฉพาะเมื่อลบไฟล์ได้จริงในรอบนี้ ผู้เรียกจะได้นับจำนวนถูก
     * ไฟล์ที่หายไปก่อนแล้วยังถือว่าล้างสำเร็จ (ปลายทางที่ต้องการคือ "ไม่มีไฟล์")
     */
    public function purgeFile(): bool
    {
        if ($this->isPurged()) {
            return false;
        }

        $disk = Storage::disk($this->disk);
        $deleted = false;

        if ($disk->exists($this->path)) {
            $disk->delete($this->path);
            $deleted = true;
        }

        $this->forceFill(['purged_at' => now()])->save();

        return $deleted;
    }

    /**
     * สิ่งที่เจ้าของข้อมูลเห็นเกี่ยวกับไฟล์ตัวเอง — ไม่มี path.
     */
    public function toOwnerArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'original_name' => $this->original_name,
            'size' => $this->size,
            'mime' => $this->mime,
            'purged' => $this->isPurged(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
