<?php

namespace App\Services\AiBot;

use App\Services\ContractRegistry;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — กลยุทธ์ไหนลงมือได้จริงแล้วบ้าง.
 *
 * "ปลดล็อกตามแพลน" กับ "ทำงานได้จริง" เป็นคนละเรื่องกัน ผู้ใช้ระดับ VIP ปลดล็อก
 * อาร์บิทราจได้ แต่กลยุทธ์นั้นต้องมีราคาสองแหล่งถึงจะมีส่วนต่างให้จับ — ตอนนี้มี
 * แค่ราคาจากตลาดกลาง เพราะพูล DEX บน TPIX Chain ยังไม่ deploy
 *
 * ปล่อยให้สร้างบอทได้จะเกิดสิ่งที่แย่กว่าปุ่มเทา: บอทที่ดูเหมือนทำงาน กินเครดิต
 * ทุกครั้งที่ตื่น แล้วไม่เคยเข้าไม้เลย ผู้ใช้จะคิดว่ากลยุทธ์นี้ห่วย ไม่ใช่ว่ายังไม่เปิด
 *
 * ⚠️ ต้องบังคับที่ฝั่งเซิร์ฟเวอร์ด้วย ไม่ใช่แค่ทำปุ่มเทา
 *    แอพมือถือกับใครก็ตามที่ยิง API ตรงไม่ได้เห็นปุ่มของเรา
 *
 * Developed by Xman Studio.
 */
class StrategyAvailability
{
    private const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

    /** ผลการอ่านไฟล์ที่อยู่สัญญา — อ่านครั้งเดียวต่อ request */
    private ?bool $dexDeployed = null;

    /**
     * กลยุทธ์นี้ลงมือได้จริงไหม.
     *
     * @return array{available: bool, reason: ?string, reason_en: ?string}
     */
    public function check(string $code): array
    {
        /*
         * กลยุทธ์ที่ถูก "ถอดออกจากการขาย" — ธง retired ใน config/aibot.php
         *
         * ต่างจาก "ยังไม่พร้อม" (อาร์บิทราจรอ DEX): ตัวนี้เคยขายแล้ววัดผลจริงแล้ว
         * ว่าแพ้โดยโครงสร้าง (ดูสแกลป์) รายการยังอยู่ในแคตตาล็อกเพื่อให้บอทเก่า
         * อ่านชื่อ/ประวัติได้ แต่ห้ามสร้างหรือเริ่มตัวใหม่ และเหตุผลต้องไปถึงผู้ใช้
         */
        $spec = collect(config('aibot.strategies', []))->firstWhere('code', $code);

        if ($spec && ($spec['retired'] ?? false)) {
            return [
                'available' => false,
                'reason' => (string) ($spec['retired_reason'] ?? 'กลยุทธ์นี้ถูกถอดออกจากการขายแล้ว'),
                'reason_en' => (string) ($spec['retired_reason_en'] ?? 'This strategy has been retired.'),
            ];
        }

        if ($code === 'arbitrage' && ! $this->dexDeployed()) {
            return [
                'available' => false,
                'reason' => 'รอพูล DEX บน TPIX Chain เปิดใช้งานก่อน จึงจะมีราคาสองฝั่งให้เทียบส่วนต่าง',
                'reason_en' => 'Waiting for the TPIX Chain DEX pool — arbitrage needs a second price source.',
            ];
        }

        return ['available' => true, 'reason' => null, 'reason_en' => null];
    }

    public function isAvailable(string $code): bool
    {
        return $this->check($code)['available'];
    }

    /** แนบสถานะลงในแคตตาล็อกที่ส่งให้หน้าเว็บและแอพ */
    public function decorate(array $strategies): array
    {
        return array_map(function (array $strategy) {
            $status = $this->check($strategy['code'] ?? '');

            return $strategy + [
                'available' => $status['available'],
                'unavailable_reason' => $status['reason'],
                'unavailable_reason_en' => $status['reason_en'],
            ];
        }, $strategies);
    }

    /** โค้ดของกลยุทธ์ที่ลงมือได้จริง — ใช้เป็นรายการอนุญาตตอน validate */
    public function availableCodes(): array
    {
        return collect(config('aibot.strategies', []))
            ->pluck('code')
            ->filter(fn (string $code) => $this->isAvailable($code))
            ->values()
            ->all();
    }

    /**
     * DEX บน TPIX Chain deploy แล้วหรือยัง.
     *
     * ถามทะเบียนสัญญา (ContractRegistry) ตัวเดียวกับที่หน้าเทรดใช้ — ไม่อ่านไฟล์ JSON
     * ที่ build ติดไปกับหน้าเว็บอีกแล้ว เพราะสคริปต์ deploy ลงทะเบียนที่อยู่เข้ามาที่
     * ทะเบียนโดยตรง ไฟล์นั้นจะล้าหลังทันทีที่ deploy โดยไม่มีใครรู้
     *
     * ตอบไม่ได้ = ถือว่ายังไม่ deploy กลยุทธ์ที่พึ่งมันจะถูกปิดไว้ ซึ่งเป็นฝั่งที่
     * ปลอดภัยกว่าการเดาว่าพร้อมแล้วปล่อยให้ผู้ใช้เสียเครดิตทิ้ง
     */
    public function dexDeployed(): bool
    {
        if ($this->dexDeployed !== null) {
            return $this->dexDeployed;
        }

        try {
            return $this->dexDeployed = app(ContractRegistry::class)->dexReady();
        } catch (\Throwable $e) {
            Log::warning('ตรวจความพร้อม DEX ไม่ได้ ถือว่ายังไม่ deploy', ['error' => $e->getMessage()]);

            return $this->dexDeployed = false;
        }
    }
}
