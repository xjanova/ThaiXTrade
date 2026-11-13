<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\StrategyAvailability;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ทุกช่องตั้งค่าที่ผู้ใช้เห็นต้อง "ทำงานจริง".
 *
 * เจ้าของสั่ง 2026-09-23: "ตั้งค่ายืดหยุ่นได้ + มีค่าปริยายที่เหมาะสม + ใช้งานได้จริง
 * เพราะมันคือความน่าเชื่อถือ"
 *
 * ⚠️ บั๊กที่ชุดนี้กัน: `pause_in_downtrend` ("พักสะสมช่วงขาลงใหญ่") อยู่ในฟอร์มและเทมเพลต
 *    มาตั้งแต่แรก แต่ไม่มีโค้ดไหนอ่านค่าเลย — เปิด/ปิดได้ผล backtest เท่ากันทุกตัวเลข
 *    และ `news_filter` ของเทมเพลต ai_signal ที่ไม่ได้ประกาศเป็นช่อง ทำให้หน้าเว็บไม่เคย
 *    จับได้ว่าผู้ใช้กำลังใช้เทมเพลตนั้นอยู่
 *
 * ผลต่อพฤติกรรมจริงของแต่ละช่อง (เปลี่ยนค่าแล้ว backtest 1 ปีเปลี่ยน) ตรวจด้วยข้อมูลจริง
 * นอก CI — ชุดนี้คือด่านถาวรที่ถูกและเร็ว: ประกาศช่องแล้วต้องมีโค้ดอ่าน
 *
 * Developed by Xman Studio.
 */
class SettingsEffectTest extends TestCase
{
    /**
     * @param  bool  $usableOnly  ข้ามกลยุทธ์ที่ผู้ใช้สร้างบอทไม่ได้ (ถอดจากการขาย / อาร์บิทราจรอพูล DEX)
     *                            — ฟอร์มของกลยุทธ์พวกนั้นไม่มีใครกรอกได้ จึงไม่ใช่ป้ายหลอก
     *                            แต่วันที่เปิดใช้ ช่องของมันจะถูกบังคับตรวจทันทีโดยไม่ต้องแก้เทสต์
     * @return list<array{0: string, 1: array}> [กลยุทธ์หรือ "common", spec]
     */
    private function allSpecs(bool $usableOnly = false): array
    {
        $out = [];

        foreach ((array) config('aibot.common_params') as $spec) {
            $out[] = ['common', $spec];
        }

        foreach ((array) config('aibot.strategies') as $strategy) {
            if ($usableOnly && ! app(StrategyAvailability::class)->isAvailable($strategy['code'])) {
                continue;
            }

            foreach ((array) ($strategy['params'] ?? []) as $spec) {
                $out[] = [$strategy['code'], $spec];
            }
        }

        return $out;
    }

    /**
     * โค้ดที่ตัดสินใจเรื่องบอท — ช่องตั้งค่าต้องถูกอ่านที่ใดที่หนึ่งในนี้.
     *
     * ไม่รวม AiBotService (ตัวล้างค่า): มันวนทุกช่องแบบไม่เจาะชื่อ และเอ่ยชื่อบางช่องตอนแปลงค่ารุ่นเก่า
     * ถ้านับด้วย ช่องที่เอนจินเลิกอ่านไปแล้วก็ยังผ่าน
     */
    private function engineSource(): string
    {
        return collect(File::allFiles(app_path('Services/AiBot')))
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");
    }

    #[Test]
    public function every_setting_in_the_form_is_read_by_the_engine(): void
    {
        $source = $this->engineSource();

        foreach ($this->allSpecs(usableOnly: true) as [$owner, $spec]) {
            $key = preg_quote($spec['key'], '/');

            /*
             * นับเฉพาะรูปแบบที่เป็น "การอ่านค่า": $params['key'] หรืออ้างเป็นค่าในตารางจับคู่
             * (PositionSizer: 'grid' => 'order_size_usd') — ไม่นับการใช้ชื่อเดียวกันเป็นคีย์ของ
             * ข้อมูลอื่น เช่น 'mode' => $bot->mode ที่เคยทำให้ช่อง mode ผ่านทั้งที่ไม่เกี่ยวกัน
             */
            $this->assertMatchesRegularExpression(
                "/\\[\\s*['\"]{$key}['\"]\\s*\\]|=>\\s*['\"]{$key}['\"]/",
                $source,
                "ช่อง {$spec['key']} ({$owner}) อยู่ในฟอร์ม แต่ไม่มีโค้ดไหนในเอนจินอ่านค่า = ป้ายหลอก",
            );
        }
    }

    /**
     * บล็อกเงื่อนไขของโหมด "วางกลยุทธ์เอง" เป็นช่องตั้งค่าเหมือนกัน — แค่อยู่ในกฎแทนที่จะอยู่ในฟอร์มแบน.
     *
     * บล็อกที่อยู่ในรายการแต่ตัวประเมินไม่รู้จัก = เงื่อนไขที่ไม่มีวันเป็นจริง (หรือแย่กว่า: ถูกมองข้าม)
     * ช่องที่ตัวประเมินไม่อ่าน = ผู้ใช้ปรับแล้วบอททำเหมือนเดิม · ตัวเลือกที่โค้ดไม่เอ่ยถึง = ตกไปสาขาปริยายเงียบๆ
     */
    #[Test]
    public function every_rule_block_field_and_option_is_read_by_the_rule_evaluator(): void
    {
        $evaluator = File::get(app_path('Services/AiBot/Custom/RuleEvaluator.php'));
        $blocks = (array) config('aibot.custom_rules.blocks');

        $this->assertNotEmpty($blocks);

        foreach ($blocks as $block) {
            $type = preg_quote($block['type'], '/');

            $this->assertMatchesRegularExpression(
                "/['\"]{$type}['\"]\\s*=>\\s*self::/",
                $evaluator,
                "บล็อก {$block['type']} อยู่ในรายการ แต่ตัวประเมินกฎไม่มีสาขาของมัน",
            );

            foreach ($block['fields'] as $field) {
                $key = preg_quote($field['key'], '/');

                $this->assertMatchesRegularExpression(
                    "/\\\$block\\[\\s*['\"]{$key}['\"]\\s*\\]/",
                    $evaluator,
                    "ช่อง {$field['key']} ของบล็อก {$block['type']} ไม่มีโค้ดอ่านค่า = ป้ายหลอก",
                );

                foreach ($field['options'] ?? [] as $option) {
                    $this->assertNotEmpty($field['option_labels'][$option]['th'] ?? null, "{$block['type']}.{$field['key']}: {$option} ไม่มีป้ายไทย");
                    $this->assertNotEmpty($field['option_labels'][$option]['en'] ?? null, "{$block['type']}.{$field['key']}: {$option} ไม่มีป้ายอังกฤษ");
                }

                // ช่องสองตัวเลือกเทียบกับตัวเดียวแล้วอีกตัวเป็น "ไม่ใช่" ได้ — แต่ต้องมีอย่างน้อยหนึ่งตัวถูกเอ่ยถึง
                // และช่องที่มีเกินสองตัวเลือกต้องถูกเอ่ยถึงอย่างน้อยทุกตัวยกเว้นตัวสุดท้าย (สาขา default)
                $options = $field['options'] ?? [];
                $named = array_filter($options, fn (string $o) => str_contains($evaluator, "'{$o}'"));

                if ($options !== []) {
                    $this->assertGreaterThanOrEqual(count($options) - 1, count($named), "{$block['type']}.{$field['key']}: มีตัวเลือกที่ตัวประเมินไม่ได้แยกสาขา");
                }

                foreach (['label', 'label_en'] as $label) {
                    $this->assertNotEmpty($field[$label] ?? null, "{$block['type']}.{$field['key']} ไม่มี {$label}");
                }

                if ($field['type'] !== 'select') {
                    $this->assertGreaterThanOrEqual($field['min'], $field['default'], "{$block['type']}.{$field['key']}");
                    $this->assertLessThanOrEqual($field['max'], $field['default'], "{$block['type']}.{$field['key']}");
                } else {
                    $this->assertContains($field['default'], $field['options'], "{$block['type']}.{$field['key']}");
                }
            }

            foreach (['label', 'label_en', 'hint', 'hint_en'] as $text) {
                $this->assertNotEmpty($block[$text] ?? null, "บล็อก {$block['type']} ไม่มี {$text}");
            }

            $this->assertNotEmpty(array_intersect($block['use'], ['entry', 'exit']), "บล็อก {$block['type']} ไม่ได้บอกว่าใช้ฝั่งไหน");
        }
    }

    #[Test]
    public function every_template_only_uses_settings_the_form_declares(): void
    {
        $common = collect((array) config('aibot.common_params'))->pluck('key');

        foreach ((array) config('aibot.strategies') as $strategy) {
            $declared = $common->concat(collect($strategy['params'] ?? [])->pluck('key'))->all();

            foreach ((array) ($strategy['templates'] ?? []) as $template) {
                foreach (array_keys((array) ($template['params'] ?? [])) as $key) {
                    $this->assertContains(
                        $key,
                        $declared,
                        "เทมเพลต {$strategy['code']}/{$template['code']} ใช้ช่อง {$key} ที่ฟอร์มไม่มี — หน้าเว็บจะจับไม่ได้ว่ากำลังใช้เทมเพลตนี้",
                    );
                }
            }
        }
    }

    #[Test]
    public function every_choice_has_a_human_label(): void
    {
        foreach ($this->allSpecs() as [$owner, $spec]) {
            if (($spec['type'] ?? null) !== 'select') {
                continue;
            }

            foreach ($spec['options'] as $option) {
                foreach (['th' => 'ไทย', 'en' => 'อังกฤษ'] as $lang => $name) {
                    $this->assertNotEmpty(
                        $spec['option_labels'][$option][$lang] ?? null,
                        "ตัวเลือก {$option} ของช่อง {$spec['key']} ({$owner}) ไม่มีป้าย{$name} — ผู้ใช้จะเห็นค่าดิบ",
                    );
                }
            }
        }
    }

    #[Test]
    public function every_default_is_one_of_its_own_choices(): void
    {
        foreach ($this->allSpecs() as [$owner, $spec]) {
            if (($spec['type'] ?? null) === 'select') {
                $this->assertContains($spec['default'], $spec['options'], "ค่าปริยายของ {$spec['key']} ({$owner}) ไม่อยู่ในตัวเลือก");
            }

            if (($spec['type'] ?? null) === 'number') {
                $this->assertGreaterThanOrEqual($spec['min'], $spec['default'], "ค่าปริยายของ {$spec['key']} ({$owner}) ต่ำกว่าขั้นต่ำ");
                $this->assertLessThanOrEqual($spec['max'], $spec['default'], "ค่าปริยายของ {$spec['key']} ({$owner}) เกินเพดาน");
            }
        }
    }
}
