<?php

namespace Tests\Feature\Admin;

use App\Exceptions\PurchaseException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Services\SaleStatusService;
use App\Services\TokenSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — สร้างรอบขายใหม่จากหลังบ้าน.
 *
 * token_sales.slug เป็น NOT NULL แต่ฟอร์มไม่เคยส่งมา กด Create จึงตาย 500 ทุกครั้ง
 * ส่วน slug ของรอบที่มีอยู่แล้วต้องไม่ขยับ เพราะ URL สาธารณะอาจอ้างถึง
 *
 * ฟอร์มเดิมส่ง ['USDT', 'STRIPE'] และไม่ส่งเชน รอบใหม่จึงเปิดทางโอน USDT เข้า sale_wallet_address
 * และอยู่บนเชน 56 ทั้งที่นโยบายคือบัตรเป็นหลัก ส่งมอบบนเชน 4289
 *
 * Developed by Xman Studio.
 */
class TokenSaleCreateTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG_FORMAT = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** ค่าที่ฟอร์ม Create Token Sale ส่งจริงตอนกรอกแค่ชื่อ */
    private const FORM = [
        'id' => null,
        'name' => 'TPIX ICO Round 1',
        'description' => '',
        'total_supply_for_sale' => 700000000,
        'accept_currencies' => ['CARD'],
        'sale_wallet_address' => '',
        'starts_at' => '',
        'ends_at' => '',
        'status' => 'draft',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** ล็อกอินผ่าน session แบบเบราว์เซอร์จริง — ดูเหตุผลใน AuditLogAdminIdTest */
    private function signIn(string $role = 'super_admin'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => $role,
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    /** @param  array<string, mixed>  $overrides */
    private function submit(array $overrides = []): TokenSale
    {
        $this->post('/admin/token-sales', $overrides + self::FORM)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        return TokenSale::latest('id')->firstOrFail();
    }

    #[Test]
    public function สร้างรอบขายจากฟอร์มได้_slug_มาจากชื่อ(): void
    {
        $admin = $this->signIn();

        $sale = $this->submit();

        $this->assertSame('TPIX ICO Round 1', $sale->name);
        $this->assertSame('tpix-ico-round-1', $sale->slug);
        $this->assertSame('draft', $sale->status);

        $log = AuditLog::where('action', 'token_sale.save')->sole();

        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame([], $log->old_values);
        $this->assertSame('draft', $log->new_values['status']);
    }

    #[Test]
    public function ชื่อซ้ำกันยังสร้างได้และ_slug_ไม่ชนกัน(): void
    {
        $this->signIn();

        $this->assertSame('tpix-ico-round-1', $this->submit()->slug);
        $this->assertSame('tpix-ico-round-1-1', $this->submit()->slug);
        $this->assertSame('tpix-ico-round-1-2', $this->submit()->slug);
    }

    /** แถวที่ลบแบบ soft delete ยังอยู่ใน unique index — ถ้าไม่นับด้วยจะชนแล้วตาย 500 */
    #[Test]
    public function slug_ไม่ชนกับรอบที่ถูกลบไปแล้ว(): void
    {
        $this->signIn();
        $this->submit()->delete();

        $this->assertSame('tpix-ico-round-1-1', $this->submit()->slug);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesWithoutUsableSlug(): array
    {
        return [
            'ภาษาไทยล้วน' => ['รอบขายแรก'],
            'สัญลักษณ์ล้วน' => ['!!! ???'],
        ];
    }

    #[Test]
    #[DataProvider('namesWithoutUsableSlug')]
    public function ชื่อที่แปลงเป็น_slug_ไม่ได้ยังได้_slug_ที่ใช้ได้(string $name): void
    {
        $this->signIn();

        $first = $this->submit(['name' => $name]);
        $second = $this->submit(['name' => $name]);

        $this->assertMatchesRegularExpression(self::SLUG_FORMAT, $first->slug);
        $this->assertMatchesRegularExpression(self::SLUG_FORMAT, $second->slug);
        $this->assertNotSame($first->slug, $second->slug);
    }

    /** '@' กลายเป็น 'at' — slug ยาวกว่าชื่อได้ และเลขต่อท้ายต้องยังพอที่ในคอลัมน์ 255 ตัว */
    #[Test]
    public function ชื่อยาวสุดไม่ทำให้_slug_ล้นคอลัมน์(): void
    {
        $this->signIn();
        $name = str_repeat('@', 255);

        $first = $this->submit(['name' => $name]);
        $second = $this->submit(['name' => $name]);

        foreach ([$first, $second] as $sale) {
            $this->assertLessThanOrEqual(255, strlen($sale->slug));
            $this->assertMatchesRegularExpression(self::SLUG_FORMAT, $sale->slug);
        }
        $this->assertNotSame($first->slug, $second->slug);
    }

    #[Test]
    public function แก้รอบเดิมไม่เปลี่ยน_slug_แม้เปลี่ยนชื่อหรือส่ง_slug_มาเอง(): void
    {
        $this->signIn();
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'total_supply_for_sale' => 1000,
            'status' => 'draft',
        ]);

        $this->post('/admin/token-sales', [
            'id' => $sale->id,
            'name' => 'ชื่อใหม่ทั้งหมด',
            'slug' => 'hijacked',
        ] + self::FORM)->assertSessionHasNoErrors();

        $sale->refresh();

        $this->assertSame('ชื่อใหม่ทั้งหมด', $sale->name);
        $this->assertSame('tpix-public-sale', $sale->slug);
    }

    #[Test]
    public function ฟอร์มกำหนด_slug_ของรอบใหม่เองไม่ได้(): void
    {
        $this->signIn();

        $this->assertSame('tpix-ico-round-1', $this->submit(['slug' => 'chosen-by-client'])->slug);
    }

    #[Test]
    public function แอดมินระดับล่างสร้างรอบขายไม่ได้(): void
    {
        $this->signIn('admin');

        $this->post('/admin/token-sales', self::FORM)->assertForbidden();

        $this->assertSame(0, TokenSale::count());
    }

    #[Test]
    public function รอบใหม่จากฟอร์มรับบัตรบนเชน_4289(): void
    {
        $this->signIn();

        $sale = $this->submit();

        $this->assertSame(['CARD'], $sale->accept_currencies);
        $this->assertSame(4289, $sale->accept_chain_id);

        $log = AuditLog::where('action', 'token_sale.save')->sole();

        $this->assertSame(['CARD'], $log->new_values['accept_currencies']);
        $this->assertSame(4289, $log->new_values['accept_chain_id']);
    }

    #[Test]
    public function รอบใหม่จากฟอร์มซื้อด้วยบัตรได้แต่ปิดทางโอน_usdt(): void
    {
        $this->signIn();
        SiteSetting::set('payment', 'stripe_enabled', '1');
        SiteSetting::set('payment', 'stripe_secret_key', 'sk_test_x');
        SiteSetting::set('payment', 'stripe_publishable_key', 'pk_test_x');

        $sale = $this->submit();

        $this->assertSame(['card' => true, 'bank' => false], app(SaleStatusService::class)->paymentMethods($sale));

        $sales = app(TokenSaleService::class);
        $sales->assertCurrencyAccepted($sale, 'USD');

        $this->expectException(PurchaseException::class);
        $sales->assertCurrencyAccepted($sale, 'USDT');
    }

    #[Test]
    public function เลือกช่องทางเพิ่มได้และเก็บลำดับเดียวกันเสมอ(): void
    {
        $this->signIn();

        $sale = $this->submit(['accept_currencies' => ['CRYPTO', 'BANK', 'CARD', 'BANK']]);

        $this->assertSame(['CARD', 'BANK', 'CRYPTO'], $sale->accept_currencies);
    }

    /** ค่า CRYPTO คือทาง USDT ใหม่ ต้องไม่กลายเป็นการเปิดทางยื่น tx_hash แบบเก่าไปด้วย */
    #[Test]
    public function เปิด_crypto_แล้วทางโอน_usdt_แบบเก่ายังปิด(): void
    {
        $this->signIn();

        $sale = $this->submit(['accept_currencies' => ['CARD', 'CRYPTO']]);

        $this->expectException(PurchaseException::class);
        app(TokenSaleService::class)->assertCurrencyAccepted($sale, 'USDT');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedChannels(): array
    {
        return [
            'ค่าเก่าของฟอร์ม' => [['USDT', 'STRIPE']],
            'โอนเหรียญตรง' => [['BNB', 'USDT']],
            'แอบ USDT มากับบัตร' => [['CARD', 'USDT']],
            'ไม่มีบัตร' => [['BANK']],
            'ตัวพิมพ์เล็ก' => [['card']],
            'ค่าซ้อนในอาร์เรย์' => [['CARD', ['USDT']]],
            'ลิสต์ว่างคือไม่จำกัดสกุล' => [[]],
            'ไม่ใช่อาร์เรย์' => ['CARD'],
            'null คือไม่จำกัดสกุล' => [null],
        ];
    }

    #[Test]
    #[DataProvider('refusedChannels')]
    public function รอบใหม่ปฏิเสธช่องทางนอกนโยบาย(mixed $channels): void
    {
        $this->signIn();

        $this->post('/admin/token-sales', ['accept_currencies' => $channels] + self::FORM)
            ->assertRedirect()
            ->assertSessionHasErrors('accept_currencies');

        $this->assertSame(0, TokenSale::count());
    }

    #[Test]
    public function ไม่ส่งช่องทางมาเลยก็สร้างไม่ได้(): void
    {
        $this->signIn();
        $form = self::FORM;
        unset($form['accept_currencies']);

        $this->post('/admin/token-sales', $form)->assertSessionHasErrors('accept_currencies');

        $this->assertSame(0, TokenSale::count());
    }

    #[Test]
    public function ฟอร์มกำหนดเชนของรอบขายเองไม่ได้(): void
    {
        $this->signIn();

        $this->assertSame(4289, $this->submit(['accept_chain_id' => 56])->accept_chain_id);
    }

    #[Test]
    public function แก้รอบเดิมเปิดทางโอน_usdt_กลับมาไม่ได้(): void
    {
        $this->signIn();
        $sale = $this->existingSale(['accept_currencies' => ['CARD']]);

        $this->post('/admin/token-sales', ['id' => $sale->id, 'accept_currencies' => ['CARD', 'USDT']] + self::FORM)
            ->assertSessionHasErrors('accept_currencies');

        $this->assertSame(['CARD'], $sale->fresh()->accept_currencies);
    }

    #[Test]
    public function แก้รอบเดิมล้างช่องทางจนกลายเป็นไม่จำกัดสกุลไม่ได้(): void
    {
        $this->signIn();
        $sale = $this->existingSale(['accept_currencies' => ['CARD']]);

        $this->post('/admin/token-sales', ['id' => $sale->id, 'accept_currencies' => []] + self::FORM)
            ->assertSessionHasErrors('accept_currencies');

        $this->assertSame(['CARD'], $sale->fresh()->accept_currencies);
    }

    #[Test]
    public function แก้รอบเดิมเพิ่มช่องทางได้และบันทึกค่าก่อนหน้าไว้(): void
    {
        $this->signIn();
        $sale = $this->existingSale(['accept_currencies' => ['CARD']]);

        $this->post('/admin/token-sales', ['id' => $sale->id, 'accept_currencies' => ['CARD', 'BANK']] + self::FORM)
            ->assertSessionHasNoErrors();

        $this->assertSame(['CARD', 'BANK'], $sale->fresh()->accept_currencies);

        $log = AuditLog::where('action', 'token_sale.save')->sole();

        $this->assertSame(['CARD'], $log->old_values['accept_currencies']);
        $this->assertSame(['CARD', 'BANK'], $log->new_values['accept_currencies']);
    }

    /** แถวเก่าบนเชน 56 (เช่น จาก seeder) ต้องกลับเข้านโยบายทันทีที่บันทึกผ่านหลังบ้าน */
    #[Test]
    public function บันทึกรอบเก่าบนเชน_56_แล้วย้ายมาเชน_4289(): void
    {
        $this->signIn();
        $sale = $this->existingSale(['accept_currencies' => ['BNB', 'USDT'], 'accept_chain_id' => 56]);

        $this->post('/admin/token-sales', ['id' => $sale->id] + self::FORM)->assertSessionHasNoErrors();

        $sale->refresh();

        $this->assertSame(['CARD'], $sale->accept_currencies);
        $this->assertSame(4289, $sale->accept_chain_id);

        $log = AuditLog::where('action', 'token_sale.save')->sole();

        $this->assertSame(56, $log->old_values['accept_chain_id']);
        $this->assertSame(4289, $log->new_values['accept_chain_id']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function existingSale(array $attributes): TokenSale
    {
        return TokenSale::create($attributes + [
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'total_supply_for_sale' => 1000,
            'status' => 'draft',
        ]);
    }
}
