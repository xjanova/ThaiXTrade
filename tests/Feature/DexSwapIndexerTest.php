<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\Kline;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Services\DexSwapIndexer;
use App\Services\TpixDexService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesTpixDexChain;
use Tests\TestCase;

/**
 * รายการเทรดของคู่บนเชน TPIX ต้องมาจากเชน ไม่ใช่จากตัวเลขที่หน้าเว็บส่งมา
 *
 * ⚠️ เดิม POST /api/v1/swap/execute บนเชน 4289 บันทึก status=confirmed ตามคำขอ
 *    → ไม้ปลอม (tx hash มั่ว + จำนวน/ราคาอะไรก็ได้) ขึ้นรายการเทรดสาธารณะได้
 *    → สวอปจากแอปมือถือ/สัญญาตรงไม่เคยขึ้นรายการ
 */
class DexSwapIndexerTest extends TestCase
{
    use FakesTpixDexChain;

    private const TRADER = '0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'blockchain.tpix_dex_index_from_block' => 100,
            'blockchain.dex.wtpix' => '',
            'blockchain.dex.usdt' => '',
            'blockchain.dex.factory' => '',
            'blockchain.dex.router' => '',
        ]);

        // พูล WTPIX/USDT: 2,000,000 TPIX / 400,000 USDT (ราคา 0.2) — dex:sync สร้างคู่ TPIX-USDT ให้
        $this->registerDex();
        $this->fakeChain([
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();
    }

    /** ขาย 1 TPIX ได้ 0.1994 USDT (WTPIX เป็น token0) */
    private function fakeSell(string $hash, int $block, string $from = self::TRADER): void
    {
        $this->fakeSwap($hash, $from, $block, $this->pairTpixUsdt, [self::units('1'), '0', '0', '199400']);
    }

    private function postSwap(string $hash, string $wallet = self::TRADER, array $overrides = [])
    {
        return $this->withoutMiddleware(VerifyWalletOwnership::class)->postJson('/api/v1/swap/execute', array_merge([
            'from_token' => '0x0000000000000000000000000000000000000000',
            'to_token' => $this->usdt,
            'from_amount' => 1,
            'to_amount' => 0.1994,
            'fee_amount' => 0,
            'tx_hash' => $hash,
            'chain_id' => 4289,
            'wallet_address' => $wallet,
        ], $overrides));
    }

    public function test_reported_swap_is_recorded_from_the_chain_not_from_the_request(): void
    {
        $hash = '0x'.str_repeat('a1', 32);
        $this->fakeSell($hash, 150);

        // หน้าเว็บส่งจำนวนมั่วมา — ต้องไม่มีผล
        $this->postSwap($hash, self::TRADER, ['from_amount' => 9999, 'to_amount' => 123456])
            ->assertStatus(201)
            ->assertJsonPath('data.source', 'chain');

        $tx = Transaction::where('tx_hash', $hash)->sole();
        $this->assertSame(self::TRADER, $tx->wallet_address);
        $this->assertSame('0x0000000000000000000000000000000000000000', $tx->from_token);
        $this->assertSame(strtolower($this->usdt), $tx->to_token);
        $this->assertSame(0, bccomp($tx->from_amount, '1', 18));
        $this->assertSame(0, bccomp($tx->to_amount, '0.1994', 18));
        $this->assertSame('sell', $tx->metadata['side']);
        $this->assertSame('TPIX/USDT', $tx->metadata['pair']);
        $this->assertSame('chain', $tx->metadata['source']);
        $this->assertSame(150, $tx->metadata['block_number']);
        // เวลาไม้ = เวลาบล็อกบนเชน
        $this->assertSame(self::FAKE_GENESIS_TIME + 150 * 2, $tx->created_at->getTimestamp());
    }

    public function test_forged_hash_is_never_recorded(): void
    {
        $hash = '0x'.str_repeat('b2', 32);

        // ไม่มีบนเชน = ยังรอ (202) ไม่ใช่บันทึกเป็นไม้ที่ยืนยันแล้ว
        $this->postSwap($hash)->assertStatus(202)->assertJsonPath('data.status', 'pending');

        $this->assertSame(0, Transaction::where('tx_hash', $hash)->count());
    }

    public function test_someone_else_cannot_claim_a_swap(): void
    {
        $hash = '0x'.str_repeat('c3', 32);
        $this->fakeSell($hash, 160, '0x'.str_repeat('9', 40));

        $this->postSwap($hash, self::TRADER)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');

        $this->assertSame(0, Transaction::where('tx_hash', $hash)->count());
    }

    public function test_swap_event_from_a_contract_that_is_not_our_pool_is_ignored(): void
    {
        $hash = '0x'.str_repeat('d4', 32);
        // สัญญาปลอมยิง event หน้าตาเหมือน Swap — ไม่ใช่พูลที่ factory ของเราสร้าง
        $this->fakeSwap($hash, self::TRADER, 170, '0x'.str_repeat('7', 40), [self::units('1'), '0', '0', '199400']);

        $this->postSwap($hash)->assertStatus(422)->assertJsonPath('error.code', 'NOT_A_DEX_SWAP');
        $this->assertSame(0, Transaction::where('tx_hash', $hash)->count());
    }

    public function test_reverted_swap_is_rejected(): void
    {
        $hash = '0x'.str_repeat('e5', 32);
        $this->fakeSwap($hash, self::TRADER, 180, $this->pairTpixUsdt, [self::units('1'), '0', '0', '199400'], false);

        $this->postSwap($hash)->assertStatus(422)->assertJsonPath('error.code', 'TX_FAILED');
    }

    public function test_dex_sync_picks_up_swaps_made_outside_the_website_once(): void
    {
        // ไม้ซื้อจากแอปมือถือ (ไม่มีใครแจ้งเว็บ): จ่าย 2 USDT ได้ 9.9 TPIX — ห่างจากไม้ขายเกิน 1000 บล็อก
        $buy = '0x'.str_repeat('f6', 32);
        $this->fakeSwap($buy, self::TRADER, 2500, $this->pairTpixUsdt, ['0', '2000000', '9900000000000000000', '0']);
        $sell = '0x'.str_repeat('a7', 32);
        $this->fakeSell($sell, 150);

        $this->artisan('dex:sync')->assertSuccessful();
        $this->artisan('dex:sync')->assertSuccessful(); // รอบซ้ำต้องไม่บันทึกซ้ำ

        $this->assertSame(1, Transaction::where('tx_hash', $buy)->count());
        $this->assertSame(1, Transaction::where('tx_hash', $sell)->count());

        $bought = Transaction::where('tx_hash', $buy)->sole();
        $this->assertSame('buy', $bought->metadata['side']);
        $this->assertSame(0, bccomp($bought->from_amount, '2', 18));
        $this->assertSame(0, bccomp($bought->to_amount, '9.9', 18));

        // ปริมาณจริงลงแท่ง 1 นาที (เดิมเป็น 0 ตลอด) และไม่นับซ้ำเมื่อรันซ้ำ
        $pair = TradingPair::where('symbol', 'TPIX-USDT')->sole();
        $this->assertSame(0, bccomp((string) Kline::forPair($pair->id)->forInterval('1m')->sum('volume'), '10.9', 8));

        // รายการเทรดสาธารณะของคู่เห็นทั้งสองไม้
        $sides = collect($this->getJson('/api/v1/dex/trades/TPIX-USDT')->assertOk()->json('data'))->pluck('side')->sort()->values()->all();
        $this->assertSame(['buy', 'sell'], $sides);

        // แจ้งซ้ำจากหน้าเว็บหลังตัวอ่านบันทึกไปแล้ว = สำเร็จ ไม่ใช่ error
        $this->postSwap($sell)->assertOk()->assertJsonPath('data.tx_hash', $sell);
        $this->assertSame(1, Transaction::where('tx_hash', $sell)->count());
    }

    public function test_cursor_does_not_skip_blocks_when_the_chain_cannot_answer(): void
    {
        // เชนมีบล็อกใหม่ แต่ eth_getLogs ล้ม — ห้ามขยับเคอร์เซอร์ข้ามช่วงที่ยังไม่ได้อ่าน
        $dex = \Mockery::mock(TpixDexService::class)->makePartial();
        $dex->shouldReceive('blockNumber')->andReturn(5000);
        $dex->shouldReceive('logs')->once()->andReturn(null);
        $this->app->instance(TpixDexService::class, $dex);

        $this->assertSame(0, app(DexSwapIndexer::class)->syncAll());

        $pair = TradingPair::where('symbol', 'TPIX-USDT')->sole();
        // ถามฐานข้อมูลตรง — SiteSetting::get แคชค่า default ไว้ด้วย
        $this->assertFalse(SiteSetting::where('group', 'dex_indexer')->where('key', "swap_block_{$pair->id}")->exists());
    }

    public function test_cursor_is_saved_and_resumes_where_it_stopped(): void
    {
        $this->fakeSell('0x'.str_repeat('c9', 32), 150);

        app(DexSwapIndexer::class)->syncAll();

        $pair = TradingPair::where('symbol', 'TPIX-USDT')->sole();
        // ไล่ถึงบล็อกล่าสุดของเชนจำลอง (150 + 5) แล้ว รอบหน้าเริ่มบล็อกถัดไป
        $this->assertSame(156, (int) SiteSetting::where('group', 'dex_indexer')->where('key', "swap_block_{$pair->id}")->value('value'));
    }
}
