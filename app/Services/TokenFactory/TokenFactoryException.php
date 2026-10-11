<?php

namespace App\Services\TokenFactory;

use RuntimeException;

/**
 * ความผิดพลาดของโรงงานสร้างเหรียญที่ต้องบอกผู้ใช้ — มีโค้ด ข้อความภาษาไทย และ HTTP status.
 *
 * ข้อความเขียนให้ผู้ใช้อ่าน ห้ามใส่ stack trace / stderr / ที่อยู่ RPC ลงไป
 */
class TokenFactoryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    /** ธุรกรรมค่าธรรมเนียมยังรอการยืนยันบนเชน — หน้าเว็บถามซ้ำได้ */
    public static function feePending(string $message, array $data = []): self
    {
        return new self('FEE_PENDING', $message, 202, $data);
    }

    public function isPending(): bool
    {
        return $this->errorCode === 'FEE_PENDING';
    }

    public function toResponseArray(): array
    {
        return [
            'success' => false,
            'error' => array_merge(['code' => $this->errorCode, 'message' => $this->getMessage()], $this->data),
        ];
    }
}
