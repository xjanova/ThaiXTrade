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
 * Developed by Xman Studio
 */
class SwapRecordHistoryTest extends TestCase
{
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

        SiteSetting::set('trading', 'fee_collector_wallet', '0x'.str_repeat('f', 40));

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

    private function record(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/swap/execute', array_merge([
            'from_token' => self::USDT,
            'to_token' => self::BTCB,
            'from_amount' => 100,
            'to_amount' => 0.0015,
            'fee_amount' => 0.3,
            'tx_hash' => $this->nextHash(),
            'chain_id' => 56,
            'wallet_address' => self::WALLET,
        ], $overrides));
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
    }

    public function test_an_understated_fee_without_the_flag_is_still_rejected(): void
    {
        // กันการแจ้งค่าธรรมเนียมต่ำกว่าจริง — ช่องทางเดียวที่ยอมรับ 0 คือบอกตรงๆ ว่าไม่ได้โอน
        $this->record(['fee_amount' => 0.1])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEE_MISMATCH');
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
        $this->assertEqualsWithDelta(66466.67, (float) $row['price'], 0.01);
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
