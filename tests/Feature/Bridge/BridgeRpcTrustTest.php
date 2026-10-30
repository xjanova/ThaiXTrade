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
 * RPC ตัวเดียวห้ามสร้างเงินได้ — ต้องเป็นเชนที่ถูก และ provider อื่นต้องเห็นเงินฝากเดียวกันก่อน mint.
 */
class BridgeRpcTrustTest extends TestCase
{
    use FakesBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->useTwoRpcs();
    }

    private function deposit(?string $tx = null): BridgeDeposit
    {
        $tx ??= $this->fakeDeposit(56, $this->alice, '25000000000000000000', 900);

        return BridgeDeposit::create([
            'source_chain_id' => 56, 'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 900,
            'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtBsc,
            'token_decimals' => 18, 'amount_raw' => '25000000000000000000', 'amount_units' => '25000000',
            'mint_units' => '25000000', 'deposit_id' => Abi::depositId(56, $tx, 0), 'status' => BridgeDeposit::STATUS_CONFIRMED,
        ]);
    }

    private function relay(): void
    {
        $this->artisan('bridge:relay')->assertSuccessful();
    }

    #[Test]
    public function two_providers_seeing_the_same_deposit_lets_it_mint(): void
    {
        $d = $this->deposit();

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
    }

    #[Test]
    public function a_second_provider_that_contradicts_the_deposit_stops_the_mint(): void
    {
        $tx = $this->fakeDeposit(56, $this->alice, '25000000000000000000', 900);
        $d = $this->deposit($tx);
        // ตัวแรกถูกเจาะ: มี log ปลอม ส่วนตัวที่สองเห็นธุรกรรมเดียวกันแต่ไม่มีการโอนเข้า vault
        $this->rpcFaultReceipts['rpc2.bsc.test'][$tx] = ['transactionHash' => $tx, 'status' => '0x1', 'blockNumber' => '0x384', 'logs' => []];

        $this->relay();

        $d->refresh();
        $this->assertSame([BridgeDeposit::STATUS_NEEDS_REVIEW, 'rpc_disagreement'], [$d->status, $d->review_reason]);
        $this->assertSame([], $this->signCalls);
        $this->assertTrue(SystemAlert::where('alert_key', 'bridge_rpc_disagreement')->where('severity', 'critical')->exists());
    }

    #[Test]
    public function a_different_amount_from_the_second_provider_stops_the_mint(): void
    {
        $tx = $this->fakeDeposit(56, $this->alice, '25000000000000000000', 900);
        $d = $this->deposit($tx);
        $receipt = $this->chainState[56]['receipts'][$tx];
        $receipt['logs'][0]['data'] = '0x'.Abi::encUint('1000000000000000000');
        $this->rpcFaultReceipts['rpc2.bsc.test'][$tx] = $receipt;

        $this->relay();

        $this->assertSame('rpc_disagreement', $d->fresh()->review_reason);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function a_provider_that_has_not_seen_it_yet_only_delays_the_mint(): void
    {
        $d = $this->deposit();
        $this->rpcFaults['rpc2.bsc.test|eth_getTransactionReceipt'] = 'null';

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $d->fresh()->status);
        $this->assertSame([], $this->signCalls);

        unset($this->rpcFaults['rpc2.bsc.test|eth_getTransactionReceipt']);
        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
    }

    #[Test]
    public function a_deposit_no_second_provider_ever_confirms_goes_to_review(): void
    {
        $d = $this->deposit();
        $this->rpcFaults['rpc2.bsc.test|eth_getTransactionReceipt'] = 'null';

        $this->travel(31)->minutes();
        $this->relay();

        $d->refresh();
        $this->assertSame([BridgeDeposit::STATUS_NEEDS_REVIEW, 'source_unconfirmed'], [$d->status, $d->review_reason]);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function an_admin_approval_overrides_a_missing_second_provider(): void
    {
        $d = $this->deposit();
        $d->update(['limits_overridden' => true]);
        $this->rpcFaults['rpc2.bsc.test|eth_getTransactionReceipt'] = 'null';

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
    }

    #[Test]
    public function a_single_configured_provider_still_mints_and_logs_the_gap(): void
    {
        config(['bridge.chains.56.rpc' => ['https://rpc.bsc.test']]);
        $d = $this->deposit();

        $this->relay();

        $this->assertSame(BridgeDeposit::STATUS_MINTED, $d->fresh()->status);
    }

    #[Test]
    public function an_rpc_answering_for_another_chain_is_skipped_and_flagged(): void
    {
        $this->rpcChainIds['rpc.bsc.test'] = 97;

        $block = app(BridgeConfig::class)->rpc(56)->blockNumber();

        $this->assertSame(1000, $block, 'ต้องได้คำตอบจากตัวที่สอง ไม่ใช่ตัวที่ชี้ผิดเชน');
        $this->assertTrue(SystemAlert::where('alert_key', 'bridge_rpc_wrong_chain_56')->where('severity', 'critical')->exists());
    }

    #[Test]
    public function no_correct_rpc_means_unreadable_not_a_wrong_answer(): void
    {
        $this->rpcChainIds['rpc.bsc.test'] = 97;
        $this->rpcChainIds['rpc2.bsc.test'] = 97;

        $this->assertNull(app(BridgeConfig::class)->rpc(56)->blockNumber());
    }

    #[Test]
    public function the_alert_text_never_carries_the_rpc_url(): void
    {
        config(['bridge.chains.56.rpc' => ['https://rpc.bsc.test/secret-api-key', 'https://rpc2.bsc.test']]);
        $this->rpcChainIds['rpc.bsc.test'] = 97;

        app(BridgeConfig::class)->rpc(56)->blockNumber();

        $alert = SystemAlert::where('alert_key', 'bridge_rpc_wrong_chain_56')->sole();
        $this->assertStringNotContainsString('secret-api-key', $alert->message.json_encode($alert->data));
    }

    #[Test]
    public function the_chain_check_is_cached_per_url(): void
    {
        $rpc = app(BridgeConfig::class)->rpc(56);

        $rpc->blockNumber();
        $rpc->blockNumber();

        $this->assertSame(1, $this->rpcCallCount('rpc.bsc.test', 'eth_chainId'));
    }
}
