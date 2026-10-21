<?php

use App\Services\FeeCalculationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * มูลค่าค่าธรรมเนียมเป็นดอลลาร์ของแต่ละไม้สวอป (ออดิท 2026-10-21)
 *
 * ⚠️ fee_amount เป็นหน่วยของ "เหรียญขาเข้า" — หน้ารายได้ของแอดมินเคยรวมคอลัมน์นี้ตรงๆ
 *    ขาย PEPE 1,000,000,000 เหรียญ = ค่าธรรมเนียม 3,000,000 PEPE (~$30) ถูกบวกเป็น $3,000,000
 *    คอลัมน์ใหม่เก็บมูลค่าดอลลาร์ ณ ตอนบันทึก (SwapApiController คิดให้ทุกไม้ใหม่)
 *
 * แถวเก่าเติมให้ด้วยกฎเดียวกับไม้ใหม่ (FeeCalculationService::feeUsdFor):
 *   จ่ายด้วยสเตเบิล = ค่าธรรมเนียมคือดอลลาร์ · ได้สเตเบิล = ราคาโดยนัยของไม้นั้น · นอกนั้น null (ไม่เดา)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'fee_usd')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->decimal('fee_usd', 30, 8)->nullable()->after('fee_amount')
                    ->comment('มูลค่าค่าธรรมเนียมเป็น USD ณ ตอนบันทึก (null = ตีมูลค่าไม่ได้)');
            });
        }

        // สัญลักษณ์ของเหรียญทุกตัว (ตารางเล็ก) — คีย์ "{chain PK}:{address พิมพ์เล็ก}"
        $symbols = [];
        foreach (DB::table('tokens')->get(['chain_id', 'contract_address', 'symbol']) as $token) {
            $symbols[$token->chain_id.':'.strtolower((string) $token->contract_address)] = $token->symbol;
        }
        $native = ['0x0000000000000000000000000000000000000000', '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'];
        $symbolOf = function ($chainId, $address) use ($symbols, $native) {
            $address = strtolower((string) $address);
            if (isset($symbols[$chainId.':'.$address])) {
                return $symbols[$chainId.':'.$address];
            }
            if (in_array($address, $native, true)) {
                foreach ($native as $alias) {
                    if (isset($symbols[$chainId.':'.$alias])) {
                        return $symbols[$chainId.':'.$alias];
                    }
                }
            }

            return null;
        };

        DB::table('transactions')
            ->where('type', 'swap')
            ->whereNull('fee_usd')
            ->orderBy('id')
            ->select(['id', 'chain_id', 'from_token', 'to_token', 'from_amount', 'to_amount', 'fee_amount'])
            ->chunkById(500, function ($rows) use ($symbolOf) {
                foreach ($rows as $row) {
                    $feeUsd = FeeCalculationService::feeUsdFor(
                        $symbolOf($row->chain_id, $row->from_token),
                        $symbolOf($row->chain_id, $row->to_token),
                        (float) $row->fee_amount,
                        (float) $row->from_amount,
                        (float) $row->to_amount,
                    );

                    if ($feeUsd !== null) {
                        DB::table('transactions')->where('id', $row->id)->update(['fee_usd' => $feeUsd]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'fee_usd')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('fee_usd');
            });
        }
    }
};
