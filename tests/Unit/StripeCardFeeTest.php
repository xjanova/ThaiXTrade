<?php

namespace Tests\Unit;

use App\Services\StripePaymentService;
use PHPUnit\Framework\TestCase;

/**
 * ค่าบริการชำระด้วยบัตร 15% (เจ้าของกำหนด 2026-10-21)
 *
 * สิ่งที่ห้ามพัง: ค่าบริการต้อง "บวกเพิ่ม" ในยอดที่ Stripe เรียกเก็บ แต่ต้องไม่ถูกนับ
 * เป็นค่าเหรียญตอนออกเหรียญ — เดิม webhook หาร amount_total ด้วยราคาเหรียญตรงๆ
 * ถ้ายอดรวมมีค่าบริการ ผู้ซื้อจะได้เหรียญเกินไป 15% ทุกรายการ
 *
 * Developed by Xman Studio.
 */
class StripeCardFeeTest extends TestCase
{
    public function test_fee_is_fifteen_percent_of_token_amount_in_cents(): void
    {
        $this->assertSame(75, StripePaymentService::feeCents(500, 15));
        $this->assertSame(1500, StripePaymentService::feeCents(10000, 15));
        $this->assertSame(0, StripePaymentService::feeCents(500, 0));
        // ปัดเป็นเซ็นต์แบบเดียวกับหน้าเว็บ (Math.round): 18.45 → 18 · 26.25 → 26
        $this->assertSame(18, StripePaymentService::feeCents(123, 15));
        $this->assertSame(26, StripePaymentService::feeCents(175, 15));
    }

    public function test_token_cents_exclude_the_fee_line(): void
    {
        $items = [
            ['amount_total' => 500, 'price' => ['product' => ['metadata' => ['kind' => 'tpix_token']]]],
            ['amount_total' => 75, 'price' => ['product' => ['metadata' => ['kind' => StripePaymentService::FEE_LINE_KIND]]]],
        ];

        $this->assertSame(500, StripePaymentService::tokenCentsFromLineItems($items));
    }

    public function test_sessions_created_before_the_fee_still_count_in_full(): void
    {
        // session เก่าไม่มี metadata kind และ product ไม่ได้ expand (เป็นแค่ id)
        $items = [
            ['amount_total' => 500, 'price' => ['product' => 'prod_old123']],
        ];

        $this->assertSame(500, StripePaymentService::tokenCentsFromLineItems($items));
    }

    public function test_accepts_stripe_objects_not_just_arrays(): void
    {
        $item = (object) [
            'amount_total' => 75,
            'price' => (object) ['product' => (object) ['metadata' => (object) ['kind' => StripePaymentService::FEE_LINE_KIND]]],
        ];
        $token = (object) [
            'amount_total' => 2000,
            'price' => (object) ['product' => (object) ['metadata' => (object) ['kind' => 'tpix_token']]],
        ];

        $this->assertSame(2000, StripePaymentService::tokenCentsFromLineItems([$token, $item]));
    }
}
