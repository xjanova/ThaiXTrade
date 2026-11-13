<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\Chain;
use App\Models\LimitOrder;
use App\Models\Token;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Services\Trading\LimitOrderService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\FakesBscSwapChain;
use Tests\TestCase;

/**
 * คำสั่งตั้งราคา (limit order) ของคู่ที่เทรดบนเชนจริง.
 *
 * สิ่งที่ต้องจริงเสมอ:
 *   - ตั้งได้เฉพาะคู่ที่ลงไม้ได้จริง (onchain + เปิดอยู่ + เชน live)
 *   - "ได้รับอย่างน้อย" คิดจากราคาที่ตั้ง ด้วยทศนิยมเต็ม ไม่ผ่าน float
 *   - ปิดเป็นสำเร็จได้ทางเดียว: เซิร์ฟเวอร์เห็นแถว swap ที่ยืนยันแล้วของ hash นั้นจากกระเป๋าเดียวกัน
 *     (client อ้าง hash มั่ว / hash ของคนอื่น ต้องไม่ทำให้คำสั่งสำเร็จ)
 *   - กระเป๋าอื่นแตะคำสั่งของเราไม่ได้
 *
 * Developed by Xman Studio
 */
class LimitOrderApiTest extends TestCase
{
    use FakesBscSwapChain;

    private const WALLET = '0x1234567890abcdef1234567890abcdef12345678';

    private const OTHER = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const USDT = '0x55d398326f99059fF775485246999027B3197955';

    private const BTCB = '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c';

    private Chain $bsc;

    private TradingPair $pair;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // ด่านลายเซ็นกระเป๋า/โควตามีเทสต์ของตัวเอง — ที่นี่ทดสอบตัวคำสั่ง (มีเทสต์ด่านจริงแยกไว้ท้ายไฟล์)
        $this->withoutMiddleware([VerifyWalletOwnership::class, ThrottleRequests::class]);

        // งานตามเวลาถามผลของธุรกรรมจากเชนเอง — ต้องเป็นเชนจำลอง ไม่งั้นเทสต์ยิง RPC จริง
        $this->fakeBscChain();

        $this->bsc = Chain::where('chain_id', 56)->firstOrFail();
        $this->bsc->update(['is_active' => true, 'status' => Chain::STATUS_LIVE]);

        $this->pair = $this->makePair('BTC-USDT', 'onchain');
    }

    private function makePair(string $symbol, string $mode, bool $active = true): TradingPair
    {
        [$baseSymbol, $quoteSymbol] = explode('-', $symbol);

        $base = Token::firstOrCreate(
            ['chain_id' => $this->bsc->id, 'contract_address' => '0x'.substr(hash('sha256', $baseSymbol), 0, 40)],
            ['symbol' => $baseSymbol, 'name' => $baseSymbol, 'decimals' => 18, 'is_active' => true],
        );
        $quote = Token::firstOrCreate(
            ['chain_id' => $this->bsc->id, 'contract_address' => self::USDT],
            ['symbol' => $quoteSymbol, 'name' => $quoteSymbol, 'decimals' => 18, 'is_active' => true],
        );

        return TradingPair::create([
            'base_token_id' => $base->id,
            'quote_token_id' => $quote->id,
            'chain_id' => $this->bsc->id,
            'symbol' => $symbol,
            'is_active' => $active,
            'execution_mode' => $mode,
        ]);
    }

    private function place(array $overrides = [], string $wallet = self::WALLET)
    {
        return $this->postJson('/api/v1/trading/limit-orders', array_merge([
            'wallet_address' => $wallet,
            'pair' => 'BTC-USDT',
            'side' => 'buy',
            'price' => '50000',
            'amount' => '100',
        ], $overrides));
    }

    private function swapRow(string $hash, string $status, string $wallet = self::WALLET, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'type' => 'swap',
            'wallet_address' => $wallet,
            'chain_id' => $this->bsc->id,
            'from_token' => self::USDT,
            'to_token' => self::BTCB,
            'from_amount' => '100',
            'to_amount' => '0.00201',
            'fee_amount' => '0',
            'tx_hash' => $hash,
            'status' => $status,
        ], $overrides));
    }

    private function hash(string $char = 'a'): string
    {
        return '0x'.str_repeat($char, 64);
    }

    // ── สร้างคำสั่ง ─────────────────────────────────────────────────────────

    public function test_buy_order_derives_base_amount_and_minimum_receive_from_the_limit_price(): void
    {
        $response = $this->place(['price' => '50000', 'amount' => '100', 'expires_in_days' => 30]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'limit')
            ->assertJsonPath('data.side', 'buy')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.price', '50000')
            ->assertJsonPath('data.total', '100')
            // 100 ÷ 50000 = 0.002 เหรียญหลัก — คือ "ได้รับอย่างน้อย" ของไม้ซื้อ
            ->assertJsonPath('data.amount', '0.002')
            ->assertJsonPath('data.min_receive', '0.002')
            ->assertJsonPath('data.network_chain_id', 56);

        $order = LimitOrder::firstOrFail();
        $this->assertSame(self::WALLET, $order->wallet_address);
        $this->assertEqualsWithDelta(30, now()->diffInDays($order->expires_at), 0.01);
    }

    public function test_sell_order_minimum_receive_is_base_times_price(): void
    {
        $this->place(['side' => 'sell', 'price' => '0.1234', 'amount' => '1000.5'])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', '1000.5')
            ->assertJsonPath('data.total', '123.4617')
            ->assertJsonPath('data.min_receive', '123.4617');
    }

    public function test_tiny_price_keeps_full_precision_instead_of_float_notation(): void
    {
        // PEPE ราคา 0.00000394 — float จะกลายเป็น 3.94E-6 แล้ว bcmath โยน ValueError
        $this->makePair('PEPE-USDT', 'onchain');

        $this->place(['pair' => 'PEPE-USDT', 'side' => 'sell', 'price' => '0.00000394', 'amount' => '1000000'])
            ->assertStatus(201)
            ->assertJsonPath('data.price', '0.00000394')
            ->assertJsonPath('data.min_receive', '3.94');
    }

    public function test_pair_symbol_is_normalised(): void
    {
        $this->place(['pair' => 'btc/usdt'])->assertStatus(201)->assertJsonPath('data.pair', 'BTC-USDT');
    }

    public function test_reference_price_pairs_and_disabled_pairs_cannot_take_limit_orders(): void
    {
        $this->makePair('XTZ-USDT', 'index');
        $this->makePair('DOGE-USDT', 'onchain', active: false);

        foreach (['XTZ-USDT', 'DOGE-USDT', 'NOPE-USDT'] as $symbol) {
            $this->place(['pair' => $symbol])
                ->assertStatus(422)
                ->assertJsonPath('error.code', LimitOrderService::ERR_PAIR_NOT_TRADABLE);
        }

        $this->assertSame(0, LimitOrder::count());
    }

    public function test_pair_on_a_chain_that_is_not_live_is_rejected(): void
    {
        $this->bsc->update(['status' => 'coming_soon']);

        $this->place()->assertStatus(422)->assertJsonPath('error.code', LimitOrderService::ERR_PAIR_NOT_TRADABLE);
    }

    public function test_invalid_numbers_are_rejected_before_reaching_bcmath(): void
    {
        foreach (['0', '-1', '1e5', 'abc', '', '1.2.3', '0.0000000000000000001'] as $bad) {
            $this->place(['price' => $bad])->assertStatus(422);
            $this->place(['amount' => $bad])->assertStatus(422);
        }

        $this->assertSame(0, LimitOrder::count());
    }

    public function test_order_whose_minimum_receive_rounds_to_zero_is_rejected(): void
    {
        // จ่าย 0.000000000000000001 ที่ราคา 50000 → ได้รับอย่างน้อย = 0 = ไม่มีการคุ้มครองราคา
        $this->place(['amount' => '0.000000000000000001'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', LimitOrderService::ERR_INVALID_AMOUNT);
    }

    public function test_wallet_address_is_required(): void
    {
        $this->postJson('/api/v1/trading/limit-orders', ['pair' => 'BTC-USDT', 'side' => 'buy', 'price' => '1', 'amount' => '1'])
            ->assertStatus(422);
        $this->getJson('/api/v1/trading/limit-orders')->assertStatus(422);
    }

    public function test_open_order_cap_per_wallet(): void
    {
        for ($i = 0; $i < LimitOrderService::MAX_OPEN_PER_WALLET; $i++) {
            $this->place()->assertStatus(201);
        }

        $this->place()->assertStatus(422)->assertJsonPath('error.code', LimitOrderService::ERR_TOO_MANY_OPEN);

        // กระเป๋าอื่นไม่ถูกนับรวม
        $this->place([], self::OTHER)->assertStatus(201);
    }

    // ── อ่านรายการ ──────────────────────────────────────────────────────────

    public function test_orders_are_listed_only_for_their_own_wallet_and_in_the_open_orders_feed(): void
    {
        $mine = $this->place()->json('data.id');
        $this->place([], self::OTHER);

        $this->getJson('/api/v1/trading/limit-orders?wallet_address='.self::WALLET)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine);

        // ตาราง "คำสั่งที่เปิดอยู่" เดิมของหน้าเทรด/แอปต้องเห็นคำสั่งตั้งราคาด้วย
        $this->getJson('/api/v1/trading/orders?wallet_address='.self::WALLET)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine)
            ->assertJsonPath('data.0.type', 'limit')
            ->assertJsonPath('data.0.source', 'limit');
    }

    // ── ยกเลิก ──────────────────────────────────────────────────────────────

    public function test_owner_can_cancel_an_open_order_and_others_cannot(): void
    {
        $id = $this->place()->json('data.id');

        $this->deleteJson("/api/v1/trading/limit-orders/{$id}", ['wallet_address' => self::OTHER])
            ->assertStatus(404);
        $this->assertSame('open', LimitOrder::firstOrFail()->status);

        $this->deleteJson("/api/v1/trading/limit-orders/{$id}", ['wallet_address' => self::WALLET])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // กดซ้ำ (double-tap) — ไม่ใช่ 500 และไม่เปลี่ยนสถานะ
        $this->deleteJson("/api/v1/trading/limit-orders/{$id}", ['wallet_address' => self::WALLET])
            ->assertStatus(409);

        $this->getJson('/api/v1/trading/limit-orders?wallet_address='.self::WALLET)->assertJsonCount(0, 'data');
    }

    public function test_existing_cancel_button_endpoint_cancels_limit_orders_too(): void
    {
        $id = $this->place()->json('data.id');

        $this->deleteJson("/api/v1/trading/order/{$id}", ['wallet_address' => self::OTHER])->assertStatus(404);

        $this->deleteJson("/api/v1/trading/order/{$id}", ['wallet_address' => self::WALLET])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->deleteJson("/api/v1/trading/order/{$id}", ['wallet_address' => self::WALLET])->assertStatus(404);
    }

    // ── ผูกธุรกรรม + ตัดสินผลจากเชน ────────────────────────────────────────

    public function test_order_is_filled_only_when_the_server_holds_a_confirmed_swap_row_for_that_hash(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('a');

        // ยังไม่มีแถว swap — แค่ "รอผล" ไม่ใช่สำเร็จ
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.tx_hash', $hash);

        // ส่งธุรกรรมแล้ว ยกเลิกไม่ได้ — ผลบนเชนเป็นตัวตัดสิน
        $this->deleteJson("/api/v1/trading/limit-orders/{$id}", ['wallet_address' => self::WALLET])->assertStatus(409);

        // เซิร์ฟเวอร์ยืนยันไม้จากเชนแล้ว → งานตามเวลาปิดคำสั่งด้วยยอดจริงจากใบเสร็จ
        $this->swapRow($hash, 'confirmed', overrides: ['from_amount' => '100', 'to_amount' => '0.00201']);
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $order = LimitOrder::firstOrFail();
        $this->assertSame('filled', $order->status);
        $this->assertSame(0, bccomp((string) $order->filled_base, '0.00201', 18));
        $this->assertSame(0, bccomp((string) $order->filled_quote, '100', 18));
        $this->assertNotNull($order->filled_at);
    }

    public function test_submitting_when_the_swap_is_already_confirmed_fills_immediately(): void
    {
        $id = $this->place(['side' => 'sell', 'price' => '50000', 'amount' => '0.002'])->json('data.id');
        $hash = $this->hash('b');
        $this->swapRow($hash, 'confirmed', overrides: [
            'from_token' => self::BTCB, 'to_token' => self::USDT, 'from_amount' => '0.002', 'to_amount' => '100.4',
        ]);

        // กระเป๋าบางตัวคืน hash เป็นตัวพิมพ์ใหญ่ — ต้องจับคู่กับแถว swap ที่เก็บตัวพิมพ์เล็กได้
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => '0x'.strtoupper(substr($hash, 2))])
            ->assertOk()
            ->assertJsonPath('data.status', 'filled');

        $order = LimitOrder::firstOrFail();
        $this->assertSame(0, bccomp((string) $order->filled_base, '0.002', 18));
        $this->assertSame(0, bccomp((string) $order->filled_quote, '100.4', 18));
    }

    public function test_confirmed_swap_of_another_wallet_never_fills_my_order(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('c');
        $this->swapRow($hash, 'confirmed', wallet: self::OTHER);

        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        // เลยช่วงผ่อนผัน — hash นี้ไม่ใช่ไม้ของเรา → เปิดคำสั่งคืน ไม่ใช่สำเร็จ
        $this->travel(LimitOrderService::SUBMITTED_GRACE_MINUTES + 1)->minutes();
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $order = LimitOrder::firstOrFail();
        $this->assertSame('open', $order->status);
        $this->assertNull($order->tx_hash);
        $this->assertSame('tx_not_found', $order->close_reason);
    }

    public function test_reverted_swap_reopens_the_order(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('d');

        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        $this->swapRow($hash, 'failed');
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $order = LimitOrder::firstOrFail();
        $this->assertSame('open', $order->status);
        $this->assertSame('tx_failed', $order->close_reason);

        // เปิดคืนแล้ว ผูกธุรกรรมใหม่ได้
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $this->hash('e')])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    /**
     * หน้าเว็บ/แอปแจ้งบันทึกไม้ไม่สำเร็จ ทั้งที่ธุรกรรมลงเชนไปแล้ว — เราไม่มีแถว swap
     * ห้ามเปิดคำสั่งคืน: คำสั่งที่เปิดคืนจะชวนเจ้าของยืนยันอีกรอบ = ไม้ลงสองรอบ
     */
    public function test_a_swap_that_landed_on_chain_fills_the_order_even_when_no_swap_row_ever_reached_us(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('7');
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();

        // ลงเชนจริงจากกระเป๋าเรา แต่ไม่มีใครแจ้งบันทึก
        $this->bscSwap($hash, self::WALLET, self::USDT, self::BTCB, self::wei('100', 18), self::wei('0.00201', 18));

        $this->travel(LimitOrderService::SUBMITTED_GRACE_MINUTES + 5)->minutes();
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $order = LimitOrder::firstOrFail();
        $this->assertSame('filled', $order->status);
        $this->assertSame($hash, $order->tx_hash);
        // ไม่มีแถวให้อ่านยอด — เว้นว่าง ไม่เดาจากตัวเลขของคำสั่ง
        $this->assertNull($order->filled_base);
        $this->assertNull($order->filled_quote);
    }

    public function test_a_swap_that_reverted_on_chain_reopens_the_order_without_waiting_for_the_grace_period(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('8');
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        $this->bscSwap($hash, self::WALLET, self::USDT, self::BTCB, self::wei('100', 18), self::wei('0.00201', 18), 'failed');

        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $order = LimitOrder::firstOrFail();
        $this->assertSame('open', $order->status);
        $this->assertSame('tx_failed', $order->close_reason);
    }

    public function test_a_transaction_still_in_the_mempool_keeps_the_order_waiting(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('9');
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        $this->bscSwap($hash, self::WALLET, self::USDT, self::BTCB, self::wei('100', 18), self::wei('0.00201', 18), 'pending');

        $this->travel(LimitOrderService::SUBMITTED_GRACE_MINUTES + 5)->minutes();
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $this->assertSame('submitted', LimitOrder::firstOrFail()->status);
    }

    public function test_the_order_keeps_waiting_while_the_chain_cannot_be_asked(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('0');
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        $this->bscDown();

        // RPC ล่ม = ไม่รู้ผล — เปิดคำสั่งคืนตอนนี้อาจชวนยืนยันไม้ที่ลงไปแล้วซ้ำ
        $this->travel(LimitOrderService::SUBMITTED_GRACE_MINUTES + 5)->minutes();
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $this->assertSame('submitted', LimitOrder::firstOrFail()->status);
    }

    public function test_someone_elses_transaction_on_chain_never_fills_my_order(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('5');
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        // ธุรกรรมจริงบนเชน แต่ของกระเป๋าอื่น — อ้าง hash ของคนอื่นมาปิดคำสั่งตัวเองไม่ได้
        $this->bscSwap($hash, self::OTHER, self::USDT, self::BTCB, self::wei('100', 18), self::wei('0.00201', 18));

        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $order = LimitOrder::firstOrFail();
        $this->assertSame('open', $order->status);
        $this->assertSame('tx_failed', $order->close_reason);
    }

    public function test_pending_swap_keeps_the_order_waiting_even_after_the_grace_period(): void
    {
        $id = $this->place()->json('data.id');
        $hash = $this->hash('f');
        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        $this->swapRow($hash, 'pending');

        $this->travel(LimitOrderService::SUBMITTED_GRACE_MINUTES + 5)->minutes();
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $this->assertSame('submitted', LimitOrder::firstOrFail()->status);
    }

    public function test_same_hash_can_be_resent_but_not_bound_to_a_second_order(): void
    {
        $first = $this->place()->json('data.id');
        $second = $this->place()->json('data.id');
        $hash = $this->hash('1');

        $payload = ['wallet_address' => self::WALLET, 'tx_hash' => $hash];
        $this->postJson("/api/v1/trading/limit-orders/{$first}/submitted", $payload)->assertOk();
        // หน้าเว็บลองใหม่เมื่อเน็ตสะดุด — ต้องไม่พัง
        $this->postJson("/api/v1/trading/limit-orders/{$first}/submitted", $payload)->assertOk();

        $this->postJson("/api/v1/trading/limit-orders/{$second}/submitted", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', LimitOrderService::ERR_HASH_TAKEN);
        $this->assertSame('open', LimitOrder::where('uuid', $second)->firstOrFail()->status);
    }

    public function test_another_wallet_cannot_bind_a_transaction_to_my_order(): void
    {
        $id = $this->place()->json('data.id');

        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::OTHER, 'tx_hash' => $this->hash('2')])
            ->assertStatus(404);

        $this->assertSame('open', LimitOrder::firstOrFail()->status);
    }

    public function test_malformed_hash_and_malformed_id_are_rejected(): void
    {
        $id = $this->place()->json('data.id');

        $this->postJson("/api/v1/trading/limit-orders/{$id}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => '0x123'])
            ->assertStatus(422);
        $this->postJson('/api/v1/trading/limit-orders/not-a-uuid/submitted', ['wallet_address' => self::WALLET, 'tx_hash' => $this->hash('3')])
            ->assertStatus(404);
    }

    // ── หมดอายุ ─────────────────────────────────────────────────────────────

    public function test_open_orders_expire_but_orders_awaiting_a_chain_result_do_not(): void
    {
        $waiting = $this->place(['expires_in_days' => 1])->json('data.id');
        $sent = $this->place(['expires_in_days' => 1])->json('data.id');
        $long = $this->place(['expires_in_days' => 30])->json('data.id');

        $this->travel(23)->hours();
        $hash = $this->hash('4');
        $this->postJson("/api/v1/trading/limit-orders/{$sent}/submitted", ['wallet_address' => self::WALLET, 'tx_hash' => $hash])->assertOk();
        $this->swapRow($hash, 'pending');

        $this->travel(2)->hours();
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();

        $this->assertSame('expired', LimitOrder::where('uuid', $waiting)->firstOrFail()->status);
        $this->assertSame('submitted', LimitOrder::where('uuid', $sent)->firstOrFail()->status);
        $this->assertSame('open', LimitOrder::where('uuid', $long)->firstOrFail()->status);

        // ไม้ของคำสั่งที่เลยอายุไปแล้ว revert → หมดอายุ ไม่ใช่เปิดคืน
        Transaction::where('tx_hash', $hash)->update(['status' => 'failed']);
        $this->artisan('trading:settle-limit-orders')->assertSuccessful();
        $this->assertSame('expired', LimitOrder::where('uuid', $sent)->firstOrFail()->status);
    }

    // ── ด่านกระเป๋าจริง ─────────────────────────────────────────────────────

    public function test_unverified_wallet_is_refused_by_the_real_ownership_gate(): void
    {
        $this->withMiddleware([VerifyWalletOwnership::class]);

        $this->place()->assertStatus(403);
        $this->getJson('/api/v1/trading/limit-orders?wallet_address='.self::WALLET)->assertStatus(403);
        $this->assertSame(0, LimitOrder::count());
    }
}
