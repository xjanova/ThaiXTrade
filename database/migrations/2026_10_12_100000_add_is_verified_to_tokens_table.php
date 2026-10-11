<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * tokens.is_verified — แถวนี้ "แอดมินรับรองแล้ว" จึงขึ้น /tokenlist.json ได้.
 *
 * ทำไมต้องมี: explorer กับ TPIX Wallet จะถือรายการ tokenlist.json เป็นรายการ "ของจริง"
 * แต่ตาราง tokens บนเชน TPIX มีแถวที่ไม่มีใครรับรองปนอยู่สองแบบ
 *
 *   1. dex:sync เพิ่มเหรียญให้เองทุกพูลที่จับคู่กับ WTPIX/USDT — factory ของ DEX
 *      เปิดให้ใครก็สร้างพูลได้ และเชนค่าแก๊ส 0 → deploy เหรียญชื่อ "Tether USD"
 *      แล้วเปิดพูลเองฟรี ๆ ก็จะได้แถว is_active = true ทันที
 *   2. แถวดัชนีจาก seeder (BTC, ETH, …) ที่อยู่เป็น placeholder ไม่ใช่สัญญาจริงบนเชน
 *
 * ค่าตั้งต้นของคอลัมน์ = false (ทางไหนที่สร้างแถวโดยไม่คิด ต้องไม่หลุดเข้ารายการ)
 * แถวเดิมตั้งเป็น true ยกเว้นบนเชน TPIX ที่:
 *   - ที่อยู่เป็น placeholder (12 ไบต์แรกเป็นศูนย์ — สัญญาจริงไม่มีทางได้ที่อยู่แบบนี้)
 *   - หน้าตาเหมือนแถวที่ dex:sync เพิ่มเอง (sort_order 100 · ไม่มีโลโก้ · ไม่มี coingecko id)
 * แอดมินกดรับรองเพิ่มเองได้ที่ /admin/tokens
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tokens', 'is_verified')) {
            Schema::table('tokens', function (Blueprint $table) {
                $table->boolean('is_verified')->default(false)->after('is_active');
            });
        }

        $tpixChainIds = DB::table('chains')
            ->where('chain_id', 4289)
            ->orWhereRaw('LOWER(chain_id_hex) = ?', ['0x10c1'])
            ->pluck('id')
            ->all();

        DB::table('tokens')->orderBy('id')->chunkById(200, function ($rows) use ($tpixChainIds) {
            foreach ($rows as $row) {
                $verified = ! in_array($row->chain_id, $tpixChainIds, false) || $this->looksCurated($row);

                DB::table('tokens')->where('id', $row->id)->update(['is_verified' => $verified]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tokens', 'is_verified')) {
            Schema::table('tokens', function (Blueprint $table) {
                $table->dropColumn('is_verified');
            });
        }
    }

    private function looksCurated(object $row): bool
    {
        $address = strtolower((string) $row->contract_address);

        if (! preg_match('/^0x[0-9a-f]{40}$/', $address) || str_starts_with($address, '0x'.str_repeat('0', 24))) {
            return false;
        }

        $autoDiscovered = (int) $row->sort_order === 100
            && blank($row->logo)
            && blank($row->coingecko_id);

        return ! $autoDiscovered;
    }
};
