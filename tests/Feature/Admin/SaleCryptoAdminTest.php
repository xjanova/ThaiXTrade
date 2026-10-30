<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SaleCryptoOrder;
use App\Models\SaleCryptoPayment;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — USDT ที่รอคนตัดสินในหลังบ้าน: สั่งได้เฉพาะ super_admin และต้องมีร่องรอย.
 *
 * ออกเหรียญตามยอดที่ได้รับคือการสร้างยอดขายด้วยมือ ส่วนปิดรายการคือการบอกว่าไม่ออกเหรียญให้เงินก้อนนี้
 * ทั้งคู่ต้องตอบได้ว่าใครสั่ง จึงเข้าระบบผ่าน session เหมือนเบราว์เซอร์ ไม่ใช้ actingAs()
 *
 * Developed by Xman Studio.
 */
class SaleCryptoAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_WALLET = '0x5a1e000000000000000000000000000000005a1e';

    private const BUYER = '0xbbbb00000000000000000000000000000000bbbb';

    private const USDT_BSC = '0x55d398326f99059ff775485246999027b3197955';

    private SalePhase $phase;

    private SaleCryptoOrder $order;

    private SaleCryptoPayment $underpaid;

    private SaleCryptoPayment $unmatched;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake();

        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'accept_currencies' => ['CARD', 'CRYPTO'],
            'accept_chain_id' => 4289,
            'sale_wallet_address' => self::SALE_WALLET,
        ]);

        $this->phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 1000000,
            'min_purchase' => 100,
            'max_purchase' => 1000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);

        $this->order = SaleCryptoOrder::create([
            'reference' => 'TPX-ABCDEFG',
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => self::BUYER,
            'chain_id' => 56,
            'token_address' => self::USDT_BSC,
            'token_symbol' => 'USDT',
            'token_decimals' => 18,
            'pay_to_address' => self::SALE_WALLET,
            'amount_usd' => 10,
            'tpix_amount' => 200,
            'price_per_tpix' => 0.05,
            'pay_amount_raw' => '10000100000000000000',
            'tag_micro' => 100,
            'status' => SaleCryptoOrder::STATUS_NEEDS_REVIEW,
            'expires_at' => now()->addMinutes(30),
        ]);

        // โอนขาด 9 USDT จากกระเป๋าของคำสั่งซื้อ
        $this->underpaid = $this->payment('9000000000000000000', SaleCryptoPayment::REASON_UNDERPAID, $this->order);
        $this->unmatched = $this->payment('5000000000000000000', SaleCryptoPayment::REASON_UNMATCHED);
    }

    private function payment(string $raw, string $reason, ?SaleCryptoOrder $order = null): SaleCryptoPayment
    {
        return SaleCryptoPayment::create([
            'chain_id' => 56,
            'tx_hash' => '0x'.Str::random(64),
            'log_index' => 0,
            'block_number' => 1000,
            'block_timestamp' => now()->timestamp,
            'from_address' => self::BUYER,
            'to_address' => self::SALE_WALLET,
            'token_address' => self::USDT_BSC,
            'token_symbol' => 'USDT',
            'token_decimals' => 18,
            'amount_raw' => $raw,
            'order_id' => $order?->id,
            'status' => SaleCryptoPayment::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
        ]);
    }

    private function signIn(string $role = 'super_admin'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => $role,
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);

        // เข้าระบบแบบเบราว์เซอร์ — actingAs() สลับ guard เริ่มต้น จึงบังบั๊กอ่านผิด guard ที่พังจริงบนโปรดักชัน
        Auth::forgetGuards();
        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    #[Test]
    public function super_adminออกเหรียญตามยอดที่ได้รับและมีชื่อคนสั่ง(): void
    {
        $admin = $this->signIn();

        $this->post("/admin/token-sales/crypto/{$this->underpaid->id}/credit")
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $tx = SaleTransaction::sole();
        $this->assertEquals(180, (float) $tx->tpix_amount);
        $this->assertSame('USDT_BSC', $tx->payment_currency);
        $this->assertSame('confirmed', $tx->status);
        $this->assertSame($admin->email, $this->underpaid->fresh()->resolved_by);
        $this->assertSame(SaleCryptoOrder::STATUS_PAID, $this->order->fresh()->status);
        $this->assertEquals(180, (float) $this->phase->fresh()->sold);

        $log = AuditLog::where('action', 'token_sale.crypto_credit')->sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame(SaleCryptoPayment::STATUS_NEEDS_REVIEW, $log->old_values['status']);
        $this->assertSame(SaleCryptoPayment::REASON_UNDERPAID, $log->old_values['review_reason']);
        $this->assertSame($tx->id, $log->new_values['sale_transaction_id']);
        $this->assertSame(self::BUYER, $log->new_values['wallet_address']);
    }

    #[Test]
    public function super_adminปิดรายการที่คืนเงินแล้วและมีชื่อคนสั่ง(): void
    {
        $admin = $this->signIn();

        $this->post("/admin/token-sales/crypto/{$this->unmatched->id}/resolve", ['note' => 'คืนเงินแล้ว tx 0xabc'])
            ->assertSessionHas('success');

        $fresh = $this->unmatched->fresh();
        $this->assertSame(SaleCryptoPayment::STATUS_RESOLVED, $fresh->status);
        $this->assertSame($admin->email, $fresh->resolved_by);
        $this->assertSame(0, SaleTransaction::count());

        $log = AuditLog::where('action', 'token_sale.crypto_resolve')->sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('คืนเงินแล้ว tx 0xabc', $log->new_values['review_note']);
    }

    #[Test]
    public function แอดมินระดับล่างสั่งไม่ได้ทั้งสองเส้น(): void
    {
        foreach (['admin', 'moderator', 'support'] as $role) {
            $this->signIn($role);

            $this->post("/admin/token-sales/crypto/{$this->underpaid->id}/credit")->assertForbidden();
            $this->post("/admin/token-sales/crypto/{$this->unmatched->id}/resolve", ['note' => 'x'])->assertForbidden();
        }

        $this->assertSame(0, SaleTransaction::count());
        $this->assertSame(SaleCryptoPayment::STATUS_NEEDS_REVIEW, $this->underpaid->fresh()->status);
        $this->assertSame(SaleCryptoPayment::STATUS_NEEDS_REVIEW, $this->unmatched->fresh()->status);
        $this->assertSame(0, AuditLog::whereNotNull('new_values')->count());
    }

    #[Test]
    public function โควตาหมดแล้วไม่ออกเหรียญแต่เหตุผลใหม่ไม่หาย(): void
    {
        $this->signIn();
        $this->phase->update(['sold' => 999900]);

        $this->post("/admin/token-sales/crypto/{$this->underpaid->id}/credit")->assertSessionHasErrors('crypto');

        $this->assertSame(0, SaleTransaction::count());
        $this->assertSame(SaleCryptoPayment::REASON_ALLOCATION, $this->underpaid->fresh()->review_reason);
        $this->assertSame(0, AuditLog::where('action', 'token_sale.crypto_credit')->count());
    }

    #[Test]
    public function กดซ้ำไม่ออกเหรียญสองรอบ(): void
    {
        $this->signIn();

        $this->post("/admin/token-sales/crypto/{$this->underpaid->id}/credit")->assertSessionHas('success');
        $this->post("/admin/token-sales/crypto/{$this->underpaid->id}/credit")->assertSessionHasErrors('crypto');

        $this->assertSame(1, SaleTransaction::count());
        $this->assertSame(1, AuditLog::where('action', 'token_sale.crypto_credit')->count());
    }

    #[Test]
    public function ปิดรายการต้องบอกว่าจัดการอย่างไร(): void
    {
        $this->signIn();

        $this->post("/admin/token-sales/crypto/{$this->unmatched->id}/resolve", ['note' => ''])->assertSessionHasErrors('note');

        $this->assertSame(SaleCryptoPayment::STATUS_NEEDS_REVIEW, $this->unmatched->fresh()->status);
    }

    #[Test]
    public function หน้าส่งทั้งสถานะusdtและสิทธิ์ของคนที่เปิดดู(): void
    {
        $this->signIn('support');
        $this->get('/admin/token-sales')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canManageMoney', false)
            ->has('crypto.review', 2)
            ->where('crypto.accepted', true));

        $this->signIn();
        $this->get('/admin/token-sales')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canManageMoney', true)
            ->has('crypto.review', 2));
    }
}
