<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ผู้ใช้ที่ใช้เลขกระเป๋าเป็นไอดีต้องไม่ถูกทิ้งไว้กลางทาง.
 *
 * เจ้าของสั่งให้ซ่อนปุ่ม Sign In เมื่อเชื่อมกระเป๋าแล้ว ซึ่งจะปลอดภัยก็ต่อเมื่อ
 * ผู้ใช้กระเป๋ามีทางเข้าโปรไฟล์ของตัวเองจริงๆ — ไม่งั้นซ่อนปุ่มแล้วเขาจะไปไหนไม่ได้เลย
 *
 * ขณะเดียวกัน /profile เดิมต้องยังหวงไว้เหมือนเดิม เพราะเป็นหน้าที่เปลี่ยน
 * รหัสผ่าน/อีเมล/อวาตาร์ ถ้าปลด guard เพื่อความสะดวก = เปิดช่องแก้บัญชีโดยไม่มี session
 *
 * Developed by Xman Studio.
 */
class WalletIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⭐ หน้าโปรไฟล์กระเป๋าต้องเข้าได้โดยไม่ต้องล็อกอิน.
     */
    #[Test]
    public function the_wallet_profile_is_reachable_without_a_session(): void
    {
        $this->get('/wallet')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('WalletProfile'));
    }

    /**
     * ⭐ แต่หน้าบัญชีเดิมต้องยังหวงไว้ — ไม่ใช่เปิดตามไปด้วย.
     */
    #[Test]
    public function the_account_profile_still_requires_a_session(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    #[Test]
    public function the_wallet_profile_route_is_named_for_use_in_links(): void
    {
        $this->assertSame(url('/wallet'), route('wallet.profile'));
    }
}
