<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SystemAlert Model.
 *
 * เหตุวิกฤตโครงสร้างพื้นฐาน (เชนหยุด, เซิร์ฟเวอร์เงียบ, restart ไม่สำเร็จ) —
 * แสดงเป็นคาดแดงบนทุกหน้า admin จนกว่าจะ resolved (โดยแอดมินหรือระบบหายเอง)
 *
 * เหตุ key เดิมจาก node เดิมที่ยัง active อยู่จะถูกรวมเป็นแถวเดียว (occurrences++)
 * เพื่อไม่ให้ watchdog ที่ยิงซ้ำทุกนาทีสร้างแถวท่วมตาราง
 *
 * @property int $id
 * @property string $node
 * @property string $alert_key
 * @property string $severity critical|warning|info
 * @property string $message
 * @property array|null $data
 * @property string $status active|resolved
 * @property int $occurrences
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 */
class SystemAlert extends Model
{
    /**
     * เหตุที่ heartbeat (= "ทุก check ฝั่งเชนผ่าน") ถือว่าจบแล้ว ปิดให้อัตโนมัติ —
     * ส่วน chain_restarted (warning) ค้างไว้ให้แอดมินกดรับทราบเอง จะได้รู้ว่าเคยเกิด.
     */
    public const AUTO_RESOLVE_KEYS = [
        'chain_stalled',
        'chain_down',
        'chain_restart_blocked',
        'heartbeat_missing',
    ];

    protected $fillable = [
        'node',
        'alert_key',
        'severity',
        'message',
        'data',
        'status',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // =========================================================================
    // Methods
    // =========================================================================

    /**
     * ยกเหตุขึ้น (หรือรวมเข้าเหตุ active เดิมของ node+key เดียวกัน).
     *
     * ทำใน transaction + lockForUpdate กันสอง request ชนกันสร้างแถวซ้ำ
     * ยกระดับ severity ได้ (warning → critical) แต่ไม่ลดระดับเอง
     */
    public static function raise(string $node, string $key, string $severity, string $message, ?array $data = null): self
    {
        return DB::transaction(function () use ($node, $key, $severity, $message, $data) {
            $existing = static::query()
                ->where('node', $node)
                ->where('alert_key', $key)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $escalated = $severity === 'critical' && $existing->severity !== 'critical';
                $existing->update([
                    'severity' => $escalated ? 'critical' : $existing->severity,
                    'message' => $message,
                    'data' => $data ?? $existing->data,
                    'occurrences' => $existing->occurrences + 1,
                    'last_seen_at' => now(),
                ]);

                if ($escalated) {
                    static::notifyAdmins($existing);
                }

                return $existing;
            }

            $alert = static::create([
                'node' => $node,
                'alert_key' => $key,
                'severity' => $severity,
                'message' => $message,
                'data' => $data,
                'status' => 'active',
                'occurrences' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            if ($severity === 'critical') {
                static::notifyAdmins($alert);
            }

            return $alert;
        });
    }

    /**
     * ปิดเหตุ active ของ node ตามรายการ key — คืนจำนวนที่ปิดไป.
     */
    public static function resolveKeys(string $node, array $keys, string $by = 'auto'): int
    {
        return static::query()
            ->where('node', $node)
            ->whereIn('alert_key', $keys)
            ->where('status', 'active')
            ->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by' => $by,
            ]);
    }

    /**
     * กระจายเข้ากระดิ่งแจ้งเตือนของแอดมินทุกคน (เฉพาะเหตุ critical ใหม่/ยกระดับ)
     * — คาดแดงเห็นเฉพาะตอนเปิดหน้า admin อยู่ กระดิ่งเก็บไว้ให้คนที่เพิ่งล็อกอินเห็นด้วย.
     */
    protected static function notifyAdmins(self $alert): void
    {
        $now = now();
        $rows = AdminUser::query()->pluck('id')->map(fn ($id) => [
            'admin_user_id' => $id,
            'type' => 'system_alert',
            'title' => "🔴 [{$alert->node}] {$alert->alert_key}",
            'message' => $alert->message,
            'data' => json_encode(['system_alert_id' => $alert->id]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            AdminNotification::insert($rows);
        }
    }
}
