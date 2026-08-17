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

        $account = $this->account($bot->wallet_address);
        $budget = min($budgetUsd, (float) $account->balance);

        if ($budget < 1.0) {
            return ['ok' => false, 'reason' => 'เครดิตทดลองไม่พอสำหรับไม้นี้'];
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
            $this->account($bot->wallet_address)->increment('balance', $proceeds);

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

    /** กระเป๋าทดลองของ wallet — สร้างพร้อมเงินตั้งต้นถ้ายังไม่มี */
    public function account(string $wallet): AiBotDemoAccount
    {
        $starting = (float) config('aibot_risk.demo.starting_balance', 10000);

        return AiBotDemoAccount::firstOrCreate(
            ['wallet_address' => strtolower($wallet)],
            ['balance' => $starting, 'starting_balance' => $starting],
        );
    }

    /**
     * ล้างพอร์ตทดลองกลับไปตั้งต้น (ปิดไม้ทั้งหมด ล้างประวัติ).
     *
     * @return array{ok: bool, reason?: string}
     */
    public function reset(string $wallet): array
    {
        $wallet = strtolower($wallet);
        $account = $this->account($wallet);
        $maxPerDay = (int) config('aibot_risk.demo.max_resets_per_day', 3);

        // นับเฉพาะการล้างของวันนี้ — ป้องกันการล้างรัวเพื่อไล่หาผลลัพธ์สวยๆ
        if ($account->last_reset_at?->isToday() && $account->reset_count >= $maxPerDay) {
            return ['ok' => false, 'reason' => "ล้างพอร์ตทดลองได้วันละ {$maxPerDay} ครั้ง"];
        }

        DB::transaction(function () use ($wallet, $account) {
            $botIds = AiBotConfig::where('wallet_address', $wallet)->pluck('id');

            AiBotPosition::whereIn('ai_bot_config_id', $botIds)->where('mode', 'demo')->delete();
            AiBotTrade::whereIn('ai_bot_config_id', $botIds)->where('mode', 'demo')->delete();

            $account->update([
                'balance' => $account->starting_balance,
                'reset_count' => $account->last_reset_at?->isToday() ? $account->reset_count + 1 : 1,
                'last_reset_at' => now(),
            ]);
        });

        return ['ok' => true];
    }
}
