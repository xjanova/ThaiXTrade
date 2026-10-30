<?php

namespace App\Services\Kyc;

use RuntimeException;

/**
 * ความล้มเหลวของการเชื่อม Thaiprompt ที่ "บอกผู้ใช้ได้ตรงๆ" — ข้อความคือค่าคงที่ ERR_* ของ ThaipromptKycService.
 *
 * แยกจาก RuntimeException ทั่วไปเพราะ QueryException และ DecryptException ก็เป็น RuntimeException
 * ข้อความของพวกนั้นมี SQL ค่าที่ bind หรือรายละเอียดภายใน คอนโทรลเลอร์จึงส่งข้อความออกได้เฉพาะคลาสนี้
 *
 * Developed by Xman Studio.
 */
class ThaipromptKycException extends RuntimeException {}
