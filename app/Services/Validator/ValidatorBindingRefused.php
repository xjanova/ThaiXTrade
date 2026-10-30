<?php

namespace App\Services\Validator;

use RuntimeException;

/**
 * ปฏิเสธการผูกกุญแจ validator — code ส่งให้แอป, message เป็นภาษาคนพร้อมโชว์.
 */
class ValidatorBindingRefused extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
