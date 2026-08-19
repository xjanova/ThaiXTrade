<?php

namespace App\Services\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotPosition;
use App\Models\AiBotTrade;
use Illuminate\Support\Facades\DB;

/**
 * TPIX TRADE — โบรกเกอร์จำลองสำหรับโหมดทดลอง (paper trading).
 *
 * ใช้ "ราคาจริงจากตลาด" แต่เงินเป็นเครดิตเดโม — ผู้ใช้ทดลองกลยุทธ์ได้ก่อนเช่าจริง
 *
 * ตั้งใจให้ผลลัพธ์ "แย่กว่าความจริงเล็กน้อย" ทุกไม้:
 *   - คิดค่าธรรมเนียมเท่าของจริง
 *   - บวก slippage ให้ราคาที่ได้แย่กว่าราคาตลาดเสมอ (ซื้อแพงขึ้น ขายถูกลง)
 * เพราะผลทดลองที่สวยเกินจริงคือสิ่งที่ทำให้ผู้ใช้ตัดสินใจเช่าแล้วผิดหวัง
 * และนั่นแพงกว่าการไม่ได้ลูกค้าคนนั้นมาก
 *
 * Developed by Xman Studio.
 */
class PaperBroker
{
    /**
     * เปิด/เติมไม้ซื้อ.
     *
     * @return array{ok: bool, reason?: string, trade?: AiBotTrade}
     */
    public function buy(AiBotConfig $bot, float $marketPrice, float $budgetUsd, array $context): array
    {
        if ($marketPrice <= 0) {
            return ['ok' => false, 'reason' => 'ไม่มีราคาตลาดให้ใช้คำนวณ'];
        }

        $account = $this->accountFor($bot);
        $budget = min($budgetUsd, (float) $account->balance);

        if ($budget < 1.0) {
            /*
             * แยกสองสาเหตุออกจากกัน — อาการเหมือนกันแต่วิธีแก้คนละเรื่อง
             *
             * งบที่ส่งมาน้อยเองแปลว่ากรอบความเสี่ยงของบอทถูกตั้งไว้ต่ำ (เคยเกิดจาก
             * เพดานทุนของแพลนเป็น 0) ซึ่งผู้ใช้แก้ได้เองที่หน้าตั้งค่าบอท ส่วน
             * "เครดิตทดลองไม่พอ" คือพอร์ตทดลองหมด ต้องกดล้างพอร์ต — บอกผิดทาง
             * แล้วผู้ใช้จะไปกดล้างพอร์ตซ้ำๆ ทั้งที่ไม่ใช่ต้นเหตุ
             */
            $reason = $budgetUsd < 1.0
                ? 'ทุนต่อไม้ที่ตั้งไว้น้อยเกินไป — แก้ได้ที่กรอบความเสี่ยงของบอท'
                : 'เครดิตทดลองไม่พอสำหรับไม้นี้';

            return ['ok' => false, 'reason' => $reason];
        }

        $fillPrice = $this->fillPrice($marketPrice, 'buy');
        $feeRate = (float) config('aibot_risk.demo.fee_rate', 0.1) / 100;

        // ค่าธรรมเนียมหักจากงบก่อน — เงินที่เหลือคือส่วนที่ได้เหรียญจริง
        $fee = $budget * $feeRate;
        $spend = $budget - $fee;
        $quantity = $spend / $fillPrice;

        if ($quantity <= 0) {
            return ['ok' => false, 'reason' => 'จำนวนที่คำนวณได้เป็นศูนย์'];
        }

        return DB::transaction(function () use ($bot, $account, $budget, $fee, $spend, $quantity, $fillPrice, $marketPrice, $context) {
            $account->decrement('balance', $budget);

            $position = AiBotPosition::where('ai_bot_config_id', $bot->id)
                ->where('mode', 'demo')
                ->lockForUpdate()
                ->first();

            if ($position) {
                // เติมไม้ → ต้นทุนเฉลี่ยใหม่จากเงินที่จ่ายไปทั้งหมด
                $newQty = (float) $position->quantity + $quantity;
                $newCost = (float) $position->cost_basis + $budget;

                $position->update([
                    'quantity' => $newQty,
                    'cost_basis' => $newCost,
                    'entry_price' => $newCost / $newQty,
                    'entry_count' => $position->entry_count + 1,
                ]);
            } else {
                AiBotPosition::create([
                    'ai_bot_config_id' => $bot->id,
                    'pair' => $bot->pair,
                    'mode' => 'demo',
                    'quantity' => $quantity,
                    'entry_price' => $budget / $quantity,
                    'cost_basis' => $budget,
                    'entry_count' => 1,
                    'opened_at' => now(),
                ]);
            }

            $trade = AiBotTrade::create([
                'ai_bot_config_id' => $bot->id,
                'wallet_address' => $bot->wallet_address,
                'pair' => $bot->pair,
                'mode' => 'demo',
                'side' => 'buy',
                'price' => $fillPrice,
                'quantity' => $quantity,
                'gross_value' => $spend,
                'fee' => $fee,
                'slippage_cost' => ($fillPrice - $marketPrice) * $quantity,
                'strategy' => $bot->strategy,
                'reason' => $context['reason'] ?? '',
                'signal_meta' => $context['meta'] ?? null,
                'risk_level' => $context['risk_level'] ?? 'calm',
            ]);

            return ['ok' => true, 'trade' => $trade];
        });
    }

    /**
     * ปิดไม้ (ขายทั้งหมด).
     *
     * @return array{ok: bool, reason?: string, trade?: AiBotTrade, pnl?: float}
     */
    public function sell(AiBotConfig $bot, float $marketPrice, array $context): array
    {
        if ($marketPrice <= 0) {
            return ['ok' => false, 'reason' => 'ไม่มีราคาตลาดให้ใช้คำนวณ'];
        }

        $position = AiBotPosition::where('ai_bot_config_id', $bot->id)->where('mode', 'demo')->first();

        if (! $position || (float) $position->quantity <= 0) {
            return ['ok' => false, 'reason' => 'ไม่มีของให้ขาย'];
        }

        $fillPrice = $this->fillPrice($marketPrice, 'sell');
        $feeRate = (float) config('aibot_risk.demo.fee_rate', 0.1) / 100;

        $quantity = (float) $position->quantity;
        $gross = $quantity * $fillPrice;
        $fee = $gross * $feeRate;
        $proceeds = $gross - $fee;
        $pnl = $proceeds - (float) $position->cost_basis;

        return DB::transaction(function () use ($bot, $position, $quantity, $fillPrice, $marketPrice, $gross, $fee, $proceeds, $pnl, $context) {
            $this->accountFor($bot)->increment('balance', $proceeds);

            $trade = AiBotTrade::create([
                'ai_bot_config_id' => $bot->id,
                'wallet_address' => $bot->wallet_address,
                'pair' => $bot->pair,
                'mode' => 'demo',
                'side' => 'sell',
                'price' => $fillPrice,
                'quantity' => $quantity,
                'gross_value' => $gross,
                'fee' => $fee,
                'slippage_cost' => ($marketPrice - $fillPrice) * $quantity,
                'realized_pnl' => $pnl,
                'strategy' => $bot->strategy,
                'reason' => $context['reason'] ?? '',
                'signal_meta' => $context['meta'] ?? null,
                'risk_level' => $context['risk_level'] ?? 'calm',
            ]);

            $position->delete();

            return ['ok' => true, 'trade' => $trade, 'pnl' => $pnl];
        });
    }

    /**
     * ราคาที่ได้จริงหลังบวก slippage — ซื้อแพงขึ้น ขายถูกลงเสมอ.
     */
    public function fillPrice(float $marketPrice, string $side): float
    {
        $slippage = (float) config('aibot_risk.demo.slippage_bps', 8) / 10000;

        return $side === 'buy'
            ? $marketPrice * (1 + $slippage)
            : $marketPrice * (1 - $slippage);
    }

    /**
     * พอร์ตทดลองหนึ่งใบ.
     *
     * @param  string|null  $bucket  รหัสกลยุทธ์ — แต่ละกลยุทธ์มีพอร์ตของตัวเอง
     *                               null = พอร์ตรวม (ของเดิมก่อนแยกพอร์ต)
     *
     * แยกพอร์ตเพราะเดิมทุกกลยุทธ์กินเงินก้อนเดียวกัน กลยุทธ์ที่เข้าไม้ก่อน
     * กินงบไปหมดแล้วตัวอื่นไม่เหลือให้เทรด — เอาผลมาเทียบกันไม่ได้เลยว่า
     * กลยุทธ์ไหนทำได้ดีกว่า ซึ่งเป็นคำถามเดียวที่ต้องตอบก่อนเปิดขาย
     */
    public function account(string $wallet, ?string $bucket = null): AiBotDemoAccount
    {
        $starting = (float) config('aibot_risk.demo.starting_balance', 10000);

        return AiBotDemoAccount::firstOrCreate(
            ['wallet_address' => strtolower($wallet), 'bucket' => $bucket],
            ['balance' => $starting, 'starting_balance' => $starting],
        );
    }

    /** พอร์ตของบอทตัวนี้ — ผูกกับกลยุทธ์ ไม่ใช่ตัวบอท (บอทหลายตัวของกลยุทธ์เดียวกันใช้พอร์ตร่วม) */
    private function accountFor(AiBotConfig $bot): AiBotDemoAccount
    {
        return $this->account($bot->wallet_address, $bot->strategy);
    }

    /**
     * ล้างพอร์ตทดลองกลับไปตั้งต้น (ปิดไม้ทั้งหมด ล้างประวัติ).
     *
     * @return array{ok: bool, reason?: string}
     */
    public function reset(string $wallet): array
    {
        $wallet = strtolower($wallet);
        $maxPerDay = (int) config('aibot_risk.demo.max_resets_per_day', 3);

        /*
         * โควตาการล้างนับรวมทั้งกระเป๋า ไม่ใช่ต่อพอร์ต
         *
         * นับต่อพอร์ตแล้วผู้ใช้จะล้างได้ 8 กลยุทธ์ × 3 ครั้ง = 24 ครั้งต่อวัน
         * ซึ่งพอสำหรับไล่หาผลลัพธ์สวยๆ แล้วเอาไปอ้างว่ากลยุทธ์นี้ดี — ตรงข้าม
         * กับเหตุผลที่มีโควตาตั้งแต่แรก
         */
        $accounts = AiBotDemoAccount::where('wallet_address', $wallet)->get();

        /*
         * ยังไม่เคยมีพอร์ตเลย = สร้างพอร์ตรวมไว้หนึ่งใบก่อน
         *
         * ไม่งั้นโควตาการล้างไม่มีที่เก็บ — วนล้างได้ไม่จำกัดครั้งเพราะ reset_count
         * ไม่เคยถูกเขียนลงไหนเลย (ด่านที่ตั้งใจกันการไล่หาผลลัพธ์สวยๆ หายไปเงียบๆ)
         */
        if ($accounts->isEmpty()) {
            $accounts = collect([$this->account($wallet)]);
        }

        $usedToday = (int) $accounts
            ->filter(fn (AiBotDemoAccount $a) => $a->last_reset_at?->isToday())
            ->max('reset_count');

        if ($usedToday >= $maxPerDay) {
            return ['ok' => false, 'reason' => "ล้างพอร์ตทดลองได้วันละ {$maxPerDay} ครั้ง"];
        }

        DB::transaction(function () use ($wallet, $accounts, $usedToday) {
            $botIds = AiBotConfig::where('wallet_address', $wallet)->pluck('id');

            AiBotPosition::whereIn('ai_bot_config_id', $botIds)->where('mode', 'demo')->delete();
            AiBotTrade::whereIn('ai_bot_config_id', $botIds)->where('mode', 'demo')->delete();

            // ล้างทุกพอร์ตพร้อมกัน — ล้างทีละใบทำให้เทียบกลยุทธ์กันไม่ได้
            // เพราะแต่ละพอร์ตเริ่มนับจากคนละจุดเวลา
            foreach ($accounts as $account) {
                $account->update([
                    'balance' => $account->starting_balance,
                    'reset_count' => $usedToday + 1,
                    'last_reset_at' => now(),
                ]);
            }
        });

        return ['ok' => true];
    }
}
