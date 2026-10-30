<?php

namespace Tests\Feature\Bridge;

use App\Models\AdminUser;
use App\Models\BridgeDeposit;
use App\Models\BridgeWithdrawal;
use App\Models\User;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePayouter;
use Illuminate\Support\Facades\Auth;
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

    /** ล็อกอินผ่าน session แบบเบราว์เซอร์ — actingAs($admin, 'admin') สลับ guard ปริยายให้เองจนเทสต์ reviewed_by พิสูจน์อะไรไม่ได้ */
    private function asAdmin(): self
    {
        return $this->withSession([Auth::guard('admin')->getName() => $this->admin->id]);
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

    /** เงินฝากต่ำกว่าขั้นต่ำแบบที่ตัวสแกนบันทึก: ignored และไม่เคยถามเชนเรื่องผู้ส่ง */
    private function belowMinimumDeposit(?string $txFrom = null): BridgeDeposit
    {
        $tx = $this->fakeDeposit(1, $this->alice, '5000000', 4800, $txFrom);

        return BridgeDeposit::create([
            'source_chain_id' => 1, 'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 4800,
            'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtEth,
            'token_decimals' => 6, 'amount_raw' => '5000000', 'amount_units' => '5000000', 'mint_units' => '4000000',
            'deposit_id' => Abi::depositId(1, $tx, 0), 'status' => BridgeDeposit::STATUS_IGNORED, 'review_reason' => 'below_minimum',
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
    public function a_below_minimum_deposit_from_a_contract_moves_to_review_instead_of_minting(): void
    {
        $d = $this->belowMinimumDeposit();
        $this->chainState[1]['codes'][strtolower($this->alice)] = '0x6080604052';

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertStatus(409);

        $d->refresh();
        $this->assertSame([BridgeDeposit::STATUS_NEEDS_REVIEW, 'sender_is_contract'], [$d->status, $d->review_reason]);
        $this->assertFalse((bool) $d->limits_overridden);
        $this->assertStringContainsString('below_minimum', (string) $d->review_note);

        // แอดมินตรวจข้อสงสัยจริงแล้วรับรองอย่างตั้งใจอีกครั้ง
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertOk();
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $d->fresh()->status);
    }

    #[Test]
    public function a_below_minimum_deposit_sent_through_a_third_party_moves_to_review(): void
    {
        $d = $this->belowMinimumDeposit(txFrom: $this->bob);

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertStatus(409);

        $this->assertSame('sender_mismatch', $d->fresh()->review_reason);
    }

    #[Test]
    public function a_clean_below_minimum_deposit_is_approved_on_the_first_click(): void
    {
        $d = $this->belowMinimumDeposit();

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertOk();

        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $d->fresh()->status);
    }

    #[Test]
    public function an_unreadable_source_chain_blocks_a_below_minimum_approval(): void
    {
        $d = $this->belowMinimumDeposit();
        $this->chainState[1]['down'] = true;

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertStatus(503);

        $this->assertSame(BridgeDeposit::STATUS_IGNORED, $d->fresh()->status);
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
        $this->assertStringContainsString('อนุมัติโดย ผู้ดูแลระบบ', $d->review_note);
    }

    /** bridge ไม่มี foreign key ที่ reviewed_by — รหัสสมาชิกลงได้เงียบๆ แล้วบันทึกชี้ไปผิดคน */
    #[Test]
    public function a_review_is_signed_by_the_admin_even_with_a_member_in_the_same_browser(): void
    {
        User::create(['wallet_address' => strtolower($this->bob), 'email' => 'first@tpix.test', 'password' => 'secret-password']);
        $member = User::create(['wallet_address' => strtolower($this->alice), 'name' => 'สมาชิก', 'email' => 'member@tpix.test', 'password' => 'secret-password']);
        $this->withSession([Auth::guard('web')->getName() => $member->id]);
        $this->assertNotSame($this->admin->id, $member->id);

        $d = $this->reviewDeposit();
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/reject", ['reason' => 'คืนเงินแล้ว'])->assertOk();

        $d->refresh();
        $this->assertSame($this->admin->id, $d->reviewed_by);
        $this->assertStringContainsString('ปฏิเสธโดย ผู้ดูแลระบบ: คืนเงินแล้ว', $d->review_note);
    }

    #[Test]
    public function the_kill_switch_records_who_turned_it_off(): void
    {
        $this->asAdmin()->postJson('/admin/bridge/toggle', ['enabled' => false, 'reason' => 'ตรวจกระทบยอด'])->assertOk();

        $this->assertSame('ปิดโดย ผู้ดูแลระบบ: ตรวจกระทบยอด', app(BridgeConfig::class)->switchReason());
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

        // mint ไปแล้วบนสัญญา และ Minted ไปถึงผู้ฝากครบ → ปรับเป็นสำเร็จ ไม่ส่งกลับเข้าคิว
        $done = $this->reviewDeposit('500000000', 'sender_mismatch');
        $mintTx = $this->fakeMinted($done->deposit_id, $this->alice, '500000000');
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$done->id}/approve")->assertOk();
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $done->fresh()->status);
        $this->assertSame($mintTx, $done->fresh()->mint_tx_hash, 'ลิงก์ไปธุรกรรมที่ mint จริง');

        // อ่านเชนไม่ได้ → ไม่เดา
        $other = $this->reviewDeposit('400000000', 'kyc_required');
        $this->chainState[4289]['down'] = true;
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$other->id}/approve")->assertStatus(503);
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $other->fresh()->status);
    }

    #[Test]
    public function a_minted_elsewhere_deposit_can_only_be_closed_when_minted_reached_the_depositor(): void
    {
        // คีย์ relayer ที่หลุด mint depositId นี้เข้ากระเป๋าตัวเอง — เดิมกดอนุมัติแล้วกลายเป็น "สำเร็จ" ผู้ฝากไม่ได้เงิน
        $stolen = $this->reviewDeposit('500000000', 'minted_elsewhere');
        $this->fakeMinted($stolen->deposit_id, $this->bob, '500000000');

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$stolen->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $stolen->fresh()->status);

        // สัญญาบอกว่า mint แล้วแต่หา Minted ไม่เจอ = พิสูจน์ไม่ได้ → ไม่ปิดเป็นสำเร็จ
        $unknown = $this->reviewDeposit('400000000', 'minted_elsewhere');
        $this->chainState[4289]['processed'][$unknown->deposit_id] = true;
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$unknown->id}/approve")->assertStatus(422);
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $unknown->fresh()->status);
    }

    #[Test]
    public function a_deposit_whose_old_mint_tx_can_still_land_is_neither_reapproved_nor_rejected(): void
    {
        $hash = '0x'.str_repeat('5', 64);
        $d = $this->reviewDeposit('300000000', 'stuck_pending');
        $d->update(['mint_tx_hash' => $hash, 'mint_nonce' => 0, 'mint_raw_tx' => '0xf86c00']);
        // ยังอยู่ใน mempool · nonce ของ relayer บนเชนยังเป็น 0
        $this->chainState[4289]['txs'][$hash] = ['hash' => $hash, 'from' => strtolower($this->relayerAddr), 'nonce' => '0x0', 'blockNumber' => null];

        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/approve")->assertStatus(409);
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/reject", ['reason' => 'คืนเงินแล้ว'])->assertStatus(409);
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->fresh()->status);

        // nonce ถูกใช้ไปแล้ว (เจ้าของส่งธุรกรรมแทนที่) และสัญญายังไม่เคย mint → ปฏิเสธแล้วคืนเงินด้วยมือได้
        $this->chainState[4289]['nonce'][strtolower($this->relayerAddr)] = 1;
        $this->asAdmin()->postJson("/admin/bridge/deposits/{$d->id}/reject", ['reason' => 'คืนเงินจาก vault แล้ว'])->assertOk();
        $this->assertSame(BridgeDeposit::STATUS_REJECTED, $d->fresh()->status);
    }

    #[Test]
    public function a_withdrawal_with_a_dead_payout_tx_is_re_paid_only_with_on_chain_proof_of_the_nonce(): void
    {
        $w = $this->reviewWithdrawal(strtolower($this->bob), 56, 'nonce_consumed');
        $w->update([
            'payout_tx_hash' => '0x'.str_repeat('6', 64), 'payout_nonce' => 3,
            'payout_from' => strtolower($this->payoutAddr), 'payout_raw_tx' => '0xf86c03',
        ]);
        $this->chainState[56]['nonce'][strtolower($this->payoutAddr)] = 4;

        // ไม่มีใบเสร็จ + nonce ถูกใช้ แต่ไม่รู้ว่าใครใช้ → ยังห้ามจ่าย
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('needs_nonce_proof', true);

        // หลักฐานปลอม (ธุรกรรมของกระเป๋าอื่น) → ยังห้าม
        $foreign = $this->fakeCancelTx(56, $this->alice, 3);
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve", ['nonce_tx_hash' => $foreign])->assertStatus(409);
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve", ['nonce_tx_hash' => 'not-a-hash'])->assertStatus(422);
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $w->fresh()->status);

        // ธุรกรรมยกเลิกที่เจ้าของส่งเองด้วย nonce 3 → พิสูจน์ได้ → เข้าคิวจ่ายใหม่
        $cancel = $this->fakeCancelTx(56, $this->payoutAddr, 3);
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve", ['nonce_tx_hash' => $cancel])->assertOk();
        $this->assertSame(BridgeWithdrawal::STATUS_PENDING, $w->fresh()->status);
        $this->assertNull($w->fresh()->payout_tx_hash);
    }

    #[Test]
    public function a_stuck_payout_replaced_by_a_real_transfer_is_closed_as_paid_and_never_signed_again(): void
    {
        $dead = '0x'.str_repeat('6', 64);
        $w = $this->reviewWithdrawal(strtolower($this->bob), 56, BridgePayouter::REASON_STUCK);
        $w->update([
            'payout_tx_hash' => $dead, 'payout_nonce' => 3,
            'payout_from' => strtolower($this->payoutAddr), 'payout_raw_tx' => '0xf86c03',
        ]);

        // เจ้าของส่งโอนเดิมแทนที่ด้วย nonce 3 แล้วลงบล็อก — ผู้รับได้เงินแล้ว
        $replacement = $this->fakeTransferTx(56, $this->payoutAddr, 3, $this->bob, (string) $w->payout_amount_raw);

        // ปฏิเสธไม่ได้ (เงินออกแล้ว) และไม่ต้องขอหลักฐานเพิ่ม
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/reject", ['reason' => 'คืนเงินด้วยมือ', 'nonce_tx_hash' => $replacement])
            ->assertStatus(409)
            ->assertJsonPath('needs_nonce_proof', false);
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $w->fresh()->status);

        // เดิม: รายการกลับเข้าคิวแล้วจ่ายรอบสอง · ตอนนี้ต้องปิดเป็นจ่ายแล้ว ผูกกับธุรกรรมที่เงินออกจริง
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve", ['nonce_tx_hash' => $replacement])->assertOk();

        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->status);
        $this->assertSame($replacement, $w->payout_tx_hash);
        $this->assertNotNull($w->payout_block_number);
        $this->assertNotNull($w->paid_at);
        $this->assertStringContainsString($dead, $w->last_error, 'ยังตามรอย hash เดิมได้ (คอลัมน์ยาว 300 ตัด hash ทิ้งไม่ได้)');

        // กดเบิ้ล: ปิดไปแล้ว ไม่เปลี่ยนอะไร
        $this->asAdmin()->postJson("/admin/bridge/withdrawals/{$w->id}/approve", ['nonce_tx_hash' => $replacement])->assertStatus(409);

        // แถวนี้เลิกบล็อกคิวของเชน และไม่มีการเซ็นจ่ายซ้ำ
        $this->artisan('bridge:payout')->assertSuccessful();
        $this->assertSame([], $this->signCalls);
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->fresh()->status);
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
        // วงเงินฝากต่อครั้งเกิน maxPerTx บนสัญญา (1,000) → ผ่านด่านเว็บได้แต่ mint ไม่ได้ เงินค้าง vault → ปฏิเสธ
        $this->asAdmin()->putJson('/admin/bridge/settings', ['limits' => ['max_deposit_per_tx' => '1500', 'max_deposit_per_address_daily' => '3000']])
            ->assertStatus(422);
        // ค่าธรรมเนียมฝาก ≥ ขั้นต่ำ → ฝากขั้นต่ำแล้วได้ศูนย์ → ปฏิเสธ
        $this->asAdmin()->putJson('/admin/bridge/settings', ['limits' => ['deposit_fee' => '1']])->assertStatus(422);
        $this->assertSame('1000', app(BridgeConfig::class)->limit('max_deposit_per_tx'));

        $this->asAdmin()->putJson('/admin/bridge/settings', [
            'limits' => ['max_deposit_per_tx' => '900', 'max_deposit_per_address_daily' => '3000'],
            'fees' => [1 => '1.5'],
            'chains' => [1 => false],
        ])->assertOk();

        $config = app(BridgeConfig::class);
        $this->assertSame('900', $config->limit('max_deposit_per_tx'));
        $this->assertSame('1.5', $config->withdrawFee(1));
        $this->assertFalse($config->chainEnabled(1));
        $this->assertTrue($config->chainEnabled(56));
    }
}
