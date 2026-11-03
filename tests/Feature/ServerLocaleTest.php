<?php

namespace Tests\Feature;

use App\Models\Language;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TPIX TRADE — ภาษาของข้อความฝั่งเซิร์ฟเวอร์ตามปุ่ม TH/EN (App\Http\Middleware\SetLocale)
 * Developed by Xman Studio.
 */
class ServerLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test/locale', fn () => response()->json([
            'locale' => App::getLocale(),
            'text' => __('ต้องยืนยันตัวตนก่อนใช้:label', ['label' => 'X']),
        ]));
        // ผ่าน group web (EncryptCookies) — หน้าเว็บจริงทุกหน้าวิ่งทางนี้
        Route::middleware('web')->get('/__test/locale-web', fn () => response()->json(['locale' => App::getLocale()]));
    }

    public function test_header_selects_english(): void
    {
        $this->getJson('/__test/locale', ['X-Locale' => 'en'])
            ->assertJsonPath('locale', 'en');
    }

    public function test_plain_cookie_written_by_javascript_selects_english(): void
    {
        // คุกกี้นี้ JS เขียนเองจึงไม่ได้เข้ารหัส — ถ้าอ่านหลัง EncryptCookies ค่าจะหายเป็น null
        // getJson ส่งคุกกี้เฉพาะเมื่อ withCredentials() — เหมือน axios/fetch ที่ส่งคุกกี้ same-origin
        $this->withCredentials()
            ->withUnencryptedCookie('tpix_locale', 'en')
            ->getJson('/__test/locale')
            ->assertJsonPath('locale', 'en');
    }

    public function test_plain_cookie_survives_encrypt_cookies_on_web_routes(): void
    {
        $this->withUnencryptedCookie('tpix_locale', 'en')
            ->get('/__test/locale-web')
            ->assertJsonPath('locale', 'en');
    }

    public function test_header_wins_over_cookie(): void
    {
        $this->withCredentials()
            ->withUnencryptedCookie('tpix_locale', 'en')
            ->getJson('/__test/locale', ['X-Locale' => 'th'])
            ->assertJsonPath('locale', 'th');
    }

    public function test_unknown_value_falls_back_to_thai(): void
    {
        $this->getJson('/__test/locale', ['X-Locale' => 'fr'])
            ->assertJsonPath('locale', 'th');
    }

    public function test_no_choice_uses_admin_default_language(): void
    {
        Language::query()->update(['is_default' => false]);
        Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_default' => true, 'is_active' => true]);
        Cache::forget('i18n:default-locale');

        $this->getJson('/__test/locale')->assertJsonPath('locale', 'en');
    }

    public function test_thai_text_is_unchanged_in_thai(): void
    {
        $this->getJson('/__test/locale', ['X-Locale' => 'th'])
            ->assertJsonPath('text', 'ต้องยืนยันตัวตนก่อนใช้X');
    }

    public function test_thai_text_is_translated_in_english(): void
    {
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $this->assertArrayHasKey('ต้องยืนยันตัวตนก่อนใช้:label', $en, 'KycGate message must have an English translation');

        $this->getJson('/__test/locale', ['X-Locale' => 'en'])
            ->assertJsonPath('text', str_replace(':label', 'X', $en['ต้องยืนยันตัวตนก่อนใช้:label']));
    }
}
