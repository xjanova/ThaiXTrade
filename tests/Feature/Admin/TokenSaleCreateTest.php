<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\TokenSale;
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
        'accept_currencies' => ['USDT', 'STRIPE'],
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
}
