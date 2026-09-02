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
     * ⭐ เทมเพลตทุกใบต้องเป็นค่าที่ engine รับได้ตรงๆ — ไม่ถูกบีบ ไม่ถูกตัดคีย์ทิ้ง.
     *
     * เทมเพลตคือสิ่งที่ผู้ใช้กดครั้งเดียวแล้วเชื่อว่า "ทีมงานตั้งให้แล้ว" ถ้าค่าในนั้น
     * ไม่รอด sanitizeParams สิ่งที่ผู้ใช้เห็นกับสิ่งที่บอทใช้จะต่างกันตั้งแต่คลิกแรก
     */
    public function test_every_template_survives_sanitising_unchanged(): void
    {
        $service = app(AiBotService::class);

        foreach (config('aibot.strategies', []) as $spec) {
            $code = $spec['code'];
            $templates = $spec['templates'] ?? [];

            if (($spec['retired'] ?? false) || $code === 'arbitrage') {
                continue;
            }

            $this->assertNotEmpty($templates, "{$code} ต้องมีเทมเพลตอย่างน้อยหนึ่งใบ");
            $this->assertCount(count($templates), array_unique(array_column($templates, 'code')), "{$code} มีรหัสเทมเพลตซ้ำ");

            foreach ($templates as $template) {
                $label = "{$code}/{$template['code']}";

                $this->assertNotEmpty($template['name_th'] ?? '', "{$label} ไม่มีชื่อไทย");
                $this->assertNotEmpty($template['tagline_th'] ?? '', "{$label} ไม่มีคำอธิบายไทย");
                $this->assertContains($template['timeframe'], $spec['timeframes'], "{$label} ใช้ timeframe ที่กลยุทธ์ไม่รองรับ");

                $clean = $service->sanitizeParams($code, $template['params']);

                foreach ($template['params'] as $key => $value) {
                    $this->assertArrayHasKey($key, $clean, "{$label}: engine ไม่รู้จักพารามิเตอร์ {$key}");

                    if (is_bool($value)) {
                        $this->assertSame($value, $clean[$key], "{$label}.{$key}");
                    } elseif (is_numeric($value)) {
                        $this->assertEqualsWithDelta((float) $value, (float) $clean[$key], 1e-9, "{$label}.{$key} ถูกบีบ/แก้ระหว่างทาง");
                    } else {
                        $this->assertSame($value, $clean[$key], "{$label}.{$key}");
                    }
                }

                $risk = $service->sanitizeRisk($template['risk']);

                foreach ($template['risk'] as $key => $value) {
                    $this->assertEqualsWithDelta((float) $value, (float) $risk[$key], 1e-9, "{$label}.risk.{$key} อยู่นอกกรอบของระบบ");
                }
            }

            $this->assertContains($spec['default_timeframe'] ?? null, $spec['timeframes'], "{$code} default_timeframe ไม่อยู่ในรายการที่รองรับ");
        }
    }

    /**
     * ⭐ แท่งสั้นที่พิสูจน์แล้วว่าแพ้ต้นทุน ต้องไม่ถูกเสนอให้เลือกอีก.
     *
     * backtest 180 วัน (2 ก.ย. 2026) บน 5m/15m: breakout PF 0.39 · momentum 0.25 ·
     * mean_reversion 0.53 · grid 0.66 — ทุกตัวขาดทุนต่อทุน 17–73% ขณะที่ 1h/4h ชนะ
     * ตัวเลือกแบบนี้ไม่ใช่ความยืดหยุ่น แต่เป็นปุ่มที่กดแล้วเสียเงินแน่นอน
     */
    public function test_strategies_proven_to_lose_on_short_bars_do_not_offer_them(): void
    {
        foreach (['grid', 'momentum', 'mean_reversion', 'breakout'] as $code) {
            $spec = collect(config('aibot.strategies'))->firstWhere('code', $code);

            foreach (['1m', '5m', '15m'] as $short) {
                $this->assertNotContains($short, $spec['timeframes'], "{$code} ยังเสนอ {$short} ทั้งที่ backtest แพ้ต้นทุน");
            }
        }
    }

    /** พารามิเตอร์ที่ประกาศ group ต้องเป็นค่าที่ฟอร์มรู้จัก (basic|advanced) */
    public function test_param_groups_are_known_to_the_form(): void
    {
        foreach (config('aibot.strategies', []) as $spec) {
            foreach ($spec['params'] ?? [] as $param) {
                $this->assertContains($param['group'] ?? 'basic', ['basic', 'advanced'], "{$spec['code']}.{$param['key']}");
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
