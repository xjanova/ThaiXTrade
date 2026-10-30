<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Chain;
use App\Models\SaleCryptoOrder;
use App\Models\SaleCryptoPayment;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\SwapConfig;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Models\WhitelistEntry;
use App\Services\StripePaymentService;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — คำสั่งที่ขยับเงินจริงในหลังบ้าน สั่งได้เฉพาะ super_admin และต้องมีร่องรอย.
 *
 * ผลตรวจเส้นทางเงิน (C4) ส่วนที่เหลือจาก MoneySettingsAccessTest: แอดมินทุกระดับคืนเงิน เปิดรอบขาย
 * แก้ราคาเฟส ยืนยันเงินโอน และสลับเราเตอร์ที่ client ใช้ได้ โดย router_address ไม่ถูกตรวจรูปแบบ
 * และ audit_logs เก็บแค่ชื่อ route
 *
 * Developed by Xman Studio.
 */
class MoneyRoutesAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTER = '0x10ED43C718714eb63d5aA57B78B54704E256024E';

    private const OLD_ROUTER = '0x13f4EA83D0bd40E75C8222255bc855a974568Dd4';

    private const FACTORY = '0xcA143Ce32Fe78f1f7019d7d551a6402fC5350c73';

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private const SALE_WALLET = '0x5a1e000000000000000000000000000000005a1e';

    private const USDT_BSC = '0x55d398326f99059ff775485246999027b3197955';

    private Chain $chain;

    private SalePhase $phase;

    private SwapConfig $swap;

    private SaleTransaction $bankOrder;

    private SaleTransaction $cardPayment;

    private SaleTransaction $paidBankOrder;

    private WhitelistEntry $whitelisted;

    private SaleCryptoPayment $cryptoPayment;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake();

        $this->chain = Chain::create([
            'name' => 'BSC',
            'symbol' => 'BNB',
            'chain_id_hex' => '0x38',
            'rpc_url' => 'https://rpc.example.test',
            'native_currency_name' => 'BNB',
            'native_currency_symbol' => 'BNB',
            'native_currency_decimals' => 18,
            'is_active' => true,
        ]);

        $this->swap = SwapConfig::create($this->swapValues(['router_address' => self::OLD_ROUTER]));

        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'total_sold' => 1000,
            'total_raised_usd' => 100,
        ]);

        $this->phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 1,
            'duration_days' => 30,
            'price_usd' => 0.10,
            'allocation' => 1000000,
            'sold' => 1000,
            'min_purchase' => 100,
            'max_purchase' => 1000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);

        // เฟสที่ขายไปแล้วเลื่อนวันไม่ได้ — การเปิดรอบขายต้องมีเฟสถัดไปให้ตั้งวัน
        SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Next Sale',
            'slug' => 'next-sale',
            'phase_order' => 2,
            'duration_days' => 30,
            'price_usd' => 0.20,
            'allocation' => 1000000,
            'status' => 'upcoming',
        ]);

        $this->bankOrder = $this->saleTransaction('pending', 'TPIX-ABC234', [
            'metadata' => ['method' => 'bank_transfer', 'reference' => 'TPIX-ABC234'],
            'payment_currency' => 'THB_BANK',
        ]);
        $this->cardPayment = $this->saleTransaction('confirmed', 'stripe_cs_test_1');

        // รับเงินแล้วแต่เหรียญค้าง — ยอด claim ก้อนก่อนถูกปฏิเสธที่หน้าคลัง ยังไม่มีอะไรจ่ายออก
        $this->paidBankOrder = $this->saleTransaction('confirmed', 'TPIX-GHJ789', [
            'metadata' => ['method' => 'bank_transfer', 'reference' => 'TPIX-GHJ789'],
            'payment_currency' => 'THB_BANK',
            'claimed_amount' => 100,
            'vesting_start_at' => now(),
        ]);

        TreasuryPayout::create([
            'idempotency_key' => "sale-claim-{$this->paidBankOrder->uuid}-1",
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei('100'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'status' => TreasuryPayout::STATUS_REJECTED,
            'failure_reason' => 'wrong wallet',
        ]);

        $this->whitelisted = WhitelistEntry::create([
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => self::BUYER,
        ]);

        $cryptoOrder = SaleCryptoOrder::create([
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
            'tpix_amount' => 100,
            'price_per_tpix' => 0.10,
            'pay_amount_raw' => '10000100000000000000',
            'tag_micro' => 100,
            'status' => SaleCryptoOrder::STATUS_NEEDS_REVIEW,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->cryptoPayment = SaleCryptoPayment::create([
            'chain_id' => 56,
            'tx_hash' => '0x'.str_repeat('ab', 32),
            'log_index' => 0,
            'block_number' => 1000,
            'block_timestamp' => now()->timestamp,
            'from_address' => self::BUYER,
            'to_address' => self::SALE_WALLET,
            'token_address' => self::USDT_BSC,
            'token_symbol' => 'USDT',
            'token_decimals' => 18,
            'amount_raw' => '5000000000000000000',
            'order_id' => $cryptoOrder->id,
            'status' => SaleCryptoPayment::STATUS_NEEDS_REVIEW,
            'review_reason' => SaleCryptoPayment::REASON_UNDERPAID,
        ]);
    }

    /** @return array<string, mixed> */
    private function swapValues(array $overrides = []): array
    {
        return array_merge([
            'chain_id' => $this->chain->id,
            'router_address' => self::ROUTER,
            'factory_address' => self::FACTORY,
            'protocol' => 'pancakeswap',
            'name' => 'PancakeSwap V2',
            'slippage_tolerance' => 0.5,
            'is_active' => true,
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    private function saleTransaction(string $status, string $txHash, array $overrides = []): SaleTransaction
    {
        return SaleTransaction::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'token_sale_id' => $this->phase->token_sale_id,
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => self::BUYER,
            'payment_currency' => 'USD_STRIPE',
            'payment_amount' => 100,
            'payment_usd_value' => 100,
            'tpix_amount' => 1000,
            'price_per_tpix' => 0.10,
            'tx_hash' => $txHash,
            'status' => $status,
        ], $overrides));
    }

    private function asRole(string $role): self
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

        return $this->withSession([Auth::guard('admin')->getName() => $admin->id]);
    }

    /** ชื่อเส้นทางเงินทุกเส้นใน routes/admin.php ที่ยังไม่ได้อยู่ใน MoneySettingsAccessTest */
    private const GATED = [
        'แก้เฟส', 'เปิดรอบขาย', 'สวิตช์เปิดขายอัตโนมัติ', 'ล็อกวันจบรอบ',
        'เพิ่ม whitelist', 'นำเข้า whitelist', 'ลบ whitelist',
        'คืนเงินรอบขาย', 'ยืนยันเงินโอน', 'ปฏิเสธเงินโอน', 'เข้าคิวจ่ายใหม่', 'เข้าคิว claim ใหม่', 'คืนเงิน Stripe',
        'ออกเหรียญ USDT', 'ปิดรายการ USDT',
        'สร้าง swap', 'แก้ swap', 'เปิดปิด swap', 'ลบ swap',
    ];

    /** @return array<string, array{string}> */
    public static function gatedRoutes(): array
    {
        return array_combine(self::GATED, array_map(fn (string $route) => [$route], self::GATED));
    }

    private function send(string $route): TestResponse
    {
        $phaseValues = [
            'id' => $this->phase->id,
            'token_sale_id' => $this->phase->token_sale_id,
            'name' => 'Public Sale',
            'phase_order' => 1,
            'price_usd' => 0.10,
            'allocation' => 2000000,
            'status' => 'active',
        ];

        return match ($route) {
            'แก้เฟส' => $this->post('/admin/token-sales/phase', $phaseValues),
            'เปิดรอบขาย' => $this->post('/admin/token-sales/launch', ['force' => true]),
            'สวิตช์เปิดขายอัตโนมัติ' => $this->post('/admin/token-sales/auto-launch', ['armed' => true]),
            'ล็อกวันจบรอบ' => $this->post('/admin/token-sales/round-end', ['round_ends_at' => now()->addDays(60)->format('Y-m-d')]),
            'เพิ่ม whitelist' => $this->post('/admin/token-sales/whitelist', ['sale_phase_id' => $this->phase->id, 'wallet_address' => self::ROUTER]),
            'นำเข้า whitelist' => $this->post('/admin/token-sales/whitelist/import', ['sale_phase_id' => $this->phase->id, 'wallets' => self::ROUTER]),
            'ลบ whitelist' => $this->delete("/admin/token-sales/whitelist/{$this->whitelisted->id}"),
            'คืนเงินรอบขาย' => $this->post("/admin/token-sales/refund/{$this->cardPayment->id}"),
            'ยืนยันเงินโอน' => $this->post("/admin/token-sales/bank/{$this->bankOrder->id}/confirm"),
            'ปฏิเสธเงินโอน' => $this->post("/admin/token-sales/bank/{$this->bankOrder->id}/reject", ['reason' => 'ไม่พบเงินเข้า']),
            'เข้าคิวจ่ายใหม่' => $this->post("/admin/token-sales/bank/{$this->paidBankOrder->id}/requeue"),
            'เข้าคิว claim ใหม่' => $this->post("/admin/token-sales/claims/{$this->paidBankOrder->id}/requeue"),
            'ออกเหรียญ USDT' => $this->post("/admin/token-sales/crypto/{$this->cryptoPayment->id}/credit"),
            'ปิดรายการ USDT' => $this->post("/admin/token-sales/crypto/{$this->cryptoPayment->id}/resolve", ['note' => 'คืนเงินแล้ว']),
            'คืนเงิน Stripe' => $this->postJson('/admin/finance/refund', ['transaction_id' => $this->cardPayment->id]),
            'สร้าง swap' => $this->post('/admin/swap', $this->swapValues()),
            'แก้ swap' => $this->put("/admin/swap/{$this->swap->id}", $this->swapValues()),
            'เปิดปิด swap' => $this->patch("/admin/swap/{$this->swap->id}/toggle"),
            'ลบ swap' => $this->delete("/admin/swap/{$this->swap->id}"),
        };
    }

    /** @return array<int, mixed> */
    private function moneyState(): array
    {
        return [
            SwapConfig::orderBy('id')->get()->toArray(),
            SalePhase::orderBy('id')->get()->toArray(),
            SaleTransaction::orderBy('id')->pluck('status', 'id')->all(),
            TreasuryPayout::orderBy('id')->pluck('status', 'id')->all(),
            WhitelistEntry::orderBy('id')->pluck('wallet_address')->all(),
            SaleCryptoPayment::orderBy('id')->pluck('status', 'id')->all(),
            SiteSetting::orderBy('id')->pluck('value', 'id')->all(),
        ];
    }

    private function fakeStripeRefund(): void
    {
        $this->mock(StripePaymentService::class, function (MockInterface $stripe): void {
            $stripe->shouldIgnoreMissing();
            $stripe->shouldReceive('refundTransaction')->andReturnUsing(function (SaleTransaction $tx): array {
                $tx->update(['status' => 'refunded']);

                return ['refund_id' => 're_test_1', 'status' => 'succeeded', 'amount' => 100.0];
            });
        });
    }

    // ── ด่านสิทธิ์ ───────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('gatedRoutes')]
    public function แอดมินระดับล่างโดนปฏิเสธทุกเส้น(string $route): void
    {
        $this->mock(StripePaymentService::class, fn (MockInterface $stripe) => $stripe->shouldNotReceive('refundTransaction'));
        $before = $this->moneyState();

        foreach (['admin', 'moderator', 'support'] as $role) {
            $this->asRole($role)->send($route)->assertForbidden();
        }

        $this->assertSame($before, $this->moneyState());
        $this->assertSame(0, AuditLog::whereNotNull('new_values')->count());
    }

    #[Test]
    #[DataProvider('gatedRoutes')]
    public function super_adminยังสั่งได้ทุกเส้น(string $route): void
    {
        $this->fakeStripeRefund();
        $before = $this->moneyState();

        $response = $this->asRole('super_admin')->send($route);

        $this->assertTrue($response->isRedirect() || $response->isOk(), "ได้ {$response->getStatusCode()}");
        $response->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertNotSame($before, $this->moneyState(), 'ตอบสำเร็จแต่ไม่มีอะไรเปลี่ยน');
    }

    /** ด่านนี้กั้นคำสั่งเท่านั้น — ทีมงานทุกระดับยังต้องเปิดดูหน้าเหล่านี้ได้ */
    #[Test]
    public function แอดมินระดับล่างยังเปิดดูได้แต่หน้ารู้ว่าสั่งไม่ได้(): void
    {
        $this->asRole('support');

        $this->get('/admin/token-sales')->assertOk()->assertInertia(fn ($page) => $page->where('canManageMoney', false));
        $this->get('/admin/finance')->assertOk()->assertInertia(fn ($page) => $page->where('canManageMoney', false));
        $this->get('/admin/swap')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canManageMoney', false)
            ->has('configs', 1)
            ->where('configs.0.router_address', self::OLD_ROUTER));
        $this->get("/admin/token-sales/whitelist/{$this->phase->id}")->assertOk()->assertJsonPath('data.0.wallet_address', self::BUYER);
    }

    #[Test]
    public function super_adminเห็นปุ่มสั่งงาน(): void
    {
        $this->asRole('super_admin');

        $this->get('/admin/swap')->assertOk()->assertInertia(fn ($page) => $page->where('canManageMoney', true));
        $this->get('/admin/finance')->assertOk()->assertInertia(fn ($page) => $page->where('canManageMoney', true));
    }

    // ── ตรวจที่อยู่เราเตอร์ ──────────────────────────────────────────────────

    /** @return array<string, array{mixed}> */
    public static function badAddresses(): array
    {
        return [
            'ข้อความ' => ['not-a-router'],
            'สั้นไป' => ['0x1234'],
            'ยาวไป' => ['0x'.str_repeat('a', 41)],
            'ไม่มี 0x' => [str_repeat('a', 40)],
            'ไม่ใช่เลขฐานสิบหก' => ['0x'.str_repeat('g', 40)],
            'ที่อยู่ศูนย์' => ['0x'.str_repeat('0', 40)],
        ];
    }

    #[Test]
    #[DataProvider('badAddresses')]
    public function ที่อยู่เราเตอร์ผิดรูปแบบถูกปฏิเสธทั้งตอนสร้างและตอนแก้(string $address): void
    {
        $this->asRole('super_admin');
        $values = $this->swapValues(['router_address' => $address]);

        $this->post('/admin/swap', $values)->assertSessionHasErrors('router_address');
        $this->put("/admin/swap/{$this->swap->id}", $values)->assertSessionHasErrors('router_address');

        $this->assertSame(1, SwapConfig::count());
        $this->assertSame(self::OLD_ROUTER, $this->swap->fresh()->router_address);
        $this->assertSame(0, AuditLog::whereNotNull('new_values')->count());
    }

    #[Test]
    #[DataProvider('badAddresses')]
    public function ที่อยู่แฟกทอรีผิดรูปแบบถูกปฏิเสธ(string $address): void
    {
        $this->asRole('super_admin')
            ->put("/admin/swap/{$this->swap->id}", $this->swapValues(['factory_address' => $address]))
            ->assertSessionHasErrors('factory_address');

        $this->assertSame(self::FACTORY, $this->swap->fresh()->factory_address);
    }

    /** ช่องแฟกทอรีเป็นตัวเลือก ฟอร์มส่งค่าว่างมาเมื่อไม่กรอก */
    #[Test]
    public function เว้นว่างที่อยู่แฟกทอรีได้(): void
    {
        $this->asRole('super_admin')
            ->put("/admin/swap/{$this->swap->id}", $this->swapValues(['factory_address' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->swap->fresh()->factory_address);
    }

    // ── ร่องรอย ─────────────────────────────────────────────────────────────

    #[Test]
    public function สลับเราเตอร์แล้วบันทึกทั้งค่าเก่าและค่าใหม่พร้อมตัวผู้สั่ง(): void
    {
        $this->asRole('super_admin')->put("/admin/swap/{$this->swap->id}", $this->swapValues());

        $log = AuditLog::where('action', 'swap_config.update')->sole();

        $this->assertSame(self::OLD_ROUTER, $log->old_values['router_address']);
        $this->assertSame(self::ROUTER, $log->new_values['router_address']);
        $this->assertSame($this->swap->id, $log->new_values['id']);
        $this->assertSame(AdminUser::where('role', 'super_admin')->value('id'), $log->admin_id);
    }

    #[Test]
    public function สร้างเปิดปิดและลบswapทิ้งร่องรอยครบ(): void
    {
        $this->asRole('super_admin');

        $this->post('/admin/swap', $this->swapValues(['name' => 'แถวใหม่']));
        $created = AuditLog::where('action', 'swap_config.create')->sole();
        $this->assertSame([], $created->old_values);
        $this->assertSame(self::ROUTER, $created->new_values['router_address']);

        $this->patch("/admin/swap/{$this->swap->id}/toggle");
        $toggled = AuditLog::where('action', 'swap_config.toggle')->sole();
        $this->assertTrue($toggled->old_values['is_active']);
        $this->assertFalse($toggled->new_values['is_active']);

        $this->delete("/admin/swap/{$this->swap->id}");
        $deleted = AuditLog::where('action', 'swap_config.delete')->sole();
        $this->assertSame(self::OLD_ROUTER, $deleted->old_values['router_address']);
        $this->assertSame([], $deleted->new_values);
    }

    #[Test]
    public function คืนเงินรอบขายคืนยอดครั้งเดียวและทิ้งร่องรอย(): void
    {
        $this->asRole('super_admin');
        $uri = "/admin/token-sales/refund/{$this->cardPayment->id}";

        $this->post($uri)->assertSessionHas('success');
        $this->post($uri)->assertSessionHas('error');

        $this->assertSame(0.0, (float) $this->phase->fresh()->sold);
        $this->assertSame(0.0, (float) $this->phase->tokenSale->fresh()->total_sold);
        $this->assertSame(0.0, (float) $this->phase->tokenSale->fresh()->total_raised_usd);

        $log = AuditLog::where('action', 'token_sale.refund')->sole();
        $this->assertSame('confirmed', $log->old_values['status']);
        $this->assertSame('refunded', $log->new_values['status']);
        $this->assertSame(self::BUYER, $log->new_values['wallet_address']);
    }

    #[Test]
    public function คืนเงิน_stripeบันทึกเลขอ้างอิงคืนเงิน(): void
    {
        $this->fakeStripeRefund();

        $this->asRole('super_admin')
            ->postJson('/admin/finance/refund', ['transaction_id' => $this->cardPayment->id])
            ->assertOk()
            ->assertJsonPath('data.refund_id', 're_test_1');

        $log = AuditLog::where('action', 'finance.refund')->sole();
        $this->assertSame('confirmed', $log->old_values['status']);
        $this->assertSame('refunded', $log->new_values['status']);
        $this->assertSame('re_test_1', $log->new_values['refund_id']);
    }

    /** Stripe ปฏิเสธ = เงินไม่ได้ออก ห้ามมีบันทึกว่าคืนแล้ว */
    #[Test]
    public function คืนเงิน_stripeไม่สำเร็จต้องไม่มีบันทึกว่าคืนแล้ว(): void
    {
        $this->mock(StripePaymentService::class, fn (MockInterface $stripe) => $stripe
            ->shouldReceive('refundTransaction')->andThrow(new RuntimeException('card_error')));

        $this->asRole('super_admin')
            ->postJson('/admin/finance/refund', ['transaction_id' => $this->cardPayment->id])
            ->assertStatus(500);

        $this->assertSame('confirmed', $this->cardPayment->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'finance.refund')->exists());
    }

    #[Test]
    public function ยืนยันและปฏิเสธเงินโอนทิ้งร่องรอย(): void
    {
        $this->asRole('super_admin');

        $this->post("/admin/token-sales/bank/{$this->bankOrder->id}/confirm")->assertSessionHas('success');

        $confirmed = AuditLog::where('action', 'token_sale.bank_confirm')->sole();
        $this->assertSame('pending', $confirmed->old_values['status']);
        $this->assertSame('confirmed', $confirmed->new_values['status']);
        $this->assertSame(self::BUYER, $confirmed->new_values['wallet_address']);

        $order = $this->saleTransaction('pending', 'TPIX-DEF567', [
            'metadata' => ['method' => 'bank_transfer', 'reference' => 'TPIX-DEF567'],
        ]);

        $this->post("/admin/token-sales/bank/{$order->id}/reject", ['reason' => 'ไม่พบเงินเข้า'])->assertSessionHas('success');

        $rejected = AuditLog::where('action', 'token_sale.bank_reject')->sole();
        $this->assertSame('pending', $rejected->old_values['status']);
        $this->assertSame('failed', $rejected->new_values['status']);
    }

    /** คำสั่งซื้อที่ถูกปฏิเสธโดยไม่มีร่องรอยคือเงินลูกค้าที่ตามไม่ได้ว่าใครตัดทิ้ง */
    #[Test]
    public function บันทึกร่องรอยไม่ได้ต้องไม่ปฏิเสธเงินโอน(): void
    {
        AuditLog::creating(function (): void {
            throw new RuntimeException('audit store down');
        });

        $this->asRole('super_admin')
            ->post("/admin/token-sales/bank/{$this->bankOrder->id}/reject", ['reason' => 'ไม่พบเงินเข้า'])
            ->assertServerError();

        $this->assertSame('pending', $this->bankOrder->fresh()->status);
    }

    #[Test]
    public function ปฏิเสธรายการที่ยืนยันแล้วไม่ได้(): void
    {
        $this->asRole('super_admin')
            ->post("/admin/token-sales/bank/{$this->bankOrder->id}/confirm")
            ->assertSessionHas('success');

        $this->post("/admin/token-sales/bank/{$this->bankOrder->id}/reject", ['reason' => 'ไม่พบเงินเข้า'])
            ->assertSessionHasErrors('bank');

        $this->assertSame('confirmed', $this->bankOrder->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'token_sale.bank_reject')->exists());
    }

    /**
     * โควตาหมดระหว่างรอโอน: confirm() commit เครื่องหมาย needs_refund ก่อนโยน exception
     * ถ้า controller ครอบด้วยธุรกรรมชั้นนอก เครื่องหมายจะหาย และไม่เหลือร่องรอยว่าต้องคืนเงินลูกค้า.
     */
    #[Test]
    public function โควตาไม่พอแล้วเครื่องหมายต้องคืนเงินยังอยู่(): void
    {
        $this->phase->update(['allocation' => 1000]);

        $this->asRole('super_admin')
            ->post("/admin/token-sales/bank/{$this->bankOrder->id}/confirm")
            ->assertSessionHasErrors('bank');

        $order = $this->bankOrder->fresh();
        $this->assertSame('failed', $order->status);
        $this->assertTrue($order->metadata['needs_refund']);
        $this->assertFalse(AuditLog::where('action', 'token_sale.bank_confirm')->exists());
    }
}
