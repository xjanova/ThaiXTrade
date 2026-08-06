<?php

namespace Tests\Unit;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * TPIX TRADE - Order fill-state Tests
 *
 * กันการถอยกลับของบั๊กที่ Order เคยประกาศ isFillable(): bool ทับเมธอดของ
 * Eloquent (Model::isFillable(string $key): bool) ซึ่งผิด LSP ทำให้ PHP
 * fatal error ตั้งแต่ตอน link คลาส — ทุก request ที่แตะ Order พังทั้งหมด
 *
 * Developed by Xman Studio.
 */
class OrderFillableTest extends TestCase
{
    private function orderWithStatus(string $status): Order
    {
        $order = new Order();
        $order->status = $status;

        return $order;
    }

    // =========================================================================
    // สัญญากับคลาสแม่ของ Eloquent
    // =========================================================================

    public function test_order_class_loads_without_signature_clash(): void
    {
        // ถ้ามีเมธอดที่ signature ชนคลาสแม่ บรรทัดนี้จะ fatal ก่อนถึง assert
        $this->assertInstanceOf(Model::class, new Order());
    }

    public function test_eloquent_is_fillable_is_left_intact(): void
    {
        $reflection = new \ReflectionMethod(Order::class, 'isFillable');

        $this->assertSame(
            Model::class,
            $reflection->getDeclaringClass()->getName(),
            'Order ต้องไม่ override isFillable() ของ Eloquent'
        );
        $this->assertSame(1, $reflection->getNumberOfParameters());
    }

    public function test_inherited_is_fillable_still_answers_about_attributes(): void
    {
        $order = new Order();

        // เมธอดของ Eloquent รับชื่อคอลัมน์ ไม่ใช่ถามสถานะออเดอร์
        $this->assertIsBool($order->isFillable('status'));
    }

    // =========================================================================
    // canBeFilled — ตรรกะของโดเมน
    // =========================================================================

    public function test_open_order_can_be_filled(): void
    {
        $this->assertTrue($this->orderWithStatus('open')->canBeFilled());
    }

    public function test_partially_filled_order_can_be_filled(): void
    {
        $this->assertTrue($this->orderWithStatus('partially_filled')->canBeFilled());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedStatuses')]
    public function test_closed_order_cannot_be_filled(string $status): void
    {
        $this->assertFalse($this->orderWithStatus($status)->canBeFilled());
    }

    public static function closedStatuses(): array
    {
        return [
            'filled' => ['filled'],
            'cancelled' => ['cancelled'],
            'expired' => ['expired'],
            'rejected' => ['rejected'],
        ];
    }
}
