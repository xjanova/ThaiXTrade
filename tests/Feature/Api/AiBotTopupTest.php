<?php

namespace Tests\Feature\Api;

use App\Models\AiBotCredit;
use App\Models\SiteSetting;
use App\Services\AiBotService;
use App\Services\Trading\TradingCreditService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เติมเครดิตบอท AI TRADE ด้วยการโอน TPIX จริงบนเชน 4289.
 *
 * ⚠️ ชุดนี้คุมทางที่พลาดแล้วเสียเงินจริง: ธุรกรรมใบเดียวได้เครดิตสองรอบ · เอาธุรกรรมของ
 *    คนอื่นมาขอเครดิต · โอนไม่ครบแต่ได้แพ็กเต็ม · เชนยังไม่ยืนยันแต่ลงเครดิตไปแล้ว
 *
 * RPC ถูกจำลองด้วย Http::fake แบบเดียวกับ TradingFeeApiTest — ไม่มีการยิงออกเน็ตจริง
 *
 * Developed by Xman Studio.
 */
class AiBotTopupTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xabababababababababababababababababababab';

    private const OTHER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    /** กระเป๋ารับค่าเครดิตบอท — คนละใบกับคลังค่าธรรมเนียมเทรด */
    private const TREASURY = '0x7777777777777777777777777777777777777777';

    private const TRADING_TREASURY = '0x9999999999999999999999999999999999999999';

    /** แพ็ก pack_1500 = 140 TPIX ได้ 1,500 + โบนัส 100 เครดิต */
    private const PACK = 'pack_1500';

    private const PACK_PRICE_WEI = '140000000000000000000';

    private const PACK_CREDITS = 1600;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        config([
            'aibot.credits.topup_enabled' => true,
            'aibot.credits.topup_wallet' => self::TREASURY,
            // เครดิตใช้เช่าแพลนเท่านั้น — เติมได้เมื่อเปิดขายแล้ว (ด่านนี้มีเทสต์แยกด้านล่าง)
            'aibot.sales_open' => true,
            'aibot.admin_wallets' => [],
        ]);

        foreach ([self::WALLET, self::OTHER] as $wallet) {
            Cache::put("wallet_verified:{$wallet}", [
                'ip' => '127.0.0.1',
                'verified_at' => now()->toIso8601String(),
            ], now()->addHours(4));
        }
    }

    /** จำลอง RPC ของเชน 4289: ใบเสร็จ + ธุรกรรมโอน TPIX native ($receipt = null คือยังไม่ถูกขุด) */
    private function fakeChainTransfer(string $from, string $to, string $valueWei, ?string $status = '0x1'): void
    {
        Http::fake(function ($request) use ($from, $to, $valueWei, $status) {
            $method = $request->data()['method'] ?? '';

            if ($method === 'eth_getTransactionReceipt') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $status === null
                    ? null
                    : ['status' => $status, 'blockNumber' => '0x10']]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
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

    private function confirm(string $txHash, string $wallet = self::WALLET, string $pack = self::PACK)
    {
        return $this->postJson('/api/v1/ai-bot/topup', [
            'wallet_address' => $wallet,
            'pack' => $pack,
            'tx_hash' => $txHash,
        ]);
    }

    private function balance(string $wallet = self::WALLET): float
    {
        return app(AiBotService::class)->balanceFor($wallet);
    }

    // ── ใบแจ้งชำระ ──────────────────────────────────────────────────────────

    /** ไม่ส่ง tx hash = ได้ใบแจ้งชำระ: ยอด · กระเป๋ารับเงิน · เชน มาจากเซิร์ฟเวอร์ และยังไม่ลงเครดิต */
    #[Test]
    public function ขอเติมโดยยังไม่จ่ายได้ใบแจ้งชำระและยังไม่ได้เครดิต(): void
    {
        Http::fake();

        $this->postJson('/api/v1/ai-bot/topup', ['wallet_address' => self::WALLET, 'pack' => self::PACK])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.pay_to', self::TREASURY)
            ->assertJsonPath('data.chain_id', 4289)
            ->assertJsonPath('data.amount_tpix', '140')
            ->assertJsonPath('data.amount_wei', self::PACK_PRICE_WEI)
            ->assertJsonPath('data.currency', 'TPIX');

        $this->assertSame(0.0, $this->balance());
        Http::assertNothingSent();
    }

    /** ⚠️ client ส่งกระเป๋ารับเงินมาเองก็ไม่มีผล — ปลายทางเงินมาจาก config เท่านั้น */
    #[Test]
    public function กระเป๋ารับเงินที่_client_ส่งมาถูกเมินทั้งตอนขอใบแจ้งและตอนยืนยัน(): void
    {
        $this->postJson('/api/v1/ai-bot/topup', [
            'wallet_address' => self::WALLET, 'pack' => self::PACK, 'pay_to' => self::OTHER,
        ])->assertOk()->assertJsonPath('data.pay_to', self::TREASURY);

        // โอนเข้ากระเป๋าที่ client อ้าง ไม่ใช่กระเป๋ารับเงินจริง
        $this->fakeChainTransfer(self::WALLET, self::OTHER, self::PACK_PRICE_WEI);

        $this->postJson('/api/v1/ai-bot/topup', [
            'wallet_address' => self::WALLET, 'pack' => self::PACK,
            'tx_hash' => '0x'.str_repeat('e', 64), 'pay_to' => self::OTHER,
        ])->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_WRONG_RECIPIENT');

        $this->assertSame(0.0, $this->balance());
    }

    // ── ยืนยันการโอน ────────────────────────────────────────────────────────

    /** ⭐ ทางหลัก: โอนครบ → ได้เครดิตของแพ็ก + โบนัส และยืนยันซ้ำไม่ได้เพิ่ม */
    #[Test]
    public function โอนครบราคาแพ็กแล้วได้เครดิตครั้งเดียวแม้ยืนยันซ้ำ(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);
        $tx = '0x'.str_repeat('1', 64);

        $this->confirm($tx)->assertOk()
            ->assertJsonPath('data.status', 'credited')
            ->assertJsonPath('data.credited', self::PACK_CREDITS)
            ->assertJsonPath('data.balance', self::PACK_CREDITS)
            ->assertJsonPath('data.pack', self::PACK);

        // ยิงซ้ำ (เน็ตหลุดหลังจ่าย · กดซ้ำ · hash ตัวพิมพ์ใหญ่) = คำตอบเดิม ไม่ลงรอบสอง
        $this->confirm($tx)->assertOk()->assertJsonPath('data.status', 'credited')->assertJsonPath('data.balance', self::PACK_CREDITS);
        $this->confirm('0x'.strtoupper(str_repeat('1', 64)))->assertOk()->assertJsonPath('data.balance', self::PACK_CREDITS);
        // เปลี่ยนรหัสแพ็กแต่ใช้ธุรกรรมเดิมก็ไม่ได้เพิ่ม
        $this->confirm($tx, self::WALLET, 'pack_500')->assertOk()->assertJsonPath('data.balance', self::PACK_CREDITS);

        $this->assertSame((float) self::PACK_CREDITS, $this->balance());
        $this->assertSame(1, AiBotCredit::where('type', 'topup')->count());

        $row = AiBotCredit::where('type', 'topup')->firstOrFail();
        $this->assertSame('topup:'.$tx, $row->reference);
        $this->assertSame(self::PACK, $row->meta['pack']);
    }

    /** ⚠️ กระเป๋าที่สองเอาธุรกรรมที่ลงเครดิตไปแล้วมาขอซ้ำไม่ได้ — แม้เชนจะตอบว่าเป็นผู้โอนก็ตาม */
    #[Test]
    public function ธุรกรรมที่ลงเครดิตไปแล้วกระเป๋าอื่นเอามาขอซ้ำไม่ได้(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);
        $tx = '0x'.str_repeat('2', 64);
        $this->confirm($tx)->assertOk();

        $this->confirm($tx, self::OTHER)->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_TX_ALREADY_USED');

        $this->assertSame(0.0, $this->balance(self::OTHER));
        $this->assertSame((float) self::PACK_CREDITS, $this->balance());
    }

    /** ⚠️ ธุรกรรมที่เคยใช้เติมคลังค่าธรรมเนียมเทรดแล้ว เอามาเติมเครดิตบอทซ้ำไม่ได้ */
    #[Test]
    public function ธุรกรรมที่ใช้เติมคลังเทรดแล้วเอามาเติมเครดิตบอทไม่ได้(): void
    {
        $tx = '0x'.str_repeat('3', 64);
        app(TradingCreditService::class)->topup(self::WALLET, 140, $tx);
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);

        $this->confirm($tx)->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_TX_ALREADY_USED');

        $this->assertSame(0.0, $this->balance());
        Http::assertNothingSent();
    }

    #[Test]
    public function โอนเข้ากระเป๋าอื่นไม่ได้เครดิต(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TRADING_TREASURY, self::PACK_PRICE_WEI);

        $this->confirm('0x'.str_repeat('4', 64))->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_WRONG_RECIPIENT');

        $this->assertSame(0.0, $this->balance());
    }

    /** โอนขาดหรือเกินแม้ 1 wei ก็ไม่ใช่ราคาแพ็กนี้ — ไม่ลงเครดิต และธุรกรรมยังไม่ถูกใช้ */
    #[Test]
    public function โอนไม่ตรงราคาแพ็กไม่ได้เครดิตและธุรกรรมยังใช้กับแพ็กที่ราคาตรงได้(): void
    {
        $tx = '0x'.str_repeat('5', 64);

        foreach (['139999999999999999999', '140000000000000000001', '0'] as $wei) {
            $this->fakeChainTransfer(self::WALLET, self::TREASURY, $wei);
            $this->confirm($tx)->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_WRONG_AMOUNT');
            Http::swap(new Factory());
        }

        $this->assertSame(0.0, $this->balance());

        // โอนมา 50 TPIX แต่เลือกแพ็ก 140 → ปฏิเสธ · เลือกแพ็ก 50 (pack_500) ด้วยธุรกรรมเดิม → ได้
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, '50000000000000000000');
        $this->confirm($tx)->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_WRONG_AMOUNT');
        $this->confirm($tx, self::WALLET, 'pack_500')->assertOk()->assertJsonPath('data.credited', 500);

        $this->assertSame(500.0, $this->balance());
    }

    /** ⚠️ เอาธุรกรรมของคนอื่นมาขอเครดิตไม่ได้ */
    #[Test]
    public function ธุรกรรมที่โอนจากกระเป๋าอื่นลงเครดิตให้เราไม่ได้(): void
    {
        $this->fakeChainTransfer(self::OTHER, self::TREASURY, self::PACK_PRICE_WEI);

        $this->confirm('0x'.str_repeat('6', 64))->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_WRONG_SENDER');

        $this->assertSame(0.0, $this->balance());
    }

    #[Test]
    public function ธุรกรรมที่_revert_ไม่ได้เครดิต(): void
    {
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI, '0x0');

        $this->confirm('0x'.str_repeat('7', 64))->assertStatus(422)->assertJsonPath('error.code', 'TOPUP_TX_FAILED');

        $this->assertSame(0.0, $this->balance());
    }

    /** เชนยังไม่ยืนยัน = ตอบให้รอ (ลองใหม่ได้) ไม่ลงเครดิต — พอเชนยืนยันแล้วถามซ้ำจึงได้ */
    #[Test]
    public function ใบเสร็จยังไม่มาตอบให้รอและไม่ลงเครดิตจนกว่าเชนจะยืนยัน(): void
    {
        $tx = '0x'.str_repeat('8', 64);
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI, null);

        $this->confirm($tx)->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.tx_hash', $tx);

        $this->assertSame(0.0, $this->balance());
        $this->assertSame(0, AiBotCredit::count());

        Http::swap(new Factory());
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);

        $this->confirm($tx)->assertOk()->assertJsonPath('data.status', 'credited');
        $this->assertSame((float) self::PACK_CREDITS, $this->balance());
    }

    /** RPC ล่ม / ตอบ error / หมดเวลา = ยังไม่รู้ผล → รอ ไม่ใช่ลงเครดิตและไม่ใช่ปฏิเสธถาวร */
    #[Test]
    public function rpc_ล่มหรือตอบผิดรูปตอบให้รอและไม่ลงเครดิต(): void
    {
        $tx = '0x'.str_repeat('9', 64);

        Http::fake(fn () => Http::response('upstream down', 502));
        $this->confirm($tx)->assertOk()->assertJsonPath('data.status', 'pending');

        Http::swap(new Factory());
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'boom']]));
        $this->confirm($tx)->assertOk()->assertJsonPath('data.status', 'pending');

        Http::swap(new Factory());
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $response = $this->confirm($tx)->assertOk()->assertJsonPath('data.status', 'pending');

        // ข้อความดิบของ RPC ต้องไม่ไปถึงผู้ใช้
        $this->assertStringNotContainsString('timed out', $response->getContent());
        $this->assertSame(0.0, $this->balance());
    }

    // ── ด่านเปิด/ปิด ────────────────────────────────────────────────────────

    /** ธงปิด = ปฏิเสธทั้งใบแจ้งชำระและการยืนยัน โดยไม่ถามเชนเลย */
    #[Test]
    public function ปิดการเติมอยู่ยืนยันธุรกรรมที่ถูกต้องก็ไม่ได้เครดิต(): void
    {
        config(['aibot.credits.topup_enabled' => false]);
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);

        $this->confirm('0x'.str_repeat('a', 64))->assertStatus(422)
            ->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);

        $this->postJson('/api/v1/ai-bot/topup', ['wallet_address' => self::WALLET, 'pack' => self::PACK])
            ->assertStatus(422)->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);

        $this->assertSame(0.0, $this->balance());
        Http::assertNothingSent();
        $this->assertFalse($this->getJson('/api/v1/ai-bot/catalog')->json('data.features.credit_topup'));
    }

    /** เปิดธงแต่ยังไม่ตั้งกระเป๋ารับเงิน (หรือตั้งผิดรูป) = ยังปิด — ไม่มีที่ให้โอน */
    #[Test]
    public function เปิดธงแต่ไม่มีกระเป๋ารับเงินถือว่ายังปิด(): void
    {
        foreach (['', 'not-a-wallet', '0x1234'] as $wallet) {
            config(['aibot.credits.topup_wallet' => $wallet]);

            $this->postJson('/api/v1/ai-bot/topup', ['wallet_address' => self::WALLET, 'pack' => self::PACK])
                ->assertStatus(422)->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);
            $this->assertFalse($this->getJson('/api/v1/ai-bot/catalog')->json('data.features.credit_topup'));
        }

        config(['aibot.credits.topup_wallet' => strtoupper(self::TREASURY)]);
        $this->assertTrue($this->getJson('/api/v1/ai-bot/catalog')->json('data.features.credit_topup'));
    }

    /**
     * ⚠️ กระเป๋ารับเงินซ้ำกับคลังค่าธรรมเนียมเทรด = ปิด.
     *
     * ใช้ใบเดียวกัน ธุรกรรมใบเดียวจะขอเครดิตได้ทั้งสองบัญชี (ฝั่งคลังเทรดไม่รู้จักบัญชีเครดิตบอท)
     */
    #[Test]
    public function กระเป๋ารับเงินซ้ำกับคลังเทรดถือว่ายังปิด(): void
    {
        SiteSetting::set('trading', 'tpix_topup_wallet', self::TREASURY);
        SiteSetting::clearCache();
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);

        $this->confirm('0x'.str_repeat('b', 64))->assertStatus(422)
            ->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);

        $this->assertSame(0.0, $this->balance());
    }

    /**
     * ⚠️ ยังไม่เปิดขาย = ผู้ใช้ทั่วไปเช่าแพลนไม่ได้ เครดิตที่เติมจึงใช้ไม่ได้ — ต้องไม่รับเงิน.
     *
     * ทีมงานผ่านได้ เพื่อทดสอบรางรับเงินบน production ก่อนเปิดให้คนทั่วไป
     */
    #[Test]
    public function ยังไม่เปิดขายผู้ใช้ทั่วไปเติมไม่ได้แต่ทีมงานเติมได้(): void
    {
        config(['aibot.sales_open' => false, 'aibot.admin_wallets' => [strtoupper(self::OTHER)]]);
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);

        $this->postJson('/api/v1/ai-bot/topup', ['wallet_address' => self::WALLET, 'pack' => self::PACK])
            ->assertStatus(422)->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);
        $this->confirm('0x'.str_repeat('f', 64))->assertStatus(422)
            ->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);
        $this->assertSame(0.0, $this->balance());

        $features = $this->getJson('/api/v1/ai-bot/catalog')->json('data.features');
        $this->assertFalse($features['credit_topup']);
        $this->assertTrue($features['credit_topup_ready']);

        // กระเป๋าทีมงาน (ตั้งใน .env แบบ checksum case ก็ต้องตรง) เติมได้
        Http::swap(new Factory());
        $this->fakeChainTransfer(self::OTHER, self::TREASURY, self::PACK_PRICE_WEI);

        $this->postJson('/api/v1/ai-bot/topup', ['wallet_address' => self::OTHER, 'pack' => self::PACK])
            ->assertOk()->assertJsonPath('data.pay_to', self::TREASURY);
        $this->confirm('0x'.str_repeat('f', 64), self::OTHER)->assertOk()->assertJsonPath('data.status', 'credited');
        $this->assertSame((float) self::PACK_CREDITS, $this->balance(self::OTHER));
    }

    // ── ข้อมูลเข้าที่ผิด ────────────────────────────────────────────────────

    #[Test]
    public function tx_hash_ผิดรูปหรือแพ็กที่ไม่มีถูกปฏิเสธก่อนถามเชน(): void
    {
        Http::fake();

        $this->confirm('0x1234')->assertStatus(422)->assertJsonValidationErrors('tx_hash');
        $this->confirm('0x'.str_repeat('z', 64))->assertStatus(422)->assertJsonValidationErrors('tx_hash');
        $this->confirm('0x'.str_repeat('c', 64), self::WALLET, 'no_such_pack')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PACK');

        Http::assertNothingSent();
        $this->assertSame(0.0, $this->balance());
    }

    /** กระเป๋าที่ยังไม่ได้เซ็นยืนยันความเป็นเจ้าของ ยืนยันการเติมแทนคนอื่นไม่ได้ */
    #[Test]
    public function กระเป๋าที่ไม่ได้ยืนยันความเป็นเจ้าของเติมไม่ได้(): void
    {
        Cache::forget('wallet_verified:'.self::WALLET);
        $this->fakeChainTransfer(self::WALLET, self::TREASURY, self::PACK_PRICE_WEI);

        $this->confirm('0x'.str_repeat('d', 64))->assertStatus(403);

        $this->assertSame(0.0, $this->balance());
    }
}
