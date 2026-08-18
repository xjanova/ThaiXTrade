<?php

namespace App\Services\Kyc;

use App\Models\KycSubmission;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * TPIX TRADE — ด่านยืนยันตัวตน.
 *
 * ที่เดียวที่ตอบคำถาม "คนนี้ใช้ฟีเจอร์นี้ได้ไหม" — ทั้ง API และหน้าจอถามที่นี่
 * แยกกันตอบเมื่อไหร่ ปุ่มกับด่านจริงจะไม่ตรงกัน แล้วผู้ใช้จะเจอปุ่มกดได้แต่ยิงไม่ผ่าน
 *
 * ⚠️ ปิดปุ่มบนหน้าจอไม่ใช่การกัน — ต้องเรียก check() ใน controller ด้วยเสมอ
 *    ใครก็ยิง API ตรงได้ด้วย curl โดยไม่ผ่านหน้าเว็บของเรา
 *
 * Developed by Xman Studio.
 */
class KycGate
{
    public const SETTING_GROUP = 'kyc';

    public const KEY_ENABLED = 'kyc_enabled';

    /** ทุกคีย์ต้องขึ้นต้น kyc_ เพราะหน้าตั้งค่าหลังบ้านยุบทุกกลุ่มเป็นแมพเดียวที่คีย์ล้วน */
    public const KEY_GATE_PREFIX = 'kyc_gate_';

    public const KEY_LEVEL_PREFIX = 'kyc_level_';

    public const KEY_RETENTION = 'kyc_retention_days';

    public const KEY_CONSENT_VERSION = 'kyc_consent_version';

    /**
     * รายชื่อด่านพร้อมป้ายชื่อและสถานะปัจจุบัน — ใช้วาดหน้าตั้งค่าหลังบ้าน.
     */
    public function features(): array
    {
        $out = [];

        foreach ((array) config('kyc.features', []) as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label_th' => $meta['label_th'] ?? $key,
                'label_en' => $meta['label_en'] ?? $key,
                'desc_th' => $meta['desc_th'] ?? '',
                'enabled' => $this->requires($key),
                'level' => $this->requiredLevel($key),
            ];
        }

        return $out;
    }

    public function featureExists(string $feature): bool
    {
        return array_key_exists($feature, (array) config('kyc.features', []));
    }

    /**
     * สวิตช์ใหญ่ — ปิดอันนี้แล้วทุกด่านหยุดทำงานพร้อมกัน.
     *
     * มีไว้เพื่อกรณีระบบตรวจมีปัญหาจนคิวค้าง จะได้ปลดล็อกผู้ใช้ทั้งเว็บได้ในคลิกเดียว
     * ไม่ต้องไล่ปิดทีละด่านแล้วลืมเปิดคืน
     */
    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get(self::SETTING_GROUP, self::KEY_ENABLED, false);
    }

    /**
     * ฟีเจอร์นี้ต้องผ่าน KYC ไหม
     */
    public function requires(string $feature): bool
    {
        if (! $this->isEnabled() || ! $this->featureExists($feature)) {
            return false;
        }

        $default = (bool) (config("kyc.features.{$feature}.default", false));

        return (bool) SiteSetting::get(
            self::SETTING_GROUP,
            self::KEY_GATE_PREFIX.$feature,
            $default
        );
    }

    public function requiredLevel(string $feature): string
    {
        $default = (string) config("kyc.features.{$feature}.level", KycSubmission::LEVEL_BASIC);

        $level = (string) SiteSetting::get(
            self::SETTING_GROUP,
            self::KEY_LEVEL_PREFIX.$feature,
            $default
        );

        return in_array($level, [KycSubmission::LEVEL_BASIC, KycSubmission::LEVEL_ENHANCED], true)
            ? $level
            : KycSubmission::LEVEL_BASIC;
    }

    /**
     * ผ่านด่านนี้หรือยัง.
     *
     * ยังไม่ล็อกอิน = ไม่ผ่านเมื่อด่านเปิด เพราะเราผูกการยืนยันตัวตนกับบัญชี
     * ไม่ใช่กับกระเป๋า — กระเป๋าสร้างใหม่กี่ใบก็ได้ฟรี ด่านที่ผูกกับกระเป๋าจึงไม่มีความหมาย
     */
    public function passes(?User $user, string $feature): bool
    {
        if (! $this->requires($feature)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $this->satisfiesLevel($user, $this->requiredLevel($feature));
    }

    /**
     * ระดับที่ผ่านแล้วสูงพอสำหรับที่ขอไหม
     *
     * enhanced ครอบ basic เสมอ — ส่งเอกสารมากกว่าแล้วต้องไม่ถูกขอซ้ำ
     */
    public function satisfiesLevel(User $user, string $needed): bool
    {
        return $this->levelCovers($this->approvedLevel($user), $needed);
    }

    /**
     * ระดับสูงสุดที่ผู้ใช้คนนี้ได้รับอนุมัติแล้ว — null ถ้ายังไม่เคยผ่าน.
     *
     * ⚠️ ไม่อ่านจาก users.kyc_status เพราะคอลัมน์นั้นไม่มีระดับ
     *    และแอดมินแก้มือได้ที่หน้าสมาชิกโดยไม่มีใบคำขอรองรับ
     *    ด่านจึงต้องดูใบจริงที่ยังไม่ถูกล้างเสมอ
     */
    public function approvedLevel(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $levels = KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('status', KycSubmission::STATUS_APPROVED)
            ->whereNull('purged_at')
            ->pluck('level')
            ->all();

        if ($levels === []) {
            return null;
        }

        return in_array(KycSubmission::LEVEL_ENHANCED, $levels, true)
            ? KycSubmission::LEVEL_ENHANCED
            : KycSubmission::LEVEL_BASIC;
    }

    /**
     * ระดับที่ผ่านแล้วเทียบกับระดับที่ขอ — ใช้เมื่อมีระดับอยู่ในมือแล้ว.
     *
     * แยกออกมาเพื่อให้ statusFor() อ่านระดับจากฐานข้อมูลครั้งเดียวแล้วเทียบทุกด่าน
     * แทนที่จะยิง query ซ้ำเท่าจำนวนด่าน
     */
    private function levelCovers(?string $approved, string $needed): bool
    {
        if ($approved === null) {
            return false;
        }

        return $needed === KycSubmission::LEVEL_ENHANCED
            ? $approved === KycSubmission::LEVEL_ENHANCED
            : true;
    }

    /**
     * หาว่าคำขอนี้เป็นของใคร.
     *
     * มีสองทางเข้าที่ต่างกันสิ้นเชิง:
     *   เว็บ    — ล็อกอินปกติ มี session, $request->user() ใช้ได้เลย
     *   มือถือ/API — ไม่มี session พิสูจน์ตัวด้วยลายเซ็นกระเป๋า (VerifyWalletOwnership)
     *
     * ⚠️ ทางที่สองต้องเช็ค cache `wallet_verified:` ด้วยตัวเอง ห้ามเชื่อ wallet_address
     *    ที่ติดมากับคำขอเฉยๆ — ไม่งั้นใครก็ผ่านด่านได้ด้วยการพิมพ์เลขกระเป๋าของคนที่ KYC แล้ว
     *    นี่คือ cache ตัวเดียวกับที่ VerifyWalletOwnership ใช้ ไม่ได้ผ่อนเงื่อนไขใหม่
     */
    public function resolveUser(Request $request): ?User
    {
        if ($user = $request->user()) {
            return $user;
        }

        $wallet = $request->input('wallet_address')
            ?? $request->query('wallet_address')
            ?? $request->route('walletAddress')
            ?? $request->route('wallet_address');

        if (! is_string($wallet) || ! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return null;
        }

        $normalized = strtolower($wallet);

        $verified = Cache::get("wallet_verified:{$normalized}");

        if (! $verified || ! is_array($verified)) {
            return null;
        }

        return User::where('wallet_address', $normalized)->first();
    }

    /**
     * กันจริงในฝั่งเซิร์ฟเวอร์ — เรียกอันนี้ใน controller ก่อนทำงาน.
     *
     * @throws HttpException 403 พร้อมข้อความที่บอกได้ว่าต้องทำอะไรต่อ
     */
    public function check(?User $user, string $feature): void
    {
        if ($this->passes($user, $feature)) {
            return;
        }

        $label = (string) config("kyc.features.{$feature}.label_th", $feature);
        $level = $this->requiredLevel($feature);

        $message = $user === null
            ? "ต้องเข้าสู่ระบบและยืนยันตัวตนก่อนใช้{$label}"
            : "ต้องยืนยันตัวตนก่อนใช้{$label}";

        if ($user !== null && $level === KycSubmission::LEVEL_ENHANCED) {
            $message .= ' (ระดับเพิ่มเติม)';
        }

        abort(403, $message);
    }

    /**
     * สรุปสถานะให้หน้าจอใช้ปิด/เปิดปุ่ม
     *
     * ส่งไปทั้งชุดครั้งเดียวดีกว่าให้หน้าจอถามทีละฟีเจอร์
     */
    public function statusFor(?User $user): array
    {
        $keys = array_keys((array) config('kyc.features', []));

        /*
         * ระบบปิดอยู่ = ตอบได้โดยไม่ต้องแตะฐานข้อมูลเลย
         *
         * ก้อนนี้ถูกส่งไปกับ "ทุกหน้า" ของเว็บผ่าน Inertia
         * ถ้าไม่ลัดตรงนี้ ทุกหน้าจะยิง query หาใบ KYC ทั้งที่เจ้าของยังไม่ได้เปิดใช้ด้วยซ้ำ
         */
        if (! $this->isEnabled()) {
            return [
                'enabled' => false,
                'approved_level' => null,
                'features' => array_fill_keys($keys, [
                    'required' => false,
                    'level' => KycSubmission::LEVEL_BASIC,
                    'passed' => true,
                ]),
            ];
        }

        // อ่านจากฐานข้อมูลครั้งเดียวแล้วเทียบทุกด่านในหน่วยความจำ
        $approved = $this->approvedLevel($user);
        $features = [];

        foreach ($keys as $key) {
            $required = $this->requires($key);
            $level = $this->requiredLevel($key);

            $features[$key] = [
                'required' => $required,
                'level' => $level,
                'passed' => ! $required
                    || ($user !== null && $this->levelCovers($approved, $level)),
            ];
        }

        return [
            'enabled' => true,
            'approved_level' => $approved,
            'features' => $features,
        ];
    }
}
