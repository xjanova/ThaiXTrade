<?php

namespace App\Services\CreatorRegistration;

use RuntimeException;

/**
 * ความผิดพลาดของการลงทะเบียนผู้สร้างเหรียญที่ต้องบอกผู้ใช้ — โค้ด ข้อความ (แปลแล้ว) และ HTTP status.
 *
 * ข้อความเขียนให้ผู้ใช้อ่าน ห้ามใส่ข้อความจาก exception ภายใน (SQL, RPC) ลงไป
 */
class CreatorRegistrationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function toResponseArray(): array
    {
        return [
            'success' => false,
            'error' => ['code' => $this->errorCode, 'message' => $this->getMessage()],
        ];
    }
}
