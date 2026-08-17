<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use Database\Seeders\AllChainsSeeder;
use Database\Seeders\BscMajorPairsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE — ทะเบียนเหรียญ BSC ฝั่ง PHP กับฝั่ง JS ต้องตรงกันเสมอ.
 *
 * เหตุผล: ฝั่ง JS (resources/js/Config/bscTradeTokens.js) เป็นตัวส่งธุรกรรมจริง
 * เข้า PancakeSwap ส่วน config/bsc_trade_tokens.php เป็นตัว seed ลงหลังบ้าน
 * ถ้าสองไฟล์นี้หลุดกัน หลังบ้านจะโชว์ address หนึ่ง แต่ผู้ใช้เทรดอีก address หนึ่ง
 * — เป็นบั๊กที่เงียบและอันตรายที่สุดของระบบนี้ จึงล็อกไว้ด้วยเทสต์
 *
 * Developed by Xman Studio.
 */
class BscTokenRegistrySyncTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> symbol => address (ตัวพิมพ์เล็ก) */
    private function jsRegistry(): array
    {
        $path = resource_path('js/Config/bscTradeTokens.js');
        $this->assertFileExists($path, 'ไม่พบทะเบียนเหรียญฝั่ง JS');

        $source = file_get_contents($path);

        // ตัดเอาเฉพาะบล็อก BSC_TRADE_TOKENS — กันไปจับ address ที่อยู่ในคอมเมนต์
        $start = strpos($source, 'export const BSC_TRADE_TOKENS');
        $this->assertNotFalse($start, 'ไม่พบ BSC_TRADE_TOKENS ในไฟล์ JS');
        $block = substr($source, $start, strpos($source, '};', $start) - $start);

        preg_match_all(
            "/^\s*(\w+):\s*\{[^}]*address:\s*(?:'(0x[a-fA-F0-9]{40})'|(\w+))/m",
            $block,
            $matches,
            PREG_SET_ORDER
        );

        $registry = [];
        foreach ($matches as $m) {
            // BNB ใช้ค่าคงที่ NATIVE_TOKEN_ADDRESS แทน literal
            $address = $m[2] !== '' ? $m[2] : '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE';
            $registry[$m[1]] = strtolower($address);
        }

        return $registry;
    }

    /** @return array<string, string> */
    private function phpRegistry(): array
    {
        return collect(config('bsc_trade_tokens.tokens'))
            ->map(fn (array $t) => strtolower($t['address']))
            ->all();
    }

    public function test_both_registries_list_the_same_symbols(): void
    {
        $js = $this->jsRegistry();
        $php = $this->phpRegistry();

        $this->assertNotEmpty($js, 'อ่านทะเบียนฝั่ง JS ไม่ได้ — regex อาจต้องอัปเดตตามรูปแบบไฟล์');

        $onlyInJs = array_diff(array_keys($js), array_keys($php));
        $onlyInPhp = array_diff(array_keys($php), array_keys($js));

        $this->assertSame([], array_values($onlyInJs), 'มีเฉพาะใน JS: เพิ่มลง config/bsc_trade_tokens.php ด้วย');
        $this->assertSame([], array_values($onlyInPhp), 'มีเฉพาะใน PHP: เพิ่มลง bscTradeTokens.js ด้วย');
    }

    public function test_every_contract_address_matches(): void
    {
        $js = $this->jsRegistry();
        $php = $this->phpRegistry();

        foreach ($php as $symbol => $address) {
            $this->assertSame(
                $js[$symbol] ?? null,
                $address,
                "address ของ {$symbol} ไม่ตรงกันระหว่าง JS กับ PHP — หลังบ้านจะโชว์คนละเหรียญกับที่ผู้ใช้เทรดจริง"
            );
        }
    }

    public function test_seeder_puts_the_major_pairs_on_bsc_not_tpix(): void
    {
        $this->seed(AllChainsSeeder::class);
        $this->seed(BscMajorPairsSeeder::class);

        $bsc = Chain::where('chain_id_hex', '0x38')->firstOrFail();

        $active = TradingPair::active()->with('chain')->get();
        $this->assertGreaterThan(0, $active->count());

        // คู่เทรดที่เปิดใช้งานทุกคู่ต้องอยู่บน BSC (คู่ TPIX ยังไม่มีในเทสต์นี้)
        $this->assertTrue(
            $active->every(fn (TradingPair $p) => $p->chain_id === $bsc->id),
            'ยังมีคู่เทรดที่เปิดอยู่นอกเชน BSC'
        );

        $this->assertDatabaseHas('trading_pairs', ['symbol' => 'BTC-USDT', 'chain_id' => $bsc->id, 'is_active' => true]);
    }

    public function test_seeded_tokens_use_the_real_bsc_addresses(): void
    {
        $this->seed(AllChainsSeeder::class);
        $this->seed(BscMajorPairsSeeder::class);

        $bsc = Chain::where('chain_id_hex', '0x38')->firstOrFail();

        foreach ($this->phpRegistry() as $symbol => $address) {
            $this->assertDatabaseHas('tokens', [
                'chain_id' => $bsc->id,
                'symbol' => $symbol,
            ]);

            $token = Token::where('chain_id', $bsc->id)->where('symbol', $symbol)->first();
            $this->assertSame($address, strtolower($token->contract_address), "address ของ {$symbol} ใน DB ไม่ตรงทะเบียน");
        }
    }

    public function test_running_the_seeder_twice_does_not_duplicate(): void
    {
        $this->seed(AllChainsSeeder::class);
        $this->seed(BscMajorPairsSeeder::class);
        $before = TradingPair::count();

        $this->seed(BscMajorPairsSeeder::class);

        $this->assertSame($before, TradingPair::count());
    }
}
