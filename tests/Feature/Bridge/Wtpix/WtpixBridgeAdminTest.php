<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\AdminUser;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * หลังบ้านสะพาน TPIX ⇄ wTPIX — เฉพาะ super_admin · ด่านความพร้อมอ่านบทบาทจริงบนเชน ·
 * อนุมัติ/ปฏิเสธตรวจบนเชนซ้ำ · กดเบิ้ลได้ 409 ไม่ทำซ้ำ.
 */
class WtpixBridgeAdminTest extends TestCase
{
    use FakesWtpixBridgeChains;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ', 'email' => 'admin@tpix.test', 'password' => bcrypt('secret-password'),
            'role' => 'super_admin', 'is_active' => true,
        ]);
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin, 'admin');
    }

    private function reviewRow(string $direction = WtpixBridgeTransfer::TO_BSC, string $amount = '150000', string $reason = 'over_tx_limit', array $extra = []): WtpixBridgeTransfer
    {
        static $n = 0;
        $n++;
        $chain = $direction === WtpixBridgeTransfer::TO_BSC ? 4289 : 56;
        $tx = '0x'.hash('sha256', "wtpix-admin-{$n}");

        return WtpixBridgeTransfer::create(array_merge([
            'direction' => $direction, 'source_chain_id' => $chain,
            'source_contract' => $chain === 4289 ? $this->wVault : $this->wToken,
            'tx_hash' => $tx, 'log_index' => 1, 'block_number' => 100 + $n, 'from_address' => $this->alice, 'recipient' => $this->alice,
            'amount_wei' => $this->tpix($amount), 'fee_wei' => '0', 'payout_wei' => $this->tpix($amount),
            'deposit_id' => Abi::depositId($chain, $tx, 1), 'status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'review_reason' => $reason,
            'dest_chain_id' => $chain === 4289 ? 56 : 4289,
        ], $extra));
    }

    #[Test]
    public function only_super_admins_reach_the_wtpix_bridge_admin(): void
    {
        $this->get('/admin/bridge/wtpix')->assertRedirect();

        $support = AdminUser::create([
            'name' => 'ซัพพอร์ต', 'email' => 'support@tpix.test', 'password' => bcrypt('secret-password'), 'role' => 'support', 'is_active' => true,
        ]);
        $this->actingAs($support, 'admin')->post('/admin/bridge/wtpix/toggle', ['enabled' => true])->assertForbidden();
        $this->actingAs($support, 'admin')->post('/admin/bridge/wtpix/transfers/1/approve')->assertForbidden();
    }

    #[Test]
    public function the_dashboard_reads_roles_minter_rights_and_gas_from_both_chains(): void
    {
        $this->reviewRow();

        $this->asAdmin()->get('/admin/bridge/wtpix')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Bridge/Wtpix')
                ->where('state.mint_ready', true)
                ->where('state.release_ready', true)
                ->where('state.token.initial_minter_set', true)
                ->where('state.token.minter_is_minter', true)
                ->where('state.signers.relayer.address', $this->wRelayer)
                ->where('state.signers.relayer.bnb', '1')
                ->where('state.vault.max_lock', '100000')
                ->where('state.minter.daily_cap', '500000')
                ->has('transfers.data', 1)
                ->where('counts.needs_review', fn ($v) => (int) $v === 1));
    }

    #[Test]
    public function readiness_flags_a_missing_minter_right_and_a_missing_initial_minter(): void
    {
        $this->chain[56]['token']['minters'] = [];
        $this->chain[56]['token']['initial_minter_set'] = false;

        $this->asAdmin()->get('/admin/bridge/wtpix')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state.mint_ready', false)
                ->where('state.checks', fn ($checks) => collect($checks)->where('key', 'minter_is_minter')->first()['ok'] === false
                    && collect($checks)->where('key', 'initial_minter_set')->first()['ok'] === false));
    }

    #[Test]
    public function approving_requeues_the_row_and_a_double_click_gets_409(): void
    {
        $row = $this->reviewRow(amount: '5000');

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk()->assertJsonPath('success', true);
        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(409);

        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->status);
        $this->assertTrue($row->limits_overridden);
        $this->assertSame($this->admin->id, $row->reviewed_by);
        $this->assertSame([], $this->signCalls, 'the web never signs — the CLI relay does');
    }

    #[Test]
    public function approving_above_the_on_chain_max_is_refused(): void
    {
        $row = $this->reviewRow(amount: '150000');

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    #[Test]
    public function a_row_already_executed_on_chain_is_flagged_first_and_only_closed_after_a_second_explicit_approval(): void
    {
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_TPIX, '500');
        $this->chain[4289]['vault']['processed'][$row->deposit_id] = true;

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(409);
        $this->assertSame('released_elsewhere', $row->fresh()->review_reason);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk();
        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->status);
        $this->assertSame($this->wVault, $row->dest_contract);
    }

    #[Test]
    public function a_row_from_a_contract_that_is_no_longer_configured_cannot_be_approved(): void
    {
        $row = $this->reviewRow(amount: '500', reason: 'config_changed', extra: ['source_chain_id' => 97, 'deposit_id' => '0x'.str_repeat('1', 64)]);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(422);
    }

    #[Test]
    public function rejecting_needs_a_reason_checks_the_chain_and_is_409_on_repeat(): void
    {
        $row = $this->reviewRow(amount: '500');

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/reject", [])->assertStatus(422);
        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/reject", ['reason' => 'ล็อกจากกระดานเทรด'])->assertOk();
        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/reject", ['reason' => 'ซ้ำ'])->assertStatus(409);

        $this->assertSame(WtpixBridgeTransfer::STATUS_REJECTED, $row->fresh()->status);
    }

    #[Test]
    public function a_row_already_minted_on_chain_cannot_be_rejected(): void
    {
        $row = $this->reviewRow(amount: '500');
        $this->chain[56]['minter']['processed'][$row->deposit_id] = true;

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/reject", ['reason' => 'x'])->assertStatus(409);
        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    #[Test]
    public function an_unreadable_destination_chain_answers_503_instead_of_guessing(): void
    {
        $row = $this->reviewRow(amount: '500');
        $this->chain[56]['down'] = true;

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(503);
        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/reject", ['reason' => 'x'])->assertStatus(503);
        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    #[Test]
    public function the_switch_toggles_and_settings_reject_inverted_limits(): void
    {
        $this->asAdmin()->postJson('/admin/bridge/wtpix/toggle', ['enabled' => false, 'reason' => 'บำรุงรักษา'])->assertOk();
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->asAdmin()->postJson('/admin/bridge/wtpix/toggle', ['enabled' => true])->assertOk();
        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());

        $this->asAdmin()->putJson('/admin/bridge/wtpix/settings', ['limits' => ['lock_fee' => '50']])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->asAdmin()->putJson('/admin/bridge/wtpix/settings', ['limits' => ['min_lock' => 'abc']])->assertStatus(422);

        $this->asAdmin()->putJson('/admin/bridge/wtpix/settings', ['limits' => ['max_lock_per_tx' => '50000', 'burn_fee' => '0.5']])
            ->assertOk()->assertJsonPath('data.limits.max_lock_per_tx', '50000');
        $this->assertSame('0.5', app(WtpixBridgeConfig::class)->limit('burn_fee'));
    }
}
