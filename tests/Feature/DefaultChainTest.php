<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\TradingPair;
use App\Services\ChainResolver;
use App\Support\DefaultMarket;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesTpixDexChain;
use Tests\TestCase;

/**
 * เชนปริยาย + คู่ปริยาย ต้องเดินตามความพร้อมจริงของเชน TPIX.
 *
 * เจ้าของสั่ง (2026-09-05): "เมื่อเปิดใช้เชนได้แล้ว ให้ดีฟอลต์ไว้เชน TPIX แทน BSC ทุกคน"
 *
 * ที่กันไว้:
 *   - ยังไม่พร้อม = ห้ามพาผู้ใช้ไปเชนที่เทรดไม่ได้ (ตกกลับ BSC)
 *   - พร้อมแล้ว = ต้องเปลี่ยนเองทั้งเว็บและแอป โดยไม่ต้องแก้ .env (ลืมง่ายที่สุด)
 *   - คู่ปริยายของหน้า /trade ต้องเปลี่ยนตามด้วย ไม่ค้างที่ BTC-USDT
 *   - คู่ TPIX-USDT ที่พูลว่าง (is_active = false) ไม่ใช่คู่ปริยาย — กดแล้วเทรดไม่ได้
 */
class DefaultChainTest extends TestCase
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

    private function resolver(): ChainResolver
    {
        return app(ChainResolver::class);
    }

    public function test_default_chain_is_bsc_while_the_tpix_chain_is_not_live(): void
    {
        $this->assertSame(Chain::STATUS_COMING_SOON, Chain::where('chain_id', 4289)->value('status'));

        $this->assertSame(56, $this->resolver()->defaultChainId());
        $this->assertSame(DefaultMarket::FALLBACK, DefaultMarket::pair());
    }

    public function test_default_chain_becomes_tpix_once_the_dex_sync_opens_the_chain(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);

        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(4289, $this->resolver()->defaultChainId());
        $this->assertSame(DefaultMarket::TPIX, DefaultMarket::pair());
    }

    public function test_chains_api_tells_clients_which_chain_is_default(): void
    {
        $this->fakeChain();

        $this->getJson('/api/v1/chains')
            ->assertOk()
            ->assertJsonPath('meta.default_chain_id', 56);

        $this->registerDex();
        $this->fakeChain([
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();

        $this->getJson('/api/v1/chains')
            ->assertOk()
            ->assertJsonPath('meta.default_chain_id', 4289);
    }

    public function test_trade_page_opens_on_the_tpix_board_once_it_is_tradable(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();

        $this->get('/trade')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Trade')->where('pair', 'TPIX-USDT'));
    }

    public function test_an_empty_pool_never_becomes_the_default_pair(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, '0', '0'],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();

        // เชนพร้อม (สัญญาครบ) แต่คู่หลักยังไม่มีสภาพคล่อง → อย่าพาคนไปกระดานที่กดแล้วเด้ง
        $this->assertSame(4289, $this->resolver()->defaultChainId());
        $this->assertFalse(TradingPair::where('symbol', 'TPIX-USDT')->value('is_active'));
        $this->assertSame(DefaultMarket::FALLBACK, DefaultMarket::pair());
    }

    public function test_status_command_reports_the_whole_chain_in_one_place(): void
    {
        $this->fakeChain();

        $this->artisan('tpix:status --json')->assertSuccessful();
    }
}
