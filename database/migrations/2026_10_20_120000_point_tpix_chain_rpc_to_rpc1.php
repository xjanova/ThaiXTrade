<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPIX Chain ใช้ rpc1.tpix.online เป็น RPC หลัก (2026-10-20 — เจ้าของสั่ง "เราใช้ rpc1").
 *
 * แถวนี้คือสิ่งที่ /api/v1/chains ส่งให้เว็บและแอปเอาไป "เพิ่มเครือข่ายลงกระเป๋า"
 * rpc.tpix.online อยู่หลัง Cloudflare bot rule ที่ตอบ 403 ให้ client ที่ไม่ใช่เบราว์เซอร์
 * กระเป๋าที่ได้ RPC นี้ไปจึงอ่านยอด/ส่งธุรกรรมไม่ได้เป็นพัก ๆ ส่วน rpc1 เข้าโหนดตรง
 *
 * แก้เฉพาะแถวที่ยังเป็นค่าเดิม — ถ้าแอดมินตั้ง RPC อื่นไว้เองที่ /admin/chains ไม่ทับ
 * rpc.tpix.online ย้ายไปเป็นตัวสำรอง (ไม่ซ้ำถ้ามีอยู่แล้ว)
 */
return new class extends Migration
{
    private const OLD = ['https://rpc.tpix.online', 'https://rpc.tpix.online/'];

    private const NEW = 'https://rpc1.tpix.online';

    public function up(): void
    {
        if (! Schema::hasTable('chains')) {
            return;
        }

        $rows = DB::table('chains')
            ->whereIn('chain_id_hex', ['0x10C1', '0x10c1'])
            ->whereIn('rpc_url', self::OLD)
            ->get();

        foreach ($rows as $row) {
            $update = ['rpc_url' => self::NEW, 'updated_at' => now()];

            if (Schema::hasColumn('chains', 'rpc_fallbacks')) {
                $fallbacks = json_decode((string) ($row->rpc_fallbacks ?? ''), true);
                $fallbacks = is_array($fallbacks) ? $fallbacks : [];
                $fallbacks = array_values(array_unique(array_filter(
                    array_merge($fallbacks, [self::OLD[0]]),
                    fn ($url) => is_string($url) && $url !== '' && rtrim($url, '/') !== self::NEW,
                )));
                $update['rpc_fallbacks'] = json_encode($fallbacks);
            }

            DB::table('chains')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // ไม่ย้อน — rpc.tpix.online ตอบ 403 ให้กระเป๋า การกลับไปใช้คือทำให้ผู้ใช้พังอีกรอบ
    }
};
