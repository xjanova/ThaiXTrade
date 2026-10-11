<?php

namespace App\Services\Stabilizer;

/**
 * ตัวเซ็น/ส่งธุรกรรมของกระเป๋า Stability Reserve.
 *
 * แยกเป็น interface เพื่อให้เทสต์ใช้ตัวปลอมได้โดยไม่ต้องมีคีย์จริง/ไม่ส่งอะไรขึ้นเชนจริง
 * และแยก "เซ็น" กับ "ส่ง" ออกจากกันโดยตั้งใจ — ผู้เรียกต้องบันทึก tx hash + raw ลงฐานข้อมูล
 * ก่อนส่งเสมอ (ดับกลางคันแล้วรอบหน้ายังรู้ว่าเคยเซ็นอะไรไว้ = ไม่เซ็นซ้ำด้วย nonce ใหม่)
 *
 * Developed by Xman Studio
 */
interface StabilizerTxSigner
{
    /** เซ็นได้ในบริบทนี้ไหม (ต้องเป็น CLI — php-fpm ปิด proc_open) */
    public function isAvailable(): bool;

    /**
     * เซ็นธุรกรรมแบบ legacy (type 0, gasPrice 0) — ยังไม่ส่ง.
     *
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, error?: string, code?: string}
     */
    public function sign(string $to, string $data, string $valueWei, int $gasLimit, int $nonce): array;

    /**
     * ส่ง raw ที่เซ็นแล้วขึ้นเชน.
     *
     * @return array{ok: bool, txHash?: string, alreadyKnown?: bool, error?: string, code?: string}
     */
    public function send(string $rawTx): array;
}
