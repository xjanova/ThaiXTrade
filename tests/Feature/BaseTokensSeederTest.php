<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\Token;
use Database\Seeders\BaseTokensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — BaseTokensSeeder ต้องรันซ้ำได้ทุกรอบ deploy โดยไม่ล้ม.
 *
 * จำลองเหตุการณ์จริงบน production (2026-08-21 → 2026-09-03): migration เปลี่ยน
 * เหรียญของ Polygon จาก MATIC → POL แต่แถว tokens (chain, 0x000…) ยังชื่อ MATIC
 * seeder รุ่นเดิมค้นด้วย symbol ไม่เจอ แล้ว insert address ศูนย์ซ้ำ
 * → SQLSTATE 23000 / 1062 Duplicate entry '3-0x000…' ทุกรอบ deploy โดย deploy ยังเขียว
 *
 * ตาราง chains ถูกเติมครบ 11 เชน (Polygon = POL แล้ว) โดย migration 2026_08_21
 * ตอน RefreshDatabase จึงไม่ต้อง seed เชนเองในเทสต์
 *
 * Developed by Xman Studio.
 */
class BaseTokensSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ZERO = BaseTokensSeeder::NATIVE_ADDRESS;

    private const BSC_USDT = '0x55d398326f99059fF775485246999027B3197955';

    #[Test]
    public function running_twice_creates_no_duplicates_and_does_not_throw(): void
    {
        $this->seed(BaseTokensSeeder::class);
        $afterFirstRun = Token::count();
        $this->assertGreaterThan(0, $afterFirstRun);

        $this->seed(BaseTokensSeeder::class);

        $this->assertSame($afterFirstRun, Token::count(), 'รอบสองต้องไม่สร้างแถวเพิ่ม');
        $this->assertEveryActiveChainHasExactlyOneNativeToken();
    }

    #[Test]
    public function legacy_matic_row_is_renamed_to_pol_instead_of_colliding(): void
    {
        $polygon = Chain::where('chain_id', 137)->firstOrFail();
        $this->assertSame('POL', $polygon->native_currency_symbol, 'migration ต้องเปลี่ยน Polygon เป็น POL แล้ว');

        // แถวจริงจาก production: tokens id 8 = (chain Polygon, MATIC, address ศูนย์)
        // ใส่ค่าที่ "แอดมินแก้เอง" ไว้ด้วย เพื่อพิสูจน์ว่า seeder ไม่ทับ
        $legacy = Token::create([
            'chain_id' => $polygon->id,
            'symbol' => 'MATIC',
            'name' => 'MATIC',
            'contract_address' => self::ZERO,
            'decimals' => 18,
            'logo' => 'tokens/admin-upload.png',
            'is_active' => false,
            'sort_order' => 42,
        ]);

        $this->seed(BaseTokensSeeder::class);
        $this->seed(BaseTokensSeeder::class);

        $native = Token::where('chain_id', $polygon->id)->where('contract_address', self::ZERO)->get();

        $this->assertCount(1, $native, 'ต้องมีเหรียญ native ของ Polygon แถวเดียว');
        $this->assertSame($legacy->id, $native[0]->id, 'ต้องใช้แถวเดิม ไม่สร้างใหม่');
        $this->assertSame('POL', $native[0]->symbol);
        $this->assertSame('POL', $native[0]->name);
        $this->assertSame(0, Token::where('chain_id', $polygon->id)->where('symbol', 'MATIC')->count());

        // ค่าที่แอดมินตั้งเองต้องคงเดิม
        $this->assertSame('tokens/admin-upload.png', $native[0]->logo);
        $this->assertFalse($native[0]->is_active);
        $this->assertSame(42, (int) $native[0]->sort_order);
    }

    #[Test]
    public function chains_after_the_colliding_one_are_still_seeded(): void
    {
        // รุ่นเดิม exception ที่ Polygon ทำให้ทุกเชนที่ id สูงกว่าไม่ถูก seed ในรอบนั้น
        $polygon = Chain::where('chain_id', 137)->firstOrFail();
        Token::create([
            'chain_id' => $polygon->id,
            'symbol' => 'MATIC',
            'name' => 'MATIC',
            'contract_address' => self::ZERO,
            'decimals' => 18,
        ]);

        $laterChains = Chain::where('is_active', true)->where('id', '>', $polygon->id)->get();
        $this->assertGreaterThan(0, $laterChains->count(), 'ต้องมีเชนที่ id สูงกว่า Polygon ให้ตรวจ');

        $this->seed(BaseTokensSeeder::class);

        foreach ($laterChains as $chain) {
            $this->assertTrue(
                Token::where('chain_id', $chain->id)->where('contract_address', self::ZERO)->exists(),
                "เหรียญ native ของ {$chain->name} ต้องถูกสร้างแม้ Polygon จะเคยชน"
            );
        }

        $this->assertEveryActiveChainHasExactlyOneNativeToken();
    }

    #[Test]
    public function usdt_is_skipped_when_its_address_already_belongs_to_another_symbol(): void
    {
        // แอดมินเปลี่ยนชื่อ USDT บน BSC เป็น BSC-USD — address เดิม symbol ใหม่
        $bsc = Chain::where('chain_id', 56)->firstOrFail();
        Token::create([
            'chain_id' => $bsc->id,
            'symbol' => 'BSC-USD',
            'name' => 'Binance-Peg BSC-USD',
            'contract_address' => self::BSC_USDT,
            'decimals' => 18,
        ]);

        $this->seed(BaseTokensSeeder::class);
        $this->seed(BaseTokensSeeder::class);

        $this->assertSame(1, Token::where('chain_id', $bsc->id)->where('contract_address', self::BSC_USDT)->count());
        $this->assertFalse(Token::where('chain_id', $bsc->id)->where('symbol', 'USDT')->exists(), 'ต้องไม่สร้าง USDT ซ้ำที่ address เดียวกัน');
    }

    #[Test]
    public function address_lookup_ignores_hex_letter_case_like_mysql_does(): void
    {
        // MySQL collation _ci ถือว่า address ต่างตัวพิมพ์เป็นค่าเดียวกันใน unique index
        // ถ้า seeder เทียบแบบ case-sensitive จะผ่านบน SQLite แต่ชน 1062 บน production
        $bsc = Chain::where('chain_id', 56)->firstOrFail();
        Token::create([
            'chain_id' => $bsc->id,
            'symbol' => 'BSC-USD',
            'name' => 'Binance-Peg BSC-USD',
            'contract_address' => strtolower(self::BSC_USDT),
            'decimals' => 18,
        ]);

        $this->seed(BaseTokensSeeder::class);

        $this->assertSame(
            1,
            Token::where('chain_id', $bsc->id)->whereRaw('LOWER(contract_address) = ?', [strtolower(self::BSC_USDT)])->count()
        );
    }

    #[Test]
    public function usdt_is_seeded_for_chains_whose_hex_is_stored_lowercase(): void
    {
        // ตาราง chains เก็บ hex ตัวพิมพ์เล็ก ('0xa4b1') ตั้งแต่ migration 2026_08_21
        // แต่ตารางที่อยู่ USDT รุ่นเดิมใช้คีย์ '0xA4B1' → หาไม่เจอ → ไม่เคยสร้าง USDT ให้เชนเหล่านี้
        $this->seed(BaseTokensSeeder::class);

        foreach ([42161 => 'Arbitrum', 10 => 'Optimism', 43114 => 'Avalanche', 250 => 'Fantom', 4289 => 'TPIX'] as $chainId => $name) {
            $chain = Chain::where('chain_id', $chainId)->firstOrFail();
            $this->assertStringStartsWith('0x', $chain->chain_id_hex);
            $this->assertSame(strtolower($chain->chain_id_hex), $chain->chain_id_hex, "{$name} hex ต้องเป็นตัวพิมพ์เล็ก");
            $this->assertTrue(
                Token::where('chain_id', $chain->id)->where('symbol', 'USDT')->exists(),
                "USDT บน {$name} ต้องถูกสร้าง"
            );
        }
    }

    #[Test]
    public function chain_without_native_symbol_is_skipped_without_creating_blank_token(): void
    {
        $blank = Chain::create([
            'name' => 'Draft Chain',
            'symbol' => 'DRAFT',
            'chain_id_hex' => '0x7a69',
            'rpc_url' => 'https://rpc.example.test',
            'native_currency_name' => '',
            'native_currency_symbol' => '',
            'native_currency_decimals' => 18,
            'is_active' => true,
        ]);

        $this->seed(BaseTokensSeeder::class);

        $this->assertSame(0, Token::where('chain_id', $blank->id)->count());
    }

    private function assertEveryActiveChainHasExactlyOneNativeToken(): void
    {
        foreach (Chain::where('is_active', true)->get() as $chain) {
            $this->assertSame(
                1,
                Token::where('chain_id', $chain->id)->where('contract_address', self::ZERO)->count(),
                "เหรียญ native ของ {$chain->name} ต้องมีแถวเดียว"
            );
        }
    }
}
