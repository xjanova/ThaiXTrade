<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

/**
 * StripeWebhookController — รับ Stripe webhook events.
 *
 * Stripe จะส่ง event มาเมื่อชำระเงินสำเร็จ → สร้าง SaleTransaction อัตโนมัติ.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private StripePaymentService $stripe,
    ) {
        //
    }

    /**
     * รับ Stripe Webhook — ตรวจสอบ signature แล้วประมวลผล.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $result = $this->stripe->handleWebhook($payload, $signature);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', [
                'error' => 'Operation failed. Please try again.',
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Webhook signature verification failed'],
            ], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook processing error', [
                'error' => 'Operation failed. Please try again.',
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'WEBHOOK_ERROR', 'message' => 'Failed to process webhook'],
            ], 500);
        }
    }
}
