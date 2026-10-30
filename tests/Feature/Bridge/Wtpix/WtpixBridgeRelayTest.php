<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixAbi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * ทิศ TPIX → BSC: mint wTPIX ผ่าน WTPIXBridgeMinter — ต้องไม่ mint ซ้ำไม่ว่าเครื่องจะดับตรงไหน.
 */
class WtpixBridgeRelayTest extends TestCase
{
    use FakesWtpixBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();
    }

    private function lockRow(string $amount = '500', string $status = WtpixBridgeTransfer::STATUS_CONFIRMED, array $extra = []): WtpixBridgeTransfer
    {
        static $n = 0;
        $n++;
        $tx = '0x'.hash('sha256', "wtpix-relay-{$n}");
        $wei = $this->tpix($amount);

        return WtpixBridgeTransfer::create(array_merge([
            'direction' => WtpixBridgeTransfer::TO_BSC,
            'source_chain_id' => 4289,
            'source_contract' => $this->wVault,
            'tx_hash' => $tx,
            'log_index' => 0,
            'block_number' => 1900 + $n,
            'from_address' => $this->alice,
            'recipient' => $this->alice,
            'amount_wei' => $wei,
            'fee_wei' => $this->tpix('1'),
            'payout_wei' => bcsub($wei, $this->tpix('1'), 0),
            'deposit_id' => Abi::depositId(4289, $tx, 0),
            'status' => $status,
            'dest_chain_id' => 56,
        ], $extra));
    }

    private function relay(): void
    {
        $this->artisan('bridge:wtpix-relay', ['--direction' => 'to_bsc'])->assertSuccessful();
    }

    private function relayerSigns(): array
    {
        return $this->signCallsFor($this->wRelayer);
    }

    #[Test]
    public function a_confirmed_lock_is_minted_on_bsc_to_the_locker_and_never_twice(): void
    {
        $row = $this->lockRow();

        $this->relay();
        $this->relay();

        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->status);
        $this->assertCount(1, $this->relayerSigns());
        $call = $this->relayerSigns()[0];
        $this->assertSame('56', $call['BRIDGE_CHAIN_ID']);
        $this->assertSame($this->wMinter, strtolower($call['BRIDGE_TO']));
        $this->assertSame(WtpixAbi::mintCalldata($this->alice, $this->tpix('499'), $row->deposit_id), $call['BRIDGE_DATA']);
        $this->assertSame('12000', $call['BRIDGE_GAS_MULTIPLIER_BPS']);
        $this->assertArrayNotHasKey('BRIDGE_KEYSTORE_PASS', $call, 'the test must never keep a passphrase');
        $this->assertSame($this->tpix('499'), $this->chain[56]['token']['supply']);
        $this->assertSame($this->wMinter, $row->dest_contract);
        $this->assertNotNull($row->completed_at);
    }

    #[Test]
    public function the_hash_is_persisted_before_broadcast_and_a_crash_only_rebroadcasts_the_same_raw(): void
    {
        $row = $this->lockRow();
        $this->sendFailCode = 'broadcast_failed'; // เครื่องดับ/RPC ล่มหลังเซ็น

        $this->relay();

        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_SENDING, $row->status);
        $this->assertNotNull($row->dest_tx_hash);
        $raw = $row->dest_raw_tx;
        $this->assertNotNull($raw);

        $this->sendFailCode = null;
        $this->travel(5)->minutes();
        $this->relay();

        $this->assertCount(1, $this->relayerSigns(), 'never sign again once a hash exists');
        $this->assertSame($raw, end($this->sendCalls));
        $this->relay();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
        $this->assertSame($this->tpix('499'), $this->chain[56]['token']['supply']);
    }

    #[Test]
    public function a_new_mint_is_not_signed_while_a_previous_one_is_unresolved(): void
    {
        $first = $this->lockRow();
        $second = $this->lockRow();
        $this->mineOnSend = false;

        $this->relay();
        $this->relay();

        $this->assertCount(1, $this->relayerSigns());
        $this->assertSame(WtpixBridgeTransfer::STATUS_SENDING, $first->fresh()->status);
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $second->fresh()->status);

        $this->mineHash($first->fresh()->dest_tx_hash);
        $this->mineOnSend = true;
        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $first->fresh()->status);
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $second->fresh()->status);
    }

    #[Test]
    public function a_receipt_only_the_second_rpc_can_see_settles_without_a_false_halt(): void
    {
        $this->useTwoRpcs();
        $row = $this->lockRow();
        $this->relay();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);

        $row->update(['status' => WtpixBridgeTransfer::STATUS_SENDING, 'completed_at' => null, 'broadcast_at' => now()->subMinutes(10)]);
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionReceipt'] = 'null';
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionByHash'] = 'null';
        $this->sendFailCode = 'nonce_used';

        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertFalse($this->chain[56]['minter']['paused']);
    }

    #[Test]
    public function a_nonce_consumed_while_some_rpc_is_unreadable_waits_instead_of_halting(): void
    {
        $this->useTwoRpcs();
        $row = $this->lockRow();
        $this->relay();

        $row->update(['status' => WtpixBridgeTransfer::STATUS_SENDING, 'completed_at' => null, 'broadcast_at' => now()->subMinutes(10)]);
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionReceipt'] = 'null';
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionByHash'] = 'null';
        $this->rpcFaults['rpc2.bsc.test|eth_getTransactionReceipt'] = 'upstream error';
        $this->sendFailCode = 'nonce_used';

        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_SENDING, $row->fresh()->status);
        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());
    }

    #[Test]
    public function a_transaction_stuck_in_the_mempool_raises_an_alert_and_clears_when_it_lands(): void
    {
        $this->mineOnSend = false;
        $row = $this->lockRow();
        $this->relay();
        $row->refresh();
        $hash = $row->dest_tx_hash;
        $this->chain[56]['txs'][$hash] = ['hash' => $hash, 'from' => strtolower($this->wRelayer), 'nonce' => '0x0', 'blockNumber' => null];

        $this->relay();
        $this->assertFalse(SystemAlert::where('alert_key', 'wtpix_bridge_tx_stuck_to_bsc')->exists(), 'ยังไม่ค้างนานพอ');

        $this->travel(31)->minutes();
        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_SENDING, $row->fresh()->status, 'ยังลงบล็อกได้ ต้องตามผลต่อ');
        $this->assertTrue(SystemAlert::where('alert_key', 'wtpix_bridge_tx_stuck_to_bsc')->where('severity', 'critical')->where('status', 'active')->exists());

        $this->mineHash($hash);
        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
        $this->assertFalse(SystemAlert::where('alert_key', 'wtpix_bridge_tx_stuck_to_bsc')->where('status', 'active')->exists());
    }

    #[Test]
    public function a_deposit_id_minted_by_someone_else_is_held_and_the_bridge_is_halted(): void
    {
        $row = $this->lockRow();
        $other = $this->lockRow();
        $this->chain[56]['minter']['processed'][$row->deposit_id] = true;

        $this->relay();

        $row->refresh();
        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'minted_elsewhere'], [$row->status, $row->review_reason]);
        $this->assertSame([], $this->relayerSigns());
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $other->fresh()->status);
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertTrue($this->chain[56]['minter']['paused'], 'the guardian paused the minter');
        $this->assertTrue($this->chain[4289]['vault']['paused'], 'the guardian paused the vault');
        $this->assertTrue(SystemAlert::where('alert_key', 'wtpix_bridge_minted_elsewhere')->where('severity', 'critical')->exists());
    }

    #[Test]
    public function not_enough_bnb_for_gas_waits_as_awaiting_liquidity_and_never_signs(): void
    {
        $row = $this->lockRow();
        $this->chain[56]['native'][$this->wRelayer] = '1000';

        $this->relay();

        $row->refresh();
        $this->assertSame(WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY, $row->status);
        $this->assertSame([], $this->relayerSigns());

        // เติม BNB แล้วรอบถัดไปทำต่อเอง
        $this->chain[56]['native'][$this->wRelayer] = $this->tpix('1');
        $this->relay();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
    }

    #[Test]
    public function the_signer_reporting_insufficient_gas_also_means_awaiting_liquidity(): void
    {
        $row = $this->lockRow();
        $this->signFailCode = 'insufficient_gas';

        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY, $row->fresh()->status);
        $this->assertNull($row->fresh()->dest_tx_hash);
    }

    #[Test]
    public function nothing_is_signed_while_the_minter_or_token_is_paused_or_the_switch_is_off(): void
    {
        $row = $this->lockRow();

        $this->chain[56]['minter']['paused'] = true;
        $this->relay();
        $this->chain[56]['minter']['paused'] = false;
        $this->chain[56]['token']['paused'] = true;
        $this->relay();
        $this->chain[56]['token']['paused'] = false;
        app(WtpixBridgeConfig::class)->setSiteSwitch(false, 'test');
        $this->relay();

        $this->assertSame([], $this->signCalls);
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->fresh()->status);
    }

    #[Test]
    public function nothing_is_signed_without_the_relayer_role_or_when_the_minter_lost_its_minter_right(): void
    {
        $this->lockRow();

        $this->chain[56]['minter']['relayers'] = [];
        $this->relay();
        $this->chain[56]['minter']['relayers'] = [$this->wRelayer => true];
        $this->chain[56]['token']['minters'] = [];
        $this->relay();

        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function on_chain_caps_are_checked_before_signing(): void
    {
        $big = $this->lockRow('200000', extra: ['limits_overridden' => true]);
        $this->chain[56]['minter']['remaining'] = $this->tpix('100');
        $normal = $this->lockRow('500');

        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'exceeds_onchain_max'], [$big->fresh()->status, $big->fresh()->review_reason]);
        // โควตาวันนี้ไม่พอ — รอวันใหม่ ไม่เปลี่ยนสถานะ ไม่ข้ามคิว
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $normal->fresh()->status);
        $this->assertStringContainsString('dailyCap', (string) $normal->fresh()->last_error);
        $this->assertSame([], $this->signCalls);

        // ข้ามเที่ยงคืน UTC บนสัญญา — โควตากลับมา รอบถัดไปทำต่อเอง
        $this->chain[56]['minter']['remaining'] = $this->tpix('500000');
        $this->relay();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $normal->fresh()->status);
    }

    #[Test]
    public function a_reverted_mint_goes_to_review_and_is_not_resigned(): void
    {
        $row = $this->lockRow();
        $this->mineOnSend = false;
        $this->relay();
        $hash = $row->fresh()->dest_tx_hash;

        $this->revertOnMine[$hash] = true;
        $this->mineHash($hash);
        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'mint_reverted'], [$row->fresh()->status, $row->fresh()->review_reason]);
        $this->assertCount(1, $this->relayerSigns());
    }

    #[Test]
    public function a_consumed_nonce_is_settled_by_asking_the_minter(): void
    {
        $row = $this->lockRow();
        $this->sendFailCode = 'broadcast_failed';
        $this->relay();

        $this->sendFailCode = 'nonce_used';
        $this->travel(5)->minutes();
        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'nonce_consumed'], [$row->fresh()->status, $row->fresh()->review_reason]);
        $this->assertCount(1, $this->relayerSigns());
    }

    #[Test]
    public function a_row_rejected_while_signing_is_never_broadcast(): void
    {
        $row = $this->lockRow();
        $this->onSign = fn () => WtpixBridgeTransfer::whereKey($row->id)->update(['status' => WtpixBridgeTransfer::STATUS_REJECTED]);

        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_REJECTED, $row->fresh()->status);
        $this->assertNull($row->fresh()->dest_tx_hash);
        $this->assertSame([], $this->sendCalls);
        $this->assertSame('0', $this->chain[56]['token']['supply']);
    }

    #[Test]
    public function rows_from_a_vault_that_is_no_longer_configured_are_sent_to_review(): void
    {
        $stale = $this->lockRow(extra: ['source_contract' => '0x7a170000000000000000000000000000000000ff']);

        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'config_changed'], [$stale->fresh()->status, $stale->fresh()->review_reason]);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function our_own_mint_seen_by_the_scanner_is_not_an_incident(): void
    {
        $this->artisan('bridge:wtpix-scan')->assertSuccessful(); // ตั้งเคอร์เซอร์
        $this->lockRow();
        $this->relay();
        $this->chain[56]['latest'] = 5030;
        $this->chain[56]['finalized'] = 5025;

        $this->artisan('bridge:wtpix-scan')->assertSuccessful();

        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertSame([], $this->signCallsFor($this->wGuardian));
    }
}
