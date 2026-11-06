<?php

namespace App\Services\Launchpad;

/**
 * เหตุที่สร้างคำสั่งซื้อไม่ได้ — code ให้หน้าเว็บแปลไทย/อังกฤษเอง · message เป็นภาษาไทยไว้ดูใน log/API ตรง ๆ
 * ห้ามใส่ข้อความจาก exception ภายใน (SQL/RPC) ลงที่นี่ — ส่งถึงผู้ใช้ตรง ๆ.
 */
class LaunchpadOrderException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
