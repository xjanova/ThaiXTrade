<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Models\TradingFeeTier;
use App\Rules\EvmAddress;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — ค่าที่กำหนดว่าเงินไปไหน แก้ได้เฉพาะ super_admin และต้องมีร่องรอย.
 *
 * ผลตรวจเส้นทางเงิน (C4): แอดมินทุกระดับสลับกระเป๋ารับรายได้ คีย์ Stripe และกระเป๋ารับเงินรอบขายได้
 * โดยไม่มีการตรวจที่อยู่ และ audit_logs เก็บแค่ชื่อ route — ตอบไม่ได้ว่าเปลี่ยนจากใบไหนเป็นใบไหน
 *
 * Developed by Xman Studio.
 */
class MoneySettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x2112b98e3ec5a252b7b2a8f02d498b64a2186a7f';

    private const OLD_WALLET = '0x0b263d083969946fa2bb44af2deba69d3d3d0220';

    private const STRIPE_SECRET = 'sk_live_51NewSecretValueZZ9Q';

    private const OLD_STRIPE_SECRET = 'sk_live_51OldSecretValueYY8P';

    private const WEBHOOK_SECRET = 'whsec_NewWebhookValueXX7R';

    /** ทุกช่องกระเป๋าที่ปลายทางรวม updateTab รับ */
    private const WALLET_FIELDS = [
        ['/admin/settings/revenue', 'revenue', 'tpix_wallet'],
        ['/admin/settings/revenue', 'revenue', 'wtpix_wallet'],
        ['/admin/settings/revenue', 'revenue', 'wallet_address'],
        ['/admin/settings/trading', 'trading', 'fee_collector_wallet'],
        ['/admin/settings/trading', 'trading', 'tpix_topup_wallet'],
        ['/admin/settings/factory', 'factory', 'fee_wallet'],
    ];

    private const TOPUP_SETTINGS = [
        'tpix_fee_enabled' => true,
        'tpix_topup_wallet' => self::WALLET,
        'tpix_topup_chain_id' => 4289,
        'tpix_min_topup' => 25,
        'refund_gas_fee' => 0.5,
        'ticket_ttl_minutes' => 20,
    ];

    private const SALE = [
        'name' => 'รอบทดสอบ',
        'slug' => 'test-round',
        'total_supply_for_sale' => 1000,
        'status' => 'draft',
        'sale_wallet_address' => self::WALLET,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function adminWithRole(string $role): AdminUser
    {
        return AdminUser::create([
            'name' => $role,
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function asRole(string $role): self
    {
        $this->actingAs($this->adminWithRole($role), 'admin');

        return $this;
    }

    /**
     * ทดสอบบนรอบขายเดิม — การสร้างรอบใหม่อยู่ใน TokenSaleCreateTest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withExistingSale(string $uri, array $payload): array
    {
        if ($uri === '/admin/token-sales') {
            $payload['id'] = TokenSale::create(['sale_wallet_address' => self::OLD_WALLET] + self::SALE)->id;
        }

        return $payload;
    }

    /** @return array<int, string|null> */
    private function allSettings(): array
    {
        return SiteSetting::orderBy('id')->pluck('value', 'id')->all();
    }

    /** ตั้ง env เป็น production แล้วด่าน CSRF ทำงาน — เทสต์ไม่มี token ให้ส่ง */
    private function pretendProduction(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── ด่านสิทธิ์ ───────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function gatedRoutes(): array
    {
        return [
            'แท็บเทรด' => ['put', '/admin/settings/trading', ['fee_collector_wallet' => self::WALLET]],
            'แท็บชำระเงิน' => ['put', '/admin/settings/payment', ['stripe_secret_key' => self::STRIPE_SECRET]],
            'แท็บโรงงาน' => ['put', '/admin/settings/factory', ['fee_wallet' => self::WALLET]],
            'แท็บรายได้' => ['put', '/admin/settings/revenue', ['tpix_wallet' => self::WALLET]],
            'ค่าตั้งรวมค่าบริการวางไม้' => ['put', '/admin/trading-fees/settings', self::TOPUP_SETTINGS],
            'บันทึกรอบขาย' => ['post', '/admin/token-sales', self::SALE],
        ];
    }

    #[Test]
    #[DataProvider('gatedRoutes')]
    public function แอดมินระดับล่างโดนปฏิเสธทุกเส้น(string $method, string $uri, array $payload): void
    {
        $payload = $this->withExistingSale($uri, $payload);
        $before = $this->allSettings();

        foreach (['admin', 'moderator', 'support'] as $role) {
            $this->asRole($role)->{$method}($uri, $payload)->assertForbidden();
        }

        $this->assertSame($before, $this->allSettings());
        $this->assertNotSame(self::WALLET, TokenSale::first()?->sale_wallet_address);
        $this->assertSame(0, AuditLog::whereNotNull('new_values')->count());
    }

    #[Test]
    #[DataProvider('gatedRoutes')]
    public function super_adminยังบันทึกได้ทุกเส้น(string $method, string $uri, array $payload): void
    {
        $payload = $this->withExistingSale($uri, $payload);

        $this->asRole('super_admin')->{$method}($uri, $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // ทุกเส้นต้องทิ้งแถวที่มีค่าใหม่ไว้จริง ไม่ใช่แค่ไม่ error
        $log = AuditLog::whereNotNull('new_values')->sole();
        $this->assertNotSame([], $log->new_values);
    }

    /** ด่านนี้ต้องไม่ลามไปแท็บที่เป็นงานประจำของแอดมินทั่วไป */
    #[Test]
    public function แอดมินทั่วไปยังทำงานประจำได้(): void
    {
        SiteSetting::set('social', 'twitter_url', '');
        $tier = TradingFeeTier::create(['min_order_usd' => 0, 'max_order_usd' => 100, 'fee_tpix' => 1, 'is_active' => true]);

        $this->asRole('moderator');

        $this->put('/admin/settings/social', ['twitter_url' => 'https://x.com/tpix'])->assertRedirect();
        $this->assertSame('https://x.com/tpix', SiteSetting::get('social', 'twitter_url'));

        $this->put("/admin/trading-fees/{$tier->id}", [
            'min_order_usd' => 0, 'max_order_usd' => 100, 'fee_tpix' => 2, 'is_active' => true,
        ])->assertRedirect();
        $this->assertSame(2.0, (float) $tier->fresh()->fee_tpix);
    }

    // ── การมองเห็น ───────────────────────────────────────────────────────────

    #[Test]
    public function แอดมินระดับล่างไม่ได้รับค่ากลุ่มเงินเลย(): void
    {
        SiteSetting::set('general', 'site_name', 'TPIX TRADE');
        SiteSetting::set('payment', 'stripe_secret_key', self::OLD_STRIPE_SECRET);
        SiteSetting::set('payment', 'stripe_public_key', 'pk_live_public');
        SiteSetting::set('stripe', 'stripe_webhook_secret', self::WEBHOOK_SECRET);
        SiteSetting::set('revenue', 'tpix_wallet', self::OLD_WALLET);
        SiteSetting::set('trading', 'fee_collector_wallet', self::OLD_WALLET);
        SiteSetting::set('factory', 'fee_wallet', self::OLD_WALLET);

        $response = $this->asRole('support')->get('/admin/settings')->assertOk();

        $this->assertFalse($response->inertiaProps('canManageMoney'));

        $settings = $response->inertiaProps('settings');
        $this->assertSame('TPIX TRADE', $settings['site_name']);

        foreach (['stripe_secret_key', 'stripe_public_key', 'stripe_webhook_secret', 'tpix_wallet', 'fee_collector_wallet', 'fee_wallet'] as $key) {
            $this->assertArrayNotHasKey($key, $settings);
        }
    }

    #[Test]
    public function super_adminเห็นแท็บเงินแต่คีย์ลับยังถูกปิด(): void
    {
        SiteSetting::set('payment', 'stripe_secret_key', self::OLD_STRIPE_SECRET);
        SiteSetting::set('revenue', 'tpix_wallet', self::OLD_WALLET);

        $response = $this->asRole('super_admin')->get('/admin/settings')->assertOk();

        $this->assertTrue($response->inertiaProps('canManageMoney'));

        $settings = $response->inertiaProps('settings');
        $this->assertSame(self::OLD_WALLET, $settings['tpix_wallet']);
        $this->assertStringStartsWith('********************', $settings['stripe_secret_key']);
    }

    #[Test]
    public function หน้าที่เปิดให้ทุกระดับบอกฟอร์มว่าแก้ไม่ได้(): void
    {
        Http::fake();

        $this->asRole('moderator');

        $this->get('/admin/trading-fees')->assertOk()->assertInertia(fn ($page) => $page->where('canManageMoney', false));
        $this->get('/admin/token-sales')->assertOk()->assertInertia(fn ($page) => $page->where('canManageMoney', false));
    }

    // ── ตรวจที่อยู่กระเป๋า ───────────────────────────────────────────────────

    /**
     * @return array<string, array{mixed}>
     */
    public static function badAddresses(): array
    {
        return [
            'ข้อความ' => ['not-a-wallet'],
            'สั้นไป' => ['0x1234'],
            'ยาวไป' => ['0x'.str_repeat('a', 41)],
            'ไม่มี 0x' => [str_repeat('a', 40)],
            'ไม่ใช่เลขฐานสิบหก' => ['0x'.str_repeat('g', 40)],
            'ที่อยู่ศูนย์' => ['0x'.str_repeat('0', 40)],
            'อาร์เรย์' => [[self::WALLET]],
        ];
    }

    #[Test]
    #[DataProvider('badAddresses')]
    public function กระเป๋าผิดรูปแบบถูกปฏิเสธทุกช่อง(mixed $bad): void
    {
        $this->asRole('super_admin');

        foreach (self::WALLET_FIELDS as [$uri, $group, $key]) {
            SiteSetting::set($group, $key, self::OLD_WALLET);

            $this->put($uri, [$key => $bad])->assertSessionHasErrors($key);

            $this->assertStringContainsString('ที่อยู่กระเป๋าไม่ถูกต้อง', session('errors')->first($key));
            $this->assertSame(self::OLD_WALLET, SiteSetting::get($group, $key));
        }

        $this->assertSame(0, AuditLog::where('action', 'like', 'settings.%.update')->count());
    }

    /** บันทึกครึ่งเดียวอันตรายกว่าไม่บันทึก — ช่องดีลงไป แต่ช่องเสียไม่ลง แล้วค่ากระจายต่อผิด */
    #[Test]
    public function ช่องเดียวผิดต้องไม่บันทึกอะไรเลย(): void
    {
        $this->asRole('super_admin')
            ->put('/admin/settings/revenue', ['tpix_wallet' => self::WALLET, 'wtpix_wallet' => 'เสีย'])
            ->assertSessionHasErrors('wtpix_wallet');

        $this->assertNull(SiteSetting::get('revenue', 'tpix_wallet'));
        $this->assertSame('', (string) SiteSetting::get('trading', 'fee_collector_wallet', ''));
    }

    #[Test]
    public function กระเป๋าเติมเครดิตผิดได้ข้อความไทยและห้ามที่อยู่ศูนย์(): void
    {
        $this->asRole('super_admin')
            ->put('/admin/trading-fees/settings', ['tpix_topup_wallet' => '0x'.str_repeat('0', 40)] + self::TOPUP_SETTINGS)
            ->assertSessionHasErrors('tpix_topup_wallet');

        $this->assertStringContainsString('ที่อยู่กระเป๋าไม่ถูกต้อง', session('errors')->first('tpix_topup_wallet'));
    }

    #[Test]
    public function ขึ้นบรรทัดใหม่ท้ายที่อยู่ไม่ผ่าน(): void
    {
        $this->assertTrue(Validator::make(['wallet' => self::WALLET."\n"], ['wallet' => new EvmAddress()])->fails());
        $this->assertFalse(Validator::make(['wallet' => self::WALLET], ['wallet' => new EvmAddress()])->fails());
    }

    /** ว่าง = ยังไม่ตั้ง เป็นค่าที่ตั้งใจ ไม่ใช่ error */
    #[Test]
    public function เว้นช่องกระเป๋าว่างได้(): void
    {
        SiteSetting::set('trading', 'fee_collector_wallet', self::OLD_WALLET);

        $this->asRole('super_admin')
            ->put('/admin/settings/trading', ['fee_collector_wallet' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('', (string) SiteSetting::get('trading', 'fee_collector_wallet'));
    }

    /** ที่อยู่ตัวพิมพ์ปนที่ checksum ไม่ตรงทำ ethers ฝั่งเบราว์เซอร์โยน error ตอนโอนค่าธรรมเนียม */
    #[Test]
    public function กระเป๋าฝั่งเทรดเก็บพิมพ์เล็ก(): void
    {
        $this->asRole('super_admin')->put('/admin/settings/trading', [
            'fee_collector_wallet' => '0xABCDEF0123456789ABCDEF0123456789ABCDEF01',
        ]);

        $this->assertSame('0xabcdef0123456789abcdef0123456789abcdef01', SiteSetting::get('trading', 'fee_collector_wallet'));
    }

    // ── audit_logs ───────────────────────────────────────────────────────────

    #[Test]
    public function เปลี่ยนกระเป๋าแล้วบันทึกค่าเก่าค่าใหม่พร้อมผู้แก้(): void
    {
        SiteSetting::set('revenue', 'tpix_wallet', self::OLD_WALLET);
        SiteSetting::set('revenue', 'tpix_chain_id', '4289');
        SiteSetting::set('trading', 'fee_collector_wallet', self::OLD_WALLET);

        $admin = $this->adminWithRole('super_admin');

        $this->actingAs($admin, 'admin')->put('/admin/settings/revenue', [
            'tpix_wallet' => self::WALLET,
            'tpix_chain_id' => '4289',
        ]);

        $log = AuditLog::where('action', 'settings.revenue.update')->sole();

        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame(self::OLD_WALLET, $log->old_values['revenue.tpix_wallet']);
        $this->assertSame(self::WALLET, $log->new_values['revenue.tpix_wallet']);

        // ค่าที่ถูกกระจายต่อคือค่าที่เงินวิ่งเข้าจริง ต้องอยู่ในแถวเดียวกัน
        $this->assertSame(self::OLD_WALLET, $log->old_values['trading.fee_collector_wallet']);
        $this->assertSame(self::WALLET, $log->new_values['trading.fee_collector_wallet']);
        $this->assertSame(self::WALLET, $log->new_values['factory.fee_wallet']);

        // เก็บเฉพาะที่เปลี่ยน ไม่งั้นหาค่าที่เปลี่ยนจริงในแถวไม่เจอ
        $this->assertArrayNotHasKey('revenue.tpix_chain_id', $log->new_values);
    }

    #[Test]
    public function กระเป๋าเติมเครดิตบันทึกค่าเก่าค่าใหม่(): void
    {
        SiteSetting::set('trading', 'tpix_topup_wallet', self::OLD_WALLET);

        $this->asRole('super_admin')->put('/admin/trading-fees/settings', self::TOPUP_SETTINGS);

        $log = AuditLog::where('action', 'trading_fee.settings.update')->sole();

        $this->assertNotNull($log->admin_id);
        $this->assertSame(self::OLD_WALLET, $log->old_values['trading.tpix_topup_wallet']);
        $this->assertSame(self::WALLET, $log->new_values['trading.tpix_topup_wallet']);
    }

    #[Test]
    public function เปลี่ยนกระเป๋ารอบขายบันทึกค่าเก่าค่าใหม่(): void
    {
        $sale = TokenSale::create(['sale_wallet_address' => self::OLD_WALLET] + self::SALE);

        $this->asRole('super_admin')
            ->post('/admin/token-sales', ['id' => $sale->id] + self::SALE)
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'token_sale.save')->sole();

        $this->assertNotNull($log->admin_id);
        $this->assertSame(self::OLD_WALLET, $log->old_values['sale_wallet_address']);
        $this->assertSame(self::WALLET, $log->new_values['sale_wallet_address']);
        $this->assertSame(self::WALLET, $sale->fresh()->sale_wallet_address);
    }

    #[Test]
    public function คีย์ลับของ_stripeไม่ลงบันทึกเลย(): void
    {
        SiteSetting::set('payment', 'stripe_secret_key', self::OLD_STRIPE_SECRET);

        $this->asRole('super_admin')->put('/admin/settings/payment', [
            'stripe_public_key' => 'pk_live_public',
            'stripe_secret_key' => self::STRIPE_SECRET,
            'stripe_webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $log = AuditLog::where('action', 'settings.payment.update')->sole();

        // ต้องรู้ว่าคีย์ถูกเปลี่ยน แต่ไม่รู้ว่าเป็นค่าอะไร
        $this->assertSame('[redacted]', $log->old_values['payment.stripe_secret_key']);
        $this->assertSame('[redacted]', $log->new_values['payment.stripe_secret_key']);
        $this->assertSame('', $log->old_values['payment.stripe_webhook_secret']);
        $this->assertSame('[redacted]', $log->new_values['payment.stripe_webhook_secret']);

        // คีย์สาธารณะบอกได้ว่าเป็นบัญชี Stripe ใบไหน จึงเก็บค่าจริง
        $this->assertSame('pk_live_public', $log->new_values['payment.stripe_public_key']);

        // ตรวจทุกแถวรวมแถวของ middleware admin.audit ด้วย
        $everything = AuditLog::all()->toJson();

        foreach ([self::STRIPE_SECRET, self::OLD_STRIPE_SECRET, self::WEBHOOK_SECRET, substr(self::STRIPE_SECRET, -4)] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    /** หน้าเว็บส่งค่าที่ถูก mask กลับมาทุกครั้งที่กดบันทึก — ห้ามนับเป็นการเปลี่ยนคีย์ */
    #[Test]
    public function ค่าmaskไม่นับเป็นการเปลี่ยนคีย์(): void
    {
        SiteSetting::set('payment', 'stripe_secret_key', self::OLD_STRIPE_SECRET);

        $this->asRole('super_admin')->put('/admin/settings/payment', [
            'stripe_secret_key' => str_repeat('*', 20).substr(self::OLD_STRIPE_SECRET, -4),
        ]);

        $this->assertSame(self::OLD_STRIPE_SECRET, SiteSetting::get('payment', 'stripe_secret_key'));
        $this->assertSame([], AuditLog::where('action', 'settings.payment.update')->sole()->new_values);
    }

    /** เงินเปลี่ยนปลายทางโดยไม่มีร่องรอยคือสิ่งที่ด่านนี้มีไว้กัน */
    #[Test]
    public function บันทึกร่องรอยไม่ได้ต้องไม่เปลี่ยนค่า(): void
    {
        SiteSetting::set('revenue', 'tpix_wallet', self::OLD_WALLET);

        AuditLog::creating(function (): void {
            throw new RuntimeException('audit store down');
        });

        $this->asRole('super_admin')
            ->put('/admin/settings/revenue', ['tpix_wallet' => self::WALLET])
            ->assertServerError();

        $this->assertSame(self::OLD_WALLET, SiteSetting::where('group', 'revenue')->where('key', 'tpix_wallet')->value('value'));
        $this->assertSame(self::OLD_WALLET, SiteSetting::get('revenue', 'tpix_wallet'));
    }

    #[Test]
    public function บันทึกร่องรอยไม่ได้ต้องไม่เปลี่ยนกระเป๋ารอบขาย(): void
    {
        $sale = TokenSale::create(['sale_wallet_address' => self::OLD_WALLET] + self::SALE);

        AuditLog::creating(function (): void {
            throw new RuntimeException('audit store down');
        });

        $this->asRole('super_admin')
            ->post('/admin/token-sales', ['id' => $sale->id] + self::SALE)
            ->assertServerError();

        $this->assertSame(self::OLD_WALLET, $sale->fresh()->sale_wallet_address);
    }

    // ── คีย์ทดสอบของ Stripe ──────────────────────────────────────────────────

    #[Test]
    public function เว็บจริงบันทึกคีย์ทดสอบไม่ได้(): void
    {
        $this->pretendProduction();

        $this->asRole('super_admin');

        foreach (['stripe_secret_key' => 'sk_test_abc', 'stripe_public_key' => 'pk_test_abc'] as $key => $value) {
            $this->put('/admin/settings/payment', [$key => $value])->assertSessionHasErrors($key);
            $this->assertNull(SiteSetting::get('payment', $key));
        }

        $this->put('/admin/settings/payment', ['stripe_secret_key' => self::STRIPE_SECRET])->assertSessionHasNoErrors();
        $this->assertSame(self::STRIPE_SECRET, SiteSetting::get('payment', 'stripe_secret_key'));
    }

    #[Test]
    public function เครื่องพัฒนายังใช้คีย์ทดสอบได้(): void
    {
        $this->asRole('super_admin')
            ->put('/admin/settings/payment', ['stripe_secret_key' => 'sk_test_abc'])
            ->assertSessionHasNoErrors();

        $this->assertSame('sk_test_abc', SiteSetting::get('payment', 'stripe_secret_key'));
    }

    /** ตรวจตอนอ่านด้วย เพราะคีย์อาจไม่ได้มาจากหน้าตั้งค่า */
    #[Test]
    public function เว็บจริงที่ถือคีย์ทดสอบปิดการรับบัตร(): void
    {
        SiteSetting::set('payment', 'stripe_secret_key', 'sk_test_abc');

        $this->assertTrue(app(StripePaymentService::class)->isEnabled());

        $this->app['env'] = 'production';
        $this->assertFalse(app(StripePaymentService::class)->isEnabled());

        SiteSetting::set('payment', 'stripe_secret_key', self::STRIPE_SECRET);
        $this->assertTrue(app(StripePaymentService::class)->isEnabled());
    }
}
