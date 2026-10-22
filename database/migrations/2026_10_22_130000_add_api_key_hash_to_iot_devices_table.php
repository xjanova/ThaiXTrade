<?php

/**
 * FoodPassport — API key ของอุปกรณ์ IoT เก็บแค่ hash.
 *
 * กุญแจตัวจริงออกให้ครั้งเดียวตอนลงทะเบียน (register-device) หรือตอนออกใหม่ (rotate-key /
 * ปุ่ม Key ในหลังบ้าน) แล้วเครื่องส่งมาทาง `Authorization: Bearer fpk_...` ทุกคำขอ
 *
 * ⚠️ เดิมกุญแจตัวจริงอยู่ใน `config.api_key` — config ถูกส่งออกพร้อมอุปกรณ์ในหน้า
 *    /food-passport/verify/{id} สาธารณะ (trace โหลด device มาด้วย) และใน my-devices
 *    ที่ใครก็ดูได้ กุญแจเดิมทุกดอกจึงต้องถือว่าหลุดแล้ว — ลบทิ้ง ไม่ย้ายมาเป็น hash
 *    (ณ 2026-10-22 production มี trace 0 แถว ยังไม่มีเครื่องไหนส่งข้อมูลสำเร็จสักครั้ง
 *    จึงไม่มีเฟิร์มแวร์ตัวไหนพังจากการลบ) เจ้าของออกกุญแจใหม่ได้เองจากหน้า IoT Devices
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('iot_devices', function (Blueprint $table) {
            // sha256 ของกุญแจ — null = ยังไม่เคยออกกุญแจ (อุปกรณ์ที่ลงทะเบียนก่อนมีระบบนี้)
            $table->string('api_key_hash', 64)->nullable()->after('config');
        });

        // eachById ไม่ใช่ each — แถวที่เหลือแต่กุญแจจะกลายเป็น config = NULL แล้วหลุดจากเงื่อนไข
        // แบ่งหน้าด้วย offset จะข้ามแถวถัดไป (เลื่อนตาม id ไม่มีปัญหานี้)
        DB::table('iot_devices')
            ->whereNotNull('config')
            ->eachById(function (object $device) {
                $config = json_decode((string) $device->config, true);

                if (! is_array($config) || ! array_key_exists('api_key', $config)) {
                    return;
                }

                unset($config['api_key']);

                DB::table('iot_devices')->where('id', $device->id)->update([
                    'config' => $config === [] ? null : json_encode($config),
                ]);
            });
    }

    public function down(): void
    {
        // กุญแจเดิมที่ลบไปกู้คืนไม่ได้ (และไม่ควรกู้ — หลุดไปแล้ว)
        Schema::table('iot_devices', function (Blueprint $table) {
            $table->dropColumn('api_key_hash');
        });
    }
};
