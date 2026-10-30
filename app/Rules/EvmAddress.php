<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ที่อยู่กระเป๋า EVM ที่รับเงินได้จริง.
 *
 * ปฏิเสธที่อยู่ศูนย์ด้วย — รูปแบบถูกต้องทุกอย่าง แต่เงินที่โอนเข้าไปหายถาวรเพราะไม่มีใครเซ็นออกได้
 * ใช้ \z แทน $ เพราะ $ ยอมให้มีขึ้นบรรทัดใหม่ปิดท้ายสตริง
 *
 * Developed by Xman Studio.
 */
class EvmAddress implements ValidationRule
{
    private const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^0x[a-fA-F0-9]{40}\z/', $value) || $value === self::ZERO_ADDRESS) {
            $fail('ที่อยู่กระเป๋าไม่ถูกต้อง — ต้องขึ้นต้นด้วย 0x ตามด้วยเลขฐานสิบหก 40 ตัว และห้ามเป็นที่อยู่ศูนย์');
        }
    }
}
