<?php

namespace App\Services\AiBot\Custom;

/**
 * TPIX TRADE — กฎของโหมด "วางกลยุทธ์เอง": ตรวจ ล้างค่า และบอกว่าต้องใช้แท่งเทียนเท่าไหร่.
 *
 * กฎเป็นข้อมูลโครงสร้างเท่านั้น:
 *
 *   ['entry' => ['match' => 'all'|'any', 'blocks' => [ ['type' => 'rsi', 'op' => 'below', ...], ... ]],
 *    'exit'  => ['match' => 'all'|'any', 'blocks' => [ ... ]]]
 *
 * รายการอนุญาต (ชนิดบล็อก · ช่อง · ช่วงตัวเลข · จำนวนบล็อกสูงสุด) อยู่ที่ config('aibot.custom_rules')
 * ไม่มีนิพจน์อิสระ ไม่มีโค้ดของผู้ใช้ ไม่มี eval — ค่าที่ไม่อยู่ในรายการอนุญาตไม่มีทางไปถึงตัวประเมิน
 *
 * สองทางเข้า ตั้งใจให้เข้มไม่เท่ากัน:
 *   validate()   เข้ม — ใช้ตอนสร้าง/แก้บอทและตอน backtest · ผิดข้อเดียวปฏิเสธทั้งชุดพร้อมบอกว่าผิดตรงไหน
 *   normalize()  ทน — ใช้ทุกรอบที่บอทเดิน (ผ่าน AiBotService::sanitizeParams) · ตัวเลขนอกช่วงถูกบีบ
 *                เหมือนพารามิเตอร์อื่น แต่ของที่ "ตีความไม่ได้" (ชนิดที่ไม่รู้จัก · บล็อกเกินเพดาน) ทำให้ทั้งชุด
 *                ถูกติดธง invalid แล้วกลยุทธ์หยุดเข้าไม้
 *
 * ⚠️ ห้ามแก้ normalize() ให้ "ทิ้งบล็อกที่ไม่รู้จักแล้วเดินต่อ": เงื่อนไขเข้าแบบ "ครบทุกข้อ" ที่หายไปหนึ่งข้อ
 *    คือกฎที่หลวมกว่าที่ผู้ใช้ตั้ง — บอทจะเข้าไม้ในจังหวะที่เจ้าของเงินสั่งไว้ว่าไม่ให้เข้า
 *
 * pure ทั้งไฟล์ (อ่านแค่ config) — ไม่แตะเวลา ฐานข้อมูล หรือเครือข่าย
 *
 * Developed by Xman Studio.
 */
final class RuleSet
{
    public const SIDES = ['entry', 'exit'];

    private const DEFAULT_MATCH = ['entry' => 'all', 'exit' => 'any'];

    /** @return array<string, array> ชนิดบล็อก → spec */
    public static function blockSpecs(): array
    {
        return collect((array) config('aibot.custom_rules.blocks', []))->keyBy('type')->all();
    }

    public static function maxBlocks(string $side): int
    {
        return max(1, (int) config("aibot.custom_rules.max_blocks.{$side}", 6));
    }

    /** กฎว่าง — รูปเดียวกับที่ normalize() คืนเสมอ */
    public static function blank(): array
    {
        return [
            'entry' => ['match' => self::DEFAULT_MATCH['entry'], 'blocks' => []],
            'exit' => ['match' => self::DEFAULT_MATCH['exit'], 'blocks' => []],
        ];
    }

    /**
     * ตรวจกฎดิบจากผู้ใช้แบบเข้ม.
     *
     * @return list<string> ข้อความของทุกจุดที่ผิด (แปลตามภาษาของคำขอแล้ว) — ว่าง = ผ่าน
     */
    public static function validate(mixed $raw): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            return [__('กฎต้องเป็นข้อมูลแบบโครงสร้างที่มีฝั่ง entry และ exit')];
        }

        $errors = [];

        // รายงานส่วนแปลกปลอมแค่ตัวแรก — ข้อความผิดพลาดต้องไม่บวมตามขนาดของคำขอ
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::SIDES, true)) {
                $errors[] = __('กฎมีส่วนที่ระบบไม่รู้จัก (:field)', ['field' => self::printable($key)]);

                break;
            }
        }

        $specs = self::blockSpecs();
        $matches = (array) config('aibot.custom_rules.match', ['all', 'any']);

        foreach (self::SIDES as $side) {
            $group = $raw[$side] ?? [];

            if (! is_array($group) || ($group !== [] && array_is_list($group))) {
                $errors[] = __('ฝั่ง :group ต้องมีวิธีรวมเงื่อนไข (match) และรายการเงื่อนไข (blocks)', ['group' => $side]);

                continue;
            }

            foreach (array_keys($group) as $key) {
                if (! in_array($key, ['match', 'blocks'], true)) {
                    $errors[] = __('กฎมีส่วนที่ระบบไม่รู้จัก (:field)', ['field' => $side.'.'.self::printable($key)]);

                    break;
                }
            }

            if (array_key_exists('match', $group) && ! in_array($group['match'], $matches, true)) {
                $errors[] = __('ฝั่ง :group: วิธีรวมเงื่อนไขต้องเป็น all (ครบทุกข้อ) หรือ any (ข้อใดข้อหนึ่ง)', ['group' => $side]);
            }

            $blocks = $group['blocks'] ?? [];

            if (! is_array($blocks) || ! array_is_list($blocks)) {
                $errors[] = __('ฝั่ง :group: รายการเงื่อนไขต้องเป็นรายการเรียงลำดับ', ['group' => $side]);

                continue;
            }

            $max = self::maxBlocks($side);

            if (count($blocks) > $max) {
                $errors[] = __('ฝั่ง :group มีเงื่อนไขได้ไม่เกิน :max ข้อ (ส่งมา :count ข้อ)', ['group' => $side, 'max' => $max, 'count' => count($blocks)]);

                // ไม่ไล่ตรวจทีละข้อของชุดที่ใหญ่เกิน — คำขอที่ยัดมาเป็นพันข้อต้องจบที่นี่
                continue;
            }

            foreach ($blocks as $index => $block) {
                foreach (self::blockErrors($block, $side, $index + 1, $specs) as $error) {
                    $errors[] = $error;
                }
            }
        }

        $entryBlocks = $raw['entry']['blocks'] ?? null;

        if ($errors === [] && (! is_array($entryBlocks) || $entryBlocks === [])) {
            $errors[] = __('ต้องมีเงื่อนไขเข้าอย่างน้อย 1 ข้อ — บอทที่ไม่มีเงื่อนไขเข้าจะไม่เปิดไม้เลย');
        }

        return $errors;
    }

    /** @return list<string> */
    private static function blockErrors(mixed $block, string $side, int $n, array $specs): array
    {
        $where = ['group' => $side, 'n' => $n];

        if (! is_array($block) || array_is_list($block) || ! is_string($block['type'] ?? null)) {
            return [__('ฝั่ง :group ข้อที่ :n: ต้องระบุชนิดของเงื่อนไข (type)', $where)];
        }

        $spec = $specs[$block['type']] ?? null;

        if ($spec === null) {
            return [__('ฝั่ง :group ข้อที่ :n: ไม่รู้จักเงื่อนไขชนิด :type', $where + ['type' => self::printable($block['type'])])];
        }

        if (! in_array($side, (array) ($spec['use'] ?? self::SIDES), true)) {
            return [__('ฝั่ง :group ข้อที่ :n: เงื่อนไขชนิด :type ใช้กับฝั่งนี้ไม่ได้', $where + ['type' => $spec['type']])];
        }

        $errors = [];
        $fields = collect((array) ($spec['fields'] ?? []))->keyBy('key');

        foreach (array_keys($block) as $key) {
            if ($key !== 'type' && ! $fields->has($key)) {
                $errors[] = __('ฝั่ง :group ข้อที่ :n: มีช่องที่ระบบไม่รู้จัก (:field)', $where + ['field' => self::printable($key)]);

                break;
            }
        }

        foreach ($fields as $key => $field) {
            // ไม่ส่งช่องมา = ใช้ค่าปริยายของช่องนั้น (แอพ/ลูกค้า API ไม่ต้องรู้จักทุกช่อง)
            if (! array_key_exists($key, $block)) {
                continue;
            }

            $value = $block[$key];

            if ($field['type'] === 'select') {
                if (! is_string($value) || ! in_array($value, $field['options'], true)) {
                    $errors[] = __('ฝั่ง :group ข้อที่ :n: ช่อง :field ต้องเป็นหนึ่งใน :options', $where + ['field' => $key, 'options' => implode(', ', $field['options'])]);
                }

                continue;
            }

            $number = self::finiteNumber($value);
            $wholeNeeded = $field['type'] === 'int';

            if ($number === null
                || $number < (float) $field['min']
                || $number > (float) $field['max']
                || ($wholeNeeded && floor($number) !== $number)) {
                $range = $where + ['field' => $key, 'min' => $field['min'], 'max' => $field['max']];

                $errors[] = $wholeNeeded
                    ? __('ฝั่ง :group ข้อที่ :n: ช่อง :field ต้องเป็นจำนวนเต็มระหว่าง :min ถึง :max', $range)
                    : __('ฝั่ง :group ข้อที่ :n: ช่อง :field ต้องเป็นตัวเลขระหว่าง :min ถึง :max', $range);
            }
        }

        if ($errors === [] && $spec['type'] === 'ema_cross') {
            $clean = self::cleanBlock($block, $spec);

            if ($clean['fast'] >= $clean['slow']) {
                $errors[] = __('ฝั่ง :group ข้อที่ :n: EMA เร็วต้องน้อยกว่า EMA ช้า', $where);
            }
        }

        return $errors;
    }

    /**
     * ล้างกฎให้อยู่ในรูปมาตรฐาน — เรียกซ้ำกี่รอบก็ได้ผลเดิม (idempotent).
     *
     * @return array{entry: array{match: string, blocks: list<array>}, exit: array{match: string, blocks: list<array>}, invalid?: true}
     */
    public static function normalize(mixed $raw): array
    {
        $out = self::blank();

        if (! is_array($raw)) {
            return $out;
        }

        $specs = self::blockSpecs();
        $matches = (array) config('aibot.custom_rules.match', ['all', 'any']);
        $invalid = ($raw['invalid'] ?? false) === true;

        foreach (self::SIDES as $side) {
            $group = is_array($raw[$side] ?? null) ? $raw[$side] : [];

            if (in_array($group['match'] ?? null, $matches, true)) {
                $out[$side]['match'] = $group['match'];
            }

            $blocks = is_array($group['blocks'] ?? null) ? array_values($group['blocks']) : [];

            if (count($blocks) > self::maxBlocks($side)) {
                $invalid = true;
                $blocks = array_slice($blocks, 0, self::maxBlocks($side));
            }

            foreach ($blocks as $block) {
                $spec = is_array($block) && is_string($block['type'] ?? null) ? ($specs[$block['type']] ?? null) : null;

                if ($spec === null || ! in_array($side, (array) ($spec['use'] ?? self::SIDES), true)) {
                    $invalid = true;

                    continue;
                }

                $clean = self::cleanBlock($block, $spec);

                if ($spec['type'] === 'ema_cross' && $clean['fast'] >= $clean['slow']) {
                    $invalid = true;

                    continue;
                }

                $out[$side]['blocks'][] = $clean;
            }
        }

        if ($invalid) {
            $out['invalid'] = true;
        }

        return $out;
    }

    /** บล็อกหนึ่งข้อในรูปมาตรฐาน: type ก่อน แล้วตามด้วยช่องตามลำดับใน config · ตัวเลขถูกบีบเข้าช่วง */
    private static function cleanBlock(array $block, array $spec): array
    {
        $clean = ['type' => $spec['type']];

        foreach ((array) ($spec['fields'] ?? []) as $field) {
            $value = $block[$field['key']] ?? $field['default'];

            if ($field['type'] === 'select') {
                $clean[$field['key']] = is_string($value) && in_array($value, $field['options'], true) ? $value : $field['default'];

                continue;
            }

            $number = self::finiteNumber($value) ?? (float) $field['default'];
            $number = max((float) $field['min'], min((float) $field['max'], $number));

            $clean[$field['key']] = $field['type'] === 'int' ? (int) round($number) : round($number, 6);
        }

        return $clean;
    }

    /**
     * จำนวนแท่งขั้นต่ำที่กฎชุดนี้ต้องใช้ — บล็อกที่ต้องการมากที่สุดเป็นตัวกำหนด.
     *
     * @param  array  $rules  กฎที่ผ่าน normalize() แล้ว
     */
    public static function minCandles(array $rules): int
    {
        $need = 30;

        foreach (self::SIDES as $side) {
            foreach ($rules[$side]['blocks'] ?? [] as $block) {
                $need = max($need, match ($block['type'] ?? '') {
                    // เท่ากับ momentum: EMA ช้าต้องคำนวณได้สองจุดถึงจะรู้ว่าตัดกันหรือยัง
                    'ema_cross' => (int) $block['slow'] + 22,
                    'rsi' => (int) $block['period'] + 16,
                    // เท่ากับ breakout: กรอบ + ATR(14)
                    'channel_breakout' => max((int) $block['period'], 14) + 16,
                    'atr' => (int) $block['period'] + 2,
                    'volume_ratio' => (int) $block['period'] + 1,
                    'price_change' => (int) $block['bars'] + 1,
                    // EMA ยาวเริ่มจากค่าเฉลี่ยธรรมดา ต้องมีแท่งให้ลู่เข้าหาค่าจริงก่อนใช้ตัดสิน
                    'trend' => (int) $block['period'] + 150,
                    default => 30,
                });
            }
        }

        return $need;
    }

    /** ตัวเลขจริงที่มีขอบเขต — บูลีน ข้อความ อาเรย์ NaN และ INF ไม่ใช่ */
    private static function finiteNumber(mixed $value): ?float
    {
        if (is_bool($value) || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /** ชื่อคีย์จากผู้ใช้ที่จะใส่ในข้อความผิดพลาด — ตัดให้สั้นและเหลือเฉพาะอักขระธรรมดา */
    private static function printable(mixed $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9_.\-]/', '?', is_scalar($value) ? (string) $value : '?'), 0, 40);
    }
}
