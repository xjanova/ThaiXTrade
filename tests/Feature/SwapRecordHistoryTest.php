<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesBscSwapChain;
use Tests\TestCase;

/**
 * ไม้ BSC ที่วางจากกระดานเทรดต้องถูกบันทึก และอ่านกลับมาเป็นภาษาของกระดานเทรด.
 *
 * อาการเดิม (ออดิท 2026-10-09):
 *   1. ค่าธรรมเนียมต่ำกว่า 0.0001 → PHP เขียน "3.0E-5" → bccomp โยน ValueError → 500
 *      (ขาย ETH 0.01 / BTC ต่ำกว่า ~0.03) ไม้จริงหายจากประวัติ
 *   2. ผู้ใช้ปฏิเสธธุรกรรมค่าธรรมเนียม → FEE_MISMATCH → ไม้หายจากประวัติเงียบๆ
 *   3. ประวัติโชว์คู่เป็นที่อยู่สัญญา ฝั่ง "swap" ราคา 0 และกราฟไม่มีป้ายไม้ BSC
 *
 * ไม้และค่าธรรมเนียมเกิดบน BSC จำลองตามตัวเลขในคำขอ (FakesBscSwapChain) — เซิร์ฟเวอร์อ่านจากใบเสร็จ
 *
 * Developed by Xman Studio
 */
class SwapRecordHistoryTest extends TestCase
{
    use FakesBscSwapChain;

    private const COLLECTOR = '0xffffffffffffffffffffffffffffffffffffffff';

    private const WALLET = '0x1234567890abcdef1234567890abcdef12345678';

    private const USDT = '0x55d398326f99059fF775485246999027B3197955';

    private const BTCB = '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c';

    private const ETH = '0x2170Ed0880ac9A755fd29B2688956BD959F933F8';

    private Chain $bsc;

    private int $hash = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // ด่านลายเซ็นกระเป๋า/โควตามีเทสต์ของตัวเองแล้ว — ที่นี่ทดสอบการบันทึกและการอ่านกลับ
        $this->withoutMiddleware([VerifyWalletOwnership::class, ThrottleRequests::class]);

        SiteSetting::set('trading', 'fee_collector_wallet', self::COLLECTOR);
        $this->fakeBscChain();

        $this->bsc = Chain::where('chain_id', 56)->firstOrFail();
        foreach (['USDT' => self::USDT, 'BTCB' => self::BTCB, 'ETH' => self::ETH] as $symbol => $address) {
            Token::firstOrCreate(
                ['chain_id' => $this->bsc->id, 'contract_address' => $address],
                ['symbol' => $symbol, 'name' => $symbol, 'decimals' => 18, 'is_active' => true],
            );
        }
    }

    private function nextHash(): string
    {
        return '0x'.str_pad(dechex(++$this->hash), 64, 'a', STR_PAD_LEFT);
    }

    /** แจ้งไม้ — $feeOnChain = false: หน้าเว็บอ้าง fee_amount แต่ไม่มีธุรกรรมโอนบนเชน */
    private function record(array $overrides = [], bool $feeOnChain = true): TestResponse
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

        // ทุกเหรียญในไฟล์นี้ 18 ตำแหน่ง
        $gross = self::wei($payload['from_amount'], 18);
        $fee = self::wei($payload['fee_amount'], 18);
        $this->bscSwap($payload['tx_hash'], self::WALLET, $payload['from_token'], $payload['to_token'], bcsub($gross, $fee, 0), self::wei($payload['to_amount'], 18));

        if ($feeOnChain && $fee !== '0') {
            $payload['fee_tx_hash'] = $this->nextHash();
            $this->bscFeeTransfer($payload['fee_tx_hash'], self::WALLET, self::COLLECTOR, $payload['from_token'], $fee);
        }

        return $this->postJson('/api/v1/swap/execute', $payload);
    }

    public function test_a_tiny_fee_is_recorded_instead_of_crashing(): void
    {
        // ขาย ETH 0.01 → ค่าธรรมเนียม 0.00003 ETH (เดิม "3.0E-5" ทำ bccomp ล้ม = 500)
        $this->record([
            'from_token' => self::ETH,
            'to_token' => self::USDT,
            'from_amount' => 0.01,
            'to_amount' => 24.9,
            'fee_amount' => 0.00003,
            'pair' => 'ETH/USDT',
            'side' => 'sell',
            'price' => 2490,
        ])->assertStatus(201)->assertJsonPath('success', true);
    }

    public function test_a_skipped_fee_transfer_still_keeps_the_trade_in_history(): void
    {
        $hash = $this->nextHash();

        $this->record(['fee_amount' => 0, 'fee_collected' => false, 'tx_hash' => $hash])
            ->assertStatus(201);

        $tx = Transaction::where('tx_hash', $hash)->firstOrFail();
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertSame('missing', $tx->metadata['fee_status']);
    }

    public function test_a_fee_claim_without_an_onchain_transfer_is_not_revenue(): void
    {
        // หน้าเว็บรุ่นเก่าบอก "เก็บแล้ว" โดยไม่มี hash ให้ตรวจ — เดิมนับเป็นรายได้ตามคำบอก (ออดิท C2)
        $hash = $this->nextHash();
        $this->record(['fee_collected' => true, 'tx_hash' => $hash], feeOnChain: false)->assertStatus(201);

        $tx = Transaction::where('tx_hash', $hash)->firstOrFail();
        $this->assertSame('unverified', $tx->metadata['fee_status']);
        $this->assertFalse($tx->metadata['fee_collected']);
        $this->assertEquals(0, (float) $tx->fee_usd);
        $this->assertEquals(0, (float) $tx->fee_amount);
        // ยอดขาเข้าที่บันทึก = ส่วนที่สวอปจริงบนเชน ไม่รวมค่าธรรมเนียมที่ไม่ได้โอน
        $this->assertEqualsWithDelta(99.7, (float) $tx->from_amount, 1e-12);
    }

    public function test_history_speaks_the_trade_board_language(): void
    {
        $this->record([
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'price' => 66466.67,
        ])->assertStatus(201);

        $row = $this->getJson('/api/v1/trading/history?wallet_address='.self::WALLET)
            ->assertOk()
            ->json('data.0');

        $this->assertSame('BTC/USDT', $row['pair']);
        $this->assertSame('buy', $row['side']);
        // ราคาคิดใหม่จากยอดบนเชน: จ่ายรวม 100 USDT (สวอป 99.7 + ค่าธรรมเนียม 0.3) ได้ 0.0015 BTCB
        $this->assertEqualsWithDelta(66666.67, (float) $row['price'], 0.01);
        // ไม้ซื้อ: จำนวน = เหรียญที่ได้ · มูลค่า = USDT ที่จ่าย (เดิมกลับด้าน)
        $this->assertEqualsWithDelta(0.0015, (float) $row['amount'], 1e-12);
        $this->assertEqualsWithDelta(100, (float) $row['total'], 1e-9);
        $this->assertSame(56, $row['chain_id']);
    }

    public function test_a_wallet_address_sent_as_an_array_is_rejected_not_a_500(): void
    {
        // ด่านกระเป๋าต้องทำงานจริงในเทสต์นี้ — ถอดเฉพาะโควตา
        $this->withMiddleware(VerifyWalletOwnership::class);

        $this->getJson('/api/v1/trading/history?wallet_address[]=0x1234')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_WALLET');
    }

    public function test_open_orders_list_works_when_only_legacy_rows_exist(): void
    {
        // ไม่มีออเดอร์ภายในเลย + มีแถว legacy ค้าง — เดิม Eloquent::merge เรียก getKey() กับ array → 500
        Transaction::create([
            'type' => 'order_buy',
            'wallet_address' => self::WALLET,
            'chain_id' => $this->bsc->id,
            'from_token' => strtolower(self::USDT),
            'to_token' => strtolower(self::BTCB),
            'from_amount' => 10,
            'to_amount' => 10,
            'fee_amount' => 0,
            'tx_hash' => $this->nextHash(),
            'status' => 'pending',
            'metadata' => ['pair' => 'BTC/USDT', 'side' => 'buy', 'price' => '65000'],
        ]);

        $this->getJson('/api/v1/trading/orders?wallet_address='.self::WALLET)
            ->assertOk()
            ->assertJsonPath('data.0.pair', 'BTC/USDT');
    }

    public function test_old_swap_rows_are_described_from_token_symbols(): void
    {
        // แถวที่บันทึกก่อนแก้ — ไม่มี pair/side/price ใน metadata
        Transaction::create([
            'type' => 'swap',
            'wallet_address' => self::WALLET,
            'chain_id' => $this->bsc->id,
            'from_token' => strtolower(self::BTCB),
            'to_token' => strtolower(self::USDT),
            'from_amount' => 0.002,
            'to_amount' => 130,
            'fee_amount' => 0.000006,
            'tx_hash' => $this->nextHash(),
            'status' => 'confirmed',
            'metadata' => ['fee_rate' => 0.3],
        ]);

        $row = $this->getJson('/api/v1/trading/history?wallet_address='.self::WALLET)
            ->assertOk()
            ->json('data.0');

        $this->assertSame('BTCB/USDT', $row['pair']);
        $this->assertSame('sell', $row['side']);
        $this->assertEqualsWithDelta(65000, (float) $row['price'], 0.01);
        $this->assertEqualsWithDelta(0.002, (float) $row['amount'], 1e-12);
        $this->assertEqualsWithDelta(130, (float) $row['total'], 1e-9);
    }
}
