<?php

/**
 * ตารางวัด uptime ของมาสเตอร์โหนด — ป้อน uptimeOracle ของ NodeRegistryV2.
 *
 *   masternode_nodes          สถานะล่าสุดต่อกระเป๋า (peer ID ที่ผูก, บล็อกล่าสุด, ประเทศ/เมือง)
 *   masternode_uptime_slots   ช่องเวลา 5 นาทีที่ heartbeat ผ่าน (1 แถวต่อกระเป๋าต่อช่อง)
 *   masternode_peer_sightings peer ID ที่ bootnode ของเราเห็นต่ออยู่ในช่องนั้น
 *   masternode_peer_reports   bootnode รายงานเข้ามากี่ครั้งต่อช่อง (ใช้ตัดสินว่ารอบนั้น "ข้อมูลเราขาด" ไหม)
 *   masternode_uptime_periods ผลรายรอบ (ชั่วโมง) ต่อกระเป๋า + ธุรกรรมที่ส่งขึ้นเชน
 *
 * ช่องเวลาเก็บเป็นเลขจำนวนเต็ม floor(unix / slot_seconds) ไม่ใช่ datetime —
 * ไม่มีเรื่องโซนเวลา/DST และเทียบช่องติดกันได้ด้วยการบวกลบ 1
 *
 * Developed by Xman Studio
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('masternode_nodes', function (Blueprint $t) {
            $t->id();
            $t->string('wallet_address', 42)->unique();
            // กุญแจโหนดหนึ่งตัวผูกได้กระเป๋าเดียว — กันรันเครื่องเดียวแล้วรับรางวัลหลายกระเป๋า
            $t->string('peer_id', 64)->unique();
            $t->string('node_address', 42)->index();
            // การมอบอำนาจที่ใช้อยู่ — ฉบับใหม่กว่า (หมดอายุทีหลัง) เท่านั้นที่ย้ายไปกุญแจโหนดตัวใหม่ได้
            $t->dateTime('delegation_expires_at')->nullable();
            $t->string('tier', 20)->nullable();
            $t->string('app_version', 32)->nullable();
            $t->unsignedSmallInteger('peer_count')->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable()->index();
            $t->unsignedBigInteger('last_block_number')->nullable();
            $t->integer('last_block_lag')->nullable();
            $t->string('last_check', 16)->nullable();
            // ระดับประเทศ/เมืองเท่านั้น — IP จริงไม่เก็บที่นี่ เก็บแค่ hash ไว้รู้ว่า IP เปลี่ยนไหม
            $t->char('country_code', 2)->nullable();
            $t->string('country_name', 64)->nullable();
            $t->string('city', 100)->nullable();
            $t->decimal('latitude', 5, 1)->nullable();
            $t->decimal('longitude', 5, 1)->nullable();
            $t->char('geo_ip_hash', 64)->nullable();
            $t->timestamps();
        });

        Schema::create('masternode_uptime_slots', function (Blueprint $t) {
            $t->id();
            $t->string('wallet_address', 42);
            $t->unsignedInteger('slot');
            $t->string('peer_id', 64);
            $t->unsignedBigInteger('block_number');
            // true = บล็อกตรงเชนและใหม่พอ · false = ล้า/แฮชผิด · null = RPC ของเราตรวจไม่ได้
            $t->boolean('block_ok')->nullable();
            $t->unsignedSmallInteger('heartbeats')->default(1);
            $t->timestamps();

            $t->unique(['wallet_address', 'slot']);
            $t->index('slot');
        });

        Schema::create('masternode_peer_sightings', function (Blueprint $t) {
            $t->id();
            $t->string('peer_id', 64);
            $t->unsignedInteger('slot');
            $t->timestamps();

            $t->unique(['peer_id', 'slot']);
            $t->index('slot');
        });

        Schema::create('masternode_peer_reports', function (Blueprint $t) {
            $t->id();
            $t->string('source', 64);
            $t->unsignedInteger('slot');
            $t->unsignedSmallInteger('reports')->default(0);
            $t->unsignedInteger('peers')->default(0);
            $t->timestamps();

            $t->unique(['source', 'slot']);
            $t->index('slot');
        });

        Schema::create('masternode_uptime_periods', function (Blueprint $t) {
            $t->id();
            $t->string('wallet_address', 42);
            $t->unsignedInteger('period_start');   // unix วินาที ต้นรอบ
            $t->unsignedSmallInteger('slots_expected');
            $t->unsignedSmallInteger('slots_ok');
            $t->unsignedSmallInteger('uptime_bps');          // ค่าที่วัดได้จริง
            $t->unsignedSmallInteger('onchain_before_bps')->nullable();
            $t->unsignedSmallInteger('target_bps');           // ค่าที่จะตั้งบนเชน (รอบข้อมูลขาดไม่ลดใคร)
            $t->boolean('degraded')->default(false);
            // shadow | unchanged | submitted | confirmed | failed
            $t->string('status', 16);
            $t->string('tx_hash', 66)->nullable()->index();
            $t->string('error', 255)->nullable();
            $t->timestamps();

            $t->unique(['wallet_address', 'period_start']);
            $t->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masternode_uptime_periods');
        Schema::dropIfExists('masternode_peer_reports');
        Schema::dropIfExists('masternode_peer_sightings');
        Schema::dropIfExists('masternode_uptime_slots');
        Schema::dropIfExists('masternode_nodes');
    }
};
