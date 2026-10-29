<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingOrderTicket;
use App\Models\Transaction;
use App\Services\Trading\OrderTicketService;
use App\Services\Trading\TradingCreditService;
use Database\Seeders\TradingFeeTierSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PDOException;
use Tests\Support\FakesBscSwapChain;
use Tests\TestCase;

/**
 * TPIX TRADE — บันทึกไม้ BSC จากใบเสร็จบนเชน (ออดิท 2026-10-28 C1/C2/L3/L4).
 *
 * เดิม /swap/execute เชื่อตัวเลขของหน้าเว็บทั้งหมด:
 *   - hash มั่ว = ไม้ "สำเร็จ" · แจ้ง hash ของคนอื่นก่อน = แถวของเจ้าของจริงหาย
 *   - "เก็บค่าธรรมเนียมแล้ว" ตามคำบอก = รายได้ในหน้าการเงิน
 *   - ตั๋วของไม้ที่ลงเชนแล้วยังขอคืนเงินได้ และตั๋วขั้นถูกครอบไม้มูลค่าสูงได้
 *
 * เทสต์ชุดนี้สร้างธุรกรรมบน BSC จำลองเองทีละเคส (FakesBscSwapChain) — ไม่ผูกยอดในคำขอกับยอดบนเชน
 *
 * Developed by Xman Studio.
 */
class BscSwapRecordTest extends TestCase
{
    use FakesBscSwapChain;

    private const COLLECTOR = '0xffffffffffffffffffffffffffffffffffffffff';

    private const WALLET = '0x1234567890abcdef1234567890abcdef12345678';

    private const OTHER = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const USDT = '0x55d398326f99059ff775485246999027b3197955';

    private const BTCB = '0x7130d2a12b9bcbfae4f2634d864a1ee1ce3ead9c';

    private const BNB = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private int $hash = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->withoutMiddleware([VerifyWalletOwnership::class, ThrottleRequests::class]);

        SiteSetting::set('trading', 'fee_collector_wallet', self::COLLECTOR);
        SiteSetting::set('trading', 'tpix_fee_enabled', '1', 'boolean');
        $this->seed(TradingFeeTierSeeder::class);
        $this->fakeBscChain();

        $bsc = Chain::where('chain_id', 56)->firstOrFail();
        foreach (['USDT' => self::USDT, 'BTCB' => self::BTCB] as $symbol => $address) {
            Token::firstOrCreate(
                ['chain_id' => $bsc->id, 'contract_address' => $address],
                ['symbol' => $symbol, 'name' => $symbol, 'decimals' => 18, 'is_active' => true],
            );
        }

        app(TradingCreditService::class)->topup(self::WALLET, 50, '0x'.str_repeat('a', 64));
    }

    private function nextHash(): string
    {
        return '0x'.str_pad(dechex(++$this->hash), 64, 'c', STR_PAD_LEFT);
    }

    /** ไม้ USDT → BTCB บนเชน: จ่าย $in USDT ได้ $out BTCB */
    private function swapOnChain(string $hash, float|string $in = '99.7', float|string $out = '0.0015', string $state = 'success', string $wallet = self::WALLET, ?string $target = null): void
    {
        $this->bscSwap($hash, $wallet, self::USDT, self::BTCB, self::wei($in, 18), self::wei($out, 18), $state, $target);
    }

    /** ธุรกรรมโอนค่าธรรมเนียม USDT */
    private function feeOnChain(float|string $amount = '0.3', string $collector = self::COLLECTOR, string $state = 'success'): string
    {
        $hash = $this->nextHash();
        $this->bscFeeTransfer($hash, self::WALLET, $collector, self::USDT, self::wei($amount, 18), $state);

        return $hash;
    }

    private function record(string $hash, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/swap/execute', array_merge([
            'from_token' => self::USDT,
            'to_token' => self::BTCB,
            'from_amount' => 100,
            'to_amount' => 0.0015,
            'fee_amount' => 0.3,
            'tx_hash' => $hash,
            'chain_id' => 56,
            'wallet_address' => self::WALLET,
        ], $overrides));
    }

    private function row(string $hash): Transaction
    {
        return Transaction::where('tx_hash', $hash)->firstOrFail();
    }

    private function reconcile(): void
    {
        $this->artisan('trading:reconcile-swaps')->assertSuccessful();
    }

    private function issueTicket(float $orderValueUsd = 100): string
    {
        return $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'order_value_usd' => $orderValueUsd,
            'chain_id' => 56,
            'method' => 'tpix_credit',
        ])->assertCreated()->json('data.uuid');
    }

    private function balance(): float
    {
        return app(TradingCreditService::class)->balanceFor(self::WALLET);
    }

    // ── ไม้ต้องมีอยู่บนเชน และเป็นของกระเป๋าที่แจ้ง ──────────────────────────

    public function test_a_hash_the_chain_has_never_seen_creates_no_row(): void
    {
        $this->record($this->nextHash())
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.recorded', false);

        $this->assertSame(0, Transaction::count());
    }

    public function test_an_unreachable_rpc_creates_no_row_and_asks_to_retry(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash);
        $this->bscDown();

        $this->record($hash)->assertStatus(202)->assertJsonPath('data.recorded', false);
        $this->assertSame(0, Transaction::count());

        $this->bscDown(false);
        $this->record($hash, ['fee_tx_hash' => $this->feeOnChain()])->assertStatus(201);
    }

    public function test_another_wallet_cannot_record_a_swap_first(): void
    {
        // ⚠️ เดิมแถวของคนแจ้งก่อนชน unique กับแถวของเจ้าของจริง → ไม้ของเจ้าของหายจากประวัติ
        $hash = $this->nextHash();
        $this->swapOnChain($hash);

        $this->record($hash, ['wallet_address' => self::OTHER])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');
        $this->assertSame(0, Transaction::count());

        $this->record($hash, ['fee_tx_hash' => $this->feeOnChain()])->assertStatus(201);
        $this->assertSame(self::WALLET, $this->row($hash)->wallet_address);
    }

    public function test_a_recorded_swap_cannot_be_reclaimed_by_another_wallet(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash);
        $this->record($hash, ['fee_tx_hash' => $this->feeOnChain()])->assertStatus(201);

        $this->record($hash, ['wallet_address' => self::OTHER])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TX_ALREADY_RECORDED');

        // คำขอซ้ำของเจ้าของ = 200 แถวเดิม (คิวในเครื่องส่งซ้ำได้)
        $this->record($hash)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame(1, Transaction::count());
    }

    public function test_a_reverted_swap_is_refused(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash, state: 'failed');

        $this->record($hash)->assertStatus(422)->assertJsonPath('error.code', 'TX_FAILED');
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_transaction_that_is_not_a_router_swap_is_refused(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash, target: '0x2222222222222222222222222222222222222222');

        $this->record($hash)->assertStatus(422)->assertJsonPath('error.code', 'NOT_A_SWAP');
        $this->assertSame(0, Transaction::count());
    }

    public function test_only_bsc_is_recorded_from_this_path(): void
    {
        $this->record($this->nextHash(), ['chain_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNSUPPORTED_CHAIN');
    }

    // ── ยอดมาจากใบเสร็จ ────────────────────────────────────────────────────

    public function test_amounts_come_from_the_receipt_not_the_request(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '99.7', out: '0.0015');

        $this->record($hash, ['to_amount' => 5, 'fee_tx_hash' => $this->feeOnChain()])
            ->assertStatus(201)
            ->assertJsonPath('data.source', 'chain')
            ->assertJsonPath('data.fee_status', 'verified');

        $tx = $this->row($hash);
        $this->assertEqualsWithDelta(0.0015, (float) $tx->to_amount, 1e-12);
        $this->assertEqualsWithDelta(100, (float) $tx->from_amount, 1e-12);
        $this->assertEquals(5, (float) $tx->metadata['client']['to_amount']);
        $this->assertNotNull($tx->block_number);
    }

    public function test_a_swap_still_in_the_mempool_is_confirmed_later_by_the_reconciler(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash, state: 'pending');
        $fee = $this->feeOnChain();

        $this->record($hash, ['fee_tx_hash' => $fee])
            ->assertStatus(202)
            ->assertJsonPath('data.recorded', true);

        $tx = $this->row($hash);
        $this->assertSame('pending', $tx->status);
        $this->assertEquals(0, (float) $tx->fee_usd);   // ยังไม่ลงบล็อก = ยังไม่ใช่รายได้

        $this->reconcile();
        $this->assertSame('pending', $tx->fresh()->status);   // ยังอยู่ใน mempool

        $this->bscMine($hash);
        $this->reconcile();

        $tx->refresh();
        $this->assertSame('confirmed', $tx->status);
        $this->assertSame('verified', $tx->metadata['fee_status']);
        $this->assertEqualsWithDelta(0.3, (float) $tx->fee_usd, 1e-9);
    }

    public function test_a_swap_that_never_lands_is_marked_dropped(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash, state: 'pending');
        $this->record($hash)->assertStatus(202);

        $this->bscForget($hash);   // ถูกแทนที่ / หลุดจาก mempool
        $this->reconcile();
        $this->assertSame('pending', $this->row($hash)->status);   // ยังไม่ถึงเวลา

        $this->travel(3)->hours();
        $this->reconcile();

        $tx = $this->row($hash);
        $this->assertSame('failed', $tx->status);
        $this->assertSame('dropped', $tx->error_message);
        $this->record($hash)->assertStatus(422)->assertJsonPath('error.code', 'TX_FAILED');
    }

    public function test_a_native_bnb_swap_and_fee_are_read_from_the_chain(): void
    {
        $hash = $this->nextHash();
        $this->bscSwap($hash, self::WALLET, self::BNB, self::USDT, self::wei('0.997', 18), self::wei('600', 18));
        $fee = $this->nextHash();
        $this->bscFeeTransfer($fee, self::WALLET, self::COLLECTOR, self::BNB, self::wei('0.003', 18));

        $this->record($hash, [
            'from_token' => self::BNB,
            'to_token' => self::USDT,
            'from_amount' => 1,
            'to_amount' => 600,
            'fee_amount' => 0.003,
            'fee_tx_hash' => $fee,
        ])->assertStatus(201)->assertJsonPath('data.fee_status', 'verified');

        $tx = $this->row($hash);
        $this->assertEqualsWithDelta(1, (float) $tx->from_amount, 1e-12);
        $this->assertEqualsWithDelta(600, (float) $tx->to_amount, 1e-9);
        // ค่าธรรมเนียมเป็น BNB คิดดอลลาร์จากขา USDT: 0.003 × 600 / 0.997
        $this->assertEqualsWithDelta(1.80541625, (float) $tx->fee_usd, 1e-6);
    }

    // ── ค่าธรรมเนียมนับจากธุรกรรมโอนบนเชนเท่านั้น ──────────────────────────

    public function test_a_fee_sent_to_someone_else_is_not_revenue(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash);

        $this->record($hash, ['fee_tx_hash' => $this->feeOnChain(collector: self::OTHER)])->assertStatus(201);

        $tx = $this->row($hash);
        $this->assertSame('invalid', $tx->metadata['fee_status']);
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertEquals(0, (float) $tx->fee_usd);
    }

    public function test_one_fee_transfer_counts_for_one_swap_only(): void
    {
        $fee = $this->feeOnChain();

        $first = $this->nextHash();
        $this->swapOnChain($first);
        $this->record($first, ['fee_tx_hash' => $fee])->assertStatus(201)->assertJsonPath('data.fee_status', 'verified');

        $second = $this->nextHash();
        $this->swapOnChain($second);
        $this->record($second, ['fee_tx_hash' => $fee])->assertStatus(201)->assertJsonPath('data.fee_status', 'invalid');

        $tx = $this->row($second);
        $this->assertSame('reused', $tx->metadata['fee_tx_rejected']);
        $this->assertNull($tx->fee_tx_hash);
        $this->assertEquals(0, (float) $tx->fee_usd);
        $this->assertSame('verified', $this->row($first)->metadata['fee_status']);
    }

    public function test_the_swap_hash_cannot_double_as_its_own_fee(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash);

        $this->record($hash, ['fee_tx_hash' => $hash])->assertStatus(201);

        $tx = $this->row($hash);
        $this->assertSame('same_as_swap', $tx->metadata['fee_tx_rejected']);
        $this->assertSame('invalid', $tx->metadata['fee_status']);
    }

    public function test_a_fee_hash_sent_after_the_swap_was_recorded_is_still_counted(): void
    {
        // ไม้ลงก่อน — หน้าเว็บส่งบันทึกครั้งแรกตอนธุรกรรมค่าธรรมเนียมยังไม่ได้ hash
        $hash = $this->nextHash();
        $this->swapOnChain($hash);
        $this->record($hash)->assertStatus(201)->assertJsonPath('data.fee_status', 'missing');

        $this->record($hash, ['fee_tx_hash' => $this->feeOnChain()])
            ->assertOk()
            ->assertJsonPath('data.fee_status', 'verified');

        $tx = $this->row($hash);
        $this->assertTrue($tx->metadata['fee_collected']);
        $this->assertEqualsWithDelta(0.3, (float) $tx->fee_usd, 1e-9);
    }

    public function test_a_fee_transfer_still_pending_is_settled_by_the_reconciler(): void
    {
        $hash = $this->nextHash();
        $this->swapOnChain($hash);
        $fee = $this->feeOnChain(state: 'pending');

        $this->record($hash, ['fee_tx_hash' => $fee])->assertStatus(201)->assertJsonPath('data.fee_status', 'pending');
        $this->assertEquals(0, (float) $this->row($hash)->fee_usd);

        $this->bscMine($fee);
        $this->reconcile();

        $tx = $this->row($hash);
        $this->assertSame('verified', $tx->metadata['fee_status']);
        $this->assertEqualsWithDelta(0.3, (float) $tx->fee_usd, 1e-9);
    }

    public function test_a_cloud_bot_renter_owes_no_coin_fee(): void
    {
        $plan = AiBotPlan::create([
            'code' => 'cloud-plan-test',
            'name' => 'Cloud',
            'name_th' => 'คลาวด์',
            'tier' => 'vip',
            'execution' => 'cloud',
            'credits_per_day' => 0,
            'price_tpix_per_day' => 0,
            'max_bots' => 5,
            'is_active' => true,
        ]);
        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '100');

        $this->record($hash, ['fee_amount' => 0])->assertStatus(201)->assertJsonPath('data.fee_status', 'not_required');

        $tx = $this->row($hash);
        $this->assertSame('waived', $tx->metadata['fee_model']);
        $this->assertTrue($tx->metadata['fee_collected']);
    }

    // ── ใบอนุญาตวางไม้ (TPIX) ──────────────────────────────────────────────

    public function test_a_ticket_bound_to_a_pending_swap_cannot_be_refunded_then_closes_when_it_lands(): void
    {
        $uuid = $this->issueTicket();
        $this->assertSame(48.0, $this->balance());

        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '100', state: 'pending');
        $this->record($hash, ['fee_amount' => 0, 'fee_ticket' => $uuid])->assertStatus(202);

        $ticket = TradingOrderTicket::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($hash, $ticket->order_tx_hash);
        $this->assertSame(56, (int) $ticket->chain_id);

        // ⚠️ ออดิท C1: เดิมคืนได้ทันที → ไม้ลงเชนแล้วแต่ได้ค่าบริการคืน
        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", ['wallet_address' => self::WALLET])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TICKET_TRADE_PENDING');
        $this->assertSame(48.0, $this->balance());

        $this->bscMine($hash);
        $this->reconcile();

        $tx = $this->row($hash);
        $this->assertSame('confirmed', $tx->status);
        $this->assertSame('tpix_ticket', $tx->metadata['fee_model']);
        $this->assertSame('not_required', $tx->metadata['fee_status']);
        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $ticket->fresh()->status);

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", ['wallet_address' => self::WALLET])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_ALREADY_USED');
        $this->assertSame(48.0, $this->balance());
    }

    public function test_a_ticket_bound_to_a_reverted_swap_is_refunded_in_full(): void
    {
        $uuid = $this->issueTicket();
        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '100', state: 'pending');
        $this->record($hash, ['fee_amount' => 0, 'fee_ticket' => $uuid])->assertStatus(202);

        $this->bscMine($hash, 'failed');

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", ['wallet_address' => self::WALLET])
            ->assertOk()
            ->assertJsonPath('data.status', TradingOrderTicket::STATUS_REFUNDED);
        $this->assertSame(50.0, $this->balance());

        $this->reconcile();
        $this->assertSame('failed', $this->row($hash)->status);
    }

    public function test_a_sped_up_swap_takes_over_the_ticket_of_the_replaced_transaction(): void
    {
        // กด "Speed up" ในกระเป๋า: ตั๋วผูกกับ hash เดิมที่ไม่มีวันลง ไม้มาเป็น hash ใหม่
        $uuid = $this->issueTicket();
        $replaced = $this->nextHash();
        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/bind", ['wallet_address' => self::WALLET, 'order_tx_hash' => $replaced])
            ->assertOk();

        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '100');
        $this->record($hash, ['fee_amount' => 0, 'fee_ticket' => $uuid])->assertStatus(201);

        $this->assertSame('tpix_ticket', $this->row($hash)->metadata['fee_model']);
        $ticket = TradingOrderTicket::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $ticket->status);
        $this->assertSame($hash, $ticket->order_tx_hash);
    }

    public function test_a_ticket_bound_to_a_swap_that_landed_cannot_cover_another_swap(): void
    {
        $uuid = $this->issueTicket();
        $landed = $this->nextHash();
        $this->swapOnChain($landed, in: '100');
        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/bind", ['wallet_address' => self::WALLET, 'order_tx_hash' => $landed])
            ->assertOk();

        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '100');
        $this->record($hash, ['fee_amount' => 0, 'fee_ticket' => $uuid])->assertStatus(201);

        $tx = $this->row($hash);
        $this->assertSame('bound_elsewhere', $tx->metadata['fee_ticket_invalid']);
        $this->assertSame('missing', $tx->metadata['fee_status']);
        $this->assertSame($landed, TradingOrderTicket::where('uuid', $uuid)->value('order_tx_hash'));
    }

    public function test_a_cheap_ticket_on_a_large_swap_is_flagged(): void
    {
        // ⚠️ ออดิท L3: ขั้นของตั๋วมาจากมูลค่าที่หน้าเว็บบอก — ตั๋ว $50 (0.5 TPIX) เคยครอบไม้ $5,000 ได้เงียบๆ
        $uuid = $this->issueTicket(50);
        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '5000', out: '0.075');

        $this->record($hash, ['from_amount' => 5000, 'to_amount' => 0.075, 'fee_amount' => 0, 'fee_ticket' => $uuid])
            ->assertStatus(201);

        $tx = $this->row($hash);
        $this->assertSame('tpix_ticket', $tx->metadata['fee_model']);
        $this->assertEquals(
            ['order_value_usd' => 5000, 'required_tpix' => 5, 'paid_tpix' => 0.5],
            $tx->metadata['fee_ticket_undervalued'],
        );
        // ไม้ลงเชนไปแล้ว ย้อนไม่ได้ — ตั๋วปิดตามปกติ แอดมินเห็นธง
        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, TradingOrderTicket::where('uuid', $uuid)->value('status'));
    }

    public function test_price_drift_within_ten_percent_is_not_flagged(): void
    {
        $uuid = $this->issueTicket(95);   // ขั้น $0–100
        $hash = $this->nextHash();
        $this->swapOnChain($hash, in: '105');

        $this->record($hash, ['from_amount' => 105, 'fee_amount' => 0, 'fee_ticket' => $uuid])->assertStatus(201);

        $this->assertArrayNotHasKey('fee_ticket_undervalued', $this->row($hash)->metadata);
    }

    // ── API ใบอนุญาต ──────────────────────────────────────────────────────

    public function test_a_ticket_binds_to_one_transaction_only(): void
    {
        $uuid = $this->issueTicket();
        $hash = $this->nextHash();
        $bind = fn (string $tx) => $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/bind", [
            'wallet_address' => self::WALLET,
            'order_tx_hash' => $tx,
        ]);

        $bind($hash)->assertOk()->assertJsonPath('data.status', TradingOrderTicket::STATUS_ISSUED);
        $bind($hash)->assertOk();   // เรียกซ้ำได้
        $bind($this->nextHash())->assertStatus(422)->assertJsonPath('error.code', 'TICKET_BOUND_ELSEWHERE');

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/bind", ['wallet_address' => self::OTHER, 'order_tx_hash' => $hash])
            ->assertStatus(422);
        $this->assertSame($hash, TradingOrderTicket::where('uuid', $uuid)->value('order_tx_hash'));
    }

    public function test_a_database_error_never_reaches_the_client(): void
    {
        // ⚠️ QueryException เป็น RuntimeException — เดิมคำสั่ง SQL ถูกส่งกลับไปเป็นรหัส error
        $this->mock(OrderTicketService::class, fn ($mock) => $mock->shouldReceive('bind')->andThrow(new QueryException(
            'sqlite',
            'update "trading_order_tickets" set "order_tx_hash" = ?',
            ['0x1'],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation'),
        )));

        $response = $this->postJson('/api/v1/trading-fee/tickets/'.fake()->uuid().'/bind', [
            'wallet_address' => self::WALLET,
            'order_tx_hash' => $this->nextHash(),
        ]);

        $response->assertStatus(500)->assertJsonPath('error.code', 'SERVER_ERROR');
        $this->assertStringNotContainsString('trading_order_tickets', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_ticket_routes_have_their_own_rate_limit_bucket(): void
    {
        // ⚠️ ออดิท L4: throttle:30,1 ไม่มีชื่อ = ใช้กุญแจเดียวกับ throttleApi ของทั้งกลุ่ม → นับซ้อนกัน
        $middleware = fn (string $uri) => collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === $uri)
            ?->gatherMiddleware() ?? [];

        foreach (['tickets', 'tickets/{uuid}/bind', 'tickets/{uuid}/consume', 'tickets/{uuid}/refund'] as $path) {
            $this->assertContains('throttle:30,1,trading-fee-tickets', $middleware("api/v1/trading-fee/{$path}"), $path);
        }
        $this->assertContains('throttle:120,1,trading-fee-quote', $middleware('api/v1/trading-fee/quote'));
        $this->assertContains('throttle:60,1,trading-fee-balance', $middleware('api/v1/trading-fee/balance'));
    }
}
