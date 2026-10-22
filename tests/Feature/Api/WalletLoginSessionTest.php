<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Elliptic\EC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use kornrunner\Keccak;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เข้าสู่ระบบด้วยกระเป๋า (สแกน/เปิดแอป TPIX Wallet) แล้วต้อง "ค้างอยู่" บนมือถือ.
 *
 * อาการที่เจ้าของเจอ: เชื่อมกระเป๋าจากมือถือแล้วไม่ติด — สลับแอป/รีโหลด/กลับมาวันหลังก็หลุด
 * ฝั่งเซิร์ฟเวอร์มีสองสาเหตุ:
 *   1. ด่าน VerifyWalletOwnership ดูแต่แคช `wallet_verified:` + IP ตอนเซ็น — มือถือเปลี่ยน IP ตลอด
 *      (Wi-Fi ↔ 4G, CGNAT) กดอะไรก็ 403 WALLET_IP_MISMATCH และพ้น 4 ชั่วโมงก็ WALLET_NOT_VERIFIED
 *      ทั้งที่ session ของเว็บยังเข้าสู่ระบบอยู่
 *   2. ต้องจำการเข้าสู่ระบบข้ามการปิดเบราว์เซอร์ได้ (คุกกี้จำฉันไว้)
 *
 * ชุดนี้ยิงผ่าน endpoint จริงพร้อมลายเซ็นจริง (EIP-191 personal_sign แบบที่แอปกระเป๋าเซ็น)
 *
 * Developed by Xman Studio.
 */
class WalletLoginSessionTest extends TestCase
{
    use RefreshDatabase;

    /** กุญแจสำหรับทดสอบเท่านั้น — ไม่เคยถือเงินและไม่มีอยู่บนเชนไหน */
    private const PRIVATE_KEY = '4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    /** กุญแจทดสอบอีกใบ (คนละเจ้าของ) */
    private const OTHER_KEY = '8da4ef21b864d2cc526dbdb2a120bd2874c36c9d0a1fb7f8c63d7f7a8b41de8f';

    private const PHONE_IP_WIFI = '203.0.113.10';

    private const PHONE_IP_4G = '198.51.100.77';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        if (! class_exists(Keccak::class)) {
            $this->markTestSkipped('ต้องมี kornrunner/keccak ถึงจะเซ็นข้อความทดสอบได้');
        }

        // ทำตัวเป็นหน้าเว็บของเราเอง — session ของ /api/* มาจาก Sanctum ที่ดู Referer (ดู WalletSignInTest)
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Referer', 'http://localhost/trade');
    }

    private function addressOf(string $privateKey): string
    {
        $publicKey = (new EC('secp256k1'))->keyFromPrivate($privateKey)->getPublic(false, 'hex');

        return '0x'.substr(Keccak::hash(hex2bin(substr($publicKey, 2)), 256), -40);
    }

    /**
     * เซ็นแบบ personal_sign (EIP-191).
     *
     * @param  int  $vBase  27 = รูปแบบ MetaMask/ethers · 0 = กระเป๋าบางตัวส่ง v ดิบ 0/1
     */
    private function sign(string $message, string $privateKey = self::PRIVATE_KEY, int $vBase = 27): string
    {
        $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
        $signature = (new EC('secp256k1'))->keyFromPrivate($privateKey)->sign($hash, ['canonical' => true]);

        return '0x'
            .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad(dechex($signature->recoveryParam + $vBase), 2, '0', STR_PAD_LEFT);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** ขอข้อความ → เซ็น → ยืนยัน (แบบที่หน้าเว็บทำหลังแอปกระเป๋าตอบกลับมาทาง WalletConnect) */
    private function signIn(string $privateKey = self::PRIVATE_KEY, int $vBase = 27): TestResponse
    {
        $address = $this->addressOf($privateKey);
        $challenge = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])
            ->assertOk()
            ->json('data');

        return $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->sign($challenge['message'], $privateKey, $vBase),
            'nonce' => $challenge['nonce'],
            'chain_id' => 4289,
            'wallet_type' => 'walletconnect',
        ]);
    }

    private function updateProfile(string $address): TestResponse
    {
        return $this->putJson('/api/v1/wallet/profile', [
            'wallet_address' => $address,
            'name' => 'นักเทรดมือถือ',
        ]);
    }

    // ── เข้าสู่ระบบด้วยลายเซ็น ─────────────────────────────────────────────

    #[Test]
    public function เซ็นผ่านแล้วได้_session_พร้อมคุกกี้จำฉันไว้(): void
    {
        $response = $this->signIn()
            ->assertOk()
            ->assertJsonPath('data.signed_in', true)
            ->assertJsonPath('data.verified', true);

        // กลับมาเปิดเว็บวันหลัง (session 2 ชั่วโมงหมดไปแล้ว) ต้องยังเข้าสู่ระบบอยู่
        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticated();
        $this->assertSame($this->addressOf(self::PRIVATE_KEY), auth()->user()->wallet_address);
    }

    /** กระเป๋าบางตัวส่ง v เป็น 0/1 แทน 27/28 — ต้องผ่านเหมือนกัน ไม่งั้นผู้ใช้เซ็นถูกแต่เข้าไม่ได้ */
    #[Test]
    public function รับลายเซ็นที่_v_เป็น_0_หรือ_1(): void
    {
        $this->signIn(self::PRIVATE_KEY, 0)
            ->assertOk()
            ->assertJsonPath('data.signed_in', true);
    }

    /** เซ็นด้วยกุญแจใบอื่นแต่อ้างว่าเป็นกระเป๋านี้ (บัญชีในแอปไม่ตรงกับที่เชื่อม) */
    #[Test]
    public function ลายเซ็นจากกุญแจใบอื่นถูกปฏิเสธ(): void
    {
        $address = $this->addressOf(self::PRIVATE_KEY);
        $challenge = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])->json('data');

        $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->sign($challenge['message'], self::OTHER_KEY),
            'nonce' => $challenge['nonce'],
        ])->assertStatus(403)->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertGuest();
        $this->assertNull(Cache::get("wallet_verified:{$address}"));
    }

    /** nonce ผูกกับกระเป๋าที่ขอ — เอา nonce ของอีกใบมาใช้ไม่ได้ */
    #[Test]
    public function ใช้_nonce_ของกระเป๋าใบอื่นไม่ได้(): void
    {
        $other = $this->addressOf(self::OTHER_KEY);
        $otherChallenge = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $other])->json('data');

        $address = $this->addressOf(self::PRIVATE_KEY);
        $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->sign($otherChallenge['message']),
            'nonce' => $otherChallenge['nonce'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_NONCE');

        $this->assertGuest();
    }

    /** ขอข้อความใหม่ = ข้อความเก่าใช้ไม่ได้ (กดเชื่อมซ้ำแล้วไปเซ็นอันเก่าในแอป) */
    #[Test]
    public function ขอข้อความใหม่แล้วลายเซ็นของข้อความเก่าใช้ไม่ได้(): void
    {
        $address = $this->addressOf(self::PRIVATE_KEY);
        $old = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])->json('data');
        $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])->assertOk();

        $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->sign($old['message']),
            'nonce' => $old['nonce'],
        ])->assertStatus(422);
    }

    // ── session ต้องพาผ่านด่าน VerifyWalletOwnership ───────────────────────

    /** อาการหลักบนมือถือ: เซ็นตอนอยู่ Wi-Fi แล้วกดต่อตอนสลับเป็น 4G */
    #[Test]
    public function เปลี่ยน_ip_หลังเซ็นแล้วยังเขียนได้ด้วย_session(): void
    {
        $this->fromIp(self::PHONE_IP_WIFI)->signIn()->assertOk();
        $address = $this->addressOf(self::PRIVATE_KEY);

        $this->fromIp(self::PHONE_IP_4G)->updateProfile($address)
            ->assertOk()
            ->assertJsonPath('data.name', 'นักเทรดมือถือ');
    }

    /** แคช 4 ชั่วโมงหมดอายุ (หรือถูกล้างตอน deploy) แต่ยังเข้าสู่ระบบอยู่ → ไม่ต้องไปเซ็นในแอปใหม่ */
    #[Test]
    public function แคชลายเซ็นหมดอายุแล้ว_session_ยังพาผ่าน(): void
    {
        $this->signIn()->assertOk();
        $address = $this->addressOf(self::PRIVATE_KEY);

        Cache::forget("wallet_verified:{$address}");

        $this->getJson("/api/v1/wallet/profile?wallet_address={$address}")->assertOk();
        $this->updateProfile($address)->assertOk();
    }

    /** ไม่มี session (เช่นสคริปต์ภายนอก) ยังต้องเจอด่าน IP แบบเดิม — ทางลัดเป็นของ session เท่านั้น */
    #[Test]
    public function ไม่มี_session_แคชอย่างเดียวเปลี่ยน_ip_ยังถูกปฏิเสธ(): void
    {
        $this->fromIp(self::PHONE_IP_WIFI)->signIn()->assertOk();
        $address = $this->addressOf(self::PRIVATE_KEY);

        auth()->logout();

        $this->fromIp(self::PHONE_IP_4G)->updateProfile($address)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_IP_MISMATCH');
    }

    /** เข้าสู่ระบบอยู่ก็จริง แต่ขอข้อมูลของกระเป๋าใบอื่น — ห้ามได้ทางลัด */
    #[Test]
    public function session_ไม่พาผ่านเมื่อขอกระเป๋าใบอื่น(): void
    {
        $mine = $this->addressOf(self::PRIVATE_KEY);
        $victim = $this->addressOf(self::OTHER_KEY);
        User::create(['email' => 'victim@tpix.test', 'password' => 'secret-password', 'wallet_address' => $victim]);

        $this->actingAs(User::create(['email' => 'me@tpix.test', 'password' => 'secret-password', 'wallet_address' => $mine]));

        $this->getJson("/api/v1/wallet/profile?wallet_address={$victim}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
        $this->updateProfile($victim)->assertStatus(403);
    }

    /** ผู้ใช้ที่ล็อกอินด้วยอีเมลแต่ยังไม่ได้ผูกกระเป๋า — ไม่ได้ทางลัดสำหรับกระเป๋าใด ๆ */
    #[Test]
    public function session_ที่ยังไม่ผูกกระเป๋าไม่พาผ่าน(): void
    {
        $address = $this->addressOf(self::PRIVATE_KEY);
        User::create(['wallet_address' => $address]);
        $this->actingAs(User::create(['email' => 'nowallet@tpix.test', 'password' => 'secret-password']));

        $this->getJson("/api/v1/wallet/profile?wallet_address={$address}")->assertStatus(403);
    }

    /** ถูกแบนหลังเข้าสู่ระบบไปแล้ว — session เดิมต้องใช้ผ่านด่านไม่ได้ */
    #[Test]
    public function บัญชีถูกแบนแล้ว_session_ไม่พาผ่าน(): void
    {
        $address = $this->addressOf(self::PRIVATE_KEY);
        $user = User::create(['wallet_address' => $address, 'is_banned' => true]);
        $this->actingAs($user);

        $this->getJson("/api/v1/wallet/profile?wallet_address={$address}")->assertStatus(403);
    }

    /** ตัดการเชื่อมต่อ = ออกจากระบบ + ล้างแคช → API ของกระเป๋าใช้ไม่ได้ทันที */
    #[Test]
    public function ตัดการเชื่อมต่อแล้วใช้_api_ของกระเป๋าไม่ได้อีก(): void
    {
        $this->signIn()->assertOk();
        $address = $this->addressOf(self::PRIVATE_KEY);

        $this->postJson('/api/v1/wallet/disconnect', ['wallet_address' => $address])
            ->assertOk()
            ->assertJsonPath('data.signed_out', true);

        $this->assertGuest();
        $this->getJson("/api/v1/wallet/profile?wallet_address={$address}")->assertStatus(403);
    }

    /** ตัวพิมพ์เล็กใหญ่ของเลขกระเป๋า (checksum) ต้องไม่ทำให้ session หลุดด่าน */
    #[Test]
    public function เลขกระเป๋าแบบ_checksum_ผ่านด้วย_session(): void
    {
        $this->signIn()->assertOk();
        $address = $this->addressOf(self::PRIVATE_KEY);
        $mixedCase = '0x'.strtoupper(substr($address, 2, 20)).substr($address, 22);

        $this->getJson("/api/v1/wallet/profile?wallet_address={$mixedCase}")->assertOk();
    }
}
