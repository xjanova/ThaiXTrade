/**
 * TPIX TRADE — รายการบล็อกของโหมด "วางกลยุทธ์เอง" สำหรับเทสต์หน้าเว็บ
 *
 * รูปเดียวกับ catalog.custom_rules ที่ /api/v1/ai-bot/catalog ส่งมา (ย่อจาก config/aibot.php)
 * หน้าเว็บไม่รู้จักชนิดบล็อกใดเป็นพิเศษ — เทสต์จึงใช้ชุดย่อนี้ได้โดยไม่ต้องตามรายการจริงทุกตัว
 *
 * Developed by Xman Studio
 */

export const RULE_SCHEMA = {
    max_blocks: { entry: 3, exit: 2 },
    match: ['all', 'any'],
    backtest: { max_bars: 9000, max_days: 730, default_days: 180 },
    blocks: [
        {
            type: 'ema_cross',
            label: 'เส้น EMA เร็ว/ช้า',
            label_en: 'EMA fast / slow',
            hint: 'เทียบ EMA สองเส้น',
            hint_en: 'Compares two EMAs',
            use: ['entry', 'exit'],
            fields: [
                {
                    key: 'when', label: 'เมื่อ', label_en: 'When', type: 'select', default: 'crosses_above',
                    options: ['crosses_above', 'crosses_below'],
                    option_labels: {
                        crosses_above: { th: 'ตัดขึ้น', en: 'Fast EMA crosses above slow EMA' },
                        crosses_below: { th: 'ตัดลง', en: 'Fast EMA crosses below slow EMA' },
                    },
                },
                { key: 'fast', label: 'EMA เร็ว (แท่ง)', label_en: 'Fast EMA (bars)', type: 'int', default: 12, min: 2, max: 100 },
                { key: 'slow', label: 'EMA ช้า (แท่ง)', label_en: 'Slow EMA (bars)', type: 'int', default: 26, min: 3, max: 200 },
            ],
        },
        {
            type: 'rsi',
            label: 'RSI',
            label_en: 'RSI',
            hint: 'ดัชนีกำลังสัมพัทธ์',
            hint_en: 'Relative Strength Index',
            use: ['entry', 'exit'],
            fields: [
                {
                    key: 'op', label: 'เมื่อ RSI', label_en: 'When RSI is', type: 'select', default: 'below',
                    options: ['below', 'above'],
                    option_labels: { below: { th: 'ต่ำกว่า', en: 'below' }, above: { th: 'สูงกว่า', en: 'above' } },
                },
                { key: 'period', label: 'คาบ RSI (แท่ง)', label_en: 'RSI period (bars)', type: 'int', default: 14, min: 2, max: 100 },
                { key: 'level', label: 'ระดับ', label_en: 'Level', type: 'number', default: 30, min: 1, max: 99, step: 1 },
            ],
        },
        {
            type: 'interval',
            label: 'ซื้อตามรอบเวลา',
            label_en: 'Fixed-interval buy',
            hint: 'ครบ N แท่งนับจากการซื้อครั้งล่าสุด',
            hint_en: 'True once N bars have passed since the last buy',
            use: ['entry'],
            fields: [
                { key: 'bars', label: 'ทุกกี่แท่ง', label_en: 'Every (bars)', type: 'int', default: 24, min: 1, max: 2000 },
            ],
        },
    ],
};

export function validRules() {
    return {
        entry: { match: 'all', blocks: [{ type: 'ema_cross', when: 'crosses_above', fast: 12, slow: 26 }] },
        exit: { match: 'any', blocks: [{ type: 'rsi', op: 'above', period: 14, level: 70 }] },
    };
}
