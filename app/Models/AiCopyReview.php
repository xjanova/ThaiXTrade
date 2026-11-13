<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — ดาวรีวิวของผู้ตามหนึ่งคนต่อมาสเตอร์หนึ่งคน (หนึ่งรีวิว แก้ได้).
 *
 * `comment` เป็นข้อความล้วน — ถูกตัดแท็กตอนบันทึกและหน้าเว็บแสดงแบบ escape เสมอ
 * (แอปนี้เคยมีเหตุ stored JS ผ่านไฟล์ SVG ข้อความจากผู้ใช้ทุกชิ้นถือว่าเป็นของอันตราย)
 *
 * Developed by Xman Studio.
 */
class AiCopyReview extends Model
{
    public const STATUS_VISIBLE = 'visible';

    /** ทีมงานซ่อน — ไม่โชว์ ไม่นับในค่าเฉลี่ย */
    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = ['ai_copy_master_id', 'reviewer_wallet', 'stars', 'comment', 'status', 'reports'];

    protected function casts(): array
    {
        return ['stars' => 'integer', 'reports' => 'integer'];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(AiCopyMaster::class, 'ai_copy_master_id');
    }

    public function reportRows(): HasMany
    {
        return $this->hasMany(AiCopyReviewReport::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VISIBLE);
    }
}
