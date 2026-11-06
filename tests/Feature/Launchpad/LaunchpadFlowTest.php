<?php

namespace Tests\Feature\Launchpad;

use App\Models\BridgeDeposit;
use App\Models\LaunchpadAgentTx;
use App\Models\LaunchpadOrder;
use App\Models\LaunchpadSale;
use App\Models\SystemAlert;
use App\Services\Bridge\Abi;
use App\Services\Launchpad\LaunchpadAgent;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\Support\FakesLaunchpad;
use Tests\TestCase;

/**
 * ทางเงินของ Launchpad ครบเส้น: ผู้ซื้อเซ็นคำสั่ง → โอน USDT บน BSC → สะพาน mint USDT_TPIX ให้ตัวแทน
 * → ตัวแทน approve + buyFor → เหรียญถึงผู้ซื้อ → เติมพูล · ทุกทางที่ซื้อไม่ได้ต้องจบที่ "ผู้ซื้อได้ USDT_TPIX คืน".
 */
class LaunchpadFlowTest extends TestCase
{
    use FakesBridgeChains;
    use FakesLaunchpad;

    /** 100 USDT บน BSC (18 ทศนิยม) */
    private const HUNDRED_BSC = '100000000000000000000';

    /** 100 USDT_TPIX (6 ทศนิยม) → IDTC ที่ $0.0000015 */
    private const HUNDRED_TOKENS = '66666666666666666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->setUpLaunchpad();
        $this->registerSale();
    }

    private function order(): LaunchpadOrder
    {
        return LaunchpadOrder::firstOrFail();
    }

    private function scanRelayDeliver(): void
    {
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();
        $this->artisan('launchpad:deliver')->assertSuccessful();
    }

    #[Test]
    public function a_matched_bsc_deposit_buys_the_tokens_for_the_buyer_and_feeds_the_pool(): void
    {
        $this->placeOrder('100')['response']->assertCreated()->assertJsonPath('data.status', LaunchpadOrder::STATUS_AWAITING);
        $buyer = $this->buyerOf();

        $this->fakeDeposit(56, $buyer, self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        $deposit = BridgeDeposit::firstOrFail();
        $this->assertSame($this->agentAddr, $deposit->mint_to, 'ยอดที่ตรงคำสั่งต้อง mint ให้ตัวแทนขาย');
        $this->assertSame($this->order()->id, $deposit->launchpad_order_id);
        $this->assertSame(LaunchpadOrder::STATUS_PAID, $this->order()->status);

        $this->artisan('bridge:relay')->assertSuccessful();
        $relay = $this->signCallsFor($this->relayerAddr);
        $this->assertCount(1, $relay);
        $this->assertSame(Abi::mintCalldata($this->agentAddr, '100000000', $deposit->deposit_id), $relay[0]['BRIDGE_DATA']);
        $this->assertSame(BridgeDeposit::STATUS_MINTED, $deposit->fresh()->status, 'Minted ไปที่ตัวแทน = ตรงกับผู้รับที่ตั้งไว้');
        $this->assertSame('100000000', $this->usdtTpixBalance($this->agentAddr));

        $this->artisan('launchpad:deliver')->assertSuccessful();

        $order = $this->order();
        $this->assertSame(LaunchpadOrder::STATUS_DELIVERED, $order->status);
        $this->assertSame(self::HUNDRED_TOKENS, $order->tokens_out);
        $this->assertSame(self::HUNDRED_TOKENS, $this->chainState[4289]['balances'][$this->lpToken][$buyer] ?? null, 'เหรียญต้องถึงกระเป๋าผู้ซื้อตรง ๆ');
        $this->assertSame('0', $this->usdtTpixBalance($this->agentAddr), 'ตัวแทนต้องไม่มียอดค้าง');

        $agent = $this->signCallsFor($this->agentAddr);
        $this->assertSame(
            [LaunchpadAgent::approveCalldata($this->lpSale, '100000000'), LaunchpadAgent::buyForCalldata($buyer, '100000000'), Abi::selector('feed()')],
            array_column($agent, 'BRIDGE_DATA'),
            'approve พอดียอด → buyFor ให้ผู้ซื้อ → feed',
        );
        $this->assertSame('100000000', $this->lp['fed'], 'เงินขายเข้าพูลครบ');
        $this->assertSame(3, LaunchpadAgentTx::where('status', LaunchpadAgentTx::STATUS_CONFIRMED)->count());

        // หน้าประวัติสะพานของผู้ซื้อบอกว่ายอดนี้ใช้ซื้อเหรียญ
        $this->getJson("/api/v1/bridge/history/{$buyer}")->assertOk()->assertJsonPath('data.deposits.0.launchpad_order', true);
        $this->getJson("/api/v1/launchpad/orders/{$order->public_id}")->assertOk()
            ->assertJsonPath('data.status', LaunchpadOrder::STATUS_DELIVERED)
            ->assertJsonMissingPath('data.signature');
    }

    #[Test]
    public function a_deposit_of_a_different_amount_is_an_ordinary_bridge_deposit_and_the_order_expires(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $buyer = $this->buyerOf();

        $this->fakeDeposit(56, $buyer, '99000000000000000000', 980);
        $this->scanRelayDeliver();

        $deposit = BridgeDeposit::firstOrFail();
        $this->assertNull($deposit->mint_to);
        $this->assertSame('99000000', $this->usdtTpixBalance($buyer), 'ยอดไม่ตรง = ได้ USDT_TPIX ตามปกติ');
        $this->assertSame(LaunchpadOrder::STATUS_AWAITING, $this->order()->status);

        $this->travel(61)->minutes();
        $this->artisan('launchpad:deliver')->assertSuccessful();
        $this->assertSame(LaunchpadOrder::STATUS_EXPIRED, $this->order()->status);
        $this->assertCount(0, $this->signCallsFor($this->agentAddr));
    }

    #[Test]
    public function a_deposit_made_before_the_order_was_signed_is_not_pulled_into_it(): void
    {
        $this->placeOrder('100')['response']->assertCreated();

        // บล็อก 920 = ~160 วินาทีก่อนสร้างคำสั่ง (เกินช่วงเผื่อ 60 วินาที)
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 920);
        $this->artisan('bridge:scan')->assertSuccessful();

        $this->assertNull(BridgeDeposit::firstOrFail()->mint_to);
        $this->assertSame(LaunchpadOrder::STATUS_AWAITING, $this->order()->status);
    }

    #[Test]
    public function a_deposit_after_the_order_expired_mints_to_the_depositor(): void
    {
        $this->placeOrder('100')['response']->assertCreated();

        $this->travel(31)->minutes();
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        $this->assertNull(BridgeDeposit::firstOrFail()->mint_to);
        $this->assertSame(LaunchpadOrder::STATUS_AWAITING, $this->order()->status);
    }

    #[Test]
    public function a_deposit_made_in_time_but_scanned_late_still_fills_the_expired_order(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $buyer = $this->buyerOf();

        // ผู้ซื้อโอนนาทีที่ 20 แต่ RPC ของ BSC ล่มนาน — ตัวสแกนเห็นตอนชั่วโมงที่ 2 หลังคำสั่งถูกปิดเป็น expired แล้ว
        $this->travel(20)->minutes();
        $this->fakeDeposit(56, $buyer, self::HUNDRED_BSC, 980);
        $this->chainState[56]['time_base'] = now()->getTimestamp() - 980 * 2 - 40;
        $this->travel(100)->minutes();
        $this->artisan('launchpad:deliver')->assertSuccessful();
        $this->assertSame(LaunchpadOrder::STATUS_EXPIRED, $this->order()->status);

        $this->scanRelayDeliver();

        $this->assertSame($this->agentAddr, BridgeDeposit::firstOrFail()->mint_to);
        $this->assertSame(LaunchpadOrder::STATUS_DELIVERED, $this->order()->status);
        $this->assertSame(self::HUNDRED_TOKENS, $this->chainState[4289]['balances'][$this->lpToken][$buyer] ?? null);
    }

    #[Test]
    public function a_cancelled_order_never_takes_a_later_deposit(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        LaunchpadOrder::firstOrFail()->update(['status' => LaunchpadOrder::STATUS_CANCELLED]);

        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        $this->assertNull(BridgeDeposit::firstOrFail()->mint_to);
    }

    #[Test]
    public function someone_elses_deposit_of_the_same_amount_never_fills_the_order(): void
    {
        $this->placeOrder('100')['response']->assertCreated();

        $this->fakeDeposit(56, $this->bob, self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        $this->assertNull(BridgeDeposit::firstOrFail()->mint_to);
        $this->assertSame(LaunchpadOrder::STATUS_AWAITING, $this->order()->status);
    }

    #[Test]
    public function a_deposit_the_bridge_holds_for_review_is_not_matched(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $buyer = $this->buyerOf();

        // ยอดฝากอื่นของกระเป๋าเดียวกันเพิ่งผ่านไป 1,950 → รวม 2,050 เกินวงเงินวัน 2,000 → สะพานส่งให้คนดู
        BridgeDeposit::create([
            'source_chain_id' => 56, 'tx_hash' => '0x'.str_repeat('ab', 32), 'log_index' => 0, 'block_number' => 500,
            'from_address' => $buyer, 'to_address' => $this->vault, 'token_address' => $this->usdtBsc, 'token_decimals' => 18,
            'amount_raw' => '1950000000000000000000', 'amount_units' => '1950000000', 'mint_units' => '1950000000',
            'deposit_id' => Abi::depositId(56, '0x'.str_repeat('ab', 32), 0), 'status' => BridgeDeposit::STATUS_MINTED,
        ]);

        $this->fakeDeposit(56, $buyer, self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        $held = BridgeDeposit::where('amount_units', '100000000')->firstOrFail();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $held->status);
        $this->assertNull($held->mint_to, 'อนุมัติทีหลัง = mint ให้ผู้ฝากตามปกติ');
        $this->assertSame(LaunchpadOrder::STATUS_AWAITING, $this->order()->status);
    }

    #[Test]
    public function when_the_sale_contract_refuses_the_purchase_the_buyer_gets_usdt_tpix_back(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $buyer = $this->buyerOf();
        $this->fakeDeposit(56, $buyer, self::HUNDRED_BSC, 980);

        // เจ้าของหยุดหน้าขายบนเชนหลังผู้ซื้อโอนแล้ว
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();
        $this->lp['paused'] = true;
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $order = $this->order();
        $this->assertSame(LaunchpadOrder::STATUS_REFUNDED, $order->status);
        $this->assertSame('100000000', $this->usdtTpixBalance($buyer), 'คืน USDT_TPIX เต็มจำนวนบนเชน 4289');
        $this->assertSame('0', $this->usdtTpixBalance($this->agentAddr));
        $this->assertNotNull($order->refund_tx_hash);
        $this->assertSame([Abi::transferCalldata($buyer, '100000000')], array_column($this->signCallsFor($this->agentAddr), 'BRIDGE_DATA'), 'ไม่ approve ไม่ซื้อ — คืนอย่างเดียว');
    }

    #[Test]
    public function a_sale_hidden_by_the_admin_after_payment_refunds_without_buying(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();

        LaunchpadSale::firstOrFail()->update(['status' => LaunchpadSale::STATUS_HIDDEN]);
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $this->assertSame(LaunchpadOrder::STATUS_REFUNDED, $this->order()->status);
        $this->assertSame('100000000', $this->usdtTpixBalance($this->buyerOf()));
    }

    #[Test]
    public function a_buy_that_reverts_on_chain_is_retried_by_simulation_then_refunded(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $buyer = $this->buyerOf();
        $this->fakeDeposit(56, $buyer, self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();

        // ระหว่างจำลองกับลงบล็อก หน้าขายถูกหยุดพอดี → buyFor revert บนเชน
        $this->onSign = function (array $env) {
            if (str_starts_with(strtolower($env['BRIDGE_DATA']), Abi::selector('buyFor(address,uint256)'))) {
                $this->lp['paused'] = true;
            }
        };
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $order = $this->order();
        $this->assertSame(LaunchpadOrder::STATUS_REFUNDED, $order->status);
        $this->assertSame(1, $order->attempts);
        $this->assertSame(1, LaunchpadAgentTx::where('kind', LaunchpadAgentTx::KIND_BUY)->where('status', LaunchpadAgentTx::STATUS_FAILED)->count());
        $this->assertSame('100000000', $this->usdtTpixBalance($buyer));
        $this->assertSame('0', $this->chainState[4289]['balances'][$this->lpToken][$buyer] ?? '0');
    }

    #[Test]
    public function a_transaction_without_a_result_blocks_every_new_signature_until_it_lands(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();

        $this->mineOnSend = false;
        $this->artisan('launchpad:deliver')->assertSuccessful();
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $this->assertCount(1, $this->signCallsFor($this->agentAddr), 'approve ยังไม่รู้ผล = ห้ามเซ็นตัวใหม่');
        $approve = LaunchpadAgentTx::firstOrFail();
        $this->assertSame(LaunchpadAgentTx::STATUS_PENDING, $approve->status);
        $this->assertNotEmpty($approve->raw_tx, 'raw ต้องถูกบันทึกก่อนส่ง');

        // เงียบเกิน 90 วินาที (เชนไม่รู้จัก) → ส่ง raw เดิมซ้ำ ไม่เซ็นใหม่
        $sends = count($this->sendCalls);
        $this->travel(2)->minutes();
        $this->artisan('launchpad:deliver')->assertSuccessful();
        $this->assertSame($sends + 1, count($this->sendCalls));
        $this->assertSame($approve->raw_tx, end($this->sendCalls));
        $this->assertCount(1, $this->signCallsFor($this->agentAddr));

        $this->mineOnSend = true;
        $this->mineHash($approve->tx_hash);
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $this->assertSame(LaunchpadAgentTx::STATUS_CONFIRMED, $approve->fresh()->status);
        $this->assertSame(LaunchpadOrder::STATUS_DELIVERED, $this->order()->status);
    }

    #[Test]
    public function a_transaction_stuck_too_long_raises_a_critical_alert_and_keeps_the_queue_stopped(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();

        $this->mineOnSend = false;
        $this->artisan('launchpad:deliver')->assertSuccessful();
        $this->travel(21)->minutes();
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $this->assertSame(LaunchpadAgentTx::STATUS_STUCK, LaunchpadAgentTx::firstOrFail()->status);
        $this->assertTrue(SystemAlert::where('node', LaunchpadAgent::ALERT_NODE)->where('alert_key', LaunchpadAgent::ALERT_STUCK)->exists());
        $this->assertCount(1, $this->signCallsFor($this->agentAddr));
        $this->assertSame(LaunchpadOrder::STATUS_PAID, $this->order()->status);
    }

    #[Test]
    public function the_relayer_holds_a_matched_deposit_when_the_agent_key_changed(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        $path = tempnam(sys_get_temp_dir(), 'launchpad-agent-');
        file_put_contents($path, json_encode(['address' => str_repeat('9', 40), 'version' => 3]));
        $this->keystoreFiles[] = $path;
        config(['bridge.signers.launchpad_agent.keystore' => $path]);

        $this->artisan('bridge:relay')->assertSuccessful();

        $deposit = BridgeDeposit::firstOrFail();
        $this->assertSame(BridgeDeposit::STATUS_NEEDS_REVIEW, $deposit->status);
        $this->assertSame('launchpad_agent_changed', $deposit->review_reason);
        $this->assertCount(0, $this->signCallsFor($this->relayerAddr));
    }

    #[Test]
    public function switching_the_launchpad_off_stops_matching_but_not_the_bridge(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        config(['launchpad.enabled' => false]);

        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();
        $this->artisan('bridge:relay')->assertSuccessful();

        $this->assertNull(BridgeDeposit::firstOrFail()->mint_to);
        $this->assertSame('100000000', $this->usdtTpixBalance($this->buyerOf()));
    }

    #[Test]
    public function an_order_whose_deposit_the_bridge_admin_rejected_is_closed(): void
    {
        $this->placeOrder('100')['response']->assertCreated();
        $this->fakeDeposit(56, $this->buyerOf(), self::HUNDRED_BSC, 980);
        $this->artisan('bridge:scan')->assertSuccessful();

        BridgeDeposit::firstOrFail()->update(['status' => BridgeDeposit::STATUS_REJECTED]);
        $this->artisan('launchpad:deliver')->assertSuccessful();

        $this->assertSame(LaunchpadOrder::STATUS_CANCELLED, $this->order()->status);
        $this->assertCount(0, $this->signCallsFor($this->agentAddr));
    }
}
