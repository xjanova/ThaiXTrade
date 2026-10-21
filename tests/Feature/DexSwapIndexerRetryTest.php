<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Services\ContractRegistry;
use App\Services\DexSwapIndexer;
use App\Services\TpixDexService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesTpixDexChain;
use Tests\TestCase;

/**
 * ตัวอ่าน event Swap ต้องไม่ทิ้งไม้ถาวรเมื่อเชนสะดุดระหว่างอ่านข้อมูลประกอบ
 *
 * ⚠️ เดิมอ่านผู้ส่ง/เวลาบล็อก/reserve ไม่ได้ = ข้าม log นั้นแล้วขยับเคอร์เซอร์ผ่านไป
 *    ไม้จากแอปมือถือ/สัญญาตรงหายจากประวัติและปริมาณซื้อขายถาวร
 *    และการแจ้งจากหน้าเว็บช่วงนั้นได้ 422 NOT_A_DEX_SWAP ทั้งที่เป็นสวอปจริง
 */
class DexSwapIndexerRetryTest extends TestCase
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

        $this->registerDex();
        $this->fakeChain([
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();
    }

    /** ขาย 1 TPIX ได้ 0.1994 USDT */
    private function fakeSell(string $hash, int $block): void
    {
        $this->fakeSwap($hash, self::TRADER, $block, $this->pairTpixUsdt, [self::units('1'), '0', '0', '199400']);
    }

    /** เชนตอบเวลาบล็อกไม่ได้รอบนี้ (ส่วนอื่นตอบปกติ) */
    private function blockTimesDown(): void
    {
        $dex = \Mockery::mock(TpixDexService::class, [app(ContractRegistry::class)])->makePartial();
        $dex->shouldReceive('blockTimestamp')->andReturn(null);
        $this->app->instance(TpixDexService::class, $dex);
    }

    private function chainBack(): void
    {
        $this->app->forgetInstance(TpixDexService::class);
        $this->app->forgetInstance(DexSwapIndexer::class);
    }

    private function cursor(): ?int
    {
        $pair = TradingPair::where('symbol', 'TPIX-USDT')->sole();
        $value = SiteSetting::where('group', 'dex_indexer')->where('key', "swap_block_{$pair->id}")->value('value');

        return $value === null ? null : (int) $value;
    }

    public function test_a_swap_is_retried_not_skipped_when_the_chain_hiccups(): void
    {
        $hash = '0x'.str_repeat('a9', 32);
        $this->fakeSell($hash, 150);

        $this->blockTimesDown();
        $this->assertSame(0, app(DexSwapIndexer::class)->syncAll());
        $this->assertSame(0, Transaction::where('tx_hash', $hash)->count());
        // เคอร์เซอร์ต้องไม่ขยับผ่านบล็อกของไม้ที่ยังอ่านไม่ครบ
        $this->assertLessThanOrEqual(150, $this->cursor() ?? 0);

        $this->chainBack();
        $this->assertSame(1, app(DexSwapIndexer::class)->syncAll());
        $this->assertSame(1, Transaction::where('tx_hash', $hash)->count());
        $this->assertGreaterThan(150, $this->cursor());
    }

    public function test_reporting_a_swap_while_the_chain_hiccups_is_pending_not_rejected(): void
    {
        $hash = '0x'.str_repeat('b8', 32);
        $this->fakeSell($hash, 150);

        $this->blockTimesDown();
        $this->withoutMiddleware(VerifyWalletOwnership::class)->postJson('/api/v1/swap/execute', [
            'from_token' => '0x0000000000000000000000000000000000000000',
            'to_token' => $this->usdt,
            'from_amount' => 1,
            'to_amount' => 0.1994,
            'fee_amount' => 0,
            'tx_hash' => $hash,
            'chain_id' => 4289,
            'wallet_address' => self::TRADER,
        ])->assertStatus(202)->assertJsonPath('data.status', 'pending');

        $this->assertSame(0, Transaction::where('tx_hash', $hash)->count());

        // รอบนาทีของ dex:sync เก็บให้เองเมื่อเชนตอบได้ — ครั้งเดียว
        $this->chainBack();
        $this->artisan('dex:sync')->assertSuccessful();
        $this->artisan('dex:sync')->assertSuccessful();
        $this->assertSame(1, Transaction::where('tx_hash', $hash)->count());
    }

    public function test_a_log_that_never_becomes_readable_stops_blocking_the_pool_eventually(): void
    {
        $hash = '0x'.str_repeat('c7', 32);
        $later = '0x'.str_repeat('d6', 32);
        $this->fakeSell($hash, 150);

        $this->blockTimesDown();
        for ($i = 0; $i < 31; $i++) {
            app(DexSwapIndexer::class)->syncAll();
        }

        // หลังเพดานรอบ ไม้ที่อ่านไม่ได้ถูกข้าม (มี log เตือน) เคอร์เซอร์เดินต่อได้
        $this->assertGreaterThan(150, $this->cursor());

        $this->chainBack();
        $this->fakeSell($later, 400);
        app(DexSwapIndexer::class)->syncAll();
        $this->assertSame(1, Transaction::where('tx_hash', $later)->count());
    }

    public function test_decoding_needs_only_the_cached_token_order_not_live_reserves(): void
    {
        $hash = '0x'.str_repeat('e4', 32);
        $this->fakeSell($hash, 150);

        // getReserves อ่านไม่ได้ — การถอด event ไม่ใช้ reserve จึงต้องบันทึกได้ตามปกติ
        $dex = \Mockery::mock(TpixDexService::class, [app(ContractRegistry::class)])->makePartial();
        $dex->shouldReceive('pairInfo')->andReturn(null);
        $this->app->instance(TpixDexService::class, $dex);

        $this->assertSame(1, app(DexSwapIndexer::class)->syncAll());
        $this->assertSame('sell', Transaction::where('tx_hash', $hash)->sole()->metadata['side']);
    }
}
