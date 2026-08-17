<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Elliptic\EC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use kornrunner\Keccak;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เซ็นกระเป๋าแล้วได้ session ของเว็บจริง.
 *
 * เดิมการเซ็นสำเร็จได้แค่แถวในแคช `wallet_verified:` ซึ่ง API ของบอทใช้ได้
 * แต่ฝั่งเว็บยังมองว่าเป็นคนแปลกหน้า — `auth.user` เป็น null ตลอด ผู้ใช้กระเป๋า
 * จึงเข้าหน้าโปรไฟล์ไม่ได้และตั้งค่าอะไรไม่ได้เลย
 *
 * ชุดนี้เดินผ่าน endpoint จริงพร้อมลายเซ็นจริง (ไม่ mock ecrecover) เพราะจุดที่
 * พังได้คือรอยต่อระหว่าง middleware · session · guard ซึ่งเทสต์ระดับ service มองไม่เห็น
 *
 * Developed by Xman Studio.
 */
class WalletSignInTest extends TestCase
{
    use RefreshDatabase;

    /** กุญแจสำหรับทดสอบเท่านั้น — ไม่เคยถือเงินและไม่มีอยู่บนเชนไหน */
    private const PRIVATE_KEY = '4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        if (! class_exists(Keccak::class)) {
            $this->markTestSkipped('ต้องมี kornrunner/keccak ถึงจะเซ็นข้อความทดสอบได้');
        }

        /*
         * ทำตัวเป็นหน้าเว็บของเราเองที่ยิงมา
         *
         * session ของเส้นทาง /api/* มาจาก Sanctum ซึ่งดู Referer/Origin เทียบกับ
         * `sanctum.stateful` — ไม่ส่งหัวข้อนี้ = ถูกมองเป็นสคริปต์ภายนอก ไม่มี session
         * ให้ใช้ ซึ่งเป็นพฤติกรรมที่ถูกต้องแต่ไม่ใช่สิ่งที่เบราว์เซอร์จริงเจอ
         *
         * ถ้าเทสต์นี้พังเพราะ signed_in เป็น false ให้สงสัย SANCTUM_STATEFUL_DOMAINS
         * กับ APP_URL ก่อนเสมอ — เป็นจุดที่พังเงียบได้บนโปรดักชันเหมือนกัน
         */
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Referer', 'http://localhost/trade');
    }

    /** ที่อยู่ที่ได้จากกุญแจทดสอบด้านบน */
    private function address(): string
    {
        $publicKey = (new EC('secp256k1'))->keyFromPrivate(self::PRIVATE_KEY)
            ->getPublic(false, 'hex');

        // ตัด prefix 04 ออกก่อน hash ตามมาตรฐาน Ethereum แล้วเอา 20 ไบต์ท้าย
        return '0x'.substr(Keccak::hash(hex2bin(substr($publicKey, 2)), 256), -40);
    }

    /** เซ็นข้อความแบบเดียวกับที่กระเป๋าทำ (EIP-191 personal_sign) */
    private function sign(string $message): string
    {
        $prefixed = "\x19Ethereum Signed Message:\n".strlen($message).$message;
        $hash = Keccak::hash($prefixed, 256);

        $signature = (new EC('secp256k1'))
            ->keyFromPrivate(self::PRIVATE_KEY)
            ->sign($hash, ['canonical' => true]);

        return '0x'
            .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
            .dechex($signature->recoveryParam + 27);
    }

    /** ขอ nonce → เซ็น → ส่งกลับไปยืนยัน */
    private function completeSignIn(): TestResponse
    {
        $address = $this->address();

        $message = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])
            ->assertOk()
            ->json('data');

        return $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->sign($message['message']),
            'nonce' => $message['nonce'],
            'chain_id' => 4289,
            'wallet_type' => 'tpix_wallet',
        ]);
    }

    #[Test]
    public function เซ็นสำเร็จแล้วได้_session_ของเว็บ(): void
    {
        $this->completeSignIn()
            ->assertOk()
            ->assertJsonPath('data.signed_in', true)
            ->assertJsonPath('data.link_result', 'signed_in');

        $this->assertAuthenticated();
        $this->assertSame($this->address(), auth()->user()->wallet_address);
    }

    /** session ต้องอยู่ต่อในคำขอถัดไป ไม่ใช่แค่ในคำขอที่เซ็น */
    #[Test]
    public function เข้าหน้าโปรไฟล์ได้หลังเซ็น(): void
    {
        $this->completeSignIn();

        $this->get('/profile')->assertOk();
    }

    /** ลายเซ็นผิดต้องไม่ได้อะไรเลย — ไม่ใช่แค่ไม่ผ่านการยืนยัน */
    #[Test]
    public function ลายเซ็นผิดไม่ได้_session(): void
    {
        $address = $this->address();
        $nonce = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])
            ->json('data.nonce');

        $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => '0x'.str_repeat('11', 65),
            'nonce' => $nonce,
        ])->assertStatus(403);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /** nonce ใช้ได้ครั้งเดียว — ดักลายเซ็นเดิมมายิงซ้ำต้องไม่ผ่าน */
    #[Test]
    public function ลายเซ็นเดิมใช้ซ้ำไม่ได้(): void
    {
        $address = $this->address();
        $message = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])->json('data');
        $signature = $this->sign($message['message']);

        $payload = [
            'wallet_address' => $address,
            'signature' => $signature,
            'nonce' => $message['nonce'],
        ];

        $this->postJson('/api/v1/wallet/verify-signature', $payload)->assertOk();

        // ล้าง session แล้วยิงซ้ำด้วยของเดิม
        auth()->logout();
        $this->postJson('/api/v1/wallet/verify-signature', $payload)->assertStatus(422);
    }

    /** ผู้ใช้ที่ล็อกอินด้วยอีเมลอยู่แล้ว เซ็นกระเป๋าแล้วต้องผูกเข้าบัญชีเดิม */
    #[Test]
    public function ล็อกอินด้วยอีเมลแล้วเซ็นกระเป๋าได้บัญชีเดียว(): void
    {
        $user = User::create([
            'email' => 'trader@tpix.test',
            'password' => 'secret-password',
            'name' => 'นักเทรด',
        ]);

        $this->actingAs($user);

        $this->completeSignIn()
            ->assertOk()
            ->assertJsonPath('data.link_result', 'linked')
            ->assertJsonPath('data.user_id', $user->id);

        $this->assertSame($this->address(), $user->fresh()->wallet_address);
        $this->assertSame(1, User::count());
    }

    /** กระเป๋าเป็นของบัญชีอื่นแล้ว — ต้องปฏิเสธพร้อมบอกทางออก ไม่ใช่ 500 */
    #[Test]
    public function ผูกกระเป๋าที่เป็นของบัญชีอื่นได้_409_พร้อมเหตุผล(): void
    {
        User::create([
            'email' => 'owner@tpix.test',
            'password' => 'secret-password',
            'wallet_address' => $this->address(),
        ]);

        $attacker = User::create(['email' => 'attacker@tpix.test', 'password' => 'secret-password']);
        $this->actingAs($attacker);

        $this->completeSignIn()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WALLET_TAKEN');

        $this->assertNull($attacker->fresh()->wallet_address);
    }

    /** ออกจากระบบต้องตัดสิทธิ์ฝั่ง API ของกระเป๋าด้วย ไม่ใช่ปล่อยค้างอีก 4 ชั่วโมง */
    #[Test]
    public function ออกจากระบบแล้วสิทธิ์ของกระเป๋าหมดทันที(): void
    {
        $this->completeSignIn();
        $address = $this->address();

        $this->assertNotNull(Cache::get("wallet_verified:{$address}"));

        $this->post('/logout')->assertRedirect();

        $this->assertGuest();
        $this->assertNull(Cache::get("wallet_verified:{$address}"));
    }

    /** ตัดการเชื่อมต่อกระเป๋าที่เป็นทางเข้าเดียว = ออกจากระบบด้วย */
    #[Test]
    public function ตัดการเชื่อมต่อกระเป๋าแล้วออกจากระบบตาม(): void
    {
        $this->completeSignIn();

        $this->postJson('/api/v1/wallet/disconnect', ['wallet_address' => $this->address()])
            ->assertOk()
            ->assertJsonPath('data.signed_out', true);

        $this->assertGuest();
    }

    /**
     * แต่ผู้ใช้ที่ล็อกอินด้วยรหัสผ่านต้องไม่ถูกเตะออกเพราะถอดกระเป๋า.
     *
     * เขาเข้ามาด้วยรหัสผ่าน การตัดการเชื่อมต่อกระเป๋าเป็นคนละเรื่องกัน
     */
    #[Test]
    public function ผู้ใช้ที่มีรหัสผ่านไม่ถูกเตะออกตอนตัดการเชื่อมต่อ(): void
    {
        $user = User::create(['email' => 'trader@tpix.test', 'password' => 'secret-password']);
        $this->actingAs($user);
        $this->completeSignIn();

        $this->postJson('/api/v1/wallet/disconnect', ['wallet_address' => $this->address()])
            ->assertOk()
            ->assertJsonPath('data.signed_out', false);

        $this->assertAuthenticated();
    }
}
