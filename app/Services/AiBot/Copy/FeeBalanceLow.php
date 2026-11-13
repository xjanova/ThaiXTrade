<?php

namespace App\Services\AiBot\Copy;

use RuntimeException;

/**
 * TPIX TRADE — กดตามมาสเตอร์ไม่ได้เพราะคลัง TPIX สำหรับค่าบริการไม่พอ หรือยังมีค่าบริการค้างจ่าย.
 *
 * พกตัวเลขของด่าน (ยอดที่มี · ที่ต้องมี · ที่ขาด · ที่ค้าง) ไปด้วย เพื่อให้คำเตือนบอกผู้ใช้ได้ว่าต้องเติมอีกเท่าไหร่
 * ข้อความของ exception เป็นรหัสเดียวกับ error อื่นของ Copy Trade — โค้ดที่จับ RuntimeException อยู่เดิมยังทำงานเหมือนเดิม
 *
 * Developed by Xman Studio.
 */
final class FeeBalanceLow extends RuntimeException
{
    /**
     * @param  array{collect: bool, ok: bool, balance: float, required: float, shortfall: float, owed: array{count: int, usd: float, tpix: float}}  $gate
     */
    public function __construct(public readonly array $gate)
    {
        parent::__construct(FeeCollector::ERR_BALANCE_LOW);
    }
}
