<?php

namespace Tests\Feature\Bridge;

use App\Models\AdminUser;
use App\Models\BridgeDeposit;
use App\Models\BridgeWithdrawal;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * หลังบ้านสะพาน — อนุมัติ/ปฏิเสธต้องตรวจซ้ำบนเชน · กดเบิ้ลไม่ทำซ้ำ · เฉพาะ super_admin.
 */
class BridgeAdminTest extends TestCase
{
    use FakesBridgeChains;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin, 'admin');
    }

    private function reviewDeposit(string $units = '1500000000', string $reason = 'over_tx_limit'): BridgeDeposit
    {
        $tx = '0x'.hash('sha256', "admin-dep-{$units}-{$reason}");

        return BridgeDeposit::create([
            'source_chain_id' => 1, 'tx_hash' => $tx, 'log_index' => 2, 'block_number' => 4800,
            'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtEth,
            'token_decimals' => 6, 'amount_raw' => $units, 'amount_units' => $units, 'mint_units' => $units,
            'deposit_id' => Abi::depositId(1, $tx, 2), 'status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'review_reason' => $reason,
        ]);
    }

    private function reviewWithdrawal(string $target, ?int $chainId = 56, string $reason = 'over_tx_limit'): BridgeWithdrawal
    {
        return BridgeWithdrawal::create([
            'tx_hash' => '0x'.hash('sha256', "admin-wd-{$target}-{$reason}"), 'log_index' => 0, 'block_number' => 1,
            'from_address' => strtolower($this->alice), 'amount_units' => '1500000000', 'target_chain_raw' => (string) ($chainId ?? 999),
            'target_chain_id' => $chainId, 'target_address' => $target, 'fee_units' => '300000', 'payout_units' => '1499700000',
            'payout_amount_raw' => '1499700000000000000000', 'status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'review_reason' => $reason,
        ]);
    }

    #[Test]
    public function only_super_admins_reach_the_bridge_admin(): void
    {
        $this->get('/admin/bridge')->assertRedirect();

        $support = AdminUser::create([
            'name' => 'ซัพพอร์ต', 'email' => 'support@tpix.test', 'password' => bcrypt('secret-password'),
            'role' => 'support', 'is_active' => true,
        ]);
        $this->actingAs($support, 'admin')->post('/admin/bridge/toggle', ['enabled' => true])->assertForbidden();
    }

    #[Test]
    public function the_dashboard_renders_with_state_and_lists(): void
    {
        $this->reviewDeposit();

        $this->asAdmin()->get('/admin/bridge?tab=deposits')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Bridge/Index')
                ->where('tab', 'deposits')
                ->where('state.installed', true)
                ->where('state.addresses.minter', $this->minter)
                ->where('state.signers.relayer.address', strtolower($this->relayerAddr))
                ->where('state.mint_ready', true)
                ->has('deposits.data', 1)
                ->where('deposits.data.0.review_reason', 'over_tx_limit'));
    }

    #[Test]
    public function approving_a_deposit_requeues_it_and_a_double_click_does_nothing(): void
    {
        $d = $this->reviewDeposit('900000000'); // 900 USDT < maxPerTx 1,000 บนสัญญา

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertOk()->assertJsonPath('success', true);
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertStatus(409);

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $d->status);
        $this->assertTrue($d->limits_overridden);
        $this->assertSame($this->admin->id, $d->reviewed_by);
    }

    #[Test]
    public function approval_rechecks_the_chain_before_requeueing(): void
    {
        // เกิน maxPerTx บนสัญญา → อนุมัติไม่ได้ (mint ไปก็ revert)
        $big = $this->reviewDeposit('1500000000');
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$big->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $big->fresh()->status);

        // mint ไปแล้วบนสัญญา → ปรับเป็นสำเร็จ ไม่ส่งกลับเข้าคิว
        $done = $this->reviewDeposit('500000000', 'sender_mismatch');
        $this->chainState[4289]['processed'][$done->deposit_id] = true;
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$done->id}/approve")->assertOk();
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $done->fresh()->status);

        // อ่านเชนไม่ได้ → ไม่เดา
        $other = $this->reviewDeposit('400000000', 'kyc_required');
        $this->chainState[4289]['down'] = true;
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$other->id}/approve")->assertStatus(503);
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $other->fresh()->status);
    }

    #[Test]
    public function rejecting_requires_a_reason(): void
    {
        $d = $this->reviewDeposit();

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/reject", [])->assertStatus(422);
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/reject", ['reason' => 'ส่งมาจากกระดานเทรด — คืนเงินแล้วนอกระบบ'])->assertOk();

        $this->assertSame(BridgeDeposit::STATUS_REJECTED, $d->fresh()->status);
        $this->assertStringContainsString('กระดานเทรด', $d->fresh()->review_note);
    }

    #[Test]
    public function an_undeliverable_withdrawal_cannot_be_approved_but_can_be_rejected(): void
    {
        $bad = $this->reviewWithdrawal('TXYZnotEvm', 56, 'invalid_target_address');

        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$bad->id}/approve")->assertStatus(422);
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $bad->fresh()->status);

        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$bad->id}/reject", ['reason' => 'ที่อยู่ไม่ใช่ EVM — mint คืนบน TPIX แล้ว'])->assertOk();
        $this->assertSame(BridgeWithdrawal::STATUS_REJECTED, $bad->fresh()->status);
    }

    #[Test]
    public function approving_an_over_limit_withdrawal_requeues_it_for_payout(): void
    {
        $w = $this->reviewWithdrawal(strtolower($this->bob));

        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve")->assertOk();
        $this->assertSame(BridgeWithdrawal::STATUS_PENDING, $w->fresh()->status);
        $this->assertTrue($w->fresh()->limits_overridden);

        // คิวจ่ายไม่ปัดกลับไป needs_review เพราะวงเงิน (แอดมินรับรองแล้ว)
        $this->artisan('bridge:payout')->assertSuccessful();
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->fresh()->status);
    }

    #[Test]
    public function the_site_switch_and_settings_can_be_changed_with_validation(): void
    {
        $this->asAdmin()->postJson('/admin/bridge/toggle', ['enabled' => false, 'reason' => 'ตรวจระบบ'])->assertOk();
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertStringContainsString('ตรวจระบบ', app(BridgeConfig::class)->switchReason());

        // วงเงินกลับหัว → ปฏิเสธ
        $this->asAdmin()->putJson('/admin/bridge/settings', ['limits' => ['min_deposit' => '50', 'max_deposit_per_tx' => '10']])
            ->assertStatus(422);
        // ค่าธรรมเนียมเท่ากับขั้นต่ำการถอน → ผู้ใช้ได้ศูนย์ → ปฏิเสธ
        $this->asAdmin()->putJson('/admin/bridge/settings', ['fees' => [1 => '2']])->assertStatus(422);
        // รูปแบบผิด
        $this->asAdmin()->putJson('/admin/bridge/settings', ['limits' => ['min_deposit' => '1.1234567']])->assertStatus(422);

        $this->asAdmin()->putJson('/admin/bridge/settings', [
            'limits' => ['max_deposit_per_tx' => '1500', 'max_deposit_per_address_daily' => '3000'],
            'fees' => [1 => '1.5'],
            'chains' => [1 => false],
        ])->assertOk();

        $config = app(BridgeConfig::class);
        $this->assertSame('1500', $config->limit('max_deposit_per_tx'));
        $this->assertSame('1.5', $config->withdrawFee(1));
        $this->assertFalse($config->chainEnabled(1));
        $this->assertTrue($config->chainEnabled(56));
    }
}
