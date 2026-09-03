<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\Token;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — สร้าง tokens พื้นฐานสำหรับทุก chain
 * Native coin + USDT สำหรับทุก chain ที่ active.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ต้อง idempotent ตาม unique index ของตาราง — ไม่ใช่ตาม symbol
 * ═══════════════════════════════════════════════════════════════════════════
 * unique index ของ tokens คือ (chain_id, contract_address) แต่รุ่นเดิมค้นด้วย
 * (symbol, chain_id) แล้วค่อย insert address ศูนย์ พอ migration 2026_08_21 เปลี่ยน
 * เหรียญของ Polygon จาก MATIC → POL แถวเดิม (chain 3, 0x000…) ยังชื่อ MATIC
 * → ค้นด้วย POL ไม่เจอ → insert ซ้ำ → 1062 Duplicate entry ทุกรอบ deploy
 * ตั้งแต่ 2026-08-21 ถึง 2026-09-03 โดย deploy ยังขึ้นเขียวเพราะ `|| echo` กลืน error
 * และเชนที่อยู่หลัง Polygon ในลูปไม่ถูก seed เลยในรอบนั้น
 *
 * เหรียญ native ของเชนคือ "แถวที่ address = 0x000…" ตามนิยาม จึงค้นด้วย address
 * แล้วปรับสัญลักษณ์/ชื่อ/ทศนิยมให้ตรงกับตาราง chains ซึ่งเป็นแหล่งความจริง
 * (แอดมินแก้ได้ที่ /admin/chains) — ไม่แตะ logo / is_active / sort_order ที่แอดมินแก้เอง
 *
 * Idempotent — รันซ้ำได้ปลอดภัย (มีเทสต์ BaseTokensSeederTest คุมไว้)
 *
 * Developed by Xman Studio.
 */
class BaseTokensSeeder extends Seeder
{
    /** address ที่ใช้แทนเหรียญ native ของทุกเชน */
    public const NATIVE_ADDRESS = '0x0000000000000000000000000000000000000000';

    /**
     * USDT contract address ต่อเชน — คีย์เป็น chain_id_hex ตัวพิมพ์เล็กเสมอ.
     *
     * ตาราง chains เก็บ hex เป็นตัวพิมพ์เล็กตั้งแต่ migration 2026_08_21 (normaliseExistingRows)
     * คีย์แบบเดิม '0xA4B1' จึง isset ไม่เจอ → Arbitrum/Optimism/Avalanche/Fantom/TPIX
     * ไม่เคยได้ USDT บนฐานข้อมูลใหม่ — ตอนค้นจึง strtolower ทั้งสองฝั่ง
     */
    private const USDT_CONTRACTS = [
        '0x1' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',    // ETH
        '0x38' => '0x55d398326f99059fF775485246999027B3197955',   // BSC
        '0x89' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',   // Polygon
        '0xa4b1' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', // Arbitrum
        '0xa' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58',    // Optimism
        '0xa86a' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', // Avalanche
        '0xfa' => '0x049d68029688eAbF473097a2fC38ef61633A3C7A',    // Fantom
        '0x2105' => '0xd9aAEc86B65D86f6A7B5B1b0c42FFA531710b6CA', // Base (USDbC)
        '0x144' => '0x493257fD37EDB34451f62EDf8D2a0C418852bA4C',   // zkSync
        '0x10c1' => '0x0000000000000000000000000000000000000001',   // TPIX Chain
    ];

    public function run(): void
    {
        $stats = [
            'native_created' => 0, 'native_synced' => 0, 'native_ok' => 0, 'native_skipped' => 0,
            'usdt_created' => 0, 'usdt_exists' => 0, 'usdt_skipped' => 0, 'usdt_no_config' => 0,
        ];

        // เรียงตาม id ให้ผลซ้ำได้ทุกรอบ — เชนไหนมีปัญหาจะรู้ว่าเชนหลังจากนั้นโดนหางเลขหรือไม่
        foreach (Chain::where('is_active', true)->orderBy('id')->get() as $chain) {
            $stats[$this->syncNativeToken($chain)]++;
            $stats[$this->ensureUsdtToken($chain)]++;
        }

        $this->command?->info(sprintf(
            'Base tokens — native: สร้าง %d ปรับชื่อตามเชน %d · USDT: สร้าง %d ข้าม(address ถูกใช้แล้ว) %d · tokens ทั้งหมด %d',
            $stats['native_created'],
            $stats['native_synced'],
            $stats['usdt_created'],
            $stats['usdt_skipped'],
            Token::count(),
        ));
    }

    /**
     * เหรียญ native (address ศูนย์) — สร้างถ้ายังไม่มี หรือปรับให้ตรงกับเชนถ้ามีอยู่แล้ว.
     */
    private function syncNativeToken(Chain $chain): string
    {
        $symbol = trim((string) $chain->native_currency_symbol);

        // เชนที่แอดมินสร้างค้างไว้โดยยังไม่กรอกสกุลเงิน — ไม่สร้างเหรียญชื่อว่างให้
        if ($symbol === '') {
            return 'native_skipped';
        }

        $expected = [
            'symbol' => $symbol,
            'name' => trim((string) $chain->native_currency_name) ?: $symbol,
            'decimals' => (int) $chain->native_currency_decimals,
        ];

        $token = $this->findByAddress($chain, self::NATIVE_ADDRESS);

        if ($token === null) {
            Token::create($expected + [
                'chain_id' => $chain->id,
                'contract_address' => self::NATIVE_ADDRESS,
                'is_active' => true,
                'sort_order' => 1,
            ]);

            return 'native_created';
        }

        // แถวมีอยู่แล้วแต่ไม่ตรงกับเชน (กรณีจริง: MATIC ค้างอยู่หลัง Polygon เปลี่ยนเป็น POL)
        // → ปรับเฉพาะ symbol/name/decimals ตามเชน ส่วน logo/is_active/sort_order ปล่อยตามที่แอดมินตั้ง
        $changes = array_filter(
            $expected,
            fn ($value, $field) => (string) $token->{$field} !== (string) $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes === []) {
            return 'native_ok';
        }

        $token->update($changes);

        return 'native_synced';
    }

    /**
     * USDT ของเชน — สร้างเฉพาะเมื่อยังไม่มีทั้ง symbol USDT และ address นั้นบนเชน.
     *
     * ถ้า address ถูกใช้โดยเหรียญชื่ออื่นอยู่แล้ว (เช่น แอดมินเปลี่ยนชื่อเป็น BSC-USD)
     * ถือว่าตั้งใจ → ข้าม ไม่ insert ซ้ำให้ชน unique index
     */
    private function ensureUsdtToken(Chain $chain): string
    {
        $hex = strtolower(trim((string) $chain->chain_id_hex));
        $address = self::USDT_CONTRACTS[$hex] ?? null;

        if ($address === null) {
            return 'usdt_no_config';
        }

        if (Token::where('chain_id', $chain->id)->where('symbol', 'USDT')->exists()) {
            return 'usdt_exists';
        }

        if ($this->findByAddress($chain, $address) !== null) {
            return 'usdt_skipped';
        }

        Token::create([
            'chain_id' => $chain->id,
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'contract_address' => $address,
            'decimals' => $hex === '0x2105' ? 6 : 18,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return 'usdt_created';
    }

    /**
     * ค้นเหรียญด้วย address แบบไม่สนตัวพิมพ์.
     *
     * MySQL (collation _ci) ถือว่า 0xAbc กับ 0xabc เป็นค่าเดียวกันใน unique index
     * แต่ SQLite (ที่ใช้รันเทสต์) ไม่ถือ — LOWER ทั้งสองฝั่งให้พฤติกรรมตรงกันทุกฐานข้อมูล
     */
    private function findByAddress(Chain $chain, string $address): ?Token
    {
        return Token::where('chain_id', $chain->id)
            ->whereRaw('LOWER(contract_address) = ?', [strtolower($address)])
            ->first();
    }
}
