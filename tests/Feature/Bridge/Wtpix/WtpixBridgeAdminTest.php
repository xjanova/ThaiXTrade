<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\AdminUser;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use Illuminate\Support\Facades\Auth;
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

    /** ล็อกอินผ่าน session แบบเบราว์เซอร์ — actingAs($admin, 'admin') สลับ guard ปริยายให้เองจนเทสต์ reviewed_by พิสูจน์อะไรไม่ได้ */
    private function asAdmin(): self
    {
        return $this->withSession([Auth::guard('admin')->getName() => $this->admin->id]);
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
        $this->assertStringContainsString('อนุมัติโดย ผู้ดูแลระบบ', $row->review_note);
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
        $tx = $this->fakeForeignRelease($row->recipient, (string) $row->payout_wei, 1950, $row->deposit_id);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(409);
        $this->assertSame('released_elsewhere', $row->fresh()->review_reason);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk();
        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->status);
        $this->assertSame($this->wVault, $row->dest_contract);
        $this->assertSame($tx, $row->dest_tx_hash);
    }

    #[Test]
    public function an_elsewhere_row_with_no_event_on_chain_cannot_be_closed(): void
    {
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_TPIX, '500', 'released_elsewhere');
        $this->chain[4289]['vault']['processed'][$row->deposit_id] = true;

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(422);

        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    #[Test]
    public function an_elsewhere_row_paid_to_someone_else_cannot_be_closed(): void
    {
        // คีย์ relayer หลุดและปล่อย depositId นี้เข้ากระเป๋าคนร้าย
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_TPIX, '500', 'released_elsewhere');
        $this->chain[4289]['vault']['processed'][$row->deposit_id] = true;
        $this->fakeForeignRelease($this->bob, (string) $row->payout_wei, 1950, $row->deposit_id);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(422);

        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    #[Test]
    public function an_elsewhere_row_with_the_wrong_amount_cannot_be_closed(): void
    {
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_TPIX, '500', 'released_elsewhere');
        $this->chain[4289]['vault']['processed'][$row->deposit_id] = true;
        $this->fakeForeignRelease($row->recipient, '1', 1950, $row->deposit_id);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(422);

        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    #[Test]
    public function a_minted_elsewhere_row_is_closed_from_the_bridge_minted_event(): void
    {
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_BSC, '500', 'minted_elsewhere');
        $this->chain[56]['minter']['processed'][$row->deposit_id] = true;
        $tx = $this->fakeForeignBridgeMint($row->recipient, (string) $row->payout_wei, $row->deposit_id, 950);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk();

        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->status);
        $this->assertSame($tx, $row->dest_tx_hash);
    }

    /** แถวเก่า: สร้างไว้ตั้งแต่ก่อนบล็อก 950 แล้วเชนเดินไปอีกไกลเกินเพดาน */
    private function agedElsewhereRow(int $eventBlock, int $latest): WtpixBridgeTransfer
    {
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_BSC, '500', 'minted_elsewhere');
        $this->chain[56]['minter']['processed'][$row->deposit_id] = true;
        $this->fakeForeignBridgeMint($row->recipient, (string) $row->payout_wei, $row->deposit_id, $eventBlock);
        $this->chain[56]['latest'] = $latest;
        WtpixBridgeTransfer::whereKey($row->id)->update(['created_at' => now()->subSeconds(($latest - 950) * 2)]);

        return $row;
    }

    #[Test]
    public function an_old_row_closes_when_the_event_sits_near_its_source_transaction(): void
    {
        $row = $this->agedElsewhereRow(960, 1000000);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk();

        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
    }

    #[Test]
    public function an_event_beyond_the_window_needs_a_larger_window_not_a_database_edit(): void
    {
        config(['wtpix_bridge.bsc.verify_max_blocks' => 5000]);
        $row = $this->agedElsewhereRow(30000, 50000);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(422);
        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);

        config(['wtpix_bridge.bsc.verify_max_blocks' => 60000]);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
    }

    #[Test]
    public function unreadable_event_logs_leave_the_row_open(): void
    {
        $row = $this->reviewRow(WtpixBridgeTransfer::TO_TPIX, '500', 'released_elsewhere');
        $this->chain[4289]['vault']['processed'][$row->deposit_id] = true;
        $this->chain[4289]['fail_logs'] = true;

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(503);

        $this->assertSame(WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, $row->fresh()->status);
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

    /** ล็อกต่ำกว่าขั้นต่ำจริงบนเชน แล้วให้ตัวสแกนบันทึกเป็น ignored/below_minimum (ตัวสแกนไม่ถามเชนเรื่องผู้ส่ง) */
    private function belowMinimumLock(string $from): WtpixBridgeTransfer
    {
        $this->artisan('bridge:wtpix-scan')->assertSuccessful(); // รอบแรกตั้งเคอร์เซอร์ที่หัว
        $this->fakeLock($from, $this->tpix('5'), 2001);
        $this->chain[4289]['latest'] = 2005;
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();

        $row = WtpixBridgeTransfer::firstOrFail();
        $this->assertSame([WtpixBridgeTransfer::STATUS_IGNORED, 'below_minimum'], [$row->status, $row->review_reason]);

        return $row;
    }

    #[Test]
    public function approving_a_below_minimum_lock_from_a_wallet_rechecks_the_chain_then_queues_it(): void
    {
        $row = $this->belowMinimumLock($this->alice);

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk()->assertJsonPath('success', true);

        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->status);
        $this->assertTrue($row->limits_overridden);
    }

    #[Test]
    public function approving_a_below_minimum_lock_from_a_contract_moves_it_to_review_instead_of_minting(): void
    {
        $multisig = '0x5afe00000000000000000000000000000000a5fe';
        $this->chain[4289]['codes'][$multisig] = '0x6080';
        $row = $this->belowMinimumLock($multisig);

        // อนุมัติครั้งแรก = ข้ามแค่ขั้นต่ำ — เจอผู้ส่งเป็นสัญญาต้องหยุด ไม่เข้าคิว mint
        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")
            ->assertStatus(409)->assertJsonPath('data.review_reason', 'sender_is_contract');

        $row->refresh();
        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'sender_is_contract'], [$row->status, $row->review_reason]);

        // แอดมินตรวจแล้วรับรองข้อสงสัยนี้อย่างตั้งใจ = อนุมัติแถว needs_review ตามปกติ
        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertOk();
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->fresh()->status);
    }

    #[Test]
    public function approving_a_below_minimum_lock_burned_through_someone_else_is_sent_to_review(): void
    {
        // คนกดส่งธุรกรรมไม่ใช่เจ้าของเหรียญ (สัญญาตัวกลางล็อกแทน) — ผู้รับอาจไม่ใช่คนที่ตั้งใจ
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();
        $this->fakeLock($this->alice, $this->tpix('5'), 2001, txFrom: $this->bob);
        $this->chain[4289]['latest'] = 2005;
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();
        $row = WtpixBridgeTransfer::firstOrFail();

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(409);
        $this->assertSame('sender_mismatch', $row->fresh()->review_reason);
    }

    #[Test]
    public function approving_a_below_minimum_lock_answers_503_when_the_source_chain_is_unreadable(): void
    {
        $row = $this->belowMinimumLock($this->alice);
        $this->chain[4289]['down'] = true;

        $this->asAdmin()->postJson("/admin/bridge/wtpix/transfers/{$row->id}/approve")->assertStatus(503);
        $this->assertSame(WtpixBridgeTransfer::STATUS_IGNORED, $row->fresh()->status);
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
