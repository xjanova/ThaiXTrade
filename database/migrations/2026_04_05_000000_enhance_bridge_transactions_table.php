<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * เพิ่ม columns สำหรับ bridge verification + execution tracking
 * + unique index บน source_tx_hash ป้องกัน double-process
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_transactions', function (Blueprint $table) {
            $table->unsignedInteger('retry_count')->default(0)->after('error_message');
            $table->timestamp('verified_at')->nullable()->after('retry_count');
            $table->timestamp('completed_at')->nullable()->after('verified_at');

            // ป้องกัน double-process: tx_hash เดียวกันห้าม process ซ้ำ
            $table->unique('source_tx_hash');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_transactions', function (Blueprint $table) {
            $table->dropUnique(['source_tx_hash']);
            $table->dropColumn(['retry_count', 'verified_at', 'completed_at']);
        });
    }
};
