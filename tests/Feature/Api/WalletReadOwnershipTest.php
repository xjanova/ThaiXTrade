<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\CarbonCredit;
use App\Models\CarbonProject;
use App\Models\CarbonRetirement;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
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

    /** ข้อมูลของเจ้าของที่ห้ามหลุดไปถึงคนอื่น — ASCII ล้วน ไม่งั้น assertDontSee เทียบกับ \uXXXX ใน JSON แล้วผ่านเสมอ */
    private const OWNER_TX_HASH = '0xfeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';

    private const OWNER_TPIX_AMOUNT = 4321.5;

    private const OWNER_CREDIT_SERIAL = 'CC-OWNERONLY-20261029';

    private const OWNER_BENEFICIARY = 'Owner Beneficiary Co Ltd';

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

    /** GET ที่เลขกระเป๋าอยู่ใน path — controller อ่านกระเป๋าจาก path เสมอ ไม่ใช่จาก ?wallet_address= */
    public static function pathWalletGets(): array
    {
        return [
            'ประวัติซื้อเหรียญ' => ['/api/v1/token-sale/purchases/{wallet}'],
            'ตาราง vesting' => ['/api/v1/token-sale/vesting/{wallet}'],
            'คาร์บอนเครดิต' => ['/api/v1/carbon-credits/my-credits/{wallet}'],
            'ประวัติ retire คาร์บอน' => ['/api/v1/carbon-credits/my-retirements/{wallet}'],
        ];
    }

    /** คนแปลกหน้าพิสูจน์ได้ว่าเป็นเจ้าของกระเป๋าตัวเองจริง — ครบทั้ง 3 ทางที่ด่านรับ */
    public static function strangerProofs(): array
    {
        return [
            'ลายเซ็นจาก IP เดียวกัน' => ['signature'],
            'session ของเว็บ' => ['session'],
            'โทเคนของแอป' => ['token'],
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

    /** คนแปลกหน้าพิสูจน์กระเป๋าตัวเองด้วยวิธี $how แล้วยิงจาก OTHER_IP */
    private function asProvenStranger(string $how): static
    {
        match ($how) {
            'signature' => Cache::put('wallet_verified:'.self::STRANGER, [
                'chain_id' => 4289,
                'ip' => self::OTHER_IP,
                'verified_at' => now()->toIso8601String(),
                'signature_verified' => true,
            ], 14400),
            'session' => $this->actingAs(User::create([
                'email' => 'stranger@tpix.test',
                'password' => 'secret-password',
                'wallet_address' => self::STRANGER,
            ])),
            'token' => $this->withHeader(
                WalletSessionService::HEADER,
                app(WalletSessionService::class)->issue(self::STRANGER, 4289, self::OTHER_IP)['token'],
            ),
        };

        return $this->fromIp(self::OTHER_IP);
    }

    /** ของที่เจ้าของถือ — รายการซื้อเหรียญ (เคลม TGE ได้ 100%) คาร์บอนเครดิต และใบ retire */
    private function seedOwnerHoldings(): SaleTransaction
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
        ]);

        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Phase 1',
            'slug' => 'phase-1',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 200000000,
            'min_purchase' => 1,
            'max_purchase' => 10000000,
            'status' => 'active',
            'vesting_tge_percent' => 100,
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
        ]);

        $tx = SaleTransaction::create([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => self::OWNER,
            'payment_currency' => 'USDT',
            'payment_amount' => 216.075,
            'payment_usd_value' => 216.075,
            'tpix_amount' => self::OWNER_TPIX_AMOUNT,
            'price_per_tpix' => 0.05,
            'tx_hash' => self::OWNER_TX_HASH,
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ]);

        $project = CarbonProject::create([
            'name' => 'Owner Forest',
            'description' => 'Reforestation',
            'location' => 'Nan',
            'country' => 'TH',
            'project_type' => 'reforestation',
            'total_credits' => 1000,
            'available_credits' => 990,
            'price_per_credit_usd' => 12,
            'status' => 'active',
        ]);

        $credit = CarbonCredit::create([
            'carbon_project_id' => $project->id,
            'serial_number' => self::OWNER_CREDIT_SERIAL,
            'owner_address' => self::OWNER,
            'amount' => 5,
            'price_paid_usd' => 120,
            'status' => 'active',
        ]);

        CarbonRetirement::create([
            'carbon_credit_id' => $credit->id,
            'retiree_address' => self::OWNER,
            'beneficiary_name' => self::OWNER_BENEFICIARY,
            'amount' => 5,
            'certificate_hash' => '0x'.str_repeat('9', 64),
        ]);

        return $tx;
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

    // ── เลขกระเป๋าใน path ต้องเป็นใบเดียวกับใน query (IDOR) ───────────────

    /**
     * ช่องโหว่เดิม: ด่านเลือก ?wallet_address= ก่อน path แล้วไม่เคยเทียบสองค่า ส่วน controller อ่านจาก path
     * คนแปลกหน้าเซ็นกระเป๋าตัวเอง แล้วแนบมันใน query ของ path เหยื่อ — ด่านผ่าน ได้ข้อมูลของเหยื่อกลับไป.
     */
    #[Test]
    #[DataProvider('pathWalletGets')]
    public function แนบกระเป๋าตัวเองใน_query_อ่านกระเป๋าเหยื่อใน_path_ไม่ได้(string $url): void
    {
        $this->seedOwnerHoldings();

        $this->asProvenStranger('signature')
            ->getJson($this->url($url).'?wallet_address='.self::STRANGER)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH')
            ->assertDontSee(self::OWNER_TX_HASH)
            ->assertDontSee((string) self::OWNER_TPIX_AMOUNT)
            ->assertDontSee(self::OWNER_CREDIT_SERIAL)
            ->assertDontSee(self::OWNER_BENEFICIARY);
    }

    /** หลักฐานแบบไหนของกระเป๋าตัวเองก็ใช้เปิด path ของเหยื่อไม่ได้ */
    #[Test]
    #[DataProvider('strangerProofs')]
    public function หลักฐานของกระเป๋าตัวเองทุกแบบใช้อ่าน_path_ของเหยื่อไม่ได้(string $how): void
    {
        $this->seedOwnerHoldings();

        $this->asProvenStranger($how)
            ->getJson('/api/v1/token-sale/purchases/'.self::OWNER.'?wallet_address='.self::STRANGER)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH')
            ->assertDontSee(self::OWNER_TX_HASH);
    }

    /** ?wallet_address[]=x คู่กับ path — ห้ามเดาว่าหมายถึงใบไหน และห้ามกลายเป็น 500 */
    #[Test]
    public function wallet_address_เป็น_array_คู่กับ_path_ถือว่าไม่ตรง(): void
    {
        $this->fromIp(self::OWNER_IP)
            ->getJson('/api/v1/token-sale/purchases/'.self::OWNER.'?wallet_address[]='.self::OWNER)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');
    }

    /** เจ้าของส่งกระเป๋าตัวเองทั้งใน path และ query (แบบที่แอปบางรุ่นทำ) — ยังอ่านได้ */
    #[Test]
    #[DataProvider('pathWalletGets')]
    public function path_กับ_query_เป็นใบเดียวกันยังอ่านได้(string $url): void
    {
        $this->seedOwnerHoldings();

        $this->fromIp(self::OWNER_IP)
            ->getJson($this->url($url).'?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /** ที่อยู่แบบ checksum ใน path กับตัวเล็กใน query คือกระเป๋าใบเดียวกัน */
    #[Test]
    public function ตัวพิมพ์ใหญ่เล็กต่างกันถือเป็นกระเป๋าใบเดียวกัน(): void
    {
        $checksum = '0xABCDEFabcdefABCDEFabcdefABCDEFabcdefABCD';

        Cache::put('wallet_verified:'.strtolower($checksum), [
            'chain_id' => 4289,
            'ip' => self::OWNER_IP,
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], 14400);

        $this->fromIp(self::OWNER_IP)
            ->getJson('/api/v1/token-sale/purchases/'.$checksum.'?wallet_address='.strtolower($checksum))
            ->assertOk();
    }

    /** ข้อมูลที่ทดสอบว่าไม่หลุด ต้องเป็นของที่ path นั้นส่งให้เจ้าของจริง — ไม่งั้น assertDontSee ข้างบนผ่านเปล่าๆ */
    #[Test]
    public function เจ้าของเห็นข้อมูลที่คนแปลกหน้าไม่เห็น(): void
    {
        $this->seedOwnerHoldings();

        $this->fromIp(self::OWNER_IP)->getJson('/api/v1/token-sale/purchases/'.self::OWNER)
            ->assertOk()->assertSee(self::OWNER_TX_HASH)->assertSee((string) self::OWNER_TPIX_AMOUNT);
        $this->fromIp(self::OWNER_IP)->getJson('/api/v1/token-sale/vesting/'.self::OWNER)
            ->assertOk()->assertSee((string) self::OWNER_TPIX_AMOUNT);
        $this->fromIp(self::OWNER_IP)->getJson('/api/v1/carbon-credits/my-credits/'.self::OWNER)
            ->assertOk()->assertSee(self::OWNER_CREDIT_SERIAL);
        $this->fromIp(self::OWNER_IP)->getJson('/api/v1/carbon-credits/my-retirements/'.self::OWNER)
            ->assertOk()->assertSee(self::OWNER_BENEFICIARY);
    }

    /**
     * POST /token-sale/claim ไม่มีเลขกระเป๋าใน path — ด่านยังตรวจกระเป๋าใน body เหมือนเดิม
     * เจ้าของยังเคลมได้ ส่วนคนแปลกหน้าที่รู้ uuid ก็เคลมแทนไม่ได้ (controller ผูกรายการกับกระเป๋าที่ส่งมา).
     */
    #[Test]
    public function ยังเคลมเหรียญได้ตามปกติ_และเคลมแทนคนอื่นไม่ได้(): void
    {
        $tx = $this->seedOwnerHoldings();

        $this->asProvenStranger('signature')
            ->postJson('/api/v1/token-sale/claim', [
                'wallet_address' => self::STRANGER,
                'transaction_id' => $tx->uuid,
                'amount' => 100,
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CLAIM_ERROR');

        $this->assertSame(0, TreasuryPayout::count());

        $this->fromIp(self::OWNER_IP)
            ->postJson('/api/v1/token-sale/claim', [
                'wallet_address' => self::OWNER,
                'transaction_id' => $tx->uuid,
                'amount' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEqualsWithDelta(100.0, (float) $tx->fresh()->claimed_amount, 0.000001);

        $payout = TreasuryPayout::sole();
        $this->assertSame(self::OWNER, $payout->to_address);
        $this->assertSame('token_sale', $payout->purpose);
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
