<?php

namespace Tests\Feature\TokenFactory;

use App\Models\AdminUser;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Models\TreasuryPayout;
use App\Services\TokenFactory\TokenFactoryException;
use App\Services\TokenFactory\TokenFactoryRefunds;
use App\Services\TokenFactoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * ปฏิเสธคำขอแล้วต้องคืนค่าธรรมเนียมเต็มจำนวน — แอดมินโอนเอง (ระบบตรวจบนเชน) หรือจ่ายอัตโนมัติจากกระเป๋าร้อน.
 */
class TokenFactoryRefundTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    private string $hotWallet = '0x7777777777777777777777777777777777777777';

    private string $refundHash = '0x5555555555555555555555555555555555555555555555555555555555555555';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
        Queue::fake();

        SiteSetting::set('revenue', 'tpix_wallet', $this->hotWallet);
        config(['treasury.limits.per_transaction' => '0', 'treasury.limits.per_day' => '0']);

        $this->admin = AdminUser::create([
            'name' => 'Admin', 'email' => 'admin@tpix.test', 'password' => bcrypt('x'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    /** คำขอที่จ่ายค่าธรรมเนียม 50 TPIX ที่บล็อก 90 แล้วถูกปฏิเสธ */
    private function rejectedPaidToken(): FactoryToken
    {
        $token = FactoryToken::create([
            'name' => 'Good Coin', 'symbol' => 'GOOD', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => $this->creator, 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'pending',
            'fee_tx_hash' => '0x'.str_repeat('a', 64),
            'metadata' => [
                'fee_wallet' => $this->feeWallet,
                'fee_block' => 90,
                'fee_value_wei' => '50000000000000000000',
            ],
        ]);

        return app(TokenFactoryService::class)->rejectToken($token, 'ชื่อซ้ำแบรนด์');
    }

    private function putRefundTx(array $tx = [], int $block = 95): void
    {
        $this->putFeeTx($this->refundHash, array_merge([
            'from' => $this->feeWallet,
            'to' => $this->creator,
            'value' => self::tpixHex('50'),
        ], $tx), [], null, $block);
    }

    private function confirm(FactoryToken $token): FactoryToken
    {
        return app(TokenFactoryRefunds::class)->confirmManual($token->fresh(), $this->refundHash);
    }

    private function assertRefundError(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("ต้องปฏิเสธด้วย {$code}");
        } catch (TokenFactoryException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    #[Test]
    public function ปฏิเสธคำขอที่จ่ายแล้ว_เปิดรายการรอคืนเต็มยอดที่โอนมา(): void
    {
        $token = $this->rejectedPaidToken();

        $this->assertSame('rejected', $token->status);
        $this->assertSame(FactoryToken::REFUND_PENDING, $token->refund_status);
        $this->assertSame('manual', $token->refund_mode);
        $this->assertSame('50000000000000000000', $token->refund_amount_wei);
        $this->assertSame(0, TreasuryPayout::count(), 'โหมดโอนเองต้องไม่สร้างคิวจ่าย');
    }

    #[Test]
    public function คำขอที่ไม่ได้จ่ายอะไร_ไม่มีรายการคืนเงิน(): void
    {
        $token = FactoryToken::create([
            'name' => 'Free', 'symbol' => 'FREE', 'total_supply' => '1', 'creator_address' => $this->creator,
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => 'pending',
        ]);

        $token = app(TokenFactoryService::class)->rejectToken($token, 'x');

        $this->assertNull($token->refund_status);
    }

    #[Test]
    public function โอนคืนเองแล้ววาง_hash_ระบบตรวจบนเชนแล้วปิดรายการ(): void
    {
        $token = $this->rejectedPaidToken();
        $this->putRefundTx();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/token-factory/{$token->id}/refund/manual", ['tx_hash' => $this->refundHash])
            ->assertSessionHas('success');

        $fresh = $token->fresh();
        $this->assertSame(FactoryToken::REFUND_REFUNDED, $fresh->refund_status);
        $this->assertSame($this->refundHash, $fresh->refund_tx_hash);
        $this->assertNotNull($fresh->refunded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'token_factory.refund_manual', 'model_id' => $token->id]);

        // ปิดแล้วยืนยันซ้ำไม่ได้
        $this->assertRefundError('REFUND_ALREADY_DONE', fn () => $this->confirm($token));
    }

    #[Test]
    public function ธุรกรรมคืนเงินผิดเงื่อนไขถูกปฏิเสธทุกช่อง(): void
    {
        $token = $this->rejectedPaidToken();

        $this->putRefundTx(['to' => '0x2222222222222222222222222222222222222222']);
        $this->assertRefundError('REFUND_WRONG_RECIPIENT', fn () => $this->confirm($token));

        $this->putRefundTx(['from' => '0x3333333333333333333333333333333333333333']);
        $this->assertRefundError('REFUND_WRONG_SENDER', fn () => $this->confirm($token));

        $this->putRefundTx(['value' => self::tpixHex('49.99')]);
        $this->assertRefundError('REFUND_TOO_LOW', fn () => $this->confirm($token));

        $this->putRefundTx([], 90); // บล็อกเดียวกับที่จ่ายค่าธรรมเนียม
        $this->assertRefundError('REFUND_TX_TOO_OLD', fn () => $this->confirm($token));

        $this->putRefundTx();
        $this->chain['receipts'][$this->refundHash]['status'] = '0x0';
        $this->assertRefundError('REFUND_TX_FAILED', fn () => $this->confirm($token));

        $this->putRefundTx([], 100); // 1 บล็อกยืนยัน ต้องการ 2
        $this->assertRefundError('REFUND_TX_PENDING', fn () => $this->confirm($token));

        // ธุรกรรมค่าธรรมเนียมของใครสักคนเอามาอ้างเป็นการคืนเงินไม่ได้
        FactoryToken::whereKey($token->id)->update(['fee_tx_hash' => $this->refundHash]);
        $this->putRefundTx();
        $this->assertRefundError('REFUND_TX_USED', fn () => $this->confirm($token));

        $this->assertSame(FactoryToken::REFUND_PENDING, $token->fresh()->refund_status);
    }

    #[Test]
    public function โอนคืนจากกระเป๋าร้อนก็นับ(): void
    {
        $token = $this->rejectedPaidToken();
        $this->putRefundTx(['from' => $this->hotWallet]);

        $this->assertSame(FactoryToken::REFUND_REFUNDED, $this->confirm($token)->refund_status);
    }

    #[Test]
    public function โหมดอัตโนมัติ_ปฏิเสธแล้วเข้าคิวจ่ายจากกระเป๋าร้อนทันที_ผลคิวไหลกลับมาที่เหรียญ(): void
    {
        $this->setPolicy(['refund_mode' => 'auto']);
        $token = $this->rejectedPaidToken();

        $payout = TreasuryPayout::sole();
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->status);
        $this->assertSame(TokenFactoryRefunds::PAYOUT_PURPOSE, $payout->purpose);
        $this->assertSame($this->creator, strtolower($payout->to_address));
        $this->assertSame('50000000000000000000', (string) $payout->amount_wei);
        $this->assertNull($payout->tx_hash);

        $this->assertSame(FactoryToken::REFUND_SENDING, $token->fresh()->refund_status);
        $this->assertSame($payout->id, (int) $token->fresh()->refund_payout_id);

        // ระหว่างคิวกำลังจ่าย แอดมินยืนยันโอนเองไม่ได้ (กันคืนซ้ำ) และกดจ่ายอีกไม่สร้างคิวซ้ำ
        $this->putRefundTx();
        $this->assertRefundError('REFUND_AUTO_IN_PROGRESS', fn () => $this->confirm($token));
        app(TokenFactoryRefunds::class)->queueAuto($token->fresh());
        $this->assertSame(1, TreasuryPayout::count());

        $payout->update(['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('6', 64), 'confirmed_at' => now()]);

        $fresh = $token->fresh();
        $this->assertSame(FactoryToken::REFUND_REFUNDED, $fresh->refund_status);
        $this->assertSame('0x'.str_repeat('6', 64), $fresh->refund_tx_hash);
    }

    #[Test]
    public function คิวจ่ายล้มเหลว_รายการเป็น_failed_แล้วแอดมินสั่งใหม่ได้(): void
    {
        $this->setPolicy(['refund_mode' => 'auto']);
        $token = $this->rejectedPaidToken();

        TreasuryPayout::sole()->update(['status' => TreasuryPayout::STATUS_FAILED, 'failure_reason' => 'nonce too low']);

        $fresh = $token->fresh();
        $this->assertSame(FactoryToken::REFUND_FAILED, $fresh->refund_status);
        $this->assertSame('nonce too low', $fresh->metadata['refund_error']);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/token-factory/{$token->id}/refund/auto")
            ->assertSessionHas('success');

        $this->assertSame(2, TreasuryPayout::count());
        $this->assertStringEndsWith(':2', TreasuryPayout::latest('id')->first()->idempotency_key);
        $this->assertSame(FactoryToken::REFUND_SENDING, $token->fresh()->refund_status);
    }

    #[Test]
    public function จ่ายอัตโนมัติไม่ผ่านวงเงิน_การปฏิเสธยังสำเร็จ_รายการเป็น_failed(): void
    {
        config(['treasury.limits.per_transaction' => '10']);
        $this->setPolicy(['refund_mode' => 'auto']);

        $token = $this->rejectedPaidToken();

        $this->assertSame('rejected', $token->status);
        $this->assertSame(FactoryToken::REFUND_FAILED, $token->fresh()->refund_status);
        $this->assertStringContainsString('วงเงินต่อครั้ง', $token->fresh()->metadata['refund_error']);
        $this->assertSame(0, TreasuryPayout::count());
    }

    #[Test]
    public function moderator_สั่งคืนเงินไม่ได้(): void
    {
        $token = $this->rejectedPaidToken();
        $moderator = AdminUser::create([
            'name' => 'Mod', 'email' => 'mod@tpix.test', 'password' => bcrypt('x'), 'role' => 'moderator', 'is_active' => true,
        ]);

        $this->actingAs($moderator, 'admin')->post("/admin/token-factory/{$token->id}/refund/auto")->assertForbidden();
        $this->assertSame(0, TreasuryPayout::count());
    }
}
