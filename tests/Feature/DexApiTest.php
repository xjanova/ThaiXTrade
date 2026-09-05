<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\Chain;
use App\Models\Kline;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\ContractRegistry;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesTpixDexChain;
use Tests\TestCase;

/**
 * ปลายทางสาธารณะของ TPIX DEX — สิ่งที่หน้าเทรด/แอปมือถือพึ่ง.
 *
 * หลักการเดียวกับหน้า Swap: ถ้า DEX ยังไม่พร้อม ต้องบอกว่า "ไม่พร้อม" ชัด ๆ
 * ไม่ใช่ส่ง zero address ให้เบราว์เซอร์ไปเดา แล้วผู้ใช้เสียเงินกับ tx ที่ revert
 */
class DexApiTest extends TestCase
{
    use FakesTpixDexChain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'blockchain.dex.wtpix' => '',
            'blockchain.dex.usdt' => '',
            'blockchain.dex.factory' => '',
            'blockchain.dex.router' => '',
        ]);
    }

    /** DEX พร้อม + มีพูล ABC/TPIX และ TPIX/USDT + ซิงก์คู่แล้ว */
    private function bootDex(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairAbcTpix => [$this->abc, $this->wtpix, self::units('1000'), self::units('500')],
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();
    }

    public function test_config_reports_not_ready_before_deploy(): void
    {
        $this->fakeChain();

        $this->getJson('/api/v1/dex/config')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.chainId', 4289)
            ->assertJsonCount(4, 'data.missing');
    }

    public function test_config_reports_addresses_once_registered(): void
    {
        $this->registerDex();
        $this->fakeChain();

        $this->getJson('/api/v1/dex/config')
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.WTPIX', $this->wtpix)
            ->assertJsonPath('data.ROUTER', $this->router)
            ->assertJsonPath('data.missing', []);
    }

    public function test_registry_accepts_dex_contracts_from_the_deploy_script(): void
    {
        config(['services.contract_registry.token' => 'registry-token-for-tests']);
        $this->fakeChain();

        $this->withHeaders(['Authorization' => 'Bearer registry-token-for-tests'])
            ->postJson('/api/infra/contracts', ['contracts' => [
                'wtpix' => $this->wtpix,
                'usdt_tpix' => $this->usdt,
                'dex_factory' => $this->factory,
                'dex_router' => $this->router,
            ]])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertTrue(app(ContractRegistry::class)->dexReady());
    }

    public function test_pairs_lists_only_onchain_pairs_of_the_tpix_chain(): void
    {
        $this->bootDex();

        $response = $this->getJson('/api/v1/dex/pairs')->assertOk();
        $symbols = collect($response->json('data'))->pluck('symbol')->all();

        $this->assertContains('ABC-TPIX', $symbols);
        $this->assertContains('TPIX-USDT', $symbols);
        // คู่ดัชนีเก่า (BTC-USDT บนเชน TPIX ที่ดูราคาอย่างเดียว) ต้องไม่โผล่
        $this->assertNotContains('BTC-USDT', $symbols);

        $abc = collect($response->json('data'))->firstWhere('symbol', 'ABC-TPIX');
        $this->assertSame(strtolower($this->abc), strtolower($abc['base_address']));
        $this->assertSame('0x0000000000000000000000000000000000000000', $abc['quote_address']);
        $this->assertSame($this->pairAbcTpix, $abc['pair_address']);
        $this->assertSame(4289, $abc['chain_id']);
    }

    public function test_ticker_uses_the_live_pool_price(): void
    {
        $this->bootDex();

        $this->getJson('/api/v1/dex/ticker/ABC-TPIX')
            ->assertOk()
            ->assertJsonPath('data.source', 'dex')
            ->assertJsonPath('data.has_liquidity', true)
            ->assertJsonPath('data.price', 0.5)
            ->assertJsonPath('data.reserve_base', '1000')
            ->assertJsonPath('data.reserve_quote', '500');

        // รูปแบบ symbol ที่หน้าเว็บส่งมาแบบมี "/" ก็ต้องเจอ
        $this->getJson('/api/v1/dex/ticker/ABC/TPIX')->assertStatus(404);
        $this->getJson('/api/v1/dex/ticker/abc-tpix')->assertOk();
    }

    public function test_unknown_pair_is_a_clean_404(): void
    {
        $this->bootDex();

        $this->getJson('/api/v1/dex/ticker/NOPE-TPIX')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PAIR_NOT_FOUND');
    }

    public function test_klines_aggregate_one_minute_candles_into_the_requested_interval(): void
    {
        $this->bootDex();
        $pair = \App\Models\TradingPair::where('symbol', 'ABC-TPIX')->firstOrFail();
        Kline::forPair($pair->id)->delete();

        $base = now()->startOfHour()->subHour();
        foreach ([[0, 1.0, 1.2, 0.9, 1.1], [1, 1.1, 1.5, 1.0, 1.4], [2, 1.4, 1.45, 1.3, 1.35], [6, 2.0, 2.1, 1.9, 2.05]] as [$offset, $o, $h, $l, $c]) {
            Kline::create([
                'trading_pair_id' => $pair->id,
                'interval' => '1m',
                'open_time' => $base->copy()->addMinutes($offset),
                'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c,
            ]);
        }

        $rows = $this->getJson('/api/v1/dex/klines/ABC-TPIX?interval=5m&limit=50')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        // แท่งแรกรวมนาที 0-2: open ของแท่งแรก, high สูงสุด, low ต่ำสุด, close ของแท่งสุดท้าย
        $this->assertSame($base->getTimestampMs(), $rows[0][0]);
        $this->assertSame('1.00000000', $rows[0][1]);
        $this->assertSame('1.50000000', $rows[0][2]);
        $this->assertSame('0.90000000', $rows[0][3]);
        $this->assertSame('1.35000000', $rows[0][4]);
        $this->assertSame('2.05000000', $rows[1][4]);
    }

    public function test_orderbook_is_a_synthetic_depth_ladder_from_reserves(): void
    {
        $this->bootDex();

        $data = $this->getJson('/api/v1/dex/orderbook/ABC-TPIX?limit=5')->assertOk()->json('data');

        $this->assertTrue($data['synthetic']);
        $this->assertCount(5, $data['bids']);
        $this->assertCount(5, $data['asks']);
        // ซื้อดันราคาขึ้น (ask > กลาง) ขายกดราคาลง (bid < กลาง) และไล่ระดับถูกทาง
        $this->assertGreaterThan(0.5, $data['asks'][0][0]);
        $this->assertLessThan(0.5, $data['bids'][0][0]);
        $this->assertGreaterThan($data['asks'][0][0], $data['asks'][4][0]);
        $this->assertLessThan($data['bids'][0][0], $data['bids'][4][0]);
    }

    public function test_trades_come_from_recorded_swaps_with_the_right_side(): void
    {
        $this->bootDex();
        $chain = Chain::where('chain_id', 4289)->firstOrFail();
        $wallet = '0x'.str_repeat('a', 40);

        Transaction::create([
            'type' => 'swap', 'wallet_address' => $wallet, 'chain_id' => $chain->id,
            'from_token' => '0x0000000000000000000000000000000000000000', 'to_token' => strtolower($this->abc),
            'from_amount' => 50, 'to_amount' => 100, 'fee_amount' => 0, 'status' => 'confirmed',
            'tx_hash' => '0x'.str_repeat('1', 64),
        ]);
        Transaction::create([
            'type' => 'swap', 'wallet_address' => $wallet, 'chain_id' => $chain->id,
            'from_token' => strtolower($this->abc), 'to_token' => '0x0000000000000000000000000000000000000000',
            'from_amount' => 10, 'to_amount' => 4.9, 'fee_amount' => 0, 'status' => 'confirmed',
            'tx_hash' => '0x'.str_repeat('2', 64),
        ]);

        $rows = $this->getJson('/api/v1/dex/trades/ABC-TPIX')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $bySide = collect($rows)->keyBy('side');
        $this->assertEqualsWithDelta(0.5, $bySide['buy']['price'], 1e-9);
        $this->assertEqualsWithDelta(100, $bySide['buy']['amount'], 1e-9);
        $this->assertEqualsWithDelta(0.49, $bySide['sell']['price'], 1e-9);
    }

    public function test_market_pairs_expose_real_chain_id_and_token_addresses(): void
    {
        $this->bootDex();

        $rows = $this->getJson('/api/v1/market/pairs')->assertOk()->json('data');
        $abc = collect($rows)->firstWhere('symbol', 'ABC-TPIX');

        $this->assertNotNull($abc);
        $this->assertSame(4289, $abc['network_chain_id']);
        $this->assertSame('onchain', $abc['execution_mode']);
        $this->assertSame(strtolower($this->abc), strtolower($abc['base_address']));
        $this->assertSame(18, $abc['base_decimals']);
        $this->assertSame($this->pairAbcTpix, $abc['dex_pair_address']);
    }

    public function test_chain_list_shows_tpix_live_once_the_dex_is_synced(): void
    {
        $this->bootDex();

        $chains = $this->getJson('/api/v1/chains')->assertOk()->json('data');
        $tpix = collect($chains)->firstWhere('chainId', 4289);

        $this->assertSame('live', $tpix['status']);
    }

    public function test_tpix_price_endpoint_prefers_the_pool_price(): void
    {
        SiteSetting::set('trading', 'tpix_price', '0.18');
        $this->bootDex();

        $this->getJson('/api/v1/tpix/price')
            ->assertOk()
            ->assertJsonPath('data.source', 'dex')
            ->assertJsonPath('data.price', 0.2);
    }

    public function test_swap_records_on_tpix_chain_accept_in_pool_fee(): void
    {
        $this->bootDex();
        SiteSetting::set('trading', 'fee_collector_wallet', '0x'.str_repeat('f', 40));

        // ด่านตรวจลายเซ็นกระเป๋ามีเทสต์ของตัวเองแล้ว — ที่นี่ทดสอบเฉพาะโมเดลค่าธรรมเนียม
        $this->withoutMiddleware(VerifyWalletOwnership::class)->postJson('/api/v1/swap/execute', [
            'from_token' => '0x0000000000000000000000000000000000000000',
            'to_token' => $this->abc,
            'from_amount' => 50,
            'to_amount' => 99.5,
            'fee_amount' => 0,
            'tx_hash' => '0x'.str_repeat('9', 64),
            'chain_id' => 4289,
            'wallet_address' => '0x'.str_repeat('a', 40),
        ])->assertStatus(201)->assertJsonPath('success', true);

        $tx = Transaction::where('tx_hash', '0x'.str_repeat('9', 64))->firstOrFail();
        $this->assertSame('in_pool', $tx->metadata['fee_model']);
    }
}
