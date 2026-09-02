<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\PositionSizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — สูตรขนาดไม้ที่ BotRunner กับ backtester ใช้ร่วมกัน.
 *
 * Developed by Xman Studio.
 */
class PositionSizerTest extends TestCase
{
    #[Test]
    public function กลยุทธ์ที่ไม่มีช่องขนาดไม้ใช้เพดานทุน_x_ความแรง_x_ตัวคูณ(): void
    {
        $this->assertSame(30.0, PositionSizer::budget('momentum', ['max_position_usd' => 100], [], 0.6, 0.5));
    }

    #[Test]
    public function ช่องขนาดไม้ใช้เต็มจำนวน_ไม่คูณความแรง_แต่ยังคูณตัวคูณความเสี่ยง(): void
    {
        // DCA คืน strength 0.5 ในรอบปกติ — ห้ามทำให้งบ $25 กลายเป็น $12.50
        $this->assertSame(25.0, PositionSizer::budget('dca', ['max_position_usd' => 100], ['budget_usd' => 25], 0.5, 1.0));
        $this->assertSame(15.0, PositionSizer::budget('dca', ['max_position_usd' => 100], ['budget_usd' => 25], 0.5, 0.6));
    }

    #[Test]
    public function เพดานทุนยังชนะช่องขนาดไม้เสมอ(): void
    {
        $this->assertSame(100.0, PositionSizer::budget('grid', ['max_position_usd' => 100], ['order_size_usd' => 500], 1.0, 1.0));
    }

    #[Test]
    public function ค่าดิบที่บันทึกไว้เป็นตัวสำรองเมื่อค่าที่ล้างแล้วไม่มี(): void
    {
        $this->assertSame(20.0, PositionSizer::orderSizeFor('grid', [], ['order_size_usd' => 20]));
        $this->assertNull(PositionSizer::orderSizeFor('momentum', [], ['order_size_usd' => 20]));
        $this->assertNull(PositionSizer::orderSizeFor('grid', ['order_size_usd' => 0], []));
    }
}
