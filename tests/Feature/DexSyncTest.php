<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\Kline;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingPair;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesTpixDexChain;
use Tests\TestCase;

/**
 * dex:sync — ทุกเหรียญบนเชน TPIX ต้องเทรดได้เองโดยไม่มีใครมาเพิ่มคู่.
 *
 * สิ่งที่กัน:
 *   - เชน TPIX เปิดเป็น live เฉพาะเมื่อสัญญา DEX ครบและมีโค้ดอยู่จริง (ไม่ใช่แค่ตั้งที่อยู่ไว้)
 *   - พูลใหม่บน factory กลายเป็นคู่เทรดพร้อมโทเคนที่อ่าน symbol/decimals จากเชน
 *   - พูลว่างเปิดเทรดไม่ได้ (สวอปไม่ได้อยู่แล้ว อย่าให้ผู้ใช้ไปเจอ revert)
 *   - ราคา TPIX อ้างอิงของระบบตามพูลจริง ไม่ใช่ตัวเลขที่แอดมินพิมพ์ไว้
 */
class DexSyncTest extends TestCase
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

    private function tpixChain(): Chain
    {
        return Chain::where('chain_id', 4289)->firstOrFail();
    }

    public function test_chain_stays_coming_soon_until_the_dex_is_registered(): void
    {
        $this->fakeChain();

        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(Chain::STATUS_COMING_SOON, $this->tpixChain()->fresh()->status);
        $this->assertSame(0, TradingPair::where('execution_mode', 'onchain')->where('chain_id', $this->tpixChain()->id)->count());
    }

    public function test_registered_addresses_without_code_do_not_open_the_chain(): void
    {
        // เชนเคย regenesis แล้วสัญญาหายทั้งที่ที่อยู่ยังค้างในทะเบียน — ห้ามเปิดเทรด
        $this->registerDex();
        $this->fakeChain(hasCode: false);

        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(Chain::STATUS_COMING_SOON, $this->tpixChain()->fresh()->status);
    }

    public function test_every_pool_on_the_factory_becomes_a_tradable_pair(): void
    {
        $this->registerDex();
        $this->fakeChain([
            // 1,000 ABC ↔ 500 TPIX → 0.5 TPIX ต่อ ABC
            $this->pairAbcTpix => [$this->abc, $this->wtpix, self::units('1000'), self::units('500')],
            // 2,000,000 TPIX ↔ 400,000 USDT → 0.20 USDT ต่อ TPIX
            $this->pairTpixUsdt => [$this->wtpix, $this->usdt, self::units('2000000'), self::units('400000', 6)],
        ]);

        $this->artisan('dex:sync')->assertSuccessful();

        $chain = $this->tpixChain()->fresh();
        $this->assertSame(Chain::STATUS_LIVE, $chain->status);

        // โทเคน ABC ถูกอ่านจากเชน
        $abc = Token::where('chain_id', $chain->id)->where('symbol', 'ABC')->first();
        $this->assertNotNull($abc);
        $this->assertSame(strtolower($this->abc), strtolower($abc->contract_address));
        $this->assertSame(18, (int) $abc->decimals);
        $this->assertSame('Abc Coin', $abc->name);

        // คู่ ABC-TPIX เปิดเทรดได้ พร้อมที่อยู่พูล
        $pair = TradingPair::where('chain_id', $chain->id)->where('symbol', 'ABC-TPIX')->first();
        $this->assertNotNull($pair);
        $this->assertTrue((bool) $pair->is_active);
        $this->assertSame('onchain', $pair->execution_mode);
        $this->assertSame($this->pairAbcTpix, $pair->dex_pair_address);
        $this->assertSame('TPIX', $pair->quoteToken->symbol);
        $this->assertSame('0x0000000000000000000000000000000000000000', $pair->quoteToken->contract_address);

        // คู่หลัก TPIX-USDT ใช้แถวเดิม (seeder) และ USDT ชี้ไปที่ USDT_TPIX ตัวจริงแล้ว
        $tpixUsdt = TradingPair::where('chain_id', $chain->id)->where('symbol', 'TPIX-USDT')->first();
        $this->assertNotNull($tpixUsdt);
        $this->assertSame('onchain', $tpixUsdt->execution_mode);
        $this->assertSame(strtolower($this->usdt), strtolower($tpixUsdt->quoteToken->contract_address));
        $this->assertSame(6, (int) $tpixUsdt->quoteToken->decimals);

        // แท่ง 1 นาทีถูกบันทึกด้วยราคากลางของพูล
        $candle = Kline::forPair($pair->id)->forInterval('1m')->first();
        $this->assertNotNull($candle);
        $this->assertEqualsWithDelta(0.5, (float) $candle->close, 1e-9);

        // ราคา TPIX อ้างอิงตามพูล
        $this->assertEqualsWithDelta(0.2, (float) SiteSetting::get('trading', 'tpix_price'), 1e-9);
    }

    public function test_an_empty_pool_is_listed_but_not_tradable(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairAbcTpix => [$this->abc, $this->wtpix, '0', '0'],
        ]);

        $this->artisan('dex:sync')->assertSuccessful();

        $pair = TradingPair::where('symbol', 'ABC-TPIX')->first();
        $this->assertNotNull($pair);
        $this->assertFalse((bool) $pair->is_active);
        $this->assertSame(0, Kline::forPair($pair->id)->count());
    }

    public function test_running_twice_does_not_duplicate_anything(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairAbcTpix => [$this->abc, $this->wtpix, self::units('1000'), self::units('500')],
        ]);

        $this->artisan('dex:sync')->assertSuccessful();
        Cache::flush();
        $this->artisan('dex:sync')->assertSuccessful();

        $chainId = $this->tpixChain()->id;
        $this->assertSame(1, Token::where('chain_id', $chainId)->where('symbol', 'ABC')->count());
        $this->assertSame(1, TradingPair::where('chain_id', $chainId)->where('symbol', 'ABC-TPIX')->count());
        $pair = TradingPair::where('symbol', 'ABC-TPIX')->first();
        $this->assertSame(1, Kline::forPair($pair->id)->forInterval('1m')->count());
    }

    public function test_chain_falls_back_to_coming_soon_when_contracts_disappear(): void
    {
        $this->registerDex();
        $this->fakeChain([
            $this->pairAbcTpix => [$this->abc, $this->wtpix, self::units('1000'), self::units('500')],
        ]);
        $this->artisan('dex:sync')->assertSuccessful();
        $this->assertSame(Chain::STATUS_LIVE, $this->tpixChain()->fresh()->status);

        Cache::flush();
        $this->fakeChain(hasCode: false);
        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(Chain::STATUS_COMING_SOON, $this->tpixChain()->fresh()->status);
    }

    public function test_maintenance_set_by_admin_is_never_overridden(): void
    {
        $chain = $this->tpixChain();
        $chain->status = Chain::STATUS_MAINTENANCE;
        $chain->save();

        $this->registerDex();
        $this->fakeChain([
            $this->pairAbcTpix => [$this->abc, $this->wtpix, self::units('1000'), self::units('500')],
        ]);

        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(Chain::STATUS_MAINTENANCE, $chain->fresh()->status);
    }
}
