<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\StrategyRegistry;
use App\Services\AiBotService;
use Tests\TestCase;

/**
 * TPIX TRADE — แคตตาล็อกกลยุทธ์ต้องไม่เสนอตัวเลือกที่ engine ไม่รองรับ.
 *
 * ตัวเลือกหลอกคือปัญหาที่ตรวจด้วยตาไม่เจอ: ฟอร์มแสดงครบ กดได้ บันทึกได้
 * แต่บอทที่ตั้งค่านั้นไม่มีวันเข้าไม้เลย และไม่มีอะไรบอกผู้ใช้ว่าทำไม
 * เกิดจริงกับ breakout ที่เสนอ short/both ทั้งที่โค้ดปฏิเสธ short และ
 * both เดินเส้นทางเดียวกับ long เป๊ะ
 *
 * ชุดนี้เป็นด่านอัตโนมัติ — เพิ่มตัวเลือกใหม่ที่ engine ยังไม่รองรับแล้วจะแดงทันที
 *
 * Developed by Xman Studio.
 */
class StrategyCatalogTest extends TestCase
{
    /**
     * ทุกกลยุทธ์ในแคตตาล็อกต้องมีคลาสที่รันได้จริง.
     */
    public function test_every_listed_strategy_has_a_working_class(): void
    {
        $registry = app(StrategyRegistry::class);

        foreach (config('aibot.strategies', []) as $spec) {
            $this->assertNotNull(
                $registry->find($spec['code']),
                "กลยุทธ์ {$spec['code']} อยู่ในแคตตาล็อกแต่ไม่มีคลาสรองรับ",
            );
        }
    }

    /**
     * ค่าปริยายของทุกช่องต้องผ่าน sanitizeParams โดยไม่ถูกเปลี่ยน.
     *
     * ถ้าค่าปริยายถูกแก้ระหว่างทาง แปลว่าสิ่งที่ฟอร์มแสดงกับสิ่งที่ระบบใช้จริง
     * ไม่ตรงกันตั้งแต่วินาทีแรกที่ผู้ใช้เปิดหน้ามา — ผู้ใช้เห็นเลขหนึ่ง บอทใช้อีกเลข
     */
    public function test_the_shipped_defaults_survive_sanitising(): void
    {
        $service = app(AiBotService::class);

        foreach (config('aibot.strategies', []) as $spec) {
            $defaults = collect($spec['params'] ?? [])->pluck('default', 'key')->all();
            $clean = $service->sanitizeParams($spec['code'], $defaults);

            foreach ($defaults as $key => $value) {
                // ตัวเลขอาจถูกกฎข้ามพารามิเตอร์ปรับได้ (เช่นกริดที่ชั้นถี่เกินต้นทุน)
                // แต่ตัวเลือกแบบ select ต้องคงเดิมเสมอ — ไม่งั้นค่าปริยายไม่ถูกต้อง
                $isSelect = collect($spec['params'])->firstWhere('key', $key)['type'] === 'select';

                if ($isSelect) {
                    $this->assertSame(
                        $value,
                        $clean[$key] ?? null,
                        "ค่าปริยายของ {$spec['code']}.{$key} ไม่รอดจาก sanitizeParams",
                    );
                }
            }
        }
    }

    /**
     * ⭐ ตัวเลือกทุกตัวที่เสนอต้องให้ผลต่างจากตัวอื่นจริง.
     *
     * ตรวจเฉพาะ breakout.direction ซึ่งเป็นเคสที่เคยหลอกลูกค้ามาแล้ว —
     * เสนอ short ทั้งที่โค้ดปฏิเสธทันที และ both ที่เหมือน long ทุกประการ
     */
    public function test_breakout_only_offers_the_direction_it_actually_supports(): void
    {
        $spec = collect(config('aibot.strategies'))->firstWhere('code', 'breakout');
        $options = collect($spec['params'])->firstWhere('key', 'direction')['options'];

        $this->assertSame(['long'], $options, 'เอนจินเป็น spot ฝั่งซื้ออย่างเดียว — เสนอทิศอื่นไม่ได้');
    }
}
