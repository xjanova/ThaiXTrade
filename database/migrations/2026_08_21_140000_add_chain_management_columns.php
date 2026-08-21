<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ย้าย "ค่าที่ตัดสินพฤติกรรมของเชน" จาก config/chains.php เข้ามาอยู่ในตาราง chains.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ปัญหาที่แก้: หน้า /admin/chains แก้อะไรก็ไม่มีผลกับเว็บเลย
 * ═══════════════════════════════════════════════════════════════════════════
 * /admin/chains ทำ CRUD บนตาราง `chains`
 * /api/v1/chains (ที่ทั้งเว็บและแอปมือถืออ่าน) อ่านจาก config/chains.php ซึ่งเป็นไฟล์ PHP
 * → ทุกอย่างที่แอดมินกดแก้ (เปิด/ปิด, RPC, ไอคอน, ลำดับ) มองไม่เห็นจากฝั่งผู้ใช้
 *
 * ที่หนักที่สุดคือ `status` (live / coming_soon) ซึ่งเป็นตัวตัดสินว่าเชนกดเลือกได้ไหม
 * ค่านั้นอยู่ในไฟล์ PHP ล้วนๆ ไม่มีคอลัมน์รองรับ — เจ้าของจะเปิด TPIX Chain ให้เทรด
 * ต้องแก้โค้ดแล้ว deploy ใหม่ ทำเองจากหลังบ้านไม่ได้เลย
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ ห้ามเปลี่ยนเลข chains.id ให้เป็น chain id จริงเด็ดขาด
 * ═══════════════════════════════════════════════════════════════════════════
 * tokens / trading_pairs / orders / trades / fee_configs / swap_configs
 * ทั้งหมดอ้าง chains.id (row id) ผ่าน FK อยู่แล้ว
 *   - ลบแถวแล้วใส่ใหม่ = CASCADE ลบโทเคนและคู่เทรดทิ้งทั้งหมด
 *   - แก้ id ในที่เดิม = แถวที่เคยหมายถึง zkSync (id 10) จะกลายเป็น Optimism เงียบๆ
 *     ประวัติการเทรดเปลี่ยนเชนโดยไม่มี error สักตัว
 * จึงเพิ่ม "คอลัมน์ใหม่" แทน ไม่แตะ primary key
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมไม่ใส่ unique index บน chain_id
 * ═══════════════════════════════════════════════════════════════════════════
 * ตารางนี้ใช้ SoftDeletes — unique ธรรมดาจะทำให้ "ลบเชนแล้วเพิ่มใหม่" ชนกับแถวที่
 * มองไม่เห็น กลายเป็น 500 ที่หาสาเหตุยากมาก ส่วน unique(chain_id, deleted_at)
 * ก็ไม่ช่วย เพราะ MySQL ถือว่า NULL ไม่เท่ากับ NULL จึงยอมให้ซ้ำได้อยู่ดี
 * เลยกันซ้ำที่ชั้น validation แทน (ดู Admin\ChainController::validatePayload)
 *
 * ⚠️ MySQL strict: ใช้ string ธรรมดาแทน enum สำหรับ status
 *    เคยมีเหตุการณ์จริงที่ migration เขียนค่านอก ENUM แล้วผ่านบน SQLite ตอนเทสต์
 *    แต่ตายกลางคันบน MySQL — deploy ขึ้นเขียวทั้งที่ index ไม่ถูกสร้าง
 */
return new class() extends Migration
{
    /**
     * รายชื่อเชนทั้งหมดที่ระบบต้องมี — คีย์คือ chain id จริง.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ทำไมต้อง "สร้างถ้ายังไม่มี" ไม่ใช่แค่ "อัปเดตของเดิม"
     * ═══════════════════════════════════════════════════════════════════════
     * ตั้งแต่ /api/v1/chains อ่านจากฐานข้อมูล รายการเชนจึงต้องครบตั้งแต่ migrate เสร็จ
     * จะไปหวังพึ่ง AllChainsSeeder ไม่ได้ เพราะ seeder ไม่ถูกเรียกตอนรันเทสต์
     * และไม่ได้อยู่ในขั้นตอน deploy — แถวอย่าง TPIX Chain มีอยู่บน production
     * เพราะเคยรัน seeder ด้วยมือเมื่อไหร่สักครั้งเท่านั้น
     *
     * ใช้ updateOrInsert คีย์ด้วย chain_id_hex → รันซ้ำกี่รอบก็ไม่เกิดแถวซ้ำ
     * และไม่ทับค่าที่แอดมินแก้ไว้เอง (ดูรายละเอียดใน seedChain)
     *
     * icon: ใช้โฮสต์ assets-cdn.trustwallet.com (ตอบ 200)
     *       ไม่ใช่ assets.trustwalletapp.com เดิมที่ตอบ 301 redirect ทุกครั้ง
     */
    private const CHAINS = [
        4289 => [
            'name' => 'TPIX Chain', 'symbol' => 'TPIX', 'short_name' => 'TPIX',
            'rpc_url' => 'https://rpc.tpix.online', 'explorer_url' => 'https://explorer.tpix.online',
            'currency' => ['TPIX', 'TPIX', 18], 'confirmations' => 3, 'sort_order' => 0,
            'color' => '#06B6D4', 'status' => 'coming_soon', 'icon' => '/tpixlogo.webp',
            'gasless' => true, 'block_time' => 2, 'consensus' => 'IBFT',
        ],
        1 => [
            'name' => 'Ethereum', 'symbol' => 'ETH', 'short_name' => 'ETH',
            'rpc_url' => 'https://eth.llamarpc.com', 'explorer_url' => 'https://etherscan.io',
            'currency' => ['Ether', 'ETH', 18], 'confirmations' => 12, 'sort_order' => 1,
            'color' => '#627EEA', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/info/logo.png',
        ],
        56 => [
            'name' => 'BNB Smart Chain', 'symbol' => 'BNB', 'short_name' => 'BSC',
            'rpc_url' => 'https://bsc-dataseed.binance.org', 'explorer_url' => 'https://bscscan.com',
            'currency' => ['BNB', 'BNB', 18], 'confirmations' => 15, 'sort_order' => 2,
            'color' => '#F3BA2F', 'status' => 'live',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/info/logo.png',
        ],
        137 => [
            // เหรียญของ Polygon เปลี่ยนชื่อจาก MATIC เป็น POL ตั้งแต่ ก.ย. 2024
            'name' => 'Polygon', 'symbol' => 'POL', 'short_name' => 'POL',
            'rpc_url' => 'https://polygon-rpc.com', 'explorer_url' => 'https://polygonscan.com',
            'currency' => ['POL', 'POL', 18], 'confirmations' => 30, 'sort_order' => 3,
            'color' => '#8247E5', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/polygon/info/logo.png',
        ],
        96 => [
            'name' => 'Bitkub Chain', 'symbol' => 'KUB', 'short_name' => 'KUB',
            'rpc_url' => 'https://rpc.bitkubchain.io', 'explorer_url' => 'https://bkcscan.com',
            'currency' => ['KUB', 'KUB', 18], 'confirmations' => 12, 'sort_order' => 4,
            'color' => '#1BC5A4', 'status' => 'coming_soon', 'icon' => null,
        ],
        42161 => [
            // L2 ทุกตัวจ่ายค่าแก๊สเป็น ETH — ARB/OP เป็นเหรียญ governance ไม่ใช่ค่าแก๊ส
            'name' => 'Arbitrum One', 'symbol' => 'ARB', 'short_name' => 'ARB',
            'rpc_url' => 'https://arb1.arbitrum.io/rpc', 'explorer_url' => 'https://arbiscan.io',
            'currency' => ['Ether', 'ETH', 18], 'confirmations' => 12, 'sort_order' => 5,
            'color' => '#28A0F0', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/arbitrum/info/logo.png',
        ],
        10 => [
            'name' => 'Optimism', 'symbol' => 'OP', 'short_name' => 'OP',
            'rpc_url' => 'https://mainnet.optimism.io', 'explorer_url' => 'https://optimistic.etherscan.io',
            'currency' => ['Ether', 'ETH', 18], 'confirmations' => 12, 'sort_order' => 6,
            'color' => '#FF0420', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/optimism/info/logo.png',
        ],
        43114 => [
            'name' => 'Avalanche C-Chain', 'symbol' => 'AVAX', 'short_name' => 'AVAX',
            'rpc_url' => 'https://api.avax.network/ext/bc/C/rpc', 'explorer_url' => 'https://snowtrace.io',
            'currency' => ['Avalanche', 'AVAX', 18], 'confirmations' => 12, 'sort_order' => 7,
            'color' => '#E84142', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/avalanchec/info/logo.png',
        ],
        250 => [
            'name' => 'Fantom', 'symbol' => 'FTM', 'short_name' => 'FTM',
            'rpc_url' => 'https://rpc.ftm.tools', 'explorer_url' => 'https://ftmscan.com',
            'currency' => ['Fantom', 'FTM', 18], 'confirmations' => 12, 'sort_order' => 8,
            'color' => '#1969FF', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/fantom/info/logo.png',
        ],
        8453 => [
            'name' => 'Base', 'symbol' => 'BASE', 'short_name' => 'BASE',
            'rpc_url' => 'https://mainnet.base.org', 'explorer_url' => 'https://basescan.org',
            'currency' => ['Ether', 'ETH', 18], 'confirmations' => 12, 'sort_order' => 9,
            'color' => '#0052FF', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/base/info/logo.png',
        ],
        324 => [
            'name' => 'zkSync Era', 'symbol' => 'ZKSYNC', 'short_name' => 'ZKSYNC',
            'rpc_url' => 'https://mainnet.era.zksync.io', 'explorer_url' => 'https://explorer.zksync.io',
            'currency' => ['Ether', 'ETH', 18], 'confirmations' => 12, 'sort_order' => 10,
            'color' => '#8C8DFC', 'status' => 'coming_soon',
            'icon' => 'https://assets-cdn.trustwallet.com/blockchains/zksync/info/logo.png',
        ],
    ];

    /** URL ไอคอนชุดเดิมที่ seeder ใส่ไว้ — ทับได้ เพราะไม่ใช่ของที่แอดมินเลือกเอง */
    private const LEGACY_ICON_HOST = 'https://assets.trustwalletapp.com/';

    public function up(): void
    {
        Schema::table('chains', function (Blueprint $table) {
            // chain id จริงของเชน (4289, 56, ...) — ไม่ใช่ row id
            $table->unsignedInteger('chain_id')->nullable()->after('id')->index();
            $table->unsignedInteger('network_id')->nullable()->after('chain_id');
            $table->string('short_name', 20)->nullable()->after('symbol');

            // live = เทรดได้จริง · coming_soon = เห็นแต่กดไม่ได้ · maintenance = ปิดชั่วคราว
            $table->string('status', 20)->default('coming_soon')->after('is_active')->index();

            $table->string('color', 20)->nullable()->after('logo');
            $table->boolean('gasless')->default(false)->after('color');
            $table->unsignedSmallInteger('block_time')->nullable()->after('gasless');
            $table->string('consensus', 30)->nullable()->after('block_time');

            // RPC สำรอง — คอลัมน์เดิม rpc_url เก็บได้ตัวเดียว ทำให้เพิ่มตัวสำรองไม่ได้เลย
            $table->json('rpc_fallbacks')->nullable()->after('rpc_url');
        });

        $this->normaliseExistingRows();

        foreach (self::CHAINS as $chainId => $spec) {
            $this->seedChain($chainId, $spec);
        }

        $this->renamePolygonTicker();
    }

    /**
     * Polygon เปลี่ยนชื่อเหรียญจาก MATIC เป็น POL ตั้งแต่ ก.ย. 2024.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ทำไมต้องแยกออกมา ไม่รวมกับ seedChain()
     * ═══════════════════════════════════════════════════════════════════════
     * seedChain() ตั้งใจไม่แตะคอลัมน์ที่แอดมินอาจแก้ไว้เอง (name/symbol/rpc)
     * แต่ MATIC เป็น "ค่าที่ผิดจริง" ไม่ใช่ค่าที่ใครตั้งใจเลือก จึงต้องแก้ให้
     *
     * ผลของการปล่อยไว้: ตอนกด "เพิ่มเครือข่าย" เข้ากระเป๋า เราส่ง nativeCurrency
     * เป็น MATIC ซึ่งขัดกับข้อมูลของ MetaMask เอง — กระเป๋าอาจปฏิเสธหรือขึ้นเตือน
     * ว่าสกุลเงินไม่ตรง และค่าแก๊สทั้งหมดถูกแสดงด้วยชื่อเหรียญที่เลิกใช้ไปแล้ว
     *
     * เช็คค่าเดิมก่อนเสมอ — ถ้าแอดมินตั้งเป็นอย่างอื่นไปแล้วแปลว่าเขาตั้งใจ ไม่ทับ
     */
    private function renamePolygonTicker(): void
    {
        $polygon = DB::table('chains')->where('chain_id', 137)->first();

        if ($polygon === null) {
            return;
        }

        $update = [];

        if (strtoupper((string) $polygon->symbol) === 'MATIC') {
            $update['symbol'] = 'POL';
            $update['short_name'] = 'POL';
        }

        if (strtoupper((string) $polygon->native_currency_symbol) === 'MATIC') {
            $update['native_currency_symbol'] = 'POL';
            $update['native_currency_name'] = 'POL';
        }

        if ($update !== []) {
            DB::table('chains')->where('id', $polygon->id)->update($update);
        }
    }

    /**
     * ทำให้แถวที่มีอยู่เดิมอยู่ในรูปแบบมาตรฐานก่อน แล้วค่อยเติมค่าใหม่.
     */
    private function normaliseExistingRows(): void
    {
        foreach (DB::table('chains')->get() as $row) {
            $hexLower = strtolower(trim((string) $row->chain_id_hex));
            $chainId = $this->hexToInt($hexLower);

            $update = [
                // เก็บ hex เป็นตัวพิมพ์เล็กเสมอ — ของเดิมปนกัน ('0x38' กับ '0xA4B1')
                // ทำให้ทุกตัวค้นต้องลองทั้งสองแบบ เขียนซ้ำกันอยู่ 4 ที่ในโค้ด
                'chain_id_hex' => $hexLower,
            ];

            if ($chainId !== null) {
                $update['chain_id'] = $chainId;
                $update['network_id'] = $chainId;
            }

            DB::table('chains')->where('id', $row->id)->update($update);
        }
    }

    /**
     * สร้างเชนถ้ายังไม่มี หรือเติมเฉพาะคอลัมน์ใหม่ถ้ามีอยู่แล้ว.
     */
    private function seedChain(int $chainId, array $spec): void
    {
        $hex = '0x'.dechex($chainId);
        $existing = DB::table('chains')->where('chain_id_hex', $hex)->first();

        // คอลัมน์ที่เพิ่งเพิ่มในรอบนี้ — เติมได้เสมอ เพราะยังไม่เคยมีใครแก้
        $newColumns = [
            'chain_id' => $chainId,
            'network_id' => $chainId,
            'short_name' => $spec['short_name'],
            'status' => $spec['status'],
            'color' => $spec['color'],
            'gasless' => $spec['gasless'] ?? false,
            'block_time' => $spec['block_time'] ?? null,
            'consensus' => $spec['consensus'] ?? null,
        ];

        if ($existing !== null) {
            /*
             * แถวมีอยู่แล้ว → แตะเฉพาะคอลัมน์ใหม่เท่านั้น
             *
             * ห้ามทับ name / rpc_url / sort_order / is_active ที่แอดมินอาจแก้ไว้
             * ยกเว้นไอคอนที่ยังว่างหรือยังเป็น URL ชุดเก่าจาก seeder ซึ่งถือว่า
             * ไม่ใช่ของที่ใครตั้งใจเลือก
             */
            $logoIsSeeded = $existing->logo === null
                || $existing->logo === ''
                || str_starts_with((string) $existing->logo, self::LEGACY_ICON_HOST);

            if ($logoIsSeeded && $spec['icon'] !== null) {
                $newColumns['logo'] = $spec['icon'];
            }

            DB::table('chains')->where('id', $existing->id)->update($newColumns);

            return;
        }

        // ยังไม่มีแถวนี้ → สร้างใหม่ให้ครบ
        [$currencyName, $currencySymbol, $decimals] = $spec['currency'];

        DB::table('chains')->insert(array_merge($newColumns, [
            'name' => $spec['name'],
            'symbol' => $spec['symbol'],
            'chain_id_hex' => $hex,
            'rpc_url' => $spec['rpc_url'],
            'explorer_url' => $spec['explorer_url'],
            'logo' => $spec['icon'],
            'is_testnet' => false,
            'is_active' => true,
            'native_currency_name' => $currencyName,
            'native_currency_symbol' => $currencySymbol,
            'native_currency_decimals' => $decimals,
            'block_confirmations' => $spec['confirmations'],
            'sort_order' => $spec['sort_order'],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** แปลง '0x38' → 56 อย่างปลอดภัย (คืน null ถ้ารูปแบบผิด) */
    private function hexToInt(?string $hex): ?int
    {
        if ($hex === null) {
            return null;
        }

        /*
         * ห้ามใช้ ltrim($hex, '0x') เด็ดขาด — พารามิเตอร์ตัวที่สองคือ "ชุดอักขระ"
         * ไม่ใช่ "คำนำหน้า" มันจะกิน 0 และ x ทุกตัวที่อยู่ต้นสตริงจนหมด
         * ทำให้ค่าที่มีเลขศูนย์นำหน้าเพี้ยนแบบเงียบๆ
         */
        if (! preg_match('/^0x([0-9a-fA-F]{1,16})$/', trim($hex), $m)) {
            return null;
        }

        return (int) hexdec($m[1]);
    }

    public function down(): void
    {
        Schema::table('chains', function (Blueprint $table) {
            $table->dropColumn([
                'chain_id',
                'network_id',
                'short_name',
                'status',
                'color',
                'gasless',
                'block_time',
                'consensus',
                'rpc_fallbacks',
            ]);
        });
    }
};
