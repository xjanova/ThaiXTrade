<?php

namespace App\Rules;

use App\Models\TokenSale;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ช่องทางรับเงินของรอบขายที่บันทึกจากหลังบ้านได้.
 *
 * ต้องมีบัตรเสมอ และค่าอื่นต้องอยู่ใน TokenSale::PAYMENT_CHANNELS เท่านั้น
 * USDT หรือ BNB เปิดทางยื่น tx_hash แบบเก่าที่ปิดไปแล้ว ส่วนค่าว่างยิ่งแย่กว่า
 * เพราะ TokenSaleService::assertCurrencyAccepted() ถือว่าไม่จำกัดสกุลเงิน
 *
 * Developed by Xman Studio.
 */
class SalePaymentChannels implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $channels = is_array($value) ? $value : [];
        $unknown = array_filter($channels, fn (mixed $channel) => ! in_array($channel, TokenSale::PAYMENT_CHANNELS, true));

        if (! in_array(TokenSale::REQUIRED_CHANNEL, $channels, true) || $unknown !== []) {
            $fail('ช่องทางรับเงินต้องมีบัตรเครดิต และเลือกเพิ่มได้เฉพาะโอนเงินธนาคารหรือ USDT ที่ตรวจเงินเข้าบนเชน');
        }
    }
}
