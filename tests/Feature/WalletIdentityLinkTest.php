<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\WalletIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — กระเป๋ากับบัญชีเป็นตัวตนเดียวกัน.
 *
 * เดิมระบบมีทางเข้าสองทางที่ไม่รู้จักกัน: สมัครด้วยอีเมล กับ เชื่อมกระเป๋า
 * ใครล็อกอินด้วยอีเมลแล้วเชื่อมกระเป๋าจะได้บัญชีใหม่แยกอีกใบเงียบๆ ประวัติเทรด
 * บอท และค่าตั้งแตกเป็นสองก้อนโดยไม่มีอะไรบอก
 *
 * ⚠️ ชุดนี้คุมทางที่ผิดพลาดแล้วเสียหายถาวร:
 *    ยึดกระเป๋าของคนอื่น · ถอดกระเป๋าจนล็อกตัวเองออกจากบัญชี · บัญชีที่ถูกแบนได้ session
 *
 * Developed by Xman Studio.
 */
class WalletIdentityLinkTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_WALLET = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private WalletIdentityService $identities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identities = app(WalletIdentityService::class);
    }

    private function emailUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => 'trader@tpix.test',
            'password' => 'secret-password',
            'name' => 'นักเทรด',
        ], $attributes));
    }

    /** บัญชีที่ระบบสร้างให้ตอนใครสักคนกดเชื่อมกระเป๋า — ไม่มีใครล็อกอินเข้าไปได้ */
    private function shellUser(string $wallet): User
    {
        return User::create(['wallet_address' => $wallet]);
    }

    private function link(?User $sessionUser, string $wallet = self::WALLET): array
    {
        return $this->identities->linkVerified($sessionUser, $wallet, 4289, 'metamask', '127.0.0.1');
    }

    // ── เข้าสู่ระบบด้วยกระเป๋า ────────────────────────────────────────────────

    #[Test]
    public function เซ็นครั้งแรกได้บัญชีใหม่พร้อมเข้าสู่ระบบ(): void
    {
        $result = $this->link(null);

        $this->assertSame(WalletIdentityService::RESULT_SIGNED_IN, $result['result']);
        $this->assertSame(self::WALLET, $result['user']->wallet_address);
    }

    #[Test]
    public function เซ็นครั้งถัดไปได้บัญชีเดิมไม่ใช่บัญชีใหม่(): void
    {
        $first = $this->link(null)['user'];
        $second = $this->link(null)['user'];

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::count());
    }

    /** แบนแล้วต้องไม่มีทางกลับเข้ามาได้ ไม่ว่าเซ็นถูกแค่ไหน */
    #[Test]
    public function บัญชีที่ถูกแบนเซ็นถูกก็ยังเข้าไม่ได้(): void
    {
        $this->shellUser(self::WALLET)->ban('ปั่นราคา');

        $result = $this->link(null);

        $this->assertSame(WalletIdentityService::ERR_BANNED, $result['error']);
        $this->assertNull($result['user']);
    }

    // ── ผูกกระเป๋าเข้าบัญชีที่ล็อกอินอยู่ ───────────────────────────────────

    /** อาการหลักที่ต้องแก้: ล็อกอินด้วยอีเมลแล้วเชื่อมกระเป๋า ต้องไม่แตกเป็นสองบัญชี */
    #[Test]
    public function ล็อกอินด้วยอีเมลแล้วผูกกระเป๋าไม่สร้างบัญชีใหม่(): void
    {
        $user = $this->emailUser();

        $result = $this->link($user);

        $this->assertSame(WalletIdentityService::RESULT_LINKED, $result['result']);
        $this->assertSame($user->id, $result['user']->id);
        $this->assertSame(self::WALLET, $user->fresh()->wallet_address);
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function ผูกกระเป๋าใบเดิมซ้ำไม่เปลี่ยนอะไร(): void
    {
        $user = $this->emailUser(['wallet_address' => self::WALLET]);

        $result = $this->link($user);

        $this->assertSame(WalletIdentityService::RESULT_ALREADY, $result['result']);
        $this->assertSame(self::WALLET, $user->fresh()->wallet_address);
    }

    /**
     * ยุบบัญชีเปล่าเข้าบัญชีจริงได้ — ไม่ใช่การยึดบัญชีของใคร.
     *
     * บัญชีเปล่าเกิดจาก /wallet/connect ซึ่งใครยิงเลขกระเป๋าอะไรมาก็สร้างให้
     * ไม่มีอีเมล ไม่มีรหัสผ่าน ไม่มีโซเชียล = ไม่มีใครล็อกอินเข้าไปได้เลย
     * ส่วนคนที่ขอผูกก็เพิ่งพิสูจน์ด้วยลายเซ็นแล้วว่าคุมกระเป๋าใบนี้จริง
     */
    #[Test]
    public function ยุบบัญชีเปล่าที่ค้างอยู่กับกระเป๋าเข้าบัญชีจริง(): void
    {
        $shell = $this->shellUser(self::WALLET);
        WalletConnection::create([
            'user_id' => $shell->id,
            'wallet_address' => self::WALLET,
            'chain_id' => 56,
            'wallet_type' => 'metamask',
            'is_primary' => true,
            'connected_at' => now()->subDay(),
        ]);

        $user = $this->emailUser();
        $result = $this->link($user);

        $this->assertSame(WalletIdentityService::RESULT_ADOPTED, $result['result']);
        $this->assertSame(self::WALLET, $user->fresh()->wallet_address);
        $this->assertSoftDeleted('users', ['id' => $shell->id]);

        // ประวัติการเชื่อมต่อต้องย้ายตามมา ไม่หายไปกับบัญชีที่ถูกยุบ
        $this->assertSame(
            0,
            WalletConnection::where('user_id', $shell->id)->count(),
            'ประวัติเก่ายังค้างอยู่กับบัญชีที่ถูกยุบ',
        );
        $this->assertGreaterThan(0, WalletConnection::where('user_id', $user->id)->count());
    }

    /** ⚠️ ทางที่ผิดแล้วเสียหายถาวร: ยึดบัญชีคนอื่นด้วยการผูกกระเป๋าของเขา */
    #[Test]
    public function ยึดกระเป๋าของบัญชีที่ล็อกอินได้จริงไม่ได้(): void
    {
        $victim = $this->emailUser([
            'email' => 'victim@tpix.test',
            'wallet_address' => self::WALLET,
        ]);
        $attacker = $this->emailUser(['email' => 'attacker@tpix.test']);

        $result = $this->link($attacker);

        $this->assertSame(WalletIdentityService::ERR_WALLET_TAKEN, $result['error']);
        $this->assertNull($attacker->fresh()->wallet_address);
        $this->assertSame(self::WALLET, $victim->fresh()->wallet_address);
    }

    /** บัญชีที่มีแค่โซเชียล (ไม่มีรหัสผ่าน) ก็เป็นบัญชีจริง ยึดไม่ได้เหมือนกัน */
    #[Test]
    public function บัญชีที่ผูกโซเชียลไว้ไม่ถือว่าเป็นบัญชีเปล่า(): void
    {
        $owner = User::create(['wallet_address' => self::WALLET]);
        SocialAccount::create([
            'user_id' => $owner->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);

        $result = $this->link($this->emailUser());

        $this->assertSame(WalletIdentityService::ERR_WALLET_TAKEN, $result['error']);
    }

    /**
     * สลับกระเป๋าให้เงียบๆ ไม่ได้ ต้องถอดใบเดิมก่อน.
     *
     * ผู้ใช้ที่เผลอสลับบัญชีในกระเป๋าจะเซ็นด้วยใบใหม่โดยไม่ตั้งใจ ถ้าระบบย้ายให้เลย
     * เขาจะเสียทางเข้าเดิมไปโดยไม่รู้ตัว
     */
    #[Test]
    public function บัญชีที่ผูกกระเป๋าไว้แล้วผูกใบที่สองไม่ได้(): void
    {
        $user = $this->emailUser(['wallet_address' => self::WALLET]);

        $result = $this->identities->linkVerified($user, self::OTHER_WALLET, 4289, 'metamask', null);

        $this->assertSame(WalletIdentityService::ERR_ALREADY_LINKED, $result['error']);
        $this->assertSame(self::WALLET, $user->fresh()->wallet_address);
    }

    // ── ถอดกระเป๋า ───────────────────────────────────────────────────────────

    #[Test]
    public function ถอดกระเป๋าได้เมื่อยังมีรหัสผ่านไว้เข้า(): void
    {
        $user = $this->emailUser(['wallet_address' => self::WALLET]);

        $this->assertTrue($this->identities->unlink($user)['ok']);
        $this->assertNull($user->fresh()->wallet_address);
    }

    /** ⚠️ ถอดแล้วล็อกตัวเองออกถาวร — บัญชีไม่มีอีเมลให้ส่งลิงก์กู้คืนด้วยซ้ำ */
    #[Test]
    public function ถอดกระเป๋าที่เป็นทางเข้าเดียวไม่ได้(): void
    {
        $user = $this->shellUser(self::WALLET);

        $result = $this->identities->unlink($user);

        $this->assertFalse($result['ok']);
        $this->assertSame('ONLY_SIGN_IN_METHOD', $result['error']);
        $this->assertSame(self::WALLET, $user->fresh()->wallet_address);
    }

    /** มีอีเมลแต่ยังไม่ได้ตั้งรหัสผ่าน = ยังล็อกอินไม่ได้ ถอดไม่ได้เหมือนกัน */
    #[Test]
    public function มีอีเมลแต่ไม่มีรหัสผ่านก็ยังถอดกระเป๋าไม่ได้(): void
    {
        $user = User::create([
            'wallet_address' => self::WALLET,
            'email' => 'no-password@tpix.test',
        ]);

        $this->assertFalse($this->identities->unlink($user)['ok']);
    }

    #[Test]
    public function ถอดกระเป๋าแล้วปิดประวัติการเชื่อมต่อที่ยังเปิดอยู่(): void
    {
        $user = $this->emailUser(['wallet_address' => self::WALLET]);
        WalletConnection::create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'chain_id' => 4289,
            'wallet_type' => 'metamask',
            'is_primary' => true,
            'connected_at' => now(),
        ]);

        $this->identities->unlink($user);

        $this->assertSame(
            0,
            WalletConnection::where('user_id', $user->id)->whereNull('disconnected_at')->count(),
        );
    }

    // ── /wallet/connect ห้ามผูกอะไรเอง ──────────────────────────────────────

    /**
     * ⚠️ ปลายทางที่ไม่พิสูจน์อะไรเลย ห้ามแตะบัญชีที่ล็อกอินอยู่.
     *
     * ถ้า connect ผูกกระเป๋าให้ ใครก็ยิง POST ด้วยเลขกระเป๋าของเหยื่อเข้าบัญชี
     * ตัวเองได้ทันที แล้วสวมสิทธิ์เป็นเจ้าของกระเป๋านั้น
     */
    #[Test]
    public function การเชื่อมกระเป๋าเฉยๆ_ไม่ผูกเข้าบัญชีที่ล็อกอินอยู่(): void
    {
        $user = $this->emailUser();

        $this->identities->resolveForConnect($user, self::WALLET, 4289, 'metamask', null);

        $this->assertNull($user->fresh()->wallet_address);
    }

    /** และต้องไม่สร้างบัญชีเปล่าค้างไว้ทุกครั้งที่ผู้ใช้ที่ล็อกอินแล้วกดเชื่อมกระเป๋า */
    #[Test]
    public function การเชื่อมกระเป๋าตอนล็อกอินอยู่ไม่สร้างบัญชีเปล่า(): void
    {
        $user = $this->emailUser();

        $this->identities->resolveForConnect($user, self::WALLET, 4289, 'metamask', null);

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function การเชื่อมกระเป๋าตอนยังไม่ล็อกอินยังสร้างบัญชีให้เหมือนเดิม(): void
    {
        $user = $this->identities->resolveForConnect(null, self::WALLET, 4289, 'metamask', null);

        $this->assertSame(self::WALLET, $user->wallet_address);
    }
}
