<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sale_transactions', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->after('status');
            $table->string('referrer_address', 42)->nullable()->after('referral_code');
            $table->decimal('bonus_percent', 5, 2)->default(0)->after('referrer_address');
            $table->decimal('bonus_amount', 36, 18)->default(0)->after('bonus_percent');
        });

        // ตาราง referral codes
        Schema::create('sale_referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('owner_address', 42);
            $table->decimal('bonus_percent', 5, 2)->default(5);
            $table->decimal('referrer_reward_percent', 5, 2)->default(3);
            $table->integer('uses')->default(0);
            $table->integer('max_uses')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('owner_address');
        });
    }

    public function down(): void
    {
        Schema::table('sale_transactions', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referrer_address', 'bonus_percent', 'bonus_amount']);
        });

        Schema::dropIfExists('sale_referral_codes');
    }
};
