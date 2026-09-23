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

    /**
     * ⭐ DCA "เพิ่มไม้เมื่อราคาย่อ" ต้องได้ไม้ใหญ่ขึ้นจริง.
     *
     * กลยุทธ์ให้ strength 0.7–1.0 ตอนย่อ พร้อมเหตุผล "ราคาย่อ — เพิ่มไม้" แต่เดิมงบคงที่เสมอ
     * (R3: ทุกไม้ $24.98) — ฟีเจอร์ที่เทมเพลตโฆษณาไว้ไม่เคยเกิดขึ้นจริง
     */
    #[Test]
    public function dca_ซื้อหนักขึ้นตอนราคาย่อ_แต่เพดานทุนยังคุม(): void
    {
        $risk = ['max_position_usd' => 100];

        $this->assertSame(25.0, PositionSizer::budget('dca', $risk, ['budget_usd' => 25], 0.5, 1.0), 'รอบปกติ = งบที่ตั้ง');
        $this->assertSame(35.0, PositionSizer::budget('dca', $risk, ['budget_usd' => 25], 0.7, 1.0), 'ย่อถึงเกณฑ์ = ×1.4');
        $this->assertSame(50.0, PositionSizer::budget('dca', $risk, ['budget_usd' => 25], 1.0, 1.0), 'ย่อลึก = ×2');
        $this->assertSame(80.0, PositionSizer::budget('dca', ['max_position_usd' => 80], ['budget_usd' => 60], 1.0, 1.0), 'เพดานทุนชนะเสมอ');
        $this->assertSame(20.0, PositionSizer::budget('grid', $risk, ['order_size_usd' => 20], 1.0, 1.0), 'กริดยังใช้ขนาดคงที่ต่อชั้น');
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
