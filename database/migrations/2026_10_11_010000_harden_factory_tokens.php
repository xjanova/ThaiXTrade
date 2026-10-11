<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Token Factory — ด่านฐานข้อมูลที่ validation อย่างเดียวกันไม่อยู่.
 *
 * 1. fee_tx_hash (unique) — ธุรกรรมค่าธรรมเนียมหนึ่งใบใช้สร้างเหรียญได้ครั้งเดียว
 *    เดิมเก็บใน metadata ไม่มีใครตรวจ ใช้ hash เดิมซ้ำได้ไม่จำกัด
 * 2. คอลัมน์ติดตามการ deploy — tx hash ถูกบันทึกทันทีที่เซ็น (ก่อนส่งขึ้นเชน)
 *    แถวที่มี tx_hash แล้วจะไม่ถูกส่งธุรกรรมใหม่อีก (ดู DeployTokenJob)
 * 3. active_symbol (generated) + unique(chain_id, active_symbol)
 *    สัญลักษณ์ซ้ำได้เฉพาะแถวที่ถูกปฏิเสธ/ล้มเหลว — กันสองคำขอที่ผ่าน validation พร้อมกัน
 * 4. ตารางใบเสนอราคาค่าธรรมเนียม (ล็อกยอด 30 นาที ผูกกับกระเป๋าและตัวเลือกที่คิดเงิน)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->string('fee_tx_hash', 66)->nullable()->after('tx_hash');
            $table->string('fee_quote_id', 36)->nullable()->after('fee_tx_hash');
            $table->unsignedBigInteger('deploy_nonce')->nullable()->after('fee_quote_id');
            $table->text('deploy_raw_tx')->nullable()->after('deploy_nonce');
            $table->timestamp('deploy_started_at')->nullable()->after('deploy_raw_tx');

            $table->unique('fee_tx_hash', 'factory_tokens_fee_tx_hash_unique');
            $table->unique('tx_hash', 'factory_tokens_tx_hash_unique');
        });

        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->string('active_symbol', 20)
                ->nullable()
                ->virtualAs("CASE WHEN status IN ('pending', 'deploying', 'deployed') THEN UPPER(symbol) ELSE NULL END");
        });

        $this->rejectDuplicatePendingSymbols();

        if ($this->hasActiveDuplicates()) {
            // แถวซ้ำที่ deploy ไปแล้วแก้ในฐานข้อมูลไม่ได้ (มีสัญญาบนเชนจริง) — ข้ามด่านนี้ไว้ก่อนแล้วบอกดัง ๆ
            Log::critical('factory_tokens: มีสัญลักษณ์ซ้ำในแถวที่ deploy แล้ว — ยังไม่ได้สร้าง unique index (chain_id, active_symbol)');
        } else {
            Schema::table('factory_tokens', function (Blueprint $table) {
                $table->unique(['chain_id', 'active_symbol'], 'factory_tokens_chain_active_symbol_unique');
            });
        }

        Schema::create('token_factory_fee_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('creator_address', 42)->index();
            $table->unsignedBigInteger('chain_id');
            $table->string('token_type', 30);
            $table->string('params_hash', 64);
            $table->decimal('amount', 36, 18);
            $table->string('amount_wei', 80);
            $table->string('fee_wallet', 42)->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('factory_token_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_factory_fee_quotes');

        Schema::table('factory_tokens', function (Blueprint $table) {
            if ($this->indexExists('factory_tokens_chain_active_symbol_unique')) {
                $table->dropUnique('factory_tokens_chain_active_symbol_unique');
            }
        });

        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->dropUnique('factory_tokens_fee_tx_hash_unique');
            $table->dropUnique('factory_tokens_tx_hash_unique');
            $table->dropColumn(['active_symbol']);
        });

        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->dropColumn(['fee_tx_hash', 'fee_quote_id', 'deploy_nonce', 'deploy_raw_tx', 'deploy_started_at']);
        });
    }

    /**
     * คำขอที่ยัง pending ซ้ำสัญลักษณ์กับแถวที่มาก่อน → ปฏิเสธพร้อมเหตุผล (ยังไม่มีอะไรบนเชน).
     */
    private function rejectDuplicatePendingSymbols(): void
    {
        $groups = DB::table('factory_tokens')
            ->select('chain_id', 'active_symbol')
            ->whereNotNull('active_symbol')
            ->groupBy('chain_id', 'active_symbol')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('factory_tokens')
                ->where('chain_id', $group->chain_id)
                ->where('active_symbol', $group->active_symbol)
                ->orderByRaw("CASE status WHEN 'deployed' THEN 0 WHEN 'deploying' THEN 1 ELSE 2 END")
                ->orderBy('id')
                ->get(['id', 'status']);

            foreach ($rows->slice(1) as $row) {
                if ($row->status !== 'pending') {
                    continue;
                }
                DB::table('factory_tokens')->where('id', $row->id)->update([
                    'status' => 'rejected',
                    'reject_reason' => 'สัญลักษณ์ซ้ำกับคำขอที่มาก่อน (ระบบตรวจตอนอัปเกรด)',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function hasActiveDuplicates(): bool
    {
        return DB::table('factory_tokens')
            ->select('chain_id', 'active_symbol')
            ->whereNotNull('active_symbol')
            ->groupBy('chain_id', 'active_symbol')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function indexExists(string $name): bool
    {
        return collect(Schema::getIndexes('factory_tokens'))->contains(fn ($index) => $index['name'] === $name);
    }
};
