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

    /** โค้ดทั้งหมดที่ตัดสินใจเรื่องบอท — ช่องตั้งค่าต้องถูกอ่านที่ใดที่หนึ่งในนี้ */
    private function engineSource(): string
    {
        $files = collect(File::allFiles(app_path('Services/AiBot')))
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        return $files."\n".File::get(app_path('Services/AiBotService.php'));
    }

    #[Test]
    public function every_setting_in_the_form_is_read_by_the_engine(): void
    {
        $source = $this->engineSource();

        foreach ($this->allSpecs(usableOnly: true) as [$owner, $spec]) {
            $key = $spec['key'];

            $this->assertMatchesRegularExpression(
                '/[\'"]'.preg_quote($key, '/').'[\'"]/',
                $source,
                "ช่อง {$key} ({$owner}) อยู่ในฟอร์ม แต่ไม่มีโค้ดไหนในเอนจินอ่านค่า = ป้ายหลอก",
            );
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
                $this->assertNotEmpty(
                    $spec['option_labels'][$option]['th'] ?? null,
                    "ตัวเลือก {$option} ของช่อง {$spec['key']} ({$owner}) ไม่มีป้ายไทย — ผู้ใช้จะเห็นค่าดิบ",
                );
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
