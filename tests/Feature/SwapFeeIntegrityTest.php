<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FinanceController;
use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingOrderTicket;
use App\Models\TradingPair;
use App\Models\Transaction;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\Support\FakesBscSwapChain;
use Tests\TestCase;

/**
 * ความถูกต้องของค่าธรรมเนียมสวอปบน BSC (ออดิท 2026-10-21).
 *
 * บั๊กที่เทสต์ชุดนี้กันไม่ให้กลับมา:
 *   1. แอดมินปิดคู่ฉุกเฉิน แต่ /swap/quote ยังให้ราคา → สวอปยังลงเชนได้
 *   2. ไม้ที่จ่ายค่าบริการด้วยเครดิต TPIX (ไม่หัก % จากเหรียญ) โดน FEE_MISMATCH → ไม้หายจากประวัติ
 *   3. เหรียญทศนิยมน้อย (TRX 6 ตำแหน่ง) ค่าธรรมเนียมที่คิดเป็นหน่วยเล็กสุดต่างเกินเกณฑ์ → ไม้หาย
 *   4. หน้ารายได้รวม fee_amount ข้ามเหรียญ (3,000,000 PEPE กลายเป็น $3,000,000)
 *   5. เปิด order book ภายในแล้วคู่ BSC ถูกจับคู่ใน DB โดยไม่มีการ settle บนเชน
 *
 * ตั้งแต่ออดิท 2026-10-28 (C2) ยอดและค่าธรรมเนียมมาจากใบเสร็จบนเชน — record() สร้างไม้และธุรกรรม
 * ค่าธรรมเนียมบน BSC จำลองให้ตรงกับคำขอ (FakesBscSwapChain) แล้วเซิร์ฟเวอร์อ่านจากตรงนั้น
 *
 * Developed by Xman Studio
 */
class SwapFeeIntegrityTest extends TestCase
{
    use FakesBscSwapChain;

    private const COLLECTOR = '0xffffffffffffffffffffffffffffffffffffffff';

    private const WALLET = '0x1234567890abcdef1234567890abcdef12345678';

    private const OTHER_WALLET = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const USDT = '0x55d398326f99059fF775485246999027B3197955';

    private const BTCB = '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c';

    private const TRX = '0xCE7de646e7208a4Ef112cb6ed5038FA6cC6b12e3';

    private const PEPE = '0x25d887Ce7a35172C62FeBFD67a1856F20FaEbB00';

    private Chain $bsc;

    /** @var array<string, Token> */
    private array $tokens = [];

    private int $hash = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->withoutMiddleware([VerifyWalletOwnership::class, ThrottleRequests::class]);

        SiteSetting::set('trading', 'fee_collector_wallet', self::COLLECTOR);
        $this->fakeBscChain();

        $this->bsc = Chain::where('chain_id', 56)->firstOrFail();
        foreach ([
            'USDT' => [self::USDT, 18],
            'BTCB' => [self::BTCB, 18],
            'TRX' => [self::TRX, 6],
            'PEPE' => [self::PEPE, 18],
        ] as $symbol => [$address, $decimals]) {
            $this->tokens[$symbol] = Token::firstOrCreate(
                ['chain_id' => $this->bsc->id, 'contract_address' => $address],
                ['symbol' => $symbol, 'name' => $symbol, 'decimals' => $decimals, 'is_active' => true],
            );
        }
    }

    private function nextHash(): string
    {
        return '0x'.str_pad(dechex(++$this->hash), 64, 'b', STR_PAD_LEFT);
    }

    /**
     * แจ้งไม้หนึ่งไม้ — ไม้และการโอนค่าธรรมเนียมเกิดบนเชนจำลองตามตัวเลขในคำขอ
     * (ส่วนที่สวอป = from_amount − fee_amount · fee_amount > 0 = มีธุรกรรมโอนเข้ากระเป๋าค่าธรรมเนียม)
     */
    private function record(array $overrides = []): TestResponse
    {
        $payload = array_merge([
            'from_token' => self::USDT,
            'to_token' => self::BTCB,
            'from_amount' => 100,
            'to_amount' => 0.0015,
            'fee_amount' => 0.3,
            'tx_hash' => $this->nextHash(),
            'chain_id' => 56,
            'wallet_address' => self::WALLET,
        ], $overrides);

        $decimals = fn (string $address) => (int) Token::where('chain_id', $this->bsc->id)
            ->where('contract_address', $address)->value('decimals');
        $fromDecimals = $decimals($payload['from_token']);
        $gross = self::wei($payload['from_amount'], $fromDecimals);
        $fee = self::wei($payload['fee_amount'], $fromDecimals);

        $this->bscSwap(
            $payload['tx_hash'],
            $payload['wallet_address'],
            $payload['from_token'],
            $payload['to_token'],
            bcsub($gross, $fee, 0),
            self::wei($payload['to_amount'], $decimals($payload['to_token'])),
        );

        if ($fee !== '0' && ! array_key_exists('fee_tx_hash', $payload)) {
            $payload['fee_tx_hash'] = $this->nextHash();
            $this->bscFeeTransfer($payload['fee_tx_hash'], $payload['wallet_address'], self::COLLECTOR, $payload['from_token'], $fee);
        }

        return $this->postJson('/api/v1/swap/execute', $payload);
    }

    private function pair(string $base, string $quote, bool $active, string $mode = 'onchain'): TradingPair
    {
        return TradingPair::create([
            'base_token_id' => $this->tokens[$base]->id,
            'quote_token_id' => $this->tokens[$quote]->id,
            'chain_id' => $this->bsc->id,
            'symbol' => "{$base}-{$quote}",
            'is_active' => $active,
            'execution_mode' => $mode,
        ]);
    }

    private function ticket(string $wallet = self::WALLET, string $status = TradingOrderTicket::STATUS_ISSUED): TradingOrderTicket
    {
        return TradingOrderTicket::create([
            'wallet_address' => strtolower($wallet),
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'order_value_usd' => 100,
            'fee_method' => TradingOrderTicket::METHOD_CREDIT,
            'fee_amount' => 1,
            'fee_currency' => 'TPIX',
            'status' => $status,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function quote(string $from, string $to): TestResponse
    {
        return $this->getJson('/api/v1/swap/quote?'.http_build_query([
            'from_token' => $from,
            'to_token' => $to,
            'amount' => 100,
            'chain_id' => 56,
        ]));
    }

    // ── ด่านปิดคู่ฉุกเฉิน ──────────────────────────────────────────────────

    public function test_quote_is_refused_when_the_admin_disabled_the_pair(): void
    {
        $this->pair('BTCB', 'USDT', active: false);

        $this->quote(self::USDT, self::BTCB)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PAIR_DISABLED');

        // ทิศกลับก็ต้องปิดด้วย (ขาย = BTCB → USDT)
        $this->quote(self::BTCB, self::USDT)->assertJsonPath('error.code', 'PAIR_DISABLED');
    }

    public function test_quote_still_works_for_an_open_pair_or_a_pair_outside_the_registry(): void
    {
        $this->pair('BTCB', 'USDT', active: true);

        $this->quote(self::USDT, self::BTCB)->assertOk()->assertJsonPath('success', true);
        // หน้า Swap สวอปคู่ที่ไม่มีในทะเบียนได้ (เหรียญทั้งสองเปิดอยู่)
        $this->quote(self::TRX, self::PEPE)->assertOk();
    }

    // ── ใบอนุญาตวางไม้ (เครดิต TPIX) ───────────────────────────────────────

    public function test_a_trade_paid_with_tpix_credit_is_recorded_without_the_percentage_fee(): void
    {
        $ticket = $this->ticket();
        $hash = $this->nextHash();

        $this->record(['fee_amount' => 0, 'fee_collected' => true, 'fee_ticket' => $ticket->uuid, 'tx_hash' => $hash])
            ->assertStatus(201);

        $tx = Transaction::where('tx_hash', $hash)->firstOrFail();
        $this->assertSame('tpix_ticket', $tx->metadata['fee_model']);
        $this->assertTrue($tx->metadata['fee_collected']);
        $this->assertSame('not_required', $tx->metadata['fee_status']);
        $this->assertSame($ticket->uuid, $tx->metadata['fee_ticket']);
        $this->assertEquals(0, (float) $tx->fee_usd);

        // ⚠️ ออดิท C1: ตั๋วถูกปิดพร้อมการบันทึกไม้ — เดิมค้าง issued แล้วขอคืนเงินหลังเทรดได้
        $ticket->refresh();
        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $ticket->status);
        $this->assertSame($hash, $ticket->order_tx_hash);
    }

    public function test_someone_elses_ticket_does_not_waive_the_fee(): void
    {
        $ticket = $this->ticket(self::OTHER_WALLET);
        $hash = $this->nextHash();

        // สวอปลงเชนไปแล้ว — ไม่ปัดตก (ไม้ต้องไม่หาย) แต่บันทึกว่าไม่ได้เก็บค่าธรรมเนียม ให้แอดมินตามต่อ
        $this->record(['fee_amount' => 0, 'fee_ticket' => $ticket->uuid, 'tx_hash' => $hash])->assertStatus(201);

        $tx = Transaction::where('tx_hash', $hash)->firstOrFail();
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertSame('not_found', $tx->metadata['fee_ticket_invalid']);
        $this->assertSame('transfer', $tx->metadata['fee_model']);
        $this->assertSame('missing', $tx->metadata['fee_status']);
        $this->assertSame(TradingOrderTicket::STATUS_ISSUED, $ticket->fresh()->status);
    }

    public function test_one_ticket_covers_only_one_trade(): void
    {
        $ticket = $this->ticket();
        $this->record(['fee_amount' => 0, 'fee_ticket' => $ticket->uuid])->assertStatus(201);

        $second = $this->nextHash();
        $this->record(['fee_amount' => 0, 'fee_ticket' => $ticket->uuid, 'tx_hash' => $second])->assertStatus(201);

        $tx = Transaction::where('tx_hash', $second)->firstOrFail();
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertSame('bound_elsewhere', $tx->metadata['fee_ticket_invalid']);
    }

    public function test_a_refunded_ticket_does_not_waive_the_fee(): void
    {
        $ticket = $this->ticket(status: TradingOrderTicket::STATUS_REFUNDED);
        $hash = $this->nextHash();

        $this->record(['fee_amount' => 0, 'fee_ticket' => $ticket->uuid, 'tx_hash' => $hash])->assertStatus(201);

        $tx = Transaction::where('tx_hash', $hash)->firstOrFail();
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertSame(TradingOrderTicket::STATUS_REFUNDED, $tx->metadata['fee_ticket_invalid']);
    }

    public function test_a_malformed_ticket_is_rejected_by_validation(): void
    {
        $this->record(['fee_ticket' => 'not-a-uuid'])->assertStatus(422);
    }

    // ── เกณฑ์ค่าธรรมเนียมตามหน่วยเล็กสุดของเหรียญ ──────────────────────────

    public function test_a_six_decimal_token_fee_rounded_to_one_unit_is_accepted(): void
    {
        // 0.0003 TRX × 0.3% = 0.0000009 แต่หน้าเว็บคิดเป็นหน่วยเล็กสุด (ปัดขึ้น) = 0.000001
        $this->record([
            'from_token' => self::TRX,
            'to_token' => self::USDT,
            'from_amount' => 0.0003,
            'to_amount' => 0.00008,
            'fee_amount' => 0.000001,
        ])->assertStatus(201);
    }

    public function test_an_understated_fee_is_recorded_as_short_and_only_the_paid_part_counts(): void
    {
        // เดิมปัดตก FEE_MISMATCH = ไม้จริงหายจากประวัติ ทั้งที่สวอปลงเชนแล้ว
        // ตอนนี้บันทึกไม้ + นับเฉพาะยอดที่โอนเข้าจริงบนเชน แล้วติดธงว่าจ่ายขาด
        $hash = $this->nextHash();
        $this->record(['fee_amount' => 0.29, 'tx_hash' => $hash])->assertStatus(201);

        $tx = Transaction::where('tx_hash', $hash)->firstOrFail();
        $this->assertSame('short', $tx->metadata['fee_status']);
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertEqualsWithDelta(0.29, (float) $tx->fee_usd, 1e-9);
    }

    // ── มูลค่าค่าธรรมเนียมเป็นดอลลาร์ ──────────────────────────────────────

    public function test_fee_usd_is_stored_for_stablecoin_buys_and_sells(): void
    {
        $buy = $this->nextHash();
        $this->record(['tx_hash' => $buy])->assertStatus(201);
        $this->assertEquals(0.3, (float) Transaction::where('tx_hash', $buy)->value('fee_usd'));

        // ขาย PEPE 1,000,000,000 → USDT 9,970 : ค่าธรรมเนียม 3,000,000 PEPE ≈ $30 (ไม่ใช่ $3,000,000)
        $sell = $this->nextHash();
        $this->record([
            'from_token' => self::PEPE,
            'to_token' => self::USDT,
            'from_amount' => 1000000000,
            'to_amount' => 9970,
            'fee_amount' => 3000000,
            'tx_hash' => $sell,
        ])->assertStatus(201);
        $this->assertEqualsWithDelta(30.0, (float) Transaction::where('tx_hash', $sell)->value('fee_usd'), 0.0001);
    }

    public function test_admin_trading_revenue_sums_dollars_not_token_units(): void
    {
        $this->record()->assertStatus(201);   // $0.30
        $this->record([
            'from_token' => self::PEPE,
            'to_token' => self::USDT,
            'from_amount' => 1000000000,
            'to_amount' => 9970,
            'fee_amount' => 3000000,
        ])->assertStatus(201);                 // ≈ $30

        $summary = new ReflectionMethod(FinanceController::class, 'getFinanceSummary');
        $data = $summary->invoke(app(FinanceController::class));

        $this->assertEqualsWithDelta(30.30, $data['trading_fee_revenue'], 0.01);
    }

    // ── order book ภายในต้องไม่รับคู่ที่ลงเชนจริง ─────────────────────────

    public function test_internal_order_book_refuses_an_onchain_bsc_pair(): void
    {
        SiteSetting::set('trading', 'orderbook_enabled', '1');
        $this->pair('BTCB', 'USDT', active: true);

        $this->postJson('/api/v1/trading/order', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTCB/USDT',
            'side' => 'buy',
            'type' => 'limit',
            'price' => 60000,
            'amount' => 0.01,
            'chain_id' => 56,
        ])->assertStatus(422)->assertJsonPath('error.code', 'ORDERBOOK_NOT_FOR_PAIR');

        $this->assertSame(0, \App\Models\Order::count());
    }
}
