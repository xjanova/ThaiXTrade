<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\User;
use App\Services\Kyc\KycGate;
use App\Services\WalletSessionService;
use Elliptic\EC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use kornrunner\Keccak;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — คำขออ่าน (GET) ข้อมูลของกระเป๋าต้องพิสูจน์ว่า "คนที่ถาม" เป็นเจ้าของ เหมือนคำขอเขียน.
 *
 * ช่องโหว่ที่ปิด: เดิม GET ในกลุ่มที่ต้องยืนยันกระเป๋าผ่านแค่มีแถว `wallet_verified:{address}` ในแคช
 * แถวนั้นผูกกับเลขกระเป๋า ไม่ได้ผูกกับคนที่ถาม — 4 ชั่วโมงหลังเจ้าของเซ็น ใครรู้เลขกระเป๋า (เห็นได้บนเชน)
 * ก็ใส่ ?wallet_address= แล้วอ่านอีเมล สถานะ KYC ประวัติเทรด ยอดเงินของเขาได้ (ข้อมูลส่วนบุคคล — PDPA)
 *
 * หลักฐานที่ยังรับ: session ของเว็บที่บัญชีผูกกระเป๋าใบนั้น · โทเคน X-Wallet-Session ของแอป ·
 * แคชลายเซ็นจาก IP เดียวกับตอนเซ็น (แอปรุ่นก่อนมีโทเคน, เว็บที่ session ไม่ติด) — เงื่อนไขเดียวกับคำขอเขียน
 *
 * Developed by Xman Studio.
 */
class WalletReadOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const STRANGER = '0x2222222222222222222222222222222222222222';

    /** IP ของเจ้าของตอนเซ็น */
    private const OWNER_IP = '203.0.113.10';

    /** IP อื่น — คนแปลกหน้า หรือเจ้าของเองหลังสลับ Wi-Fi เป็น 4G */
    private const OTHER_IP = '198.51.100.77';

    /** กุญแจสำหรับทดสอบเท่านั้น — ไม่เคยถือเงินและไม่มีอยู่บนเชนไหน (ชุดเดียวกับ WalletLoginSessionTest) */
    private const PRIVATE_KEY = '4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    /**
     * GET ทุกเส้นที่อยู่หลัง VerifyWalletOwnership → URL ที่ใช้ยิง ({wallet} = กระเป๋าที่ถูกขอ).
     *
     * เพิ่ม GET ใหม่ในกลุ่มต้องเพิ่มที่นี่ด้วย — ทุก_get_หลังด่านกระเป๋าอยู่ในรายการนี้ จับให้
     * food-passport เคยอ่าน ?address= ที่ด่านมองไม่เห็น — ทางนั้นทดสอบไว้ใน FoodPassportIoTAuthTest
     */
    private const PROTECTED_GETS = [
        'api/v1/token-sale/purchases/{walletAddress}' => '/api/v1/token-sale/purchases/{wallet}',
        'api/v1/token-sale/vesting/{walletAddress}' => '/api/v1/token-sale/vesting/{wallet}',
        'api/v1/trading/orders' => '/api/v1/trading/orders?wallet_address={wallet}',
        'api/v1/trading/order/{orderId}' => '/api/v1/trading/order/1?wallet_address={wallet}',
        'api/v1/trading/history' => '/api/v1/trading/history?wallet_address={wallet}',
        'api/v1/trading/fee-info' => '/api/v1/trading/fee-info?wallet_address={wallet}',
        'api/v1/wallet/balances' => '/api/v1/wallet/balances?wallet_address={wallet}',
        'api/v1/wallet/transactions' => '/api/v1/wallet/transactions?wallet_address={wallet}',
        'api/v1/wallet/profile' => '/api/v1/wallet/profile?wallet_address={wallet}',
        'api/v1/kyc/status' => '/api/v1/kyc/status?wallet_address={wallet}',
        'api/v1/swap/routes' => '/api/v1/swap/routes?wallet_address={wallet}',
        'api/v1/token-factory/my-tokens' => '/api/v1/token-factory/my-tokens?wallet_address={wallet}',
        'api/v1/food-passport/my-products' => '/api/v1/food-passport/my-products?wallet_address={wallet}',
        'api/v1/food-passport/iot/my-devices' => '/api/v1/food-passport/iot/my-devices?wallet_address={wallet}',
        'api/v1/carbon-credits/my-credits/{walletAddress}' => '/api/v1/carbon-credits/my-credits/{wallet}',
        'api/v1/carbon-credits/my-retirements/{walletAddress}' => '/api/v1/carbon-credits/my-retirements/{wallet}',
        'api/v1/masternode/my-nodes' => '/api/v1/masternode/my-nodes?wallet_address={wallet}',
        'api/v1/ai-bot/status' => '/api/v1/ai-bot/status?wallet_address={wallet}',
        'api/v1/ai-bot/credits' => '/api/v1/ai-bot/credits?wallet_address={wallet}',
        'api/v1/ai-bot/bots' => '/api/v1/ai-bot/bots?wallet_address={wallet}',
        'api/v1/ai-bot/decisions' => '/api/v1/ai-bot/decisions?wallet_address={wallet}',
        'api/v1/ai-bot/analytics' => '/api/v1/ai-bot/analytics?wallet_address={wallet}',
        'api/v1/ai-bot/demo' => '/api/v1/ai-bot/demo?wallet_address={wallet}',
        'api/v1/ai-bot/trades' => '/api/v1/ai-bot/trades?wallet_address={wallet}',
        'api/v1/ai-bot/wallet' => '/api/v1/ai-bot/wallet?wallet_address={wallet}',
        'api/v1/trading-fee/balance' => '/api/v1/trading-fee/balance?wallet_address={wallet}',
        'api/v1/ai/insights/{symbol}' => '/api/v1/ai/insights/BTC?wallet_address={wallet}',
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->owner = User::create([
            'wallet_address' => self::OWNER,
            'email' => 'owner@tpix.test',
            'password' => 'secret-password',
            'name' => 'เจ้าของกระเป๋า',
            'kyc_status' => 'approved',
        ]);

        // เจ้าของเพิ่งเซ็นจากมือถือของตัวเอง — แถวเดียวกับที่ WalletController::verifySignature เขียน
        Cache::put('wallet_verified:'.self::OWNER, [
            'chain_id' => 4289,
            'ip' => self::OWNER_IP,
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], 14400);
    }

    public static function protectedGets(): array
    {
        return array_map(fn (string $url) => [$url], self::PROTECTED_GETS);
    }

    /** เส้นที่อ่านแต่ฐานข้อมูล (ไม่ยิงเชน/AI ภายนอก) ครบทุกรูปแบบที่ส่งเลขกระเป๋ามา */
    public static function ownerReads(): array
    {
        return [
            'โปรไฟล์ (อีเมล สถานะ KYC ค่าตั้ง)' => ['/api/v1/wallet/profile?wallet_address={wallet}'],
            'ประวัติธุรกรรม' => ['/api/v1/wallet/transactions?wallet_address={wallet}'],
            'คำสั่งซื้อขายที่เปิดอยู่' => ['/api/v1/trading/orders?wallet_address={wallet}'],
            'ประวัติเทรด' => ['/api/v1/trading/history?wallet_address={wallet}'],
            'สถานะ KYC' => ['/api/v1/kyc/status?wallet_address={wallet}'],
            'มาสเตอร์โหนดของฉัน' => ['/api/v1/masternode/my-nodes?wallet_address={wallet}'],
            'เหรียญที่สร้าง' => ['/api/v1/token-factory/my-tokens?wallet_address={wallet}'],
            'ประวัติซื้อเหรียญ (เลขกระเป๋าใน path)' => ['/api/v1/token-sale/purchases/{wallet}'],
            'ตาราง vesting (เลขกระเป๋าใน path)' => ['/api/v1/token-sale/vesting/{wallet}'],
            'คาร์บอนเครดิต (เลขกระเป๋าใน path)' => ['/api/v1/carbon-credits/my-credits/{wallet}'],
        ];
    }

    private function url(string $template, string $wallet = self::OWNER): string
    {
        return str_replace('{wallet}', $wallet, $template);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    // ── คนแปลกหน้าที่รู้แค่เลขกระเป๋า ─────────────────────────────────────

    #[Test]
    #[DataProvider('protectedGets')]
    public function คนแปลกหน้าที่รู้แค่เลขกระเป๋าอ่านไม่ได้(string $url): void
    {
        $this->fromIp(self::OTHER_IP)
            ->getJson($this->url($url))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_IP_MISMATCH')
            ->assertDontSee('owner@tpix.test');
    }

    /** route ใหม่ที่เพิ่มเข้ากลุ่มโดยไม่มีเทสต์ = อาจกลับไปรั่วแบบเดิมโดยไม่มีใครรู้ */
    #[Test]
    public function ทุก_get_หลังด่านกระเป๋าอยู่ในรายการนี้(): void
    {
        $guarded = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('GET', $route->methods(), true)
                && in_array(VerifyWalletOwnership::class, $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => $route->uri())
            ->sort()
            ->values()
            ->all();

        $listed = collect(self::PROTECTED_GETS)->keys()->sort()->values()->all();

        $this->assertSame($guarded, $listed, 'GET หลัง VerifyWalletOwnership ต้องอยู่ใน PROTECTED_GETS ทุกเส้น');
    }

    #[Test]
    public function ยังไม่เคยเซ็นเลย_อ่านไม่ได้(): void
    {
        Cache::forget('wallet_verified:'.self::OWNER);

        $this->fromIp(self::OWNER_IP)
            ->getJson('/api/v1/wallet/profile?wallet_address='.self::OWNER)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
    }

    /** เข้าสู่ระบบด้วยบัญชีของตัวเองอยู่ก็จริง แต่ขอกระเป๋าคนอื่นที่เพิ่งเซ็นไป */
    #[Test]
    public function session_ของบัญชีตัวเองใช้อ่านกระเป๋าคนอื่นไม่ได้(): void
    {
        $this->actingAs(User::create([
            'email' => 'stranger@tpix.test',
            'password' => 'secret-password',
            'wallet_address' => self::STRANGER,
        ]));

        $this->fromIp(self::OTHER_IP)
            ->getJson('/api/v1/wallet/profile?wallet_address='.self::OWNER)
            ->assertStatus(403)
            ->assertDontSee('owner@tpix.test');
    }

    #[Test]
    public function โทเคนของกระเป๋าตัวเองใช้อ่านกระเป๋าคนอื่นไม่ได้(): void
    {
        $token = app(WalletSessionService::class)->issue(self::STRANGER, 4289, self::OTHER_IP)['token'];

        $this->fromIp(self::OTHER_IP)
            ->withHeader(WalletSessionService::HEADER, $token)
            ->getJson('/api/v1/trading/history?wallet_address='.self::OWNER)
            ->assertStatus(403);
    }

    /** ยิงตรงเข้า origin ไม่ผ่าน Cloudflare — header ของพร็อกซีจากต้นทางที่ไม่ได้เชื่อถือต้องไม่มีผล */
    #[Test]
    public function ปลอม_x_forwarded_for_เป็น_ip_ของเจ้าของไม่ช่วย(): void
    {
        $this->fromIp(self::OTHER_IP)
            ->withHeader('X-Forwarded-For', self::OWNER_IP)
            ->getJson('/api/v1/wallet/profile?wallet_address='.self::OWNER)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_IP_MISMATCH');
    }

    // ── เจ้าของต้องยังอ่านได้ ───────────────────────────────────────────

    /** เว็บ: เข้าสู่ระบบด้วยบัญชีที่ผูกกระเป๋าใบนี้ — สลับ Wi-Fi เป็น 4G แล้วก็ยังอ่านได้ */
    #[Test]
    #[DataProvider('ownerReads')]
    public function เจ้าของบนเว็บอ่านได้ด้วย_session_แม้_ip_เปลี่ยน(string $url): void
    {
        $this->actingAs($this->owner);

        $this->fromIp(self::OTHER_IP)->getJson($this->url($url))->assertOk();
    }

    /** แอป: โทเคน X-Wallet-Session ของกระเป๋าใบนี้ — ไม่ผูก IP */
    #[Test]
    #[DataProvider('ownerReads')]
    public function แอปของเจ้าของอ่านได้ด้วยโทเคนแม้_ip_เปลี่ยน(string $url): void
    {
        $token = app(WalletSessionService::class)->issue(self::OWNER, 4289, self::OWNER_IP)['token'];

        $this->fromIp(self::OTHER_IP)
            ->withHeader(WalletSessionService::HEADER, $token)
            ->getJson($this->url($url))
            ->assertOk();
    }

    /** แอปรุ่นก่อนมีโทเคน / เว็บที่ session ไม่ติด — มีแค่ลายเซ็น อ่านได้จาก IP เดิมเหมือนที่เขียนได้ */
    #[Test]
    public function มีแค่ลายเซ็นยังอ่านได้จาก_ip_เดียวกับตอนเซ็น(): void
    {
        $this->fromIp(self::OWNER_IP)
            ->getJson('/api/v1/wallet/profile?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@tpix.test');

        $this->fromIp(self::OWNER_IP)
            ->getJson('/api/v1/token-sale/purchases/'.self::OWNER)
            ->assertOk();
    }

    /** เส้นทางจริงทั้งเส้น: เซ็นผ่าน verify-signature แบบไม่มี session (แบบ TPIX Wallet/แอปรุ่นเก่า) */
    #[Test]
    public function เซ็นจริงแล้วอ่านได้เฉพาะจาก_ip_ที่เซ็น(): void
    {
        if (! class_exists(Keccak::class)) {
            $this->markTestSkipped('ต้องมี kornrunner/keccak ถึงจะเซ็นข้อความทดสอบได้');
        }

        $publicKey = (new EC('secp256k1'))->keyFromPrivate(self::PRIVATE_KEY)->getPublic(false, 'hex');
        $address = '0x'.substr(Keccak::hash(hex2bin(substr($publicKey, 2)), 256), -40);

        $challenge = $this->fromIp(self::OWNER_IP)
            ->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])
            ->assertOk()
            ->json('data');

        $this->fromIp(self::OWNER_IP)->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->personalSign($challenge['message']),
            'nonce' => $challenge['nonce'],
            'chain_id' => 4289,
        ])->assertOk()->assertJsonPath('data.signed_in', false);

        $this->fromIp(self::OTHER_IP)
            ->getJson("/api/v1/trading/history?wallet_address={$address}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_IP_MISMATCH');

        $this->fromIp(self::OWNER_IP)
            ->getJson("/api/v1/trading/history?wallet_address={$address}")
            ->assertOk();
    }

    // ── ด่าน KYC หาบัญชีจากเลขกระเป๋าด้วยเงื่อนไขเดียวกัน ──────────────────

    #[Test]
    public function ด่าน_kyc_ไม่ยกบัญชีเจ้าของให้คนแปลกหน้า(): void
    {
        $gate = app(KycGate::class);

        $stranger = Request::create('/api/v1/kyc/status', 'GET', ['wallet_address' => self::OWNER], server: ['REMOTE_ADDR' => self::OTHER_IP]);
        $this->assertNull($gate->resolveUser($stranger));

        $owner = Request::create('/api/v1/kyc/status', 'GET', ['wallet_address' => self::OWNER], server: ['REMOTE_ADDR' => self::OWNER_IP]);
        $this->assertSame($this->owner->id, $gate->resolveUser($owner)?->id);
    }

    /** EIP-191 personal_sign — แบบเดียวกับที่กระเป๋ามือถือเซ็น */
    private function personalSign(string $message): string
    {
        $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
        $signature = (new EC('secp256k1'))->keyFromPrivate(self::PRIVATE_KEY)->sign($hash, ['canonical' => true]);

        return '0x'
            .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
            .dechex(27 + (int) $signature->recoveryParam);
    }
}
