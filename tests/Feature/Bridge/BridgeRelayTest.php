<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeDeposit;
use App\Models\SystemAlert;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * ตัว mint ของสะพาน — ต้องไม่ mint ซ้ำไม่ว่าเครื่องจะดับตรงไหน.
 */
class BridgeRelayTest extends TestCase
{
    use FakesBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
    }

    private function deposit(string $units = '25000000', string $status = BridgeDeposit::STATUS_CONFIRMED, array $extra = []): BridgeDeposit
    {
        static $n = 0;
        $n++;
        $tx = '0x'.hash('sha256', "relay-test-{$n}");

        return BridgeDeposit::create(array_merge([
            'source_chain_id' => 56,
            'tx_hash' => $tx,
            'log_index' => 0,
            'block_number' => 900 + $n,
            'from_address' => strtolower($this->alice),
            'to_address' => $this->vault,
            'token_address' => $this->usdtBsc,
            'token_decimals' => 18,
            'amount_raw' => bcmul($units, '1000000000000', 0),
            'amount_units' => $units,
            'mint_units' => $units,
            'deposit_id' => Abi::depositId(56, $tx, 0),
            'status' => $status,
        ], $extra));
    }

    private function relay(): void
    {
        $this->artisan('bridge:relay')->assertSuccessful();
    }

    #[Test]
    public function a_confirmed_deposit_is_minted_through_the_minter_to_the_depositor(): void
    {
        $d = $this->deposit();

        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->status);
        $this->assertCount(1, $this->signCalls);
        $call = $this->signCalls[0];
        $this->assertSame($this->minter, strtolower($call['BRIDGE_TO']));
        $this->assertSame('4289', $call['BRIDGE_CHAIN_ID']);
        $this->assertSame(strtolower($this->relayerAddr), strtolower($call['BRIDGE_EXPECT_ADDRESS']));
        $this->assertSame(Abi::mintCalldata($this->alice, '25000000', $d->deposit_id), $call['BRIDGE_DATA']);
        $this->assertArrayNotHasKey('BRIDGE_KEYSTORE_PASS', $call, 'เทสต์ต้องไม่เก็บ passphrase');
        $this->assertSame('25000000', $this->chainState[4289]['supply']);

        // รอบถัดไปไม่มีอะไรให้ทำ
        $this->relay();
        $this->assertCount(1, $this->signCalls);
    }

    #[Test]
    public function the_tx_hash_is_persisted_before_broadcast_and_a_crash_never_leads_to_a_second_signature(): void
    {
        $d = $this->deposit();
        $this->sendFailCode = 'broadcast_failed'; // เหมือนเครื่องดับ/RPC ล่มหลังเซ็น

        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_MINTING, $d->status);
        $this->assertNotNull($d->mint_tx_hash);
        $this->assertNotNull($d->mint_raw_tx);
        $firstRaw = $d->mint_raw_tx;

        // รอบถัดไป (เกิน 90 วิ) — เชนไม่รู้จัก tx → ส่ง raw เดิมซ้ำ ไม่เซ็นใหม่
        $this->sendFailCode = null;
        $this->travel(5)->minutes();
        $this->relay();

        $this->assertCount(1, $this->signCalls, 'ห้ามเซ็นใหม่เมื่อมี tx hash แล้ว');
        $this->assertSame($firstRaw, end($this->sendCalls));
        $this->relay();
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
    }

    #[Test]
    public function a_deposit_minted_on_chain_by_a_transaction_we_did_not_send_is_held_for_review_and_stops_the_bridge(): void
    {
        // a leaked relayer key can compute depositId from the public source-chain log and mint it to itself first;
        // counting that as "minted" would make the theft invisible to reconciliation (totals still match)
        $d = $this->deposit();
        $other = $this->deposit();
        $this->chainState[4289]['processed'][$d->deposit_id] = true;

        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->status);
        $this->assertSame('minted_elsewhere', $d->review_reason);
        $this->assertSame([], $this->signCalls, 'nothing else is signed in the same run');
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $other->fresh()->status);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn(), 'the site switch is turned off at once');
        $this->assertTrue(SystemAlert::where('alert_key', 'bridge_minted_elsewhere')->where('severity', 'critical')->exists());
    }

    #[Test]
    public function nothing_is_signed_while_the_minter_is_paused_or_the_switch_is_off(): void
    {
        $d = $this->deposit();

        $this->chainState[4289]['minter_paused'] = true;
        $this->relay();
        $this->assertSame([], $this->signCalls);

        $this->chainState[4289]['minter_paused'] = false;
        app(BridgeConfig::class)->setSiteSwitch(false, 'test');
        $this->relay();
        $this->assertSame([], $this->signCalls);

        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $d->fresh()->status);
    }

    #[Test]
    public function nothing_is_signed_when_the_relayer_lacks_the_role_or_the_minter_is_not_the_bridge(): void
    {
        $this->deposit();

        $this->chainState[4289]['relayers'] = [];
        $this->relay();
        $this->chainState[4289]['relayers'] = [strtolower($this->relayerAddr) => true];
        $this->chainState[4289]['minter_is_bridge'] = false;
        $this->relay();

        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function a_reverted_mint_goes_to_review_unless_the_contract_shows_it_was_minted(): void
    {
        $d = $this->deposit();
        $this->mineOnSend = false;
        $this->relay();
        $hash = $d->fresh()->mint_tx_hash;

        $this->revertOnMine[$hash] = true;
        $this->mineHash($hash);
        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->status);
        $this->assertSame('mint_reverted', $d->review_reason);
        $this->assertCount(1, $this->signCalls, 'revert แล้วห้ามเซ็นใหม่เอง — ต้องให้แอดมินดู');
    }

    #[Test]
    public function a_reverted_mint_whose_id_was_minted_by_another_transaction_is_held_for_review(): void
    {
        $d = $this->deposit();
        $this->mineOnSend = false;
        $this->relay();
        $hash = $d->fresh()->mint_tx_hash;

        // our tx reverted ("txhash replayed") because a transaction we never sent minted this depositId first —
        // the recipient of that mint is unknown, so it must not be counted as delivered
        $this->revertOnMine[$hash] = true;
        $this->mineHash($hash);
        $this->chainState[4289]['processed'][$d->deposit_id] = true;
        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->status);
        $this->assertSame('minted_elsewhere', $d->review_reason);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
    }

    #[Test]
    public function a_new_mint_is_not_signed_while_a_previous_one_is_unresolved(): void
    {
        $first = $this->deposit();
        $second = $this->deposit();
        $this->mineOnSend = false;

        $this->relay();
        $this->relay();

        $this->assertCount(1, $this->signCalls);
        $this->assertSame(BridgeDeposit::STATUS_MINTING, $first->fresh()->status);
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $second->fresh()->status);

        $this->mineHash($first->fresh()->mint_tx_hash);
        $this->mineOnSend = true;
        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $first->fresh()->status);
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $second->fresh()->status);
    }

    #[Test]
    public function on_chain_caps_are_respected_before_signing(): void
    {
        // เกิน maxPerTx บนสัญญา (แอดมินอนุมัติยอดใหญ่มาแล้วก็จริง) → ส่งกลับให้คนดู
        $big = $this->deposit('1500000000', extra: ['limits_overridden' => true]);
        // โควตาวันนี้เหลือไม่พอ → รอวันใหม่ ไม่เปลี่ยนสถานะ
        $this->chainState[4289]['remaining'] = '10000000';
        $normal = $this->deposit('25000000');

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $big->fresh()->status);
        $this->assertSame('exceeds_onchain_max', $big->fresh()->review_reason);
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $normal->fresh()->status);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function repeated_estimate_failures_send_the_deposit_to_review(): void
    {
        $d = $this->deposit();
        $this->signFailCode = 'estimate_failed';

        for ($i = 0; $i < 5; $i++) {
            $this->relay();
        }

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->status);
        $this->assertSame('mint_estimate_failed', $d->review_reason);
        $this->assertNull($d->mint_tx_hash);
    }

    #[Test]
    public function a_deposit_rejected_by_an_admin_while_signing_is_never_broadcast(): void
    {
        $d = $this->deposit();
        $this->onSign = fn () => BridgeDeposit::whereKey($d->id)->update(['status' => BridgeDeposit::STATUS_REJECTED]);

        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_REJECTED, $d->status);
        $this->assertNull($d->mint_tx_hash);
        $this->assertCount(1, $this->signCalls);
        $this->assertSame([], $this->sendCalls, 'ธุรกรรมที่เซ็นแล้วแต่รายการถูกปฏิเสธระหว่างนั้นต้องไม่ถูกส่ง');
        $this->assertSame('0', $this->chainState[4289]['supply']);
    }

    #[Test]
    public function a_nonce_consumed_by_another_transaction_is_settled_by_asking_the_contract(): void
    {
        $d = $this->deposit();
        $this->sendFailCode = 'broadcast_failed';
        $this->relay();

        // รอบหน้า: ส่งซ้ำแล้วเชนบอกว่า nonce ถูกใช้ไปแล้ว และสัญญายังไม่เคย mint → ให้คนดู
        $this->sendFailCode = 'nonce_used';
        $this->travel(5)->minutes();
        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->status);
        $this->assertSame('nonce_consumed', $d->review_reason);
        $this->assertCount(1, $this->signCalls);
    }
}
