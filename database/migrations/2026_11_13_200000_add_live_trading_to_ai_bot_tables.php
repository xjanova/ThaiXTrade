<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — บอทเทรดเงินจริง: คำสั่งสวอปเดินผ่านคิวธุรกรรมเดียวกับการถอนของกระเป๋าบอท.
 *
 * ทำไมไม่แยกตาราง: กระเป๋าหนึ่งใบมี nonce ชุดเดียว — ถอนกับเทรดจึงต้องเข้าคิวเดียวกัน
 * และใช้เครื่องสถานะตัวเดียว (queued → signing → broadcasting → confirmed | failed)
 * สองคิวที่ไม่รู้จักกัน = สองธุรกรรมแย่ง nonce เดียวกัน
 *
 * ai_bot_wallet_transfers
 *   direction          เดิมเป็น enum(withdraw, deposit) → ข้อความ เพื่อรับ approve / swap
 *   ai_bot_config_id   บอทที่สั่งไม้นี้ (ไม่ผูก FK: ลบบอทแล้วประวัติธุรกรรมบนเชนต้องยังอยู่)
 *   parent_id          แถว approve ชี้กลับไปหาแถว swap ที่มันเปิดทางให้
 *   meta               เจตนาของคำสั่ง (ฝั่ง · ราคาอ้างอิง · เพดานราคาเบี่ยง) + เส้นทาง/ใบเสนอราคาตอนเซ็น + ผลจากใบเสร็จ
 *   expires_at         คำสั่งที่ยังไม่ได้เซ็นภายในเวลานี้ถูกปิดทิ้ง — สัญญาณเก่าไม่ควรกลายเป็นไม้
 *
 * ai_bot_trades
 *   tx_hash            ไม้เงินจริงหนึ่งไม้ = ธุรกรรมหนึ่งรายการ (unique) — ตัดสินซ้ำก็ไม่ลงบัญชีสองรอบ
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_bot_wallet_transfers')) {
            Schema::table('ai_bot_wallet_transfers', function (Blueprint $table) {
                $table->string('direction', 16)->change();
            });

            Schema::table('ai_bot_wallet_transfers', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_bot_wallet_transfers', 'ai_bot_config_id')) {
                    $table->unsignedBigInteger('ai_bot_config_id')->nullable()->after('ai_bot_wallet_id');
                    $table->index(['ai_bot_config_id', 'status'], 'idx_bot_wallet_transfer_bot');
                }
                if (! Schema::hasColumn('ai_bot_wallet_transfers', 'parent_id')) {
                    $table->unsignedBigInteger('parent_id')->nullable()->after('ai_bot_config_id');
                    $table->index('parent_id', 'idx_bot_wallet_transfer_parent');
                }
                if (! Schema::hasColumn('ai_bot_wallet_transfers', 'meta')) {
                    $table->json('meta')->nullable()->after('failure_code');
                }
                if (! Schema::hasColumn('ai_bot_wallet_transfers', 'expires_at')) {
                    $table->dateTime('expires_at')->nullable()->after('signing_at');
                }
            });
        }

        if (Schema::hasTable('ai_bot_trades') && ! Schema::hasColumn('ai_bot_trades', 'tx_hash')) {
            Schema::table('ai_bot_trades', function (Blueprint $table) {
                $table->string('tx_hash', 66)->nullable()->after('risk_level');
                $table->unique('tx_hash', 'idx_trade_tx_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_bot_trades') && Schema::hasColumn('ai_bot_trades', 'tx_hash')) {
            Schema::table('ai_bot_trades', function (Blueprint $table) {
                $table->dropUnique('idx_trade_tx_hash');
                $table->dropColumn('tx_hash');
            });
        }

        if (! Schema::hasTable('ai_bot_wallet_transfers')) {
            return;
        }

        Schema::table('ai_bot_wallet_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('ai_bot_wallet_transfers', 'ai_bot_config_id')) {
                $table->dropIndex('idx_bot_wallet_transfer_bot');
                $table->dropColumn('ai_bot_config_id');
            }
            if (Schema::hasColumn('ai_bot_wallet_transfers', 'parent_id')) {
                $table->dropIndex('idx_bot_wallet_transfer_parent');
                $table->dropColumn('parent_id');
            }
            foreach (['meta', 'expires_at'] as $column) {
                if (Schema::hasColumn('ai_bot_wallet_transfers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // direction คงเป็นข้อความ — ย้อนเป็น enum จะทำให้แถว approve/swap ที่มีอยู่ผิดชนิด
    }
};
