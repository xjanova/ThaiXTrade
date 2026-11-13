<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Custom\RuleSet;
use App\Services\AiBotService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — กฎของโหมด "วางกลยุทธ์เอง" ต้องผ่านรายการอนุญาตเท่านั้น.
 *
 * กฎเป็นข้อมูลจากผู้ใช้ที่ไปถึงเครื่องยนต์ซึ่งตัดสินใจเรื่องเงิน — ชุดนี้คุมสองเรื่อง:
 *   1. ของที่ไม่อยู่ในรายการอนุญาต (ชนิด · ช่อง · ช่วงตัวเลข · จำนวนบล็อก) ถูกปฏิเสธพร้อมบอกจุดที่ผิด
 *   2. กฎที่บันทึกไว้แล้วแต่ตีความไม่ได้ ทำให้บอทหยุดเข้าไม้ — ไม่ใช่ทิ้งเงื่อนไขนั้นแล้วเทรดด้วยกฎที่หลวมลง
 *
 * Developed by Xman Studio.
 */
class CustomRuleSetTest extends TestCase
{
    private function valid(): array
    {
        return [
            'entry' => ['match' => 'all', 'blocks' => [
                ['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26],
                ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 45],
            ]],
            'exit' => ['match' => 'any', 'blocks' => [
                ['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 70],
            ]],
        ];
    }

    /** กฎที่เปลี่ยนบล็อกเข้าข้อแรกเป็นของที่ส่งมา */
    private function withEntryBlock(mixed $block): array
    {
        $rules = $this->valid();
        $rules['entry']['blocks'][0] = $block;

        return $rules;
    }

    #[Test]
    public function a_well_formed_rule_set_passes(): void
    {
        $this->assertSame([], RuleSet::validate($this->valid()));
    }

    #[Test]
    public function every_block_in_the_catalogue_passes_with_its_own_defaults(): void
    {
        foreach (RuleSet::blockSpecs() as $type => $spec) {
            $rules = ['entry' => ['match' => 'all', 'blocks' => [['type' => 'interval', 'bars' => 6]]], 'exit' => ['match' => 'any', 'blocks' => []]];
            $side = in_array('exit', $spec['use'], true) ? 'exit' : 'entry';
            $rules[$side]['blocks'][] = ['type' => $type];

            $this->assertSame([], RuleSet::validate($rules), "บล็อก {$type} ที่ใช้ค่าปริยายต้องผ่าน");
        }
    }

    #[Test]
    public function anything_that_is_not_a_structured_rule_set_is_rejected(): void
    {
        foreach ([null, 'rsi < 30 and close > ema(200)', 42, [], [['type' => 'rsi']], true] as $raw) {
            $this->assertNotSame([], RuleSet::validate($raw), 'ต้องปฏิเสธ: '.var_export($raw, true));
        }
    }

    #[Test]
    public function an_unknown_block_type_is_rejected(): void
    {
        foreach (['macd', 'eval', 'rsi; DROP TABLE users', '', '../../etc/passwd'] as $type) {
            $errors = RuleSet::validate($this->withEntryBlock(['type' => $type]));

            $this->assertCount(1, $errors, "ชนิด '{$type}' ต้องถูกปฏิเสธ");
        }

        // ชื่อที่ผู้ใช้ส่งมาถูกทำให้ปลอดภัยก่อนใส่ในข้อความ — ไม่สะท้อนอักขระแปลกกลับไป
        $message = RuleSet::validate($this->withEntryBlock(['type' => '<script>alert(1)</script>']))[0];
        $this->assertStringNotContainsString('<', $message);
    }

    #[Test]
    public function a_block_without_a_type_is_rejected(): void
    {
        foreach ([['when' => 'crosses_above'], 'rsi', ['type' => ['rsi']], ['type' => 14]] as $block) {
            $this->assertNotSame([], RuleSet::validate($this->withEntryBlock($block)), var_export($block, true));
        }
    }

    #[Test]
    public function numbers_outside_the_allowed_range_are_rejected(): void
    {
        $cases = [
            ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 150],
            ['type' => 'rsi', 'op' => 'below', 'period' => 1, 'level' => 30],
            ['type' => 'rsi', 'op' => 'below', 'period' => 100000, 'level' => 30],
            ['type' => 'ema_cross', 'when' => 'is_above', 'fast' => 12, 'slow' => 5000],
            ['type' => 'price_change', 'op' => 'above', 'bars' => 10, 'pct' => 99],
            ['type' => 'atr', 'op' => 'above', 'period' => 14, 'pct' => -1],
        ];

        foreach ($cases as $block) {
            $this->assertCount(1, RuleSet::validate($this->withEntryBlock($block)), json_encode($block));
        }
    }

    #[Test]
    public function values_that_are_not_real_numbers_are_rejected(): void
    {
        foreach (['abc', '', true, null, [30], '1e999', 'NAN', '30; drop'] as $bad) {
            $errors = RuleSet::validate($this->withEntryBlock(['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => $bad]));

            $this->assertCount(1, $errors, 'level = '.var_export($bad, true));
        }

        // ช่องจำนวนเต็มไม่รับทศนิยม — 12.5 แท่งไม่มีความหมาย
        $this->assertCount(1, RuleSet::validate($this->withEntryBlock(['type' => 'ema_cross', 'when' => 'is_above', 'fast' => 12.5, 'slow' => 26])));
        // ตัวเลขที่มาเป็นข้อความ (ฟอร์ม/JSON บางตัว) ยังรับได้
        $this->assertSame([], RuleSet::validate($this->withEntryBlock(['type' => 'ema_cross', 'when' => 'is_above', 'fast' => '12', 'slow' => '26'])));
    }

    #[Test]
    public function an_option_outside_the_list_is_rejected(): void
    {
        foreach (['crosses', 'CROSSES_ABOVE', 1, null, ['crosses_above']] as $bad) {
            $errors = RuleSet::validate($this->withEntryBlock(['type' => 'ema_cross', 'when' => $bad, 'fast' => 12, 'slow' => 26]));

            $this->assertCount(1, $errors, 'when = '.var_export($bad, true));
        }
    }

    #[Test]
    public function unknown_fields_and_unknown_sections_are_rejected(): void
    {
        $this->assertCount(1, RuleSet::validate($this->withEntryBlock(['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30, 'expr' => 'close > 0'])));

        $rules = $this->valid();
        $rules['script'] = 'system("id")';
        $this->assertCount(1, RuleSet::validate($rules));

        $rules = $this->valid();
        $rules['entry']['code'] = 'return true;';
        $this->assertCount(1, RuleSet::validate($rules));
    }

    #[Test]
    public function a_fast_ema_that_is_not_shorter_than_the_slow_one_is_rejected(): void
    {
        foreach ([[26, 26], [50, 20]] as [$fast, $slow]) {
            $this->assertCount(1, RuleSet::validate($this->withEntryBlock(['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => $fast, 'slow' => $slow])));
        }
    }

    #[Test]
    public function the_match_mode_must_be_all_or_any(): void
    {
        $rules = $this->valid();
        $rules['entry']['match'] = 'xor';

        $this->assertCount(1, RuleSet::validate($rules));
    }

    #[Test]
    public function a_rule_set_without_an_entry_condition_is_rejected(): void
    {
        $rules = $this->valid();
        $rules['entry']['blocks'] = [];

        $this->assertCount(1, RuleSet::validate($rules));

        unset($rules['entry']);
        $this->assertCount(1, RuleSet::validate($rules));
    }

    #[Test]
    public function the_scheduled_buy_block_cannot_be_used_as_an_exit(): void
    {
        $rules = $this->valid();
        $rules['exit']['blocks'][] = ['type' => 'interval', 'bars' => 6];

        $this->assertCount(1, RuleSet::validate($rules));
    }

    #[Test]
    public function too_many_blocks_are_rejected_without_walking_them(): void
    {
        $max = RuleSet::maxBlocks('entry');

        $rules = $this->valid();
        $rules['entry']['blocks'] = array_fill(0, $max, ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30]);
        $this->assertSame([], RuleSet::validate($rules), 'จำนวนเท่าเพดานพอดีต้องผ่าน');

        $rules['entry']['blocks'][] = ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30];
        $this->assertCount(1, RuleSet::validate($rules), 'เกินเพดานหนึ่งข้อต้องถูกปฏิเสธ');

        // ชุดใหญ่ผิดทุกข้อ: ต้องได้ข้อความเดียวเรื่องจำนวน ไม่ใช่ห้าพันข้อความ
        $rules['entry']['blocks'] = array_fill(0, 5000, ['type' => 'nope', 'x' => str_repeat('A', 500)]);
        $this->assertCount(1, RuleSet::validate($rules));

        $rules = $this->valid();
        $rules['exit']['blocks'] = array_fill(0, RuleSet::maxBlocks('exit') + 1, ['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 70]);
        $this->assertCount(1, RuleSet::validate($rules));
    }

    #[Test]
    public function a_block_with_thousands_of_junk_fields_yields_one_error(): void
    {
        $block = ['type' => 'rsi'];
        for ($i = 0; $i < 3000; $i++) {
            $block["junk{$i}"] = $i;
        }

        $this->assertCount(1, RuleSet::validate($this->withEntryBlock($block)));
    }

    // ── normalize ───────────────────────────────────────────────────────────

    #[Test]
    public function normalising_fills_defaults_and_keeps_a_canonical_shape(): void
    {
        $clean = RuleSet::normalize(['entry' => ['blocks' => [['type' => 'rsi', 'level' => '35']]]]);

        $this->assertSame([
            'entry' => ['match' => 'all', 'blocks' => [['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 35.0]]],
            'exit' => ['match' => 'any', 'blocks' => []],
        ], $clean);

        $this->assertSame($clean, RuleSet::normalize($clean), 'ล้างซ้ำต้องได้ผลเดิม');
        $this->assertSame($clean, RuleSet::normalize(json_decode(json_encode($clean), true)), 'ผ่านฐานข้อมูล (JSON) แล้วต้องได้ผลเดิม');
    }

    #[Test]
    public function normalising_clamps_numbers_like_every_other_parameter(): void
    {
        $clean = RuleSet::normalize(['entry' => ['match' => 'any', 'blocks' => [
            ['type' => 'rsi', 'op' => 'above', 'period' => 9999, 'level' => -5],
        ]]]);

        $this->assertSame(['type' => 'rsi', 'op' => 'above', 'period' => 100, 'level' => 1.0], $clean['entry']['blocks'][0]);
        $this->assertSame('any', $clean['entry']['match']);
        $this->assertArrayNotHasKey('invalid', $clean);
    }

    #[Test]
    public function a_stored_block_the_engine_cannot_read_flags_the_whole_set_instead_of_being_dropped(): void
    {
        $stored = $this->valid();
        $stored['entry']['blocks'][] = ['type' => 'retired_block', 'x' => 1];

        $clean = RuleSet::normalize($stored);

        $this->assertTrue($clean['invalid'] ?? false, 'ทิ้งเงื่อนไขเงียบๆ = กฎหลวมกว่าที่ผู้ใช้ตั้ง');
        $this->assertTrue(RuleSet::normalize($clean)['invalid'] ?? false, 'ธงต้องรอดการล้างซ้ำ');

        $tooMany = $this->valid();
        $tooMany['entry']['blocks'] = array_fill(0, RuleSet::maxBlocks('entry') + 3, ['type' => 'rsi']);
        $this->assertTrue(RuleSet::normalize($tooMany)['invalid'] ?? false);

        $inverted = $this->withEntryBlock(['type' => 'ema_cross', 'when' => 'is_above', 'fast' => 50, 'slow' => 20]);
        $this->assertTrue(RuleSet::normalize($inverted)['invalid'] ?? false);
    }

    #[Test]
    public function nothing_or_garbage_normalises_to_an_empty_rule_set(): void
    {
        foreach ([null, 'x', 7, []] as $raw) {
            $this->assertSame(RuleSet::blank(), RuleSet::normalize($raw));
        }
    }

    #[Test]
    public function the_parameter_sanitiser_stores_rules_in_canonical_form(): void
    {
        $params = app(AiBotService::class)->sanitizeParams('custom', ['rules' => $this->valid(), 'order_size_usd' => 40]);

        $this->assertSame(RuleSet::normalize($this->valid()), $params['rules']);
        $this->assertEqualsWithDelta(40.0, $params['order_size_usd'], 1e-9);
        // สวิตช์ร่วมยังอยู่ครบ — โหมดนี้ไม่ได้ข้ามด่านข่าว/ตัวกรองตลาดใหญ่
        foreach (['news_mode', 'auto_pair', 'ai_gate', 'macro_filter', 'macro_ema'] as $common) {
            $this->assertArrayHasKey($common, $params);
        }
    }

    #[Test]
    public function the_engine_asks_for_enough_candles_for_the_longest_block(): void
    {
        $this->assertSame(30, RuleSet::minCandles(RuleSet::blank()));

        $rules = RuleSet::normalize(['entry' => ['blocks' => [
            ['type' => 'ema_cross', 'when' => 'is_above', 'fast' => 20, 'slow' => 100],
            ['type' => 'trend', 'side' => 'above', 'period' => 200],
        ]], 'exit' => ['blocks' => [['type' => 'channel_breakout', 'side' => 'below_low', 'period' => 55]]]]);

        $this->assertSame(350, RuleSet::minCandles($rules));
    }
}
