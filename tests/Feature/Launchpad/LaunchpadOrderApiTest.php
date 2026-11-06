<?php

namespace Tests\Feature\Launchpad;

use App\Models\LaunchpadOrder;
use App\Models\LaunchpadSale;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\Support\FakesLaunchpad;
use Tests\TestCase;

/**
 * สร้างคำสั่งซื้อ — ต้องเป็นลายเซ็นของกระเป๋าผู้ซื้อเอง ใช้ได้ครั้งเดียว และบอกเหตุที่ซื้อไม่ได้ "ก่อน" ผู้ซื้อโอนเงิน.
 */
class LaunchpadOrderApiTest extends TestCase
{
    use FakesBridgeChains;
    use FakesLaunchpad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->setUpLaunchpad();
        $this->registerSale();
    }

    #[Test]
    public function the_sale_list_shows_only_approved_sales_with_their_price(): void
    {
        LaunchpadSale::firstOrFail()->replicate()->fill(['sale_address' => '0x'.str_repeat('7', 40), 'status' => LaunchpadSale::STATUS_PENDING])->save();

        $this->getJson('/api/v1/launchpad/sales')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.token.symbol', 'IDTC')
            ->assertJsonPath('data.0.price_usdt', '0.0000015')
            ->assertJsonPath('data.0.tokens_per_usdt', '666666.666666666666666666');
    }

    #[Test]
    public function prepare_returns_the_exact_text_to_sign_and_the_deposit_target(): void
    {
        $data = $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '25.5', 'source_chain_id' => 56,
        ])->assertOk()->json('data');

        $this->assertStringContainsString('Pay exactly: 25.5 USDT on BNB Smart Chain (chain 56)', $data['message']);
        $this->assertStringContainsString($this->buyerOf(), $data['message']);
        $this->assertStringContainsString('ยอมรับความเสี่ยง', $data['message']);
        $this->assertSame('25500000', $data['amount_units']);
        $this->assertSame($this->vault, $data['quote']['vault']);
        $this->assertSame($this->usdtBsc, $data['quote']['usdt_contract']);
        $this->assertSame(18, $data['quote']['usdt_decimals']);
    }

    #[Test]
    public function a_signed_order_is_created_and_its_public_view_hides_the_signature(): void
    {
        $response = $this->placeOrder('100')['response']->assertCreated();

        $order = LaunchpadOrder::firstOrFail();
        $this->assertSame($this->buyerOf(), $order->buyer_address);
        $this->assertSame('100000000', $order->amount_units);
        $this->assertNotNull($order->risk_accepted_at);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), $order->expires_at->getTimestamp(), 5);

        $response->assertJsonMissingPath('data.signature')->assertJsonMissingPath('data.client_ip');
        $this->getJson("/api/v1/launchpad/wallet/{$this->buyerOf()}/orders")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_signature_from_another_wallet_is_refused(): void
    {
        $this->placeOrder('100', 1, 1, true, $this->testKey(2))['response']
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_SIGNATURE');
        $this->assertSame(0, LaunchpadOrder::count());
    }

    #[Test]
    public function the_buyer_must_accept_the_risks(): void
    {
        $this->placeOrder('100', 1, 1, false)['response']->assertStatus(422)->assertJsonPath('error.code', 'RISK_NOT_ACCEPTED');
    }

    #[Test]
    public function a_wallet_has_one_waiting_order_at_a_time_and_a_signature_works_once(): void
    {
        ['response' => $first, 'prepared' => $prepared] = $this->placeOrder('100');
        $first->assertCreated();

        $this->placeOrder('50')['response']->assertStatus(409)->assertJsonPath('error.code', 'OPEN_ORDER_EXISTS');

        // ยกเลิกแล้วเอาลายเซ็นเดิมมาส่งซ้ำ = ไม่รับ
        $order = LaunchpadOrder::firstOrFail();
        $cancelText = $this->getJson("/api/v1/launchpad/orders/{$order->public_id}/cancel-message")->json('data.message');
        $this->postJson("/api/v1/launchpad/orders/{$order->public_id}/cancel", ['signature' => $this->personalSign($this->testKey(2), $cancelText)])
            ->assertStatus(401);
        $this->postJson("/api/v1/launchpad/orders/{$order->public_id}/cancel", ['signature' => $this->personalSign($this->buyerKey(), $cancelText)])
            ->assertOk()->assertJsonPath('data.status', LaunchpadOrder::STATUS_CANCELLED);

        $this->postJson('/api/v1/launchpad/orders', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '100', 'source_chain_id' => 56,
            'issued_at' => $prepared['issued_at'], 'nonce' => $prepared['nonce'],
            'signature' => $this->personalSign($this->buyerKey(), $prepared['message']), 'risk_accepted' => true,
        ])->assertStatus(409)->assertJsonPath('error.code', 'SIGNATURE_USED');
    }

    #[Test]
    public function an_old_signature_is_refused(): void
    {
        $prepared = $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '100', 'source_chain_id' => 56,
        ])->json('data');

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/launchpad/orders', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '100', 'source_chain_id' => 56,
            'issued_at' => $prepared['issued_at'], 'nonce' => $prepared['nonce'],
            'signature' => $this->personalSign($this->buyerKey(), $prepared['message']), 'risk_accepted' => true,
        ])->assertStatus(422)->assertJsonPath('error.code', 'SIGNATURE_EXPIRED');
    }

    #[Test]
    public function amounts_the_bridge_or_the_sale_would_not_take_are_refused_before_payment(): void
    {
        $prepare = fn (string $amount) => $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => $amount, 'source_chain_id' => 56,
        ]);

        $prepare('0.5')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_OUT_OF_RANGE');
        $prepare('1001')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_OUT_OF_RANGE');
        $prepare('1.1234567')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_AMOUNT');
        $prepare('-5')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_AMOUNT');

        $this->lp['min_purchase'] = '5000000';
        $prepare('2')->assertStatus(422)->assertJsonPath('error.code', 'BELOW_MINIMUM');

        $this->lp['sold'] = bcsub($this->lp['cap'], '1000', 0);
        $prepare('10')->assertStatus(422)->assertJsonPath('error.code', 'SOLD_OUT');

        $this->lp['sold'] = '0';
        $this->lp['max_per_wallet'] = '1000000000000000000000000'; // 1M IDTC
        $prepare('10')->assertStatus(422)->assertJsonPath('error.code', 'WALLET_LIMIT');

        $this->lp['max_per_wallet'] = '0';
        $this->lp['paused'] = true;
        $prepare('10')->assertStatus(422)->assertJsonPath('error.code', 'SALE_PAUSED');
    }

    #[Test]
    public function no_order_is_taken_when_the_launchpad_or_the_bridge_cannot_deliver(): void
    {
        $prepare = fn () => $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '10', 'source_chain_id' => 56,
        ]);

        config(['launchpad.enabled' => false]);
        $prepare()->assertStatus(503)->assertJsonPath('error.code', 'LAUNCHPAD_UNAVAILABLE');

        config(['launchpad.enabled' => true, 'bridge.signers.launchpad_agent.keystore' => '']);
        $prepare()->assertStatus(503)->assertJsonPath('error.code', 'LAUNCHPAD_UNAVAILABLE');

        $this->setUpLaunchpad();
        $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '10', 'source_chain_id' => 1,
        ])->assertStatus(503)->assertJsonPath('error.code', 'LAUNCHPAD_UNAVAILABLE');

        LaunchpadSale::firstOrFail()->update(['status' => LaunchpadSale::STATUS_HIDDEN]);
        $prepare()->assertStatus(404)->assertJsonPath('error.code', 'SALE_NOT_FOUND');
    }

    #[Test]
    public function a_contract_wallet_is_told_before_paying_that_it_cannot_buy(): void
    {
        $this->chainState[56]['codes'][$this->buyerOf()] = '0x6080604052';

        $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '10', 'source_chain_id' => 56,
        ])->assertStatus(422)->assertJsonPath('error.code', 'CONTRACT_WALLET');
    }

    #[Test]
    public function an_unreadable_chain_gives_a_plain_error_without_internal_details(): void
    {
        $this->chainState[4289]['down'] = true;

        $response = $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => 1, 'buyer' => $this->buyerOf(), 'amount' => '10', 'source_chain_id' => 56,
        ])->assertStatus(503)->assertJsonPath('error.code', 'CHAIN_UNAVAILABLE');

        $this->assertStringNotContainsString('rpc.tpix.test', $response->getContent());
    }
}
