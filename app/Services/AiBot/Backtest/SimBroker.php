<?php

namespace App\Services\AiBot\Backtest;

/**
 * TPIX TRADE — โบรกเกอร์จำลองในหน่วยความจำ คณิตศาสตร์เดียวกับ PaperBroker ทุกบรรทัด.
 *
 * ⚠️ ถ้าแก้สูตรค่าธรรมเนียม/slippage/ต้นทุนเฉลี่ยใน PaperBroker ต้องแก้ที่นี่ด้วย
 *    SimBrokerParityTest ยิงทั้งสองตัวด้วยตัวเลขเดียวกันแล้วเทียบผลถึงทศนิยมที่ 8
 *    — เทสต์นั้นคือสิ่งเดียวที่รับประกันว่าผล backtest คือผลที่ผู้ใช้จะได้จริง
 *
 * ต่างจาก PaperBroker แค่ไม่แตะฐานข้อมูล: backtest 90 วันมีหลายพันรอบ
 * เขียน DB ทุกรอบช้าและไม่จำเป็น (ไม่มีใครต้องเห็นไม้ระหว่างทาง)
 *
 * Developed by Xman Studio.
 */
final class SimBroker
{
    public float $cash;

    /** @var array{qty: float, cost: float, entries: int, opened_index: int, opened_time: int}|null */
    public ?array $position = null;

    /** @var list<array<string, mixed>> */
    public array $trades = [];

    public function __construct(
        float $startingCash,
        private readonly float $feeRate,      // สัดส่วน เช่น 0.001 = 0.1%
        private readonly float $slippage,     // สัดส่วน เช่น 0.0008 = 8 bps
    ) {
        $this->cash = $startingCash;
    }

    /** ราคาที่ได้จริงหลังบวก slippage — ซื้อแพงขึ้น ขายถูกลงเสมอ (= PaperBroker::fillPrice) */
    public function fillPrice(float $marketPrice, string $side): float
    {
        return $side === 'buy'
            ? $marketPrice * (1 + $this->slippage)
            : $marketPrice * (1 - $this->slippage);
    }

    /**
     * เปิด/เติมไม้ซื้อ — คืน false เมื่อเข้าไม่ได้ (งบไม่พอ) เหมือน PaperBroker::buy.
     */
    public function buy(int $index, int $time, float $marketPrice, float $budgetUsd, string $reason): bool
    {
        if ($marketPrice <= 0) {
            return false;
        }

        $budget = min($budgetUsd, $this->cash);

        if ($budget < 1.0) {
            return false;
        }

        $fillPrice = $this->fillPrice($marketPrice, 'buy');

        // ค่าธรรมเนียมหักจากงบก่อน — เงินที่เหลือคือส่วนที่ได้เหรียญจริง
        $fee = $budget * $this->feeRate;
        $spend = $budget - $fee;
        $quantity = $spend / $fillPrice;

        if ($quantity <= 0) {
            return false;
        }

        $this->cash -= $budget;
        $buyCost = $fee + ($fillPrice - $marketPrice) * $quantity;

        if ($this->position) {
            // เติมไม้ → ต้นทุนเฉลี่ยใหม่จากเงินที่จ่ายไปทั้งหมด
            $this->position['qty'] += $quantity;
            $this->position['cost'] += $budget;
            $this->position['costs'] += $buyCost;
            $this->position['entries']++;
        } else {
            $this->position = [
                'qty' => $quantity,
                'cost' => $budget,
                // ค่าธรรมเนียม + slippage ขาซื้อสะสม — ไว้คิด edge ก่อนหักต้นทุนตอนปิด
                'costs' => $buyCost,
                'entries' => 1,
                'opened_index' => $index,
                'opened_time' => $time,
            ];
        }

        $this->trades[] = [
            'index' => $index,
            'time' => $time,
            'side' => 'buy',
            'price' => $fillPrice,
            'market_price' => $marketPrice,
            'quantity' => $quantity,
            'gross_value' => $spend,
            'fee' => $fee,
            'slippage_cost' => ($fillPrice - $marketPrice) * $quantity,
            'realized_pnl' => null,
            'reason' => $reason,
        ];

        return true;
    }

    /**
     * ปิดไม้ทั้งหมด — คืนกำไร/ขาดทุนสุทธิ (null = ไม่มีของให้ขาย).
     */
    public function sell(int $index, int $time, float $marketPrice, string $reason): ?float
    {
        if ($marketPrice <= 0 || ! $this->position || $this->position['qty'] <= 0) {
            return null;
        }

        $fillPrice = $this->fillPrice($marketPrice, 'sell');
        $quantity = $this->position['qty'];
        $gross = $quantity * $fillPrice;
        $fee = $gross * $this->feeRate;
        $proceeds = $gross - $fee;
        $pnl = $proceeds - $this->position['cost'];

        $this->cash += $proceeds;

        $this->trades[] = [
            'index' => $index,
            'time' => $time,
            'side' => 'sell',
            'price' => $fillPrice,
            'market_price' => $marketPrice,
            'quantity' => $quantity,
            'gross_value' => $gross,
            'fee' => $fee,
            'slippage_cost' => ($marketPrice - $fillPrice) * $quantity,
            'realized_pnl' => $pnl,
            'cost_basis' => $this->position['cost'],
            'buy_costs' => $this->position['costs'],
            'entries' => $this->position['entries'],
            'held_bars' => $index - $this->position['opened_index'],
            'reason' => $reason,
        ];

        $this->position = null;

        return $pnl;
    }

    /** ต้นทุนเฉลี่ยต่อหน่วย (รวมค่าธรรมเนียม + slippage ขาซื้อแล้ว) — = entry_price ของ PaperBroker */
    public function entryPrice(): ?float
    {
        if (! $this->position || $this->position['qty'] <= 0) {
            return null;
        }

        return $this->position['cost'] / $this->position['qty'];
    }

    /** มูลค่าพอร์ต ณ ราคาตลาด (เงินสด + ของที่ถือ ตีราคาตลาดตรงๆ ยังไม่หักต้นทุนขาออก) */
    public function equity(float $marketPrice): float
    {
        return $this->cash + ($this->position ? $this->position['qty'] * $marketPrice : 0.0);
    }

    public function unrealizedPnl(float $marketPrice): float
    {
        return $this->position ? $this->position['qty'] * $marketPrice - $this->position['cost'] : 0.0;
    }
}
