<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Chain;
use App\Models\FactoryToken;
use App\Models\Token;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — audit_logs ต้องตอบได้ว่าแอดมินคนไหนทำ.
 *
 * guard ปริยายคือ web (สมาชิก) ส่วนแอดมินอยู่ guard admin — AuditLog::log() เดิมอ่าน auth()->id()
 * จึงได้ null ทุกแถว บันทึกการแก้ค่าตั้งหรือสั่งหยุดบอทเลยบอกไม่ได้ว่าใครทำ
 *
 * ⚠️ ล็อกอินผ่าน session แบบเบราว์เซอร์จริงเท่านั้น ห้ามใช้ actingAs($admin, 'admin')
 *    เพราะมันเรียก shouldUse('admin') สลับ guard ปริยายให้ตั้งแต่ก่อนคำขอจะเริ่ม ส่วน production
 *    สลับใน AdminAuth เท่านั้น เทสต์จะผ่านทั้งที่บั๊กยังอยู่ — เป็นเหตุที่บั๊กนี้รอดเทสต์มาตลอด
 *
 * Developed by Xman Studio.
 */
class AuditLogAdminIdTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x2112b98e3ec5a252b7b2a8f02d498b64a2186a7f';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(string $role = 'super_admin'): AdminUser
    {
        return AdminUser::create([
            'name' => $role,
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function signInAdmin(): AdminUser
    {
        $admin = $this->admin();
        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    private function signInMember(): User
    {
        $member = User::create([
            'wallet_address' => self::WALLET,
            'email' => 'member@tpix.test',
            'password' => 'secret-password',
        ]);
        $this->withSession([Auth::guard('web')->getName() => $member->id]);

        return $member;
    }

    private function stuckFactoryToken(): FactoryToken
    {
        $token = FactoryToken::create([
            'name' => 'Stuck Coin',
            'symbol' => 'STUCK',
            'total_supply' => '1000000',
            'creator_address' => self::WALLET,
            'chain_id' => 4289,
            'status' => 'deploying',
        ]);
        DB::table('factory_tokens')->where('id', $token->id)->update(['deploy_started_at' => now()->subMinutes(20)]);

        return $token;
    }

    private function listedToken(): Token
    {
        return Token::create([
            'chain_id' => Chain::where('chain_id', 4289)->firstOrFail()->id,
            'name' => 'Core Coin',
            'symbol' => 'CORE',
            'contract_address' => self::WALLET,
            'decimals' => 18,
        ]);
    }

    #[Test]
    public function แอดมินที่ล็อกอินอยู่ถูกบันทึกเป็นผู้กระทำ(): void
    {
        $admin = $this->signInAdmin();

        // ด่านกันเทสต์ผ่านหลอก — ถ้า guard ปริยายกลายเป็น admin เทสต์นี้จะพิสูจน์อะไรไม่ได้
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->assertNull(auth()->id());

        $this->assertSame($admin->id, AuditLog::log('test.action')->admin_id);
    }

    /** ไม่ได้ล็อกอินแอดมินก็ต้องไม่ล้ม — ช่องนี้ว่างได้ */
    #[Test]
    public function ไม่มีใครล็อกอินบันทึกเป็นค่าว่าง(): void
    {
        $this->assertNull(AuditLog::log('test.action')->admin_id);
    }

    /**
     * admin_id อ้างถึงตาราง admin_users — รหัสของสมาชิกต้องไม่ลงช่องนี้เด็ดขาด
     * ไม่งั้นบันทึกจะชี้ไปหาแอดมินคนที่บังเอิญมีรหัสตรงกันทั้งที่เขาไม่ได้ทำ.
     */
    #[Test]
    public function รหัสสมาชิกต้องไม่ถูกบันทึกเป็นแอดมินที่รหัสตรงกัน(): void
    {
        $bystander = $this->admin();
        $member = $this->signInMember();

        $this->assertSame($bystander->id, $member->id);
        $this->assertNull(AuditLog::log('test.action')->admin_id);
    }

    #[Test]
    public function ล็อกอินทั้งสมาชิกและแอดมินในเบราว์เซอร์เดียวกันบันทึกแอดมิน(): void
    {
        $this->signInMember();
        $this->admin('moderator');
        $admin = $this->signInAdmin();

        $this->assertNotSame(auth()->id(), $admin->id);
        $this->assertSame($admin->id, AuditLog::log('test.action')->admin_id);
    }

    /** แถวแอดมินถูกลบระหว่างที่ session ยังอยู่ — ถ้าเขียนรหัสเก่าลงไปจะชน foreign key แล้วล้มทั้งคำขอ */
    #[Test]
    public function session_ของแอดมินที่ถูกลบแล้วไม่ทำให้บันทึกล้ม(): void
    {
        $this->withSession([Auth::guard('admin')->getName() => 999999]);

        $this->assertNull(AuditLog::log('test.action')->admin_id);
    }

    /**
     * @return array<string, array{string, Closure(self): mixed}>
     */
    public static function auditedRoutes(): array
    {
        return [
            'เรียก AuditLog::log() ตรง' => ['trading_fee_tier.create', fn (self $test) => $test->post('/admin/trading-fees', [
                'min_order_usd' => 0,
                'max_order_usd' => 100,
                'fee_tpix' => 1,
                'is_active' => true,
            ])],
            'ผ่าน MoneySettings' => ['settings.revenue.update', fn (self $test) => $test->put('/admin/settings/revenue', [
                'tpix_wallet' => self::WALLET,
            ])],
            'นโยบายโรงงานเหรียญ' => ['token_factory.policy.update', fn (self $test) => $test->put('/admin/token-factory/policy', [
                'options' => ['pausable' => ['state' => 'on', 'fee' => 7]],
            ])],
            'คำสั่งโรงงานเหรียญ' => ['token_factory.reset_stuck', fn (self $test) => $test->post(
                "/admin/token-factory/{$test->stuckFactoryToken()->id}/reset-stuck"
            )],
            'รายการเหรียญ' => ['token.delete', fn (self $test) => $test->delete("/admin/tokens/{$test->listedToken()->id}")],
        ];
    }

    #[Test]
    #[DataProvider('auditedRoutes')]
    public function ทุกทางที่ลงบันทึกเก็บรหัสแอดมินจริง(string $action, Closure $request): void
    {
        $admin = $this->signInAdmin();

        $request($this)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($admin->id, AuditLog::where('action', $action)->sole()->admin_id);
    }
}
