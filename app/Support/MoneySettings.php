<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\SiteSetting;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * ค่าตั้งที่กำหนดว่าเงินไปไหน — กระเป๋ารับรายได้ คีย์ Stripe ค่าธรรมเนียม.
 *
 * ทุกปลายทางที่เขียนกลุ่มเหล่านี้ต้องมี `admin.role:super_admin` ใน routes/admin.php
 * และต้องเขียนผ่าน change() เพื่อให้ audit_logs ตอบได้ว่าใครเปลี่ยนจากอะไรเป็นอะไร
 * (middleware admin.audit เก็บแค่ชื่อ route — สลับกระเป๋ารับเงินแล้วไม่เหลือร่องรอยว่าสลับเป็นใบไหน)
 *
 * change() ใช้กับกลุ่มอื่นในหน้าตั้งค่าด้วย (security, ai, map ...) — ปิด Turnstile หรือสลับ URL ปลายทางของคีย์
 * ต้องตามร่องรอยได้เหมือนกัน แต่ด่านสิทธิ์ของกลุ่มเหล่านั้นอยู่ที่ route ของแต่ละกลุ่ม ไม่ได้อยู่ในคลาสนี้
 *
 * Developed by Xman Studio.
 */
class MoneySettings
{
    /** กลุ่มใน site_settings ที่แก้ได้เฉพาะ super_admin */
    public const GROUPS = ['trading', 'payment', 'factory', 'revenue'];

    /** กลุ่มที่แอดมินระดับล่างไม่ได้รับค่าเลย — 'stripe' คือกลุ่มสำรองที่ StripePaymentService ยังอ่านอยู่ */
    public const HIDDEN_GROUPS = [...self::GROUPS, 'stripe'];

    /** ช่องกระเป๋ารับเงินของแต่ละกลุ่ม */
    public const WALLET_KEYS = [
        'trading' => ['fee_collector_wallet', 'tpix_topup_wallet'],
        'factory' => ['fee_wallet'],
        'revenue' => ['wallet_address', 'tpix_wallet', 'wtpix_wallet'],
    ];

    /**
     * ชื่อคีย์ที่ถือเป็นความลับ — เทียบแบบกว้างไว้ก่อน คีย์ใหม่ที่ไม่มีใครนึกถึงจะได้ไม่หลุดลง log.
     *
     * ลงท้าย _key นับเป็นความลับทั้งหมด (cloudflare_image_key, map_tile_key) ยกเว้นคีย์สาธารณะที่แสดงบนหน้าเว็บอยู่แล้ว
     */
    private const SECRET_PATTERN = '/secret|private|password|passphrase|mnemonic|token|api_key|(?<!public|site)_key$/i';

    private const REDACTED = '[redacted]';

    /** ผู้ที่ล็อกอินอยู่แก้ค่าเงินได้หรือไม่ — ใช้ซ่อนฟอร์มเท่านั้น ด่านจริงอยู่ที่ route */
    public static function canManage(): bool
    {
        return Auth::guard('admin')->user()?->isSuperAdmin() ?? false;
    }

    /**
     * เขียนค่าแล้วลงบันทึกในธุรกรรมเดียวกัน — บันทึกไม่สำเร็จเท่ากับค่าไม่เปลี่ยน.
     *
     * ล้างแคชใน finally เพราะระหว่างทาง SiteSetting::get() อาจแคชค่าที่ยังไม่ commit ไว้แล้ว
     *
     * @param  array<int, string>  $groups  กลุ่มที่เทียบก่อน/หลัง — ต้องครอบทุกกลุ่มที่ $write เขียน
     */
    public static function change(string $action, Closure $write, array $groups = self::GROUPS): void
    {
        try {
            DB::transaction(function () use ($action, $write, $groups): void {
                $before = self::snapshot($groups);

                $write();

                [$old, $new] = self::diff($before, self::snapshot($groups));
                self::record($action, $old, $new);
            });
        } finally {
            SiteSetting::clearCache();
        }
    }

    /**
     * ลง audit_logs ด้วย guard admin — AuditLog::log() อ่าน guard ปริยาย (web)
     * ซึ่งเป็น null สำหรับแอดมิน ทำให้ไม่รู้ว่าใครเปลี่ยน.
     */
    public static function record(string $action, array $old, array $new): void
    {
        AuditLog::create([
            'admin_id' => Auth::guard('admin')->id(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * อ่านจากฐานข้อมูลตรง ไม่ผ่านแคช — เทียบก่อน/หลังทั้งกลุ่มจึงเห็นค่าที่ถูกกระจายต่อด้วย
     * (เช่น revenue.* ที่ถูกคัดลอกไป trading.fee_collector_wallet).
     *
     * @param  array<int, string>  $groups
     * @return array<string, string>
     */
    private static function snapshot(array $groups): array
    {
        return SiteSetting::whereIn('group', $groups)
            ->get()
            ->mapWithKeys(fn (SiteSetting $setting) => ["{$setting->group}.{$setting->key}" => (string) $setting->value])
            ->all();
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? '';

            if ($previous !== $value) {
                $old[$key] = self::mask($key, $previous);
                $new[$key] = self::mask($key, $value);
            }
        }

        return [$old, $new];
    }

    /** คีย์ลับบันทึกแค่ว่ามีค่าหรือว่าง ไม่เก็บค่าจริงแม้แต่บางส่วน — แถวที่เปลี่ยนจะอยู่ใน diff อยู่แล้ว */
    private static function mask(string $key, string $value): string
    {
        return $value !== '' && preg_match(self::SECRET_PATTERN, $key) ? self::REDACTED : $value;
    }
}
