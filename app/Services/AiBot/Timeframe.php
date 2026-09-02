<?php

namespace App\Services\AiBot;

/**
 * TPIX TRADE — แปลงรหัส timeframe เป็นหน่วยเวลา.
 *
 * ตารางเดียวกันนี้เคยถูกประกาศซ้ำใน BotRunner และ MarketRiskService (private const)
 * ตัว backtester ต้องใช้ด้วย จึงยกมาไว้ที่เดียว — ค่าไม่ตรงกันระหว่างสองที่คือ
 * บั๊กประเภทที่ไม่มี error ให้เห็น แค่คำนวณรอบผิดเงียบๆ
 *
 * เก็บเป็น "นาทีต่อแท่ง" เพราะเป็นจำนวนเต็มทุกค่า (แท่งต่อชั่วโมงของ 1d = 0.0417
 * ซึ่งปัดเศษแล้วคลาดเคลื่อนสะสม)
 *
 * Developed by Xman Studio.
 */
final class Timeframe
{
    public const MINUTES = ['1m' => 1, '5m' => 5, '15m' => 15, '1h' => 60, '4h' => 240, '1d' => 1440];

    /** นาทีต่อแท่ง — รหัสที่ไม่รู้จักถือเป็น 1 ชั่วโมง (ค่าปริยายเดิมของ engine) */
    public static function minutes(string $timeframe): int
    {
        return self::MINUTES[$timeframe] ?? 60;
    }

    public static function milliseconds(string $timeframe): int
    {
        return self::minutes($timeframe) * 60_000;
    }

    public static function isKnown(string $timeframe): bool
    {
        return isset(self::MINUTES[$timeframe]);
    }
}
