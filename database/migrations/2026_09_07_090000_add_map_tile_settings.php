<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ค่าตั้งชั้นไทล์ของแผนที่ (หน้า /validators + โปรแกรมมาสเตอร์โหนด).
 *
 * ปลายเดือน ส.ค. 2026 CARTO เปลี่ยนมาบังคับใช้ API key — ไทล์ที่เรียกโดยไม่มีคีย์
 * ยังคืน 200 ตามปกติ แต่ภาพมีลายน้ำ "API KEY REQUIRED" พาดทั้งแผนที่ ไม่มี error
 * ให้ระบบจับได้เลย เห็นได้ทางเดียวคือเปิดหน้าเว็บดู
 *
 * เก็บคีย์ในตารางนี้แทน .env เพื่อให้เจ้าของเปลี่ยนเองได้จากหลังบ้าน
 * และโปรแกรมมาสเตอร์โหนดดึงไปใช้ได้ผ่าน /api/v1/validators/map-config
 * (ไม่ต้องฝังคีย์ในไฟล์ .exe และหมุนคีย์ได้โดยไม่ต้องปล่อยโปรแกรมรุ่นใหม่)
 */
return new class() extends Migration
{
    public function up(): void
    {
        $rows = [
            // แม่แบบ URL ไทล์ — ยังไม่มีคีย์ ระบบจะต่อ ?key=... ให้เอง
            // เปลี่ยนผู้ให้บริการได้โดยไม่ต้องแก้โค้ด
            ['map_tile_url', 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', 'string'],

            // คีย์ของผู้ให้บริการไทล์ — ว่าง = ปิดชั้นไทล์ ใช้แผนที่ในโปรเจกต์แทน
            // ⚠️ อยู่ในรายการ secretKeys ของ SettingController จึงถูก mask ก่อนส่งไปเบราว์เซอร์
            ['map_tile_key', '', 'string'],

            ['map_tile_subdomains', 'abcd', 'string'],
            ['map_tile_max_zoom', '15', 'string'],
            ['map_tile_attribution', '&copy; <a href="https://carto.com" target="_blank" rel="noopener">CARTO</a> &copy; <a href="https://osm.org" target="_blank" rel="noopener">OSM</a>', 'string'],
        ];

        foreach ($rows as [$key, $value, $type]) {
            // updateOrInsert แบบไม่แตะ value ถ้ามีแถวอยู่แล้ว จะได้ไม่ล้างคีย์ที่ตั้งไว้
            $exists = DB::table('site_settings')
                ->where('group', 'map')
                ->where('key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('site_settings')->insert([
                'group' => 'map',
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('site_settings')->where('group', 'map')->delete();
    }
};
