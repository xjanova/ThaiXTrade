<?php

namespace App\Services\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotReserve;
use Illuminate\Support\Facades\DB;

/**
 * TPIX TRADE — จัดการคลังเหรียญสองฝั่งของบอทอาร์บิทราจ.
 *
 * ทำไมอาร์บิทราจต้องมีคลังต่างหาก:
 * กลยุทธ์อื่นถือเงินสดแล้วค่อยซื้อของเมื่อเจอสัญญาณ แต่ส่วนต่างราคาปิดเร็วมาก
 * กว่าจะซื้อของมาขายก็หมดโอกาสแล้ว จึงต้องมีของค้างไว้ทั้งสองฝั่งตลอดเวลา
 * พอเห็นส่วนต่างก็ขายฝั่งแพงและซื้อฝั่งถูกได้ทันทีในจังหวะเดียว
 *
 * แบ่งทุนครึ่งหนึ่งเป็นเหรียญ อีกครึ่งเป็นเงิน — สัดส่วนนี้ให้ "กระสุน" เท่ากันทั้งสองทิศ
 * ถ้าเอียงไปทางใดทางหนึ่ง บอทจะจับส่วนต่างได้แค่ทิศเดียวแล้วพลาดอีกทิศไปเปล่าๆ
 *
 * Developed by Xman Studio.
 */
class ReserveService
{
    /** สัดส่วนที่แปลงเป็นเหรียญตอนตั้งคลัง (ที่เหลือเก็บเป็นเงิน) */
    private const BASE_SHARE = 0.5;

    /** ราคาอ้างอิงเฉลี่ยถ่วงน้ำหนักเมื่อเติมทุนหลายรอบ */
    private function blendedReference(AiBotReserve $reserve, float $addedQuote, float $price): float
    {
        $existingFunded = (float) ($reserve->funded_quote ?? 0);
        $existingPrice = (float) ($reserve->reference_price ?? 0);

        if ($existingFunded <= 0 || $existingPrice <= 0) {
            return $price;
        }

        return (($existingPrice * $existingFunded) + ($price * $addedQuote)) / ($existingFunded + $addedQuote);
    }

    public function find(AiBotConfig $bot): ?AiBotReserve
    {
        return AiBotReserve::where('ai_bot_config_id', $bot->id)
            ->where('mode', $bot->mode)
            ->first();
    }

    /**
     * ตั้งคลังจากเงินทดลอง แบ่งครึ่งเป็นเหรียญครึ่งเป็นเงิน.
     *
     * @return array{ok: bool, reason?: string, reserve?: AiBotReserve}
     */
    public function fund(AiBotConfig $bot, float $quoteAmount, float $price): array
    {
        if ($price <= 0) {
            return ['ok' => false, 'reason' => 'ไม่มีราคาตลาดให้ใช้แบ่งคลัง'];
        }

        if ($quoteAmount <= 0) {
            return ['ok' => false, 'reason' => 'จำนวนเงินที่จะใส่ต้องมากกว่าศูนย์'];
        }

        if ($bot->mode !== 'demo') {
            // โหมดจริงต้องให้ผู้ใช้เซ็นโอนเองเข้าคลัง — ระบบไม่ถือกุญแจ
            return ['ok' => false, 'reason' => 'โหมดจริงต้องให้ผู้ใช้โอนเข้าคลังเอง'];
        }

        $account = AiBotDemoAccount::where('wallet_address', strtolower($bot->wallet_address))->first();

        if (! $account || (float) $account->balance < $quoteAmount) {
            return ['ok' => false, 'reason' => 'เครดิตทดลองไม่พอสำหรับตั้งคลัง'];
        }

        return DB::transaction(function () use ($bot, $quoteAmount, $price, $account) {
            $account->decrement('balance', $quoteAmount);

            $toBase = $quoteAmount * self::BASE_SHARE;
            $baseQty = $toBase / $price;

            $reserve = AiBotReserve::firstOrNew([
                'ai_bot_config_id' => $bot->id,
                'mode' => $bot->mode,
            ]);

            $reserve->fill([
                'wallet_address' => strtolower($bot->wallet_address),
                'pair' => $bot->pair,
                'base_qty' => (float) ($reserve->base_qty ?? 0) + $baseQty,
                'quote_amount' => (float) ($reserve->quote_amount ?? 0) + ($quoteAmount - $toBase),
                'funded_quote' => (float) ($reserve->funded_quote ?? 0) + $quoteAmount,
                // เติมทุนหลายรอบ → ราคาอ้างอิงเป็นค่าเฉลี่ยถ่วงน้ำหนักตามเงินที่ใส่
                'reference_price' => $this->blendedReference($reserve, $quoteAmount, $price),
                'last_action_at' => now(),
            ])->save();

            return ['ok' => true, 'reserve' => $reserve->fresh()];
        });
    }

    /**
     * คืนทุนจากคลังกลับเข้าเครดิตทดลอง (ขายเหรียญที่เหลือตามราคาปัจจุบัน).
     *
     * @return array{ok: bool, reason?: string, returned?: float}
     */
    public function release(AiBotConfig $bot, float $price): array
    {
        $reserve = $this->find($bot);

        if (! $reserve) {
            return ['ok' => false, 'reason' => 'ยังไม่มีคลังของบอทตัวนี้'];
        }

        if ($price <= 0) {
            return ['ok' => false, 'reason' => 'ไม่มีราคาตลาดให้ใช้ตีมูลค่า'];
        }

        return DB::transaction(function () use ($bot, $reserve, $price) {
            $returned = $reserve->totalValue($price);

            AiBotDemoAccount::where('wallet_address', strtolower($bot->wallet_address))
                ->increment('balance', $returned);

            $reserve->delete();

            return ['ok' => true, 'returned' => round($returned, 8)];
        });
    }

    /**
     * ลงมือจับส่วนต่างหนึ่งครั้ง.
     *
     * side = 'sell_base' → ฝั่งเหรียญแพงกว่า: ขายเหรียญออก รับเงินเข้า
     * side = 'buy_base'  → ฝั่งเหรียญถูกกว่า: จ่ายเงินออก รับเหรียญเข้า
     *
     * ⚠️ ไม่ยอมให้ยอดฝั่งใดติดลบ — คลังที่หมดฝั่งหนึ่งคือคลังที่ทำอาร์บิทราจต่อไม่ได้
     *
     * @return array{ok: bool, reason?: string, filled?: float, pnl?: float}
     */
    public function execute(AiBotConfig $bot, string $side, float $price, float $notional, float $feeRate = 0.001): array
    {
        $reserve = $this->find($bot);

        if (! $reserve) {
            return ['ok' => false, 'reason' => 'ยังไม่ได้ตั้งคลังสำรอง'];
        }

        if ($price <= 0 || $notional <= 0) {
            return ['ok' => false, 'reason' => 'ราคาหรือขนาดไม้ไม่ถูกต้อง'];
        }

        return DB::transaction(function () use ($reserve, $side, $price, $notional, $feeRate) {
            $fee = $notional * $feeRate;

            if ($side === 'sell_base') {
                $qty = $notional / $price;

                if ($qty > (float) $reserve->base_qty) {
                    return ['ok' => false, 'reason' => 'เหรียญในคลังไม่พอสำหรับไม้นี้'];
                }

                $reserve->base_qty = (float) $reserve->base_qty - $qty;
                $reserve->quote_amount = (float) $reserve->quote_amount + ($notional - $fee);
            } elseif ($side === 'buy_base') {
                if ($notional > (float) $reserve->quote_amount) {
                    return ['ok' => false, 'reason' => 'เงินในคลังไม่พอสำหรับไม้นี้'];
                }

                $qty = ($notional - $fee) / $price;
                $reserve->quote_amount = (float) $reserve->quote_amount - $notional;
                $reserve->base_qty = (float) $reserve->base_qty + $qty;
            } else {
                return ['ok' => false, 'reason' => "ไม่รู้จักทิศทาง {$side}"];
            }

            /*
             * ⭐ วัดกำไรที่ "ราคาอ้างอิงตอนใส่ทุน" ไม่ใช่ราคาปัจจุบัน
             *
             * อาร์บิทราจไม่มีไม้ปิดแบบกลยุทธ์อื่น — มันหมุนของไปมาเพื่อสะสมมูลค่า
             * ถ้าตีมูลค่าด้วยราคาปัจจุบัน ตัวเลขจะแกว่งตามทิศทางตลาดจนอ่านไม่ออกว่า
             * บอทจับส่วนต่างได้จริงหรือแค่ราคาขึ้น (วัดแล้วพบว่าซื้อถูกขายแพงสำเร็จ
             * แต่ราคาร่วง 100 → 95 ทำให้ตัวเลขออกมาติดลบ ทั้งที่ได้เหรียญเพิ่ม)
             *
             * ตรึงราคาไว้ที่ราคาอ้างอิง = เห็นเฉพาะมูลค่าที่เกิดจากการหมุนของจริงๆ
             */
            $reference = (float) $reserve->reference_price ?: $price;
            $reserve->realized_pnl = $reserve->totalValue($reference) - (float) $reserve->funded_quote;
            $reserve->round_trips = $reserve->round_trips + 1;
            $reserve->last_action_at = now();
            $reserve->save();

            return [
                'ok' => true,
                'filled' => round($qty, 12),
                'pnl' => round((float) $reserve->realized_pnl, 8),
            ];
        });
    }
}
