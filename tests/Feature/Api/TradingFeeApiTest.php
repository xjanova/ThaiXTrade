<?php

namespace Tests\Feature\Api;

use App\Models\SiteSetting;
use App\Models\TradingOrderTicket;
use App\Services\Trading\TradingCreditService;
use Database\Seeders\TradingFeeTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    // ── TPIX Chain: ค่าวางไม้ต้องจ่ายด้วยเครดิต tpix ทุกไม้ (เจ้าของสั่ง 2026-11-13) ──────────

    /**
     * เดิมกลับหัว: บนเชน 4289 ทาง "จ่ายเป็นเหรียญ" ไม่ถูกเก็บค่าแพลตฟอร์มเลย (ไม่มีธุรกรรมโอนค่าธรรมเนียมให้เก็บ)
     * ขณะที่คนเลือกจ่ายด้วยเครดิตเสียค่าวางไม้เพิ่ม — ตอนนี้เชนนี้เหลือทางเดียว
     */
    #[Test]
    public function บนเชน_tpix_ปิดทางจ่ายเป็นเหรียญและแนะนำเครดิตเสมอ(): void
    {
        $this->fund(self::WALLET, 100);

        $data = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET,
            'order_value_usd' => 250,
            'chain_id' => 4289,
        ])->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertFalse($data['onchain']['available']);
        $this->assertNotEmpty($data['onchain']['reason']);
        $this->assertEquals(0.0, $data['onchain']['fee_usd']);
        $this->assertEquals(2.0, $data['tpix']['fee_tpix']);
        $this->assertSame('tpix_credit', $data['recommended']);
        $this->assertTrue($data['can_place']);
        $this->assertNull($data['reason']);
    }

    #[Test]
    public function บนเชน_tpix_เครดิตไม่พอวางไม้ไม่ได้จนกว่าจะเติม(): void
    {
        $this->fund(self::WALLET, 0.5);

        $data = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET,
            'order_value_usd' => 250,
            'chain_id' => 4289,
        ])->assertOk()->json('data');

        $this->assertFalse($data['onchain']['available']);
        $this->assertFalse($data['tpix']['has_enough']);
        $this->assertEquals(1.5, $data['tpix']['shortfall']);
        // ต่างจาก BSC: ไม่มีทางสำรองให้ "จ่ายแพงกว่า" — ต้องเติมก่อน
        $this->assertSame('tpix_credit', $data['recommended']);
        $this->assertFalse($data['can_place']);
        $this->assertNotEmpty($data['reason']);
    }

    #[Test]
    public function บนเชน_tpix_ยังไม่เชื่อมกระเป๋าก็เห็นว่าต้องใช้เครดิต(): void
    {
        $data = $this->postJson('/api/v1/trading-fee/quote', [
            'order_value_usd' => 250,
            'chain_id' => 4289,
        ])->assertOk()->json('data');

        $this->assertFalse($data['onchain']['available']);
        $this->assertFalse($data['can_place']);
    }

    /** กติกาใหม่ต้องไม่กระทบ BSC และต้องไม่มีผลเมื่อแอดมินปิดระบบค่าบริการ / กระเป๋าได้รับยกเว้น */
    #[Test]
    public function กติกาเครดิตอย่างเดียวมีผลเฉพาะเชน_tpix_ที่เปิดระบบอยู่(): void
    {
        $bsc = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET, 'order_value_usd' => 250, 'chain_id' => 56,
        ])->json('data');
        $this->assertTrue($bsc['onchain']['available']);
        $this->assertTrue($bsc['can_place']);

        SiteSetting::set('trading', 'tpix_fee_enabled', '0', 'boolean');
        SiteSetting::clearCache();

        $off = $this->postJson('/api/v1/trading-fee/quote', [
            'wallet_address' => self::WALLET, 'order_value_usd' => 250, 'chain_id' => 4289,
        ])->json('data');
        $this->assertFalse($off['enabled']);
        $this->assertTrue($off['onchain']['available']);
        $this->assertTrue($off['can_place']);
    }

    #[Test]
    public function ไม้บนเชน_tpix_ขอใบอนุญาตด้วยเครดิตได้และถูกหักตามขั้น(): void
    {
        $this->fund(self::WALLET, 10);

        $ticket = $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'TPIX/USDT',
            'side' => 'buy',
            'order_value_usd' => 250,
            'chain_id' => 4289,
            'method' => 'tpix_credit',
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($ticket['uuid']);
        $this->assertEquals(8.0, app(TradingCreditService::class)->balanceFor(self::WALLET));
        $this->assertSame(4289, (int) TradingOrderTicket::where('uuid', $ticket['uuid'])->value('chain_id'));
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

    /**
     * ⚠️ ขอใบอนุญาตแบบจ่ายบนเชนไม่ได้ — hash กับยอดมาจาก client ล้วนๆ ไม่เคยตรวจบนเชน
     *    (เดิมได้ตั๋วฟรีแล้วขอคืน "รอโอนคืน 999,999.6" ได้จากยอดที่พิมพ์มาเอง)
     */
    #[Test]
    public function ขอใบอนุญาตแบบจ่ายบนเชนถูกปฏิเสธพร้อมรหัสที่อ่านรู้เรื่อง(): void
    {
        $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT', 'side' => 'buy',
            'order_value_usd' => 250, 'chain_id' => 56, 'method' => 'onchain',
            'fee_tx_hash' => '0x'.str_repeat('7', 64),
            'fee_amount' => 1000000,
            'fee_currency' => 'USDT',
        ])->assertStatus(422)->assertJsonPath('error.code', 'ONCHAIN_FEE_NOT_SUPPORTED');

        // client เก่าที่ไม่ส่ง hash มาก็ได้รหัสเดียวกัน ไม่ใช่ validation error ที่อ่านไม่ออก
        $this->postJson('/api/v1/trading-fee/tickets', [
            'wallet_address' => self::WALLET,
            'pair' => 'BTC/USDT', 'side' => 'buy',
            'order_value_usd' => 250, 'chain_id' => 56, 'method' => 'onchain',
        ])->assertStatus(422)->assertJsonPath('error.code', 'ONCHAIN_FEE_NOT_SUPPORTED');

        $this->assertSame(0, TradingOrderTicket::count());
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

    // ── เติมเครดิตจากธุรกรรมจริงบนเชน 4289 ──────────────────────────────────

    private const TREASURY = '0x9999999999999999999999999999999999999999';

    /** จำลอง RPC ของเชน 4289: ใบเสร็จ + ธุรกรรมโอน TPIX native */
    private function fakeChainTransfer(string $from, string $to, string $valueWei, string $status = '0x1'): void
    {
        SiteSetting::set('trading', 'tpix_topup_wallet', self::TREASURY);
        SiteSetting::clearCache();

        Http::fake(function ($request) use ($from, $to, $valueWei, $status) {
            $method = $request->data()['method'] ?? '';

            if ($method === 'eth_getTransactionReceipt') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => $status, 'blockNumber' => '0x10']]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                'from' => $from,
                'to' => $to,
                'value' => '0x'.$this->decToHex($valueWei),
            ]]);
        });
    }

    private function decToHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $hex = dechex((int) bcmod($dec, '16')).$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }

    /**
     * ⭐ ทางหลักต้องลงเครดิตได้จริง — เดิม import คลาส Wei ผิด namespace
     *    ทุกการเติมที่ผ่านด่านตรวจครบจึงตอบ 500 ผู้ใช้จ่ายแล้วไม่ได้เครดิต
     */
    #[Test]
    public function โอน_tpix_เข้ากระเป๋ารับเงินแล้วลงเครดิตตามยอดบนเชน(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, '25000000000000000000');
        $tx = '0x'.str_repeat('1', 64);

        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET, 'tx_hash' => $tx,
        ])->assertOk()
            ->assertJsonPath('data.credited', 25)
            ->assertJsonPath('data.balance', 25);

        // ยืนยันซ้ำ (client retry) ไม่ลงสองรอบ
        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET, 'tx_hash' => $tx,
        ])->assertOk()->assertJsonPath('data.balance', 25);
    }

    /** ⚠️ โอนต่ำกว่าขั้นต่ำ เงินออกไปแล้วจริง — ต้องลงเครดิตตามยอด ไม่ใช่เก็บไว้เฉยๆ */
    #[Test]
    public function โอนต่ำกว่าขั้นต่ำยังได้เครดิตตามยอดจริง(): void
    {
        SiteSetting::set('trading', 'tpix_min_topup', '10');
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, '2500000000000000000');

        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET, 'tx_hash' => '0x'.str_repeat('2', 64),
        ])->assertOk()->assertJsonPath('data.credited', 2.5);
    }

    #[Test]
    public function ธุรกรรมที่ไม่ได้โอน_tpix_มาเลยลงเครดิตไม่ได้(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, '0');

        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET, 'tx_hash' => '0x'.str_repeat('3', 64),
        ])->assertStatus(422)->assertJsonPath('error.code', 'BELOW_MINIMUM');
    }

    /** ⚠️ เอาธุรกรรมของคนอื่นมาขอเครดิตไม่ได้ */
    #[Test]
    public function ธุรกรรมที่โอนจากกระเป๋าอื่นลงเครดิตให้เราไม่ได้(): void
    {
        $this->fakeChainTransfer(self::OTHER, self::TREASURY, '25000000000000000000');

        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET, 'tx_hash' => '0x'.str_repeat('4', 64),
        ])->assertStatus(422)->assertJsonPath('error.code', 'WRONG_SENDER');
    }

    #[Test]
    public function ธุรกรรมที่_revert_ลงเครดิตไม่ได้(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, '25000000000000000000', '0x0');

        $this->postJson('/api/v1/trading-fee/topup/confirm', [
            'wallet_address' => self::WALLET, 'tx_hash' => '0x'.str_repeat('5', 64),
        ])->assertStatus(422)->assertJsonPath('error.code', 'TX_FAILED');
    }
}
