<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — บัญชีค่าบริการ Copy Trade หนึ่งรายการ.
 *
 * ⚠️ เป็นบัญชี ไม่ใช่การโอน — `recorded` คือคำนวณไว้ให้เห็นเฉยๆ (ยังไม่เปิดเก็บ) ไม่มีเหรียญเปลี่ยนมือ
 *    ยังไม่มีโค้ดส่วนไหนเก็บ TPIX จากผู้ตามหรือจ่ายให้มาสเตอร์ สถานะ settled จึงยังไม่ถูกเขียนโดยระบบ
 *
 * Developed by Xman Studio.
 */
class AiCopyFee extends Model
{
    /** คำนวณไว้เฉยๆ — ยังไม่เปิดเก็บค่าบริการ (config aibot.copy_trade.fees.collect = false) */
    public const STATUS_RECORDED = 'recorded';

    /** เปิดเก็บแล้ว รายการนี้ค้างเก็บ */
    public const STATUS_ACCRUED = 'accrued';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'ai_copy_follow_id', 'ai_copy_master_id', 'ai_bot_trade_id', 'follower_wallet', 'master_wallet',
        'profit_usd', 'fee_rate_pct', 'platform_share_pct', 'fee_usd', 'master_usd', 'platform_usd',
        'tpix_usd_price', 'fee_tpix', 'master_tpix', 'platform_tpix', 'status', 'charged_at',
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
        ];
    }

    public function follow(): BelongsTo
    {
        return $this->belongsTo(AiCopyFollow::class, 'ai_copy_follow_id');
    }
}
