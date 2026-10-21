<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeWithdrawal;
use App\Models\SiteSetting;
use App\Models\SystemAlert;
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
        // คิวหยุดรอเงิน — ต้องมีคนรู้ ไม่ใช่รอเงียบ ๆ
        $this->assertTrue(SystemAlert::where('alert_key', BridgePayouter::ALERT_LIQUIDITY)->where('status', 'active')->exists());

        // เติมเงินแล้วรอบถัดไปจ่ายเอง และปิดคาดแดงเอง
        $this->setTokenBalance(1, $this->usdtEth, $this->payoutAddr, '100000000');
        $this->payout();
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->fresh()->status);
        $this->assertFalse(SystemAlert::where('alert_key', BridgePayouter::ALERT_LIQUIDITY)->where('status', 'active')->exists());
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

        // nonce ถูกใช้แล้ว แต่ไม่รู้ว่าธุรกรรมไหนใช้ — "ไม่เจอใบเสร็จ" ไม่ใช่หลักฐานว่าเงินไม่ได้ออก → ยังห้ามจ่าย
        $this->chainState[56]['nonce'][strtolower($this->payoutAddr)] = 1;
        $this->assertFalse(app(BridgePayouter::class)->retryCheck($w)['safe']);

        // เจอธุรกรรมที่ใช้ nonce นั้นจริง (ลงบล็อกแล้ว · from กระเป๋าจ่าย · คนละ hash) → พิสูจน์ได้ว่าเดิมไม่มีวันลงบล็อก
        $cancel = $this->fakeCancelTx(56, $this->payoutAddr, (int) $w->payout_nonce);
        $this->assertTrue(app(BridgePayouter::class)->retryCheck($w, $cancel)['safe']);
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

    // ── จ่ายซ้ำ: "ไม่เจอใบเสร็จ" ต้องไม่ถูกตีความว่า "เงินไม่ได้ออก" ─────────────────

    #[Test]
    public function a_mined_payout_whose_receipt_the_rpc_cannot_return_is_never_paid_a_second_time(): void
    {
        // ธุรกรรมจ่ายลงบล็อกจริง (เงินออกแล้ว) แต่ RPC ตอบใบเสร็จไม่ได้ และโหนดที่ถามตัวธุรกรรมตามหัวไม่ทัน
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionReceipt'] = 'header not found';
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionByHash'] = 'null';
        $w = $this->withdrawal();

        $this->payout();
        $this->assertSame(BridgeWithdrawal::STATUS_SENDING, $w->fresh()->status);
        $this->assertSame('1', (string) ($this->chainState[56]['nonce'][strtolower($this->payoutAddr)] ?? 0), 'ลงบล็อกแล้วจริง');

        // ส่ง raw เดิมซ้ำ → เชนบอกว่า nonce ถูกใช้แล้ว (ก็ธุรกรรมของเราเอง) → ยังไม่เจอใบเสร็จ → ให้คนดู
        $this->sendFailCode = 'nonce_used';
        $this->travel(10)->minutes();
        $this->payout();
        $w->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $w->status);
        $this->assertSame('nonce_consumed', $w->review_reason);

        // แอดมินกดอนุมัติจ่ายใหม่ — เดิมระบบตอบ "ปลอดภัย" (ไม่มีใบเสร็จ + nonce ถูกใช้) = จ่ายสองรอบ
        $check = app(BridgePayouter::class)->retryCheck($w);
        $this->assertFalse($check['safe']);
        $this->assertFalse($check['paid']);
        $this->assertStringContainsString('พิสูจน์ไม่ได้', $check['reason']);

        $this->sendFailCode = null;
        $this->payout();
        $this->assertCount(1, $this->signCalls, 'ไม่มีการเซ็นจ่ายครั้งที่สอง');

        // RPC กลับมาตอบได้ → เห็นว่าจ่ายไปแล้ว ไม่ใช่จ่ายซ้ำ
        $this->rpcFaults = [];
        $this->assertTrue(app(BridgePayouter::class)->retryCheck($w)['paid']);
    }

    #[Test]
    public function a_receipt_that_only_the_second_rpc_has_settles_the_payout(): void
    {
        $this->useTwoRpcs();
        $this->rpcFaults['rpc.bsc.test|eth_getTransactionReceipt'] = 'null'; // โหนดแรกตามหัวไม่ทัน
        $w = $this->withdrawal();

        $this->payout();

        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $w->fresh()->status);
    }

    #[Test]
    public function another_payout_that_reused_the_nonce_proves_the_old_one_never_landed(): void
    {
        $this->mineOnSend = false;
        $a = $this->withdrawal();
        $this->payout();
        $a->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_SENDING, $a->status);

        // ธุรกรรมของอีกรายการถูกเซ็นด้วย nonce เดียวกันแล้วลงบล็อก (เช่นโหนดทิ้ง a ออกจาก mempool)
        $other = $this->fakeCancelTx(56, $this->payoutAddr, (int) $a->payout_nonce);
        $this->withdrawal(56, '9000000', [
            'status' => BridgeWithdrawal::STATUS_PAID, 'payout_tx_hash' => $other,
            'payout_nonce' => $a->payout_nonce, 'payout_from' => strtolower($this->payoutAddr),
        ]);

        $this->sendFailCode = 'nonce_used';
        $this->travel(10)->minutes();
        $this->payout();
        $this->assertSame('nonce_consumed', $a->fresh()->review_reason);

        // ระบบหาหลักฐานเองจากรายการที่ใช้ nonce เดียวกัน — ไม่ต้องกรอกอะไร
        $check = app(BridgePayouter::class)->retryCheck($a->fresh());
        $this->assertTrue($check['safe']);
        $this->assertStringContainsString($other, $check['reason']);
    }

    #[Test]
    public function a_claimed_nonce_proof_must_really_be_a_mined_tx_of_the_payout_wallet_at_that_nonce(): void
    {
        $this->mineOnSend = false;
        $w = $this->withdrawal();
        $this->payout();
        $w->refresh();
        $nonce = (int) $w->payout_nonce;
        $this->chainState[56]['nonce'][strtolower($this->payoutAddr)] = $nonce + 1;

        // ธุรกรรมของกระเป๋าอื่น
        $foreign = $this->fakeCancelTx(56, $this->bob, $nonce);
        $this->assertFalse(app(BridgePayouter::class)->retryCheck($w, $foreign)['safe']);

        // กระเป๋าจ่ายแต่คนละ nonce
        $wrongNonce = $this->fakeCancelTx(56, $this->payoutAddr, $nonce + 5);
        $this->assertFalse(app(BridgePayouter::class)->retryCheck($w, $wrongNonce)['safe']);

        // ยังไม่ลงบล็อก (อยู่ใน mempool ยังถูกแทนที่ได้)
        $pending = '0x'.str_repeat('7', 64);
        $this->chainState[56]['txs'][$pending] = ['hash' => $pending, 'from' => strtolower($this->payoutAddr), 'nonce' => '0x'.dechex($nonce), 'blockNumber' => null];
        $this->assertFalse(app(BridgePayouter::class)->retryCheck($w, $pending)['safe']);

        // hash เดิมเองพิสูจน์ "ไม่ได้จ่าย" ไม่ได้
        $this->assertFalse(app(BridgePayouter::class)->retryCheck($w, $w->payout_tx_hash)['safe']);

        // ของจริง: กระเป๋าจ่าย · nonce ตรง · ลงบล็อกแล้ว
        $cancel = $this->fakeCancelTx(56, $this->payoutAddr, $nonce);
        $this->assertTrue(app(BridgePayouter::class)->retryCheck($w, $cancel)['safe']);
    }

    // ── ธุรกรรมค้าง ─────────────────────────────────────────────────────────

    #[Test]
    public function a_payout_stuck_in_the_mempool_is_flagged_alerted_blocks_the_queue_and_still_settles_when_mined(): void
    {
        $this->mineOnSend = false;
        $a = $this->withdrawal();
        $this->payout();
        $hash = $a->fresh()->payout_tx_hash;
        // อยู่ใน mempool (เชนรู้จัก) แต่ไม่ลงบล็อก
        $this->chainState[56]['txs'][$hash] = ['hash' => $hash, 'from' => strtolower($this->payoutAddr), 'nonce' => '0x0', 'blockNumber' => null];

        $this->travel(31)->minutes();
        $this->payout();

        $a->refresh();
        $this->assertSame(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $a->status);
        $this->assertSame(BridgePayouter::REASON_STUCK, $a->review_reason);
        $this->assertSame($hash, $a->payout_tx_hash, 'ยังผูกกับธุรกรรมเดิม — ไม่เซ็นใหม่');
        $this->assertTrue(SystemAlert::where('alert_key', BridgePayouter::ALERT_STUCK)->where('severity', 'critical')->where('status', 'active')->exists());

        // คิวของเชนนี้หยุด — รายการถัดไปไม่ถูกเซ็นซ้อน nonce ที่ค้าง
        $b = $this->withdrawal();
        $this->payout();
        $this->assertCount(1, $this->signCalls);
        $this->assertSame(BridgeWithdrawal::STATUS_PENDING, $b->fresh()->status);

        // แอดมินอนุมัติจ่ายใหม่ไม่ได้ — ธุรกรรมเดิมยังลงบล็อกได้ทุกเมื่อ
        $this->assertFalse(app(BridgePayouter::class)->retryCheck($a)['safe']);

        // ลงบล็อกทีหลัง → ปิดเป็นจ่ายแล้วเอง · คาดแดงหาย · คิวเดินต่อ
        $this->mineHash($hash);
        $this->mineOnSend = true;
        $this->payout();

        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $a->fresh()->status);
        $this->assertSame(BridgeWithdrawal::STATUS_PAID, $b->fresh()->status);
        $this->assertFalse(SystemAlert::where('alert_key', BridgePayouter::ALERT_STUCK)->where('status', 'active')->exists());
    }
}
