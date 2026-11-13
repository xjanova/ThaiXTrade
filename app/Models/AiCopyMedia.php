<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — ลิงก์ไลฟ์/คลิปหนึ่งรายการของมาสเตอร์ (ผู้ให้บริการ + รหัสวิดีโอ ไม่ใช่ลิงก์ดิบ).
 *
 * Developed by Xman Studio.
 */
class AiCopyMedia extends Model
{
    public const KINDS = ['live', 'clip'];

    public const STATUS_VISIBLE = 'visible';

    public const STATUS_HIDDEN = 'hidden';

    protected $table = 'ai_copy_media';

    protected $fillable = ['ai_copy_master_id', 'kind', 'provider', 'external_id', 'title', 'status', 'featured_at'];

    protected function casts(): array
    {
        return ['featured_at' => 'datetime'];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(AiCopyMaster::class, 'ai_copy_master_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VISIBLE);
    }
}
