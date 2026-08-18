<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — คำขอลบข้อมูลยืนยันตัวตน (สิทธิของเจ้าของข้อมูลตาม PDPA).
 *
 * ไม่ลบทันทีที่ผู้ใช้กด เพราะบางกรณีต้องเก็บต่อตามกฎหมายอื่น
 * ทีมงานต้องพิจารณาแล้วบันทึกเหตุผลไว้ ถ้าปฏิเสธต้องบอกได้ว่าเพราะอะไร
 *
 * ⚠️ ปฏิเสธคำขอต้องมีเหตุผลตามกฎหมายจริงเท่านั้น ไม่ใช่เพราะไม่อยากลบ
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $kyc_submission_id
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon|null $handled_at
 *
 * Developed by Xman Studio.
 */
class KycDeletionRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'kyc_submission_id',
        'reason',
        'status',
        'requested_at',
        'requested_ip',
        'handled_by',
        'handled_at',
        'handler_note',
        'files_deleted',
    ];

    protected $hidden = [
        'requested_ip',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'handled_at' => 'datetime',
            'files_deleted' => 'integer',
        ];
    }

    // === Relations ===

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'handled_by');
    }

    // === Helpers ===

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function toOwnerArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'handled_at' => $this->handled_at?->toIso8601String(),
            'handler_note' => $this->handler_note,
            'files_deleted' => $this->files_deleted,
        ];
    }
}
