<?php

namespace App\Services\Kyc;

use RuntimeException;

/**
 * Thaiprompt ปฏิเสธ token (หมดอายุ / ลูกค้าถอนสิทธิ์) — ใช้ภายใน ThaipromptKycService
 * แยกจาก RuntimeException ทั่วไปเพื่อรู้ว่าควร "ต่ออายุ token แล้วลองใหม่" ไม่ใช่ล้มทันที.
 *
 * Developed by Xman Studio.
 */
class ThaipromptUnauthorized extends RuntimeException {}
