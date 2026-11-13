<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — บัญชีค่าบริการ Copy Trade หนึ่งรายการ.
 *
 * `recorded` คือคำนวณไว้ให้เห็นเฉยๆ (ตอนนั้นยังไม่เปิดเก็บ) ไม่มีเหรียญเปลี่ยนมือ และไม่ถูกเก็บย้อนหลัง
 * `accrued` → `settled` เขียนโดย FeeCollector เมื่อหักจากคลัง TPIX ของผู้ตามและลงส่วนของมาสเตอร์สำเร็จ
 *
 * Developed by Xman Studio.
 */
class AiCopyFee extends Model
{
    /** คำนวณไว้เฉยๆ — ยังไม่เปิดเก็บค่าบริการ (config aibot.copy_trade.fees.collect = false) */
    public const STATUS_RECORDED = 'recorded';

    /** เปิดเก็บแล้ว รายการนี้ค้างเก็บ (ยอดในคลังของผู้ตามยังไม่พอ หรือยังไม่ถึงรอบเก็บ) */
    public const STATUS_ACCRUED = 'accrued';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'ai_copy_follow_id', 'ai_copy_master_id', 'ai_bot_trade_id', 'follower_wallet', 'master_wallet',
        'profit_usd', 'fee_rate_pct', 'platform_share_pct', 'fee_usd', 'master_usd', 'platform_usd',
        'tpix_usd_price', 'fee_tpix', 'master_tpix', 'platform_tpix', 'status', 'charged_at', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'profit_usd' => 'float',
            'fee_rate_pct' => 'float',
            'platform_share_pct' => 'float',
            'fee_usd' => 'float',
            'master_usd' => 'float',
            'platform_usd' => 'float',
            'tpix_usd_price' => 'float',
            'fee_tpix' => 'float',
            'master_tpix' => 'float',
            'platform_tpix' => 'float',
            'charged_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function follow(): BelongsTo
    {
        return $this->belongsTo(AiCopyFollow::class, 'ai_copy_follow_id');
    }
}
