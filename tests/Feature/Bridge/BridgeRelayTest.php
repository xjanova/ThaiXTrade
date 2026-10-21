<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeDeposit;
use App\Models\SystemAlert;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeRelayer;
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

    // ── "สัญญาบอกว่า mint แล้ว" ต้องดู Minted ก่อนสรุปว่าคีย์หลุด ────────────────────

    #[Test]
    public function our_own_mint_that_the_rpc_has_not_shown_yet_is_settled_without_halting_the_bridge(): void
    {
        // ธุรกรรมของเราลงบล็อกแล้ว แต่ RPC ตอบใบเสร็จไม่ได้และโหนดที่ถามตัวธุรกรรมตามหัวไม่ทัน
        $this->rpcFaults['rpc.tpix.test|eth_getTransactionReceipt'] = 'rate limit exceeded';
        $this->rpcFaults['rpc.tpix.test|eth_getTransactionByHash'] = 'null';
        $d = $this->deposit();
        $this->relay();
        $this->assertSame(BridgeDeposit::STATUS_MINTING, $d->fresh()->status);

        // ส่ง raw ซ้ำ → nonce ถูกใช้ (ก็ของเราเอง) → สัญญาบอก processed — เดิมตรงนี้ = "คีย์ relayer หลุด" หยุดสะพาน
        $this->sendFailCode = 'nonce_used';
        $this->travel(5)->minutes();
        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->status, 'Minted บนเชนไปถึงผู้ฝากครบ = ของเราเอง');
        $this->assertTrue(app(BridgeConfig::class)->siteSwitchOn(), 'ต้องไม่หยุดสะพานเพราะ RPC ช้า');
        $this->assertFalse(SystemAlert::where('alert_key', 'bridge_minted_elsewhere')->exists());
        $this->assertCount(1, $this->signCalls);
    }

    #[Test]
    public function a_receipt_that_only_the_second_rpc_has_settles_the_mint(): void
    {
        $this->useTwoRpcs();
        $this->rpcFaults['rpc.tpix.test|eth_getTransactionReceipt'] = 'null';
        $d = $this->deposit();

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
    }

    #[Test]
    public function a_deposit_already_minted_to_the_depositor_is_closed_as_minted_and_the_queue_moves_on(): void
    {
        // เช่นธุรกรรม mint เดิมก่อนแอดมินอนุมัติใหม่ ลงบล็อกทีหลัง — สัญญาบอก processed แต่ Minted ตรงกับผู้ฝาก
        $d = $this->deposit('25000000');
        $next = $this->deposit('7000000');
        $mintTx = $this->fakeMinted($d->deposit_id, $this->alice, '25000000');

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
        $this->assertSame($mintTx, $d->fresh()->mint_tx_hash);
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $next->fresh()->status, 'รายการถัดไปยัง mint ต่อในรอบเดียวกัน');
        $this->assertTrue(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertCount(1, $this->signCalls, 'ไม่เซ็น mint ซ้ำให้รายการที่ mint แล้ว');
    }

    #[Test]
    public function a_deposit_minted_to_someone_else_is_held_and_halts_the_bridge(): void
    {
        $d = $this->deposit('25000000');
        $this->fakeMinted($d->deposit_id, $this->bob, '25000000'); // คีย์ relayer ที่หลุด mint เข้ากระเป๋าตัวเอง

        $this->relay();

        $d->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $d->status);
        $this->assertSame('minted_elsewhere', $d->review_reason);
        $this->assertStringContainsString(strtolower($this->bob), $d->last_error);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertSame('safety_hold', app(BridgeConfig::class)->publicPausedCode());
    }

    #[Test]
    public function a_wrong_amount_minted_for_the_deposit_is_also_held(): void
    {
        $d = $this->deposit('25000000');
        $this->fakeMinted($d->deposit_id, $this->alice, '2500000000'); // ผู้รับถูกแต่ยอดเกินจริง

        $this->relay();

        $this->assertSame('minted_elsewhere', $d->fresh()->review_reason);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
    }

    // ── ธุรกรรมค้าง ─────────────────────────────────────────────────────────

    #[Test]
    public function a_mint_stuck_in_the_mempool_is_flagged_blocks_new_mints_and_settles_when_it_lands(): void
    {
        $this->mineOnSend = false;
        $a = $this->deposit();
        $this->relay();
        $hash = $a->fresh()->mint_tx_hash;
        $this->chainState[4289]['txs'][$hash] = ['hash' => $hash, 'from' => strtolower($this->relayerAddr), 'nonce' => '0x0', 'blockNumber' => null];

        $this->travel(31)->minutes();
        $this->relay();

        $a->refresh();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $a->status);
        $this->assertSame(BridgeRelayer::REASON_STUCK, $a->review_reason);
        $this->assertTrue(SystemAlert::where('alert_key', BridgeRelayer::ALERT_STUCK)->where('severity', 'critical')->where('status', 'active')->exists());

        $b = $this->deposit();
        $this->relay();
        $this->assertCount(1, $this->signCalls, 'ไม่เซ็นตัวใหม่ต่อคิวหลัง nonce ที่ค้าง');
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $b->fresh()->status);

        $this->mineHash($hash);
        $this->mineOnSend = true;
        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $a->fresh()->status);
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $b->fresh()->status);
        $this->assertFalse(SystemAlert::where('alert_key', BridgeRelayer::ALERT_STUCK)->where('status', 'active')->exists());
    }
}
