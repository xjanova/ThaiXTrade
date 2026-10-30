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

    /** ชื่อคีย์ที่ถือเป็นความลับ — เทียบแบบกว้างไว้ก่อน คีย์ใหม่ที่ไม่มีใครนึกถึงจะได้ไม่หลุดลง log */
    private const SECRET_PATTERN = '/secret|private|password|passphrase|mnemonic|token|api_key/i';

    private const REDACTED = '[redacted]';

    /** ผู้ที่ล็อกอินอยู่แก้ค่าเงินได้หรือไม่ — ใช้ซ่อนฟอร์มเท่านั้น ด่านจริงอยู่ที่ route */
    public static function canManage(): bool
    {
        return Auth::guard('admin')->user()?->isSuperAdmin() ?? false;
    }

    /**
     * เขียนค่าเงินแล้วลงบันทึกในธุรกรรมเดียวกัน — บันทึกไม่สำเร็จเท่ากับค่าไม่เปลี่ยน.
     *
     * ล้างแคชใน finally เพราะระหว่างทาง SiteSetting::get() อาจแคชค่าที่ยังไม่ commit ไว้แล้ว
     */
    public static function change(string $action, Closure $write): void
    {
        try {
            DB::transaction(function () use ($action, $write): void {
                $before = self::snapshot();

                $write();

                [$old, $new] = self::diff($before, self::snapshot());
                self::record($action, $old, $new);
            });
        } finally {
            SiteSetting::clearCache();
        }
    }

    /** บันทึกค่าเก่า/ค่าใหม่ของการเปลี่ยนแปลงเรื่องเงินที่ไม่ได้อยู่ใน site_settings */
    public static function record(string $action, array $old, array $new): void
    {
        AuditLog::log($action, null, $old, $new);
    }

    /**
     * อ่านจากฐานข้อมูลตรง ไม่ผ่านแคช — เทียบก่อน/หลังทั้งกลุ่มจึงเห็นค่าที่ถูกกระจายต่อด้วย
     * (เช่น revenue.* ที่ถูกคัดลอกไป trading.fee_collector_wallet).
     *
     * @return array<string, string>
     */
    private static function snapshot(): array
    {
        return SiteSetting::whereIn('group', self::GROUPS)
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
