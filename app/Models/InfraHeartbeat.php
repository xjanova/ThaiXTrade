<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * InfraHeartbeat Model.
 *
 * สัญญาณชีพจาก watchdog ของแต่ละเครื่องในโครงสร้างพื้นฐาน (ยิงทุก 1 นาที
 * เฉพาะตอนทุก check ฝั่งนั้นผ่าน) — ถ้าขาดเกินเกณฑ์ scheduler จะยกเหตุ
 * heartbeat_missing เป็นคาดแดง เพราะแปลว่าทั้งเครื่อง/เน็ต/watchdog ดับ
 *
 * @property int $id
 * @property string $node
 * @property int $last_block
 * @property Carbon|null $last_seen_at
 */
class InfraHeartbeat extends Model
{
    protected $fillable = [
        'node',
        'last_block',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_block' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
