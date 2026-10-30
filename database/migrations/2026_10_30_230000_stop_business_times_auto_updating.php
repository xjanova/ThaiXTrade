<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * เลิกให้ฐานข้อมูลเขียนทับเวลาทางธุรกิจเองทุกครั้งที่อัปเดตแถว (2026-10-30)
 *
 * prod เป็น MariaDB 10.6 ตั้ง explicit_defaults_for_timestamp=0 — TIMESTAMP NOT NULL คอลัมน์แรกของตาราง
 * ที่ไม่ได้ระบุ default จะได้ ON UPDATE current_timestamp() แถมมาเอง ทั้งที่ไม่มี migration ไหนขอ
 * SQLite ของเทสต์และ CI ไม่มีพฤติกรรมนี้ จึงไม่มีเทสต์ไหนเห็น
 *
 * ตัวที่อันตรายสุดคือ klines.open_time ซึ่งอยู่ใน unique key: แท่งที่ถูกอัปเดตจะย้ายไปเวลาปัจจุบัน
 * ไม้ถัดไปในนาทีเดียวกันหาแท่งเดิมไม่เจอแล้วสร้างแท่งใหม่ ปริมาณแตกกระจาย และ TWAP ของ stabilizer นับแท่งเกิน
 * แถวบน prod ยังไม่เสียเพราะยังไม่เคยมีไม้สวอป แต่ไม้แรกจะเริ่มทำให้เสียทันที
 *
 * คง TIMESTAMP ไว้ ไม่เปลี่ยนเป็น DATETIME เพราะ DATETIME แปลงค่าผ่าน time zone ของ session
 * ต้องระบุ DEFAULT CURRENT_TIMESTAMP ไม่งั้น engine เติม ON UPDATE กลับมาเอง (default เดิมบน prod ก็ค่านี้)
 * MODIFY ที่ไม่ระบุ COMMENT จะลบของเดิม จึงอ่านมาใส่กลับ
 *
 * ไม่ซ่อมค่าที่เสียไปแล้วที่นี่: token_factory_fee_quotes.expires_at ของใบที่ใช้แล้ว 1 แถว เหลือเท่ากับ used_at
 * ไม่มีทางไหนอ่านค่านั้นอีก ส่วนการตัดสินใจแก้เป็นของเจ้าของ
 */
return new class() extends Migration
{
    private const COLUMNS = [
        'staking_positions' => 'staked_at',
        'klines' => 'open_time',
        'token_factory_fee_quotes' => 'expires_at',
        'ai_market_views' => 'expires_at',
        'discord_mod_strikes' => 'occurred_at',
        'food_traces' => 'recorded_at',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            $current = DB::selectOne(
                'select extra as flags, column_comment as note from information_schema.columns
                 where table_schema = database() and table_name = ? and column_name = ?',
                [$table, $column]
            );

            // ไม่มีคอลัมน์ = ยังไม่สร้างตาราง · ไม่มี on update = engine เปิด explicit_defaults อยู่แล้ว
            if (! $current || stripos($current->flags, 'on update') === false) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT %s',
                $table,
                $column,
                DB::getPdo()->quote($current->note)
            ));
        }
    }

    public function down(): void
    {
        // ไม่ย้อน — คืน ON UPDATE คือคืนบั๊ก และโค้ดรุ่นก่อนหน้าก็ไม่ได้พึ่งพฤติกรรมนี้
    }
};
