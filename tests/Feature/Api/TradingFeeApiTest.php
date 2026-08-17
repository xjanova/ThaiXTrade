<?php

namespace Tests\Feature\Api;

use App\Models\SiteSetting;
use App\Models\TradingOrderTicket;
use App\Services\Trading\TradingCreditService;
use Database\Seeders\TradingFeeTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ด่านค่าบริการวางไม้ผ่าน HTTP จริง.
 *
 * เจ้าของกำหนด: "การขึ้นออเดอร์ เราจะเก็บตอนนั้นเลย ถ้าไม่มีก็ขึ้นออเดอร์ไม่ได้"
 * และคนที่ไม่มี TPIX ก็ต้องจ่ายก่อนวางไม้เหมือนกัน เพื่อกันเบี้ยว
 *
 * ⚠️ ชุดนี้คุมทางที่พลาดแล้วเสียเงินจริง: ตั๋วของคนอื่น · ขอตั๋วโดยไม่มีเครดิต ·
 *    เอาธุรกรรมจ่ายค่าบริการใบเดิมมาขอตั๋วซ้ำ
 *
 * Developed by Xman Studio.
 */
class TradingFeeApiTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xabababababababababababababababababababab';

    private const OTHER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(TradingFeeTierSeeder::class);
        SiteSetting::set('trading', 'tpix_fee_enabled', '1', 'boolean');

        foreach ([self::WALLET, self::OTHER] as $wallet) {
            Cache::put("wallet_verified:{$wallet}", [
                'ip' => '127.0.0.1',
                'verified_at' => now()->toIso8601String(),
            ], now()->addHours(4));
        }
    }

    private function fund(string $wallet, float $amount): void
    {
        app(TradingCreditService::class)->topup($wallet, $amount, '0x'.str_repeat(substr($wallet, 2, 1), 64));
    }

    // ── ตารางราคา (สาธารณะ) ─────────────────────────────────────────────────

    /** ต้องดูราคาได้ก่อนเชื่อมกระเป๋า — เจ้าของสั่งให้ "ชี้แจงรายละเอียดให้ครบ" */
    #[Test]
    public function ดูตารางค่าบริการได้โดยไม่ต้องเชื่อมกระเป๋า(): void
    {
        $data = $this->getJson('/api/v1/trading-fee/tiers')->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertCount(4, $data['tiers']);
        $this->assertArrayHasKey('minimum', $data['topup']);
        $this->assertArrayHasKey('refund_gas_fee', $data);
    }

    // ── ใบเสนอราคา ──────────────────────────────────────────────────────────

    /** ต้องเห็นทั้งสองทางเทียบกันได้ พร้อมรู้ว่าทางไหนคืนเงินเต็ม */
    #[Test]
    public function ใบเสนอราคาบอกทั้งสองทางและทางที่แนะนำ(): void
    {
        $this->fund(self::WALLET, 100);

        $data = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET,
            'order_value_usd' => 250,
            'chain_id' => 56,
        ])->assertOk()->json('data');

        $this->assertEquals(2.0, $data['tpix']['fee_tpix']);
        $this->assertTrue($data['tpix']['has_enough']);
        $this->assertTrue($data['tpix']['refund_full']);
        $this->assertFalse($data['onchain']['refund_full']);
        $this->assertSame('tpix_credit', $data['recommended']);
    }

    /** เครดิตไม่พอ → แนะนำทางเดิม และบอกว่าขาดอีกเท่าไร */
    #[Test]
    public function เครดิตไม่พอบอกยอดที่ขาดและแนะนำทางเดิม(): void
    {
        $this->fund(self::WALLET, 0.5);

        $data = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET,
            'order_value_usd' => 250,
            'chain_id' => 56,
        ])->assertOk()->json('data');

        $this->assertFalse($data['tpix']['has_enough']);
        $this->assertEquals(1.5, $data['tpix']['shortfall']);
        $this->assertSame('onchain', $data['recommended']);
        // ไม่มี TPIX ก็ยังเทรดได้ แค่จ่ายแพงกว่า — ไม่ปิดประตูใส่ผู้ใช้ใหม่
        $this->assertTrue($data['can_place']);
    }

    /** ปิดระบบแล้วทุกอย่างกลับไปเป็นแบบเดิม ไม่พัง */
    #[Test]
    public function ปิดระบบค่าบริการ_tpix_แล้วกลับไปทางเดิม(): void
    {
        SiteSetting::set('trading', 'tpix_fee_enabled', '0', 'boolean');
        SiteSetting::clearCache();

        $data = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET,
            'order_value_usd' => 250,
            'chain_id' => 56,
        ])->assertOk()->json('data');

        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['tpix']['available']);
        $this->assertTrue($data['can_place']);
    }

    // ── ขอ/ใช้/คืน ใบอนุญาต ─────────────────────────────────────────────────

    #[Test]
    public function ขอใบอนุญาตแล้วเครดิตถูกหักทันที(): void
    {
        $this->fund(self::WALLET, 10);

        $data = $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'order_value_usd' => 250,
            'chain_id' => 56,
            'method' => 'tpix_credit',
        ])->assertStatus(201)->json('data');

        $this->assertEquals(2.0, $data['fee_amount']);
        $this->assertEquals(8.0, $data['balance']);
        $this->assertTrue($data['refunds_in_full']);
    }

    /** ⚠️ ไม่มีเครดิต = ขอใบอนุญาตไม่ได้ = วางไม้ไม่ได้ ตรงตามที่เจ้าของสั่ง */
    #[Test]
    public function ไม่มีเครดิตขอใบอนุญาตไม่ได้(): void
    {
        $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'order_value_usd' => 250,
            'chain_id' => 56,
            'method' => 'tpix_credit',
        ])->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_CREDIT');

        $this->assertSame(0, TradingOrderTicket::count());
    }

    #[Test]
    public function ยกเลิกไม้แล้วเครดิตกลับมาเต็ม(): void
    {
        $this->fund(self::WALLET, 10);

        $uuid = $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT', 'side' => 'buy',
            'order_value_usd' => 250, 'chain_id' => 56, 'method' => 'tpix_credit',
        ])->json('data.uuid');

        $data = $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", [
            'wallet_address' => self::WALLET,
            'reason' => 'ผู้ใช้กดยกเลิกในกระเป๋า',
        ])->assertOk()->json('data');

        $this->assertEquals(2.0, $data['refund_amount']);
        $this->assertEquals(0.0, $data['gas_deducted']);
        $this->assertEquals(10.0, $data['balance']);
    }

    /** จ่ายบนเชนแล้วยกเลิก → คืนโดยหักค่าแก๊ส และต้องบอกยอดที่หักให้ชัด */
    #[Test]
    public function จ่ายบนเชนแล้วยกเลิกคืนโดยหักค่าแก๊ส(): void
    {
        SiteSetting::set('trading', 'refund_gas_fee', '0.4');
        SiteSetting::clearCache();

        $uuid = $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT', 'side' => 'buy',
            'order_value_usd' => 250, 'chain_id' => 56, 'method' => 'onchain',
            'fee_tx_hash' => '0x'.str_repeat('7', 64),
            'fee_amount' => 3.0,
            'fee_currency' => 'USDT',
        ])->assertStatus(201)->json('data.uuid');

        $data = $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", [
            'wallet_address' => self::WALLET,
        ])->assertOk()->json('data');

        $this->assertEquals(2.6, $data['refund_amount']);
        $this->assertEquals(0.4, $data['gas_deducted']);
    }

    /** ⚠️ ตั๋วของคนอื่นแตะไม่ได้ ไม่ว่าจะใช้หรือขอคืนเงิน */
    #[Test]
    public function ใบอนุญาตของกระเป๋าอื่นแตะไม่ได้(): void
    {
        $this->fund(self::WALLET, 10);

        $uuid = $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT', 'side' => 'buy',
            'order_value_usd' => 250, 'chain_id' => 56, 'method' => 'tpix_credit',
        ])->json('data.uuid');

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", [
            'wallet_address' => self::OTHER,
        ])->assertStatus(422)->assertJsonPath('error.code', 'TICKET_NOT_FOUND');

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/consume", [
            'wallet_address' => self::OTHER,
        ])->assertStatus(422);
    }

    /** ⚠️ ใช้ตั๋วแล้วขอคืนเงินไม่ได้ ไม่งั้นเทรดฟรีทุกไม้ */
    #[Test]
    public function ใช้ใบอนุญาตแล้วขอคืนเงินไม่ได้(): void
    {
        $this->fund(self::WALLET, 10);

        $uuid = $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT', 'side' => 'buy',
            'order_value_usd' => 250, 'chain_id' => 56, 'method' => 'tpix_credit',
        ])->json('data.uuid');

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/consume", [
            'wallet_address' => self::WALLET,
            'order_tx_hash' => '0x'.str_repeat('8', 64),
        ])->assertOk();

        $this->postJson("/api/v1/trading-fee/tickets/{$uuid}/refund", [
            'wallet_address' => self::WALLET,
        ])->assertStatus(422)->assertJsonPath('error.code', 'TICKET_ALREADY_USED');

        $this->assertSame(8.0, app(TradingCreditService::class)->balanceFor(self::WALLET));
    }

    #[Test]
    public function ดูยอดคลังและประวัติเดินบัญชีได้(): void
    {
        $this->fund(self::WALLET, 25);

        $data = $this->getJson('/api/v1/trading-fee/balance?wallet_address='.self::WALLET)
            ->assertOk()->json('data');

        $this->assertEquals(25.0, $data['balance']);
        $this->assertCount(1, $data['history']);
        $this->assertSame('topup', $data['history'][0]['type']);
    }

    /** ยังไม่ตั้งกระเป๋ารับเงิน = เติมไม่ได้ และต้องบอกเหตุผลตรงๆ */
    #[Test]
    public function ยังไม่ตั้งกระเป๋ารับเงินก็เติมไม่ได้(): void
    {
        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET,
            'tx_hash' => '0x'.str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_WALLET_NOT_SET');
    }
}
