<?php

namespace App\Services;

use App\Models\Chain;

/**
 * แปลง "chain id จริงของบล็อกเชน" (56, 4289, ...) ให้เป็นแถวในตาราง chains.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมต้องมีตัวกลาง
 * ═══════════════════════════════════════════════════════════════════════════
 * ระบบนี้มีเลขสองชุดที่หน้าตาเหมือนกันจนสลับกันได้ง่ายมาก:
 *
 *   chains.id       = เลขแถวในตาราง (1..11)  ← FK ทุกตัวชี้มาที่นี่
 *   chains.chain_id = chain id จริงของเชน (1, 56, 137, 4289, ...)
 *
 * ที่อันตรายคือช่วงเลขมันทับกัน — id 10 คือ zkSync Era แต่ chain id 10 คือ Optimism
 * โค้ดที่รับ chain_id จาก client แล้วเอาไปค้นด้วย id ตรงๆ จะได้เชนผิดแบบเงียบสนิท
 * ไม่มี error ให้เห็นเลย (เจอจริงใน Web3BalanceService: ขอยอดของ Optimism
 * แต่ได้รายการโทเคนของ zkSync มาแล้วไปถามผ่าน RPC ของ Optimism)
 *
 * เดิมตรรกะการค้นถูกคัดลอกไว้ 4 ที่ (TradingController สองจุด, SwapApiController,
 * และ TokenFactoryApiController ที่คัดลอกผิดจนอ้างคอลัมน์ที่ไม่มีอยู่จริง)
 * แต่ละที่เขียนไม่เหมือนกัน — บางที่เช็ค active บางที่ไม่เช็ค ทำให้ปุ่มปิดเชน
 * ในหลังบ้าน "ปิดได้ครึ่งเดียว": swap หยุด แต่การวางไม้ยังทำงานต่อ
 *
 * รวมมาไว้ที่เดียว ทุกทางจึงตัดสินเหมือนกันเสมอ
 *
 * Developed by Xman Studio.
 */
class ChainResolver
{
    /**
     * หาเชนจาก chain id จริง (ไม่สนใจว่าเปิดใช้งานอยู่หรือไม่).
     */
    public function resolve(int $chainId): ?Chain
    {
        return $this->baseQuery($chainId)->first();
    }

    /**
     * หาเฉพาะเชนที่แอดมินเปิดใช้งานอยู่.
     *
     * ใช้ตัวนี้กับทุกทางที่ "ทำอะไรจริง" กับเงินผู้ใช้ (วางไม้ / สลับเหรียญ / คิดค่าธรรมเนียม)
     * ปุ่มปิดเชนในหลังบ้านจะได้หยุดได้ทั้งระบบ ไม่ใช่หยุดแค่บางส่วน
     */
    public function resolveActive(int $chainId): ?Chain
    {
        return $this->baseQuery($chainId)->where('is_active', true)->first();
    }

    /**
     * เชนนี้เปิดให้เทรดจริงหรือยัง (status = live).
     */
    public function isLive(int $chainId): bool
    {
        return $this->resolveActive($chainId)?->status === Chain::STATUS_LIVE;
    }

    /**
     * ค้นด้วย chain_id เป็นหลัก และเผื่อ chain_id_hex ไว้เป็นทางสำรอง.
     *
     * ทางสำรองมีไว้เพราะ chain_id เป็นคอลัมน์ที่เพิ่งเพิ่ม — แถวที่ถูกสร้างก่อน
     * migration จะเติมค่า หรือแถวที่ hex ผิดรูปจนแปลงไม่ได้ จะยังมี chain_id เป็น NULL
     * เทียบ hex แบบไม่สนตัวพิมพ์ เพราะข้อมูลเดิมบน production ปนกันทั้งสองแบบ
     * ('0x38' กับ '0xA4B1') — ของใหม่ถูกบังคับเป็นพิมพ์เล็กแล้วตั้งแต่ชั้น validation
     */
    private function baseQuery(int $chainId)
    {
        $hex = '0x'.dechex($chainId);

        return Chain::query()->where(function ($q) use ($chainId, $hex) {
            $q->where('chain_id', $chainId)
                ->orWhereRaw('LOWER(chain_id_hex) = ?', [strtolower($hex)]);
        });
    }
}
