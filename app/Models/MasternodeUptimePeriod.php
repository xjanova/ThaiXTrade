<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MasternodeUptimePeriod — ผล uptime ของกระเป๋าหนึ่งในรอบหนึ่ง (ปกติ 1 ชั่วโมง).
 *
 * status
 *   shadow     คำนวณแล้วแต่ไม่ส่ง — เชนยังไม่ได้ตั้งกุญแจของเราเป็น uptimeOracle (หรือสั่ง --dry-run)
 *   unchanged  ค่าบนเชนเท่ากับค่าที่จะตั้งอยู่แล้ว ไม่ต้องส่ง
 *   submitted  ส่งธุรกรรมแล้ว รอขึ้นบล็อก
 *   confirmed  ขึ้นบล็อกแล้วและสำเร็จ
 *   failed     ส่งไม่ได้ หรือธุรกรรม revert (ดู error)
 *
 * Developed by Xman Studio
 */
class MasternodeUptimePeriod extends Model
{
    public const SHADOW = 'shadow';

    public const UNCHANGED = 'unchanged';

    public const SUBMITTED = 'submitted';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    protected $table = 'masternode_uptime_periods';

    protected $fillable = [
        'wallet_address',
        'period_start',
        'slots_expected',
        'slots_ok',
        'uptime_bps',
        'onchain_before_bps',
        'target_bps',
        'degraded',
        'status',
        'tx_hash',
        'error',
    ];

    protected $casts = [
        'period_start' => 'integer',
        'slots_expected' => 'integer',
        'slots_ok' => 'integer',
        'uptime_bps' => 'integer',
        'onchain_before_bps' => 'integer',
        'target_bps' => 'integer',
        'degraded' => 'boolean',
    ];
}
