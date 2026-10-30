<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeDeposit;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * เงินฝากของเชนที่ปิดอยู่ถูกคืนด้วยมือ — ต้องมีแถวบันทึกไว้ ไม่งั้นเปิดเชนทีหลังแล้วตัวสแกน mint ซ้ำ.
 */
class BridgeManualRefundTest extends TestCase
{
    use FakesBridgeChains;

    private string $refundTx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->refundTx = '0x'.str_repeat('9', 64);
        config(['bridge.chains.1.enabled' => false]);
    }

    private function record(string $tx, array $options = [], ?string $answer = 'yes')
    {
        $command = $this->artisan('bridge:record-manual-refund', ['chain' => 1, 'tx' => $tx, 'refund_tx' => $this->refundTx] + $options);

        return $answer === null
            ? $command
            : $command->expectsConfirmation('บันทึกว่าคืนเงินแล้ว รายการนี้จะไม่ถูก mint อีกเลย ยืนยัน?', $answer);
    }

    #[Test]
    public function a_recorded_refund_is_never_minted_when_the_chain_opens_later(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);

        $this->record($tx)->assertSuccessful();

        $row = BridgeDeposit::where('tx_hash', $tx)->sole();
        $this->assertSame([BridgeDeposit::STATUS_REJECTED, 'manual_refund'], [$row->status, $row->review_reason]);
        $this->assertStringContainsString($this->refundTx, (string) $row->review_note);
        $this->assertSame('30000000', (string) $row->amount_units);

        // เปิดเชนแล้วตั้งจุดเริ่มย้อนไปก่อนเงินฝากนั้น
        config(['bridge.chains.1.enabled' => true]);
        app(BridgeConfig::class)->setCursor('deposit_1', 4700);
        $this->artisan('bridge:scan', ['--chain' => 1])->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();

        $this->assertSame(1, BridgeDeposit::where('tx_hash', $tx)->count());
        $this->assertSame(BridgeDeposit::STATUS_REJECTED, $row->fresh()->status);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function declining_records_nothing(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);

        $this->record($tx, answer: 'no')->assertFailed();

        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function a_transaction_without_a_vault_transfer_is_refused(): void
    {
        $tx = '0x'.str_repeat('8', 64);
        $this->chainState[1]['receipts'][$tx] = ['transactionHash' => $tx, 'status' => '0x1', 'blockNumber' => '0x12c0', 'logs' => []];

        $this->record($tx, answer: null)->assertFailed();

        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function an_unreadable_source_chain_is_refused(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);
        $this->chainState[1]['down'] = true;

        $this->record($tx, answer: null)->assertFailed();

        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function a_deposit_already_minted_on_chain_is_refused(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);
        $this->chainState[4289]['processed'][Abi::depositId(1, $tx, 0)] = true;

        $this->record($tx, answer: null)->assertFailed();

        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function a_row_with_a_mint_in_flight_is_refused(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);
        BridgeDeposit::create([
            'source_chain_id' => 1, 'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 4800,
            'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtEth,
            'token_decimals' => 6, 'amount_raw' => '30000000', 'amount_units' => '30000000', 'mint_units' => '29000000',
            'deposit_id' => Abi::depositId(1, $tx, 0), 'status' => BridgeDeposit::STATUS_MINTING, 'mint_tx_hash' => '0x'.str_repeat('6', 64),
        ]);

        $this->record($tx, answer: null)->assertFailed();

        $this->assertSame(BridgeDeposit::STATUS_MINTING, BridgeDeposit::sole()->status);
    }

    #[Test]
    public function a_pending_row_is_closed_instead_of_duplicated(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);
        BridgeDeposit::create([
            'source_chain_id' => 1, 'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 4800,
            'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtEth,
            'token_decimals' => 6, 'amount_raw' => '30000000', 'amount_units' => '30000000', 'mint_units' => '29000000',
            'deposit_id' => Abi::depositId(1, $tx, 0), 'status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'review_reason' => 'over_tx_limit',
        ]);

        $this->record($tx)->assertSuccessful();

        $this->assertSame(BridgeDeposit::STATUS_REJECTED, BridgeDeposit::sole()->status);
    }

    #[Test]
    public function several_vault_transfers_need_an_explicit_log_index(): void
    {
        $tx = $this->fakeDeposit(1, $this->alice, '30000000', 4800);
        $this->fakeDeposit(1, $this->bob, '40000000', 4800, logIndex: 1, txHash: $tx);
        $this->chainState[1]['receipts'][$tx]['logs'] = array_values(array_filter(
            $this->chainState[1]['logs'],
            fn ($log) => $log['transactionHash'] === $tx,
        ));

        $this->record($tx, answer: null)->assertFailed();
        $this->assertSame(0, BridgeDeposit::count());

        $this->record($tx, ['--log-index' => 1])->assertSuccessful();
        $this->assertSame('40000000', (string) BridgeDeposit::sole()->amount_units);
    }
}
