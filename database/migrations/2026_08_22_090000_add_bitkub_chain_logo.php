<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ใส่โลโก้ให้ Bitkub Chain (KUB, chain id 96)
 *
 * ที่มา: migration 2026_08_21_140000 หว่านค่าตั้งต้นให้ทุกเชนโดยดึงไอคอนจาก
 * assets-cdn.trustwallet.com แต่ Trust Wallet ไม่มีโลโก้ Bitkub Chain
 * (ลองแล้วทั้ง /bitkub /bitkubchain /kub /bkc — 404 ทุกตัว) สเปกจึงเขียนไว้ว่า
 * 'icon' => null และแถวนี้ก็ค้างไม่มีโลโก้มาตลอด
 *
 * โลโก้ที่ใช้มาจาก CDN ทางการของ Bitkub เอง:
 *   https://cdn.bitkubnow.com/coins/icon/32/KUB.png
 *
 * เก็บเป็นไฟล์ในเว็บรูทแทนการลิงก์ตรงไป CDN ของ Bitkub เพราะ
 *   1. ไม่ต้องพึ่งโฮสต์ภายนอกที่เราคุมไม่ได้ (ของ Trust Wallet เคยย้ายมาแล้วรอบหนึ่ง
 *      จน assets.trustwalletapp.com กลายเป็น 301 ทุกครั้ง)
 *   2. ไม่ส่ง IP ผู้เข้าเว็บไปให้ CDN บุคคลที่สามทุกครั้งที่โหลดหน้า
 *   3. ไฟล์แค่ 835 ไบต์ ไม่คุ้มที่จะแลกกับ dependency ภายนอก
 *
 * ข้อจำกัดที่ต้องรู้: Bitkub เปิดให้เฉพาะขนาด 32x32 (ลอง 64/128/256/512 แล้ว
 * ตอบ 403 ทั้งหมด) บนจอความละเอียดสูงจึงอาจดูไม่คมเท่าโลโก้เชนอื่นที่เป็น 256px
 * ถ้าภายหลังได้ไฟล์ใหญ่กว่านี้มา ให้ทับไฟล์เดิมได้เลยโดยไม่ต้องแก้ฐานข้อมูล
 */
return new class extends Migration
{
    /** chain id ของ Bitkub Chain */
    private const CHAIN_ID_HEX = '0x60';

    /** พาธในเว็บรูท — ไฟล์จริงอยู่ที่ public_html/images/chains/kub.png */
    private const LOGO_PATH = '/images/chains/kub.png';

    public function up(): void
    {
        $chain = DB::table('chains')->where('chain_id_hex', self::CHAIN_ID_HEX)->first();

        if ($chain === null) {
            // ยังไม่มีแถว Bitkub — migration ที่หว่านเชนจะสร้างให้เองภายหลัง
            // ไม่สร้างซ้ำตรงนี้เพื่อไม่ให้มีสองที่ที่นิยามเชนเดียวกัน
            return;
        }

        /*
         * แตะเฉพาะตอนที่ยังไม่มีใครตั้งโลโก้ไว้จริง ๆ
         * แอดมินที่อัปโหลดไฟล์เองไว้แล้วต้องไม่โดนทับ — เป็นบทเรียนจากรอบก่อน
         * ที่ค่าว่างจากฟอร์มไปทับ logo เป็น NULL จนไอคอนหาย 8 จาก 11 แถว
         */
        $isUnset = $chain->logo === null || trim((string) $chain->logo) === '';

        if (! $isUnset) {
            return;
        }

        DB::table('chains')
            ->where('id', $chain->id)
            ->update([
                'logo' => self::LOGO_PATH,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // คืนค่าเฉพาะแถวที่ยังเป็นพาธที่ migration นี้ใส่ไว้
        DB::table('chains')
            ->where('chain_id_hex', self::CHAIN_ID_HEX)
            ->where('logo', self::LOGO_PATH)
            ->update([
                'logo' => null,
                'updated_at' => now(),
            ]);
    }
};
