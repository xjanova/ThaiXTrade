<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — บันทึกว่าใครเปิดดูเอกสารยืนยันตัวตนใบไหนเมื่อไหร่.
 *
 * PDPA ให้สิทธิเจ้าของข้อมูลถามว่าใครเข้าถึงข้อมูลเขาบ้าง ถ้าเราไม่บันทึกตอนเปิดดู
 * ก็ตอบไม่ได้ และตรวจสอบภายในไม่ได้ด้วยว่าแอดมินคนไหนเปิดดูโดยไม่มีเรื่องต้องตรวจ
 *
 * ไม่มี updated_at โดยตั้งใจ — แถวนี้คือเหตุการณ์ที่เกิดแล้ว ไม่ควรแก้ได้
 *
 * @property int $id
 * @property int $kyc_document_id
 * @property int|null $admin_user_id
 * @property Carbon $viewed_at
 *
 * Developed by Xman Studio.
 */
class KycDocumentView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'kyc_document_id',
        'admin_user_id',
        'ip',
        'user_agent',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'kyc_document_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
