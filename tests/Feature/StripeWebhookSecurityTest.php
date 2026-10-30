<?php

namespace Tests\Feature;

use App\Models\SaleTransaction;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE — Stripe Webhook Security Tests
 *
 * เคยเป็น "ประตูที่สอง" ที่ข้ามด่านตรวจการชำระเงินบน BSC ได้ทั้งเส้น:
 * webhook secret ว่าง แต่ไลบรารีของ Stripe คำนวณ HMAC ด้วยกุญแจว่างได้ปกติ
 * ใครก็เซ็น header เองแล้วส่ง payload ปลอมมาสร้างรายการซื้อสถานะ confirmed
 * ได้ฟรีโดยไม่ต้องจ่ายเงินเลย
 *
 * Developed by Xman Studio.
 */
class StripeWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ไม่ได้ตั้ง webhook secret → ต้องปฏิเสธทันที ไม่ใช่คำนวณ HMAC ด้วยกุญแจว่าง
     */
    public function test_webhook_is_rejected_when_secret_not_configured(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_live_fake']],
        ]);

        // ลายเซ็นที่ผู้โจมตีคำนวณเองด้วยกุญแจว่าง
        $t = time();
        $forged = hash_hmac('sha256', "{$t}.{$payload}", '');

        $this->expectException(\RuntimeException::class);
        app(StripePaymentService::class)->handleWebhook($payload, "t={$t},v1={$forged}");
    }

    /**
     * secret ที่ไม่ใช่รูปแบบของ Stripe (whsec_) ก็ต้องไม่ผ่าน
     */
    public function test_webhook_is_rejected_when_secret_has_wrong_format(): void
    {
        config(['services.stripe.webhook_secret' => 'not-a-real-secret']);

        $payload = '{"type":"checkout.session.completed"}';
        $t = time();
        $forged = hash_hmac('sha256', "{$t}.{$payload}", 'not-a-real-secret');

        $this->expectException(\RuntimeException::class);
        app(StripePaymentService::class)->handleWebhook($payload, "t={$t},v1={$forged}");
    }

    /**
     * ต่อให้ยิง endpoint จริง ก็ต้องไม่มีรายการซื้อเกิดขึ้น
     */
    public function test_forged_webhook_creates_no_sale_transaction(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_live_forged',
                'amount_total' => 100,
                'payment_status' => 'paid',
                'metadata' => [
                    'wallet_address' => '0x'.str_repeat('ab', 20),
                    'phase_id' => 1,
                    'sale_id' => 1,
                    'tpix_amount' => 50000000,
                    'price_per_tpix' => 0.1,
                ],
            ]],
        ]);

        $t = time();
        $forged = hash_hmac('sha256', "{$t}.{$payload}", '');

        $this->postJson('/api/v1/stripe/webhook', json_decode($payload, true), [
            'Stripe-Signature' => "t={$t},v1={$forged}",
        ]);

        $this->assertSame(0, SaleTransaction::count(),
            'webhook ปลอมต้องไม่สร้างรายการซื้อใดๆ');
    }

    /**
     * เว็บจริงต้องไม่ประมวลผลเหตุการณ์โหมดทดสอบ แม้ลายเซ็นถูกต้อง — ไม่งั้นบัตรทดสอบออกเหรียญจริงได้
     */
    public function test_test_mode_event_is_ignored_in_production(): void
    {
        $this->app['env'] = 'production';

        $result = app(StripePaymentService::class)->handleWebhook(...$this->signedExpiredEvent(livemode: false));

        $this->assertSame('ignored', $result['status']);
    }

    /**
     * เหตุการณ์จริงบนเว็บจริงยังผ่านด่านไปถึงตัวจัดการตามปกติ
     */
    public function test_live_event_is_handled_in_production(): void
    {
        $this->app['env'] = 'production';

        $result = app(StripePaymentService::class)->handleWebhook(...$this->signedExpiredEvent(livemode: true));

        $this->assertSame('expired', $result['status']);
    }

    /**
     * เครื่องพัฒนาต้องยังทดสอบ webhook ด้วย Stripe CLI ได้
     */
    public function test_test_mode_event_is_handled_outside_production(): void
    {
        $result = app(StripePaymentService::class)->handleWebhook(...$this->signedExpiredEvent(livemode: false));

        $this->assertSame('expired', $result['status']);
    }

    /** @return array{string, string} payload กับลายเซ็นที่ถูกต้องของ session ที่หมดอายุ */
    private function signedExpiredEvent(bool $livemode): array
    {
        config(['services.stripe.webhook_secret' => 'whsec_unit_test']);

        $payload = json_encode([
            'id' => 'evt_unit_test',
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'livemode' => $livemode,
            'data' => ['object' => ['id' => 'cs_unit_test', 'object' => 'checkout.session', 'metadata' => []]],
        ]);

        $t = time();

        return [$payload, "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_unit_test')];
    }
}
