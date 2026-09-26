<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — คุกกี้ "จำฉันไว้" ของสมาชิกที่ไม่มีรหัสผ่านต้องไม่ทำให้ทั้งเว็บ 500.
 *
 * สมาชิกเกือบทั้งหมดสมัครผ่าน Google/Social หรือกระเป๋า จึงไม่มีรหัสผ่าน (null)
 * และ SocialController ล็อกอินแบบจำฉันไว้เสมอ หลังอัปเกรด Laravel 12 SessionGuard
 * เอารหัสผ่านไปเทียบคุกกี้ด้วย hash_equals() ที่รับ null ไม่ได้ → พอ session หมด
 * ทุกหน้า (รวมหน้า login) ตอบ 500 จนกว่าผู้ใช้จะล้างคุกกี้เอง (เจอบน production 2026-09-26)
 *
 * Developed by Xman Studio.
 */
class RememberCookieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⭐ คุกกี้ที่ออกก่อนอัปเกรด (Laravel 11 ฝังรหัสผ่านดิบ ซึ่งว่าง) ต้องพาเข้าระบบ ไม่ใช่ 500.
     */
    #[Test]
    public function a_pre_upgrade_cookie_logs_a_passwordless_member_in(): void
    {
        $user = $this->passwordlessMember();

        $this->withRememberCookie($user->id.'|'.$user->getRememberToken().'|')
            ->get('/wallet')
            ->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    }

    /**
     * คุกกี้ที่ออกหลังอัปเกรด (ฝัง HMAC ของรหัสผ่าน) ต้องยังใช้ได้เหมือนเดิม.
     */
    #[Test]
    public function a_current_cookie_logs_a_passwordless_member_in(): void
    {
        $user = $this->passwordlessMember();
        $hash = Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());

        $this->withRememberCookie($user->id.'|'.$user->getRememberToken().'|'.$hash)
            ->get('/wallet')
            ->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    }

    /**
     * ตั้งรหัสผ่านแล้ว คุกกี้ที่ออกตอนยังไม่มีรหัสต้องหมดสิทธิ์ — การแก้นี้ต้องไม่เปิดช่อง.
     */
    #[Test]
    public function setting_a_password_retires_a_cookie_issued_without_one(): void
    {
        $user = $this->passwordlessMember();
        $user->update(['password' => 'a-brand-new-password']);

        $this->withRememberCookie($user->id.'|'.$user->getRememberToken().'|')
            ->get('/wallet')
            ->assertOk();

        $this->assertGuest('web');
    }

    /**
     * สมาชิกที่ไม่มีรหัสผ่านต้องยังล็อกอินด้วยรหัสว่างไม่ได้.
     */
    #[Test]
    public function a_passwordless_member_cannot_sign_in_with_an_empty_password(): void
    {
        $user = $this->passwordlessMember();

        $this->assertFalse(Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => '',
        ]));
    }

    private function passwordlessMember(): User
    {
        $user = User::create(['name' => 'สมาชิก Google', 'email' => 'member@tpix.test']);
        $user->setRememberToken(Str::random(60));
        $user->save();

        return $user;
    }

    private function withRememberCookie(string $value): static
    {
        return $this->withCookie(Auth::guard('web')->getRecallerName(), $value);
    }
}
