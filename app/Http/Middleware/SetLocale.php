<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * TPIX TRADE — ตั้งภาษาของคำขอตามปุ่ม TH/EN ของผู้ใช้.
 *
 * อ่านภาษาจากสองทาง (อันแรกชนะ):
 *   1. header X-Locale — สำหรับแอปมือถือ/ไคลเอนต์ API ที่ไม่มีคุกกี้
 *   2. คุกกี้ tpix_locale — หน้าเว็บเขียนทุกครั้งที่เปลี่ยนภาษา (resources/js/Composables/useTranslation.js)
 *      ไปกับทุกคำขอเองทั้ง axios, fetch และการโหลดหน้าเต็ม
 * ไม่มีทั้งคู่ → ใช้ภาษาปริยายที่แอดมินตั้ง (ตัวเดียวกับ meta default-locale ที่หน้าเว็บใช้)
 *
 * ⚠️ ไม่อ่าน Accept-Language — เบราว์เซอร์ภาษาอังกฤษจะได้ข้อความเซิร์ฟเวอร์เป็นอังกฤษ
 *    ขณะที่หน้าเว็บยังแสดงไทยตามค่าปริยาย กลายเป็นสองภาษาปนกันในจอเดียว
 *
 * ⚠️ ลงทะเบียนเป็น global middleware (AppServiceProvider) ไม่ใช่ใน group web
 *    เพราะต้องรันก่อน EncryptCookies — คุกกี้นี้ JS เขียนเองจึงไม่ได้เข้ารหัส
 *    ถ้าอ่านหลัง EncryptCookies ค่าจะถูกล้างเป็น null ทุกครั้ง
 *
 * Developed by Xman Studio.
 */
class SetLocale
{
    public const SUPPORTED = ['th', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        foreach ([$request->header('X-Locale'), $request->cookies->get('tpix_locale')] as $candidate) {
            $code = strtolower(trim((string) $candidate));
            if (in_array($code, self::SUPPORTED, true)) {
                return $code;
            }
        }

        return $this->siteDefault();
    }

    private function siteDefault(): string
    {
        try {
            $code = Cache::remember('i18n:default-locale', 600, fn () => Language::getDefault()?->code);
        } catch (Throwable) {
            // ฐานข้อมูลล่ม/ยังไม่ migrate — อย่าให้หน้าเว็บพังเพราะเรื่องภาษา
            $code = null;
        }

        return in_array($code, self::SUPPORTED, true) ? $code : (string) config('app.locale', 'th');
    }
}
