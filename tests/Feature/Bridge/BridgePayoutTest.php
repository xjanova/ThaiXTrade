<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeWithdrawal;
use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePayouter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * ตัวจ่ายของสะพาน — จ่ายครบทั้งก้อนหรือไม่จ่ายเลย และไม่จ่ายซ้ำไม่ว่าเครื่องจะดับตรงไหน.
 */
class BridgePayoutTest extends TestCase
{
    use FakesBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
    }

    private function withdrawal(int $chainId = 56, string $amount = '25000000', array $extra = []): BridgeWithdrawal
    {
        static $n = 0;
        $n++;
        $fee = $chainId === 1 ? '1000000' : '300000';
        $payout = bcsub($amount, $fee, 0);
        $decimals = $chainId === 56 ? 18 : 6;

        return BridgeWithdrawal::create(array_merge([
            'tx_hash' => '0x'.hash('sha256', "payout-test-{$n}"),
            'log_index' => 0,
            'block_number' => 1900 + $n,
            'from_address' => strtolower($this->alice),
            'amount_units' => $amount,
            'target_chain_raw' => (string) $chainId,
            'target_chain_id' => $chainId,
            'target_address' => strtolower($this->bob),
            'fee_units' => $fee,
            'payout_units' => $payout,
            'payout_amount_raw' => Abi::fromUnits6($payout, $decimals),
            'payout_token' => $chainId === 56 ? $this->usdtBsc : $this->usdtEth,
            'status' => BridgeWithdrawal::STATUS_PENDING,
        ], $extra));
    }

    private function payout(): void
    {
        $this->artisan('bridge:payout')->assertSuccessful();
    }

    #[Test]
    public function a_pending_withdrawal_is_paid_in_full_on_the_target_chain_minus_the_fee(): void
    {
        $w = $this->withdrawal(56, '25000000');

        $this->payout();

        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->status);
        $this->assertCount(1, $this->signCalls);
        $call = $this->signCalls[0];
        $this->assertSame('56', $call['BRIDGE_CHAIN_ID']);
        $this->assertSame($this->usdtBsc, strtolower($call['BRIDGE_TO']));
        $this->assertSame(strtolower($this->payoutAddr), strtolower($call['BRIDGE_EXPECT_ADDRESS']));
        // 25 − 0.3 = 24.7 USDT → 18 ทศนิยมบน BSC
        $this->assertSame(Abi::transferCalldata($this->bob, '24700000000000000000'), $call['BRIDGE_DATA']);
        $this->assertNotEmpty($call['BRIDGE_MAX_GAS_PRICE_WEI'], 'ต้องส่งเพดานค่าแก๊สไปด้วย');
    }

    #[Test]
    public function insufficient_payout_liquidity_never_sends_a_partial_amount(): void
    {
        $w = $this->withdrawal(1, '50000000');            // จ่าย 49 USDT บน Ethereum
        $this->setTokenBalance(1, $this->usdtEth, $this->payoutAddr, '10000000'); // มีแค่ 10

        $this->payout();

        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY, $w->status);
        $this->assertSame([], $this->signCalls);

        // เติมเงินแล้วรอบถัดไปจ่ายเอง
        $this->setTokenBalance(1, $this->usdtEth, $this->payoutAddr, '100000000');
        $this->payout();
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->fresh()->status);
    }

    #[Test]
    public function missing_gas_moves_the_withdrawal_to_awaiting_liquidity(): void
    {
        $w = $this->withdrawal();
        $this->signFailCode = 'insufficient_gas';

        $this->payout();

        $this->assertSame(BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY, $w->fresh()->status);
        $this->assertNull($w->fresh()->payout_tx_hash);
    }

    #[Test]
    public function a_crash_between_sign_and_broadcast_never_leads_to_a_second_payment(): void
    {
        $w = $this->withdrawal();
        $this->sendFailCode = 'broadcast_failed';

        $this->payout();

        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_SENDING, $w->status);
        $raw = $w->payout_raw_tx;
        $this->assertNotNull($raw);

        $this->sendFailCode = null;
        $this->payout();                       // ยังไม่ถึงเวลาส่งซ้ำ — ไม่ทำอะไร
        $this->travel(10)->minutes();
        $this->payout();                       // ส่ง raw เดิมซ้ำ
        $this->payout();                       // ตามผล

        $this->assertCount(1, $this->signCalls, 'ห้ามเซ็นใหม่เมื่อมี tx hash แล้ว');
        $this->assertSame($raw, end($this->sendCalls));
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->fresh()->status);
    }

    #[Test]
    public function a_nonce_consumed_without_our_receipt_goes_to_review_and_cannot_be_approved_while_unsafe(): void
    {
        $w = $this->withdrawal();
        $this->sendFailCode = 'broadcast_failed';
        $this->payout();

        $this->sendFailCode = 'nonce_used';
        $this->travel(10)->minutes();
        $this->payout();

        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $w->status);
        $this->assertSame('nonce_consumed', $w->review_reason);

        // nonce ของกระเป๋าจ่ายบนเชน (จำลอง) ยังเป็น 0 = raw เดิมยังลงบล็อกได้ → ห้ามจ่ายใหม่
        $check = app(BridgePayouter::class)->retryCheck($w);
        $this->assertFalse($check['safe']);
        $this->assertFalse($check['paid']);

        // nonce ถูกใช้ไปแล้วจริง (ธุรกรรมอื่น) → จ่ายใหม่ได้อย่างปลอดภัย
        $this->chainState[56]['nonce'][strtolower($this->payoutAddr)] = 1;
        $this->assertTrue(app(BridgePayouter::class)->retryCheck($w)['safe']);
    }

    #[Test]
    public function the_daily_payout_cap_holds_back_the_queue_until_the_next_utc_day(): void
    {
        config(['bridge.limits.max_payout_daily' => '30']);
        $first = $this->withdrawal(56, '25000000');
        $second = $this->withdrawal(56, '25000000');

        $this->payout();

        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(BridgeWithdrawal::STATUS_PENDING, $second->fresh()->status);
        $this->assertCount(1, $this->signCalls);
    }

    #[Test]
    public function nothing_is_paid_while_the_switch_is_off_or_the_chain_is_disabled(): void
    {
        $w = $this->withdrawal();

        app(BridgeConfig::class)->setSiteSwitch(false, 'test');
        $this->payout();
        $this->assertSame([], $this->signCalls);

        app(BridgeConfig::class)->setSiteSwitch(true);
        SiteSetting::set(BridgeConfig::SETTINGS_GROUP, 'chain_enabled_56', '0', 'boolean');
        $this->payout();
        $this->assertSame([], $this->signCalls);
        $this->assertSame(BridgeWithdrawal::STATUS_PENDING, $w->fresh()->status);
    }

    #[Test]
    public function a_reverted_payout_goes_to_review_and_is_not_retried_automatically(): void
    {
        $w = $this->withdrawal();
        $this->mineOnSend = false;
        $this->payout();
        $hash = $w->fresh()->payout_tx_hash;

        $this->revertOnMine[$hash] = true;
        $this->mineHash($hash);
        $this->payout();

        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $w->status);
        $this->assertSame('payout_reverted', $w->review_reason);
        $this->assertCount(1, $this->signCalls);
        // revert = เงินไม่ได้ออก → อนุมัติจ่ายใหม่ได้
        $this->assertTrue(app(BridgePayouter::class)->retryCheck($w)['safe']);
    }

    #[Test]
    public function a_withdrawal_rejected_while_signing_is_never_broadcast(): void
    {
        $w = $this->withdrawal();
        $this->onSign = fn () => BridgeWithdrawal::whereKey($w->id)->update(['status' => BridgeWithdrawal::STATUS_REJECTED]);

        $this->payout();

        $this->assertSame(BridgeWithdrawal::STATUS_REJECTED, $w->fresh()->status);
        $this->assertNull($w->fresh()->payout_tx_hash);
        $this->assertSame([], $this->sendCalls);
    }

    #[Test]
    public function queued_items_are_rechecked_before_paying(): void
    {
        // ผู้ใช้ถอนไว้ตอนวงเงินสูง แล้วแอดมินลดวงเงินต่อครั้งระหว่างรอคิว
        config(['bridge.limits.max_withdraw_per_tx' => '10']);
        $w = $this->withdrawal(56, '25000000');

        $this->payout();

        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $w->fresh()->status);
        $this->assertSame('over_tx_limit', $w->fresh()->review_reason);
        $this->assertSame([], $this->signCalls);
    }
}
