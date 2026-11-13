/**
 * TPIX TRADE — กฎของโหมด "วางกลยุทธ์เอง" ฝั่งหน้าเว็บ
 *
 * กฎเป็นข้อมูลโครงสร้าง:
 *   { entry: { match: 'all'|'any', blocks: [{ type, ...fields }] }, exit: { match, blocks } }
 *
 * รายการบล็อก/ช่อง/ช่วงตัวเลขมาจากเซิร์ฟเวอร์ (catalog.custom_rules) — ไฟล์นี้ไม่รู้จักชนิดบล็อกใดเป็นพิเศษ
 * เพิ่มบล็อกใหม่ที่ config/aibot.php แล้วตัวสร้างกฎวาดให้เองโดยไม่ต้องแก้หน้าเว็บ
 *
 * การตรวจที่นี่มีไว้บอกผู้ใช้ก่อนกดบันทึกเท่านั้น — ด่านจริงอยู่ที่เซิร์ฟเวอร์ (RuleSet::validate)
 *
 * Developed by Xman Studio
 */

export const RULE_SIDES = ['entry', 'exit'];

const DEFAULT_MATCH = { entry: 'all', exit: 'any' };

/** กฎว่าง — รูปเดียวกับที่เซิร์ฟเวอร์คืน */
export function emptyRules() {
    return {
        entry: { match: DEFAULT_MATCH.entry, blocks: [] },
        exit: { match: DEFAULT_MATCH.exit, blocks: [] },
    };
}

function blockSpecs(schema) {
    return Array.isArray(schema?.blocks) ? schema.blocks : [];
}

export function blockSpec(schema, type) {
    return blockSpecs(schema).find((spec) => spec.type === type) ?? null;
}

/** บล็อกที่ใช้กับฝั่งนี้ได้ (เช่น "ซื้อตามรอบเวลา" ใช้ได้เฉพาะฝั่งเข้า) */
export function blocksFor(schema, side) {
    return blockSpecs(schema).filter((spec) => (spec.use ?? RULE_SIDES).includes(side));
}

export function maxBlocks(schema, side) {
    const max = Number(schema?.max_blocks?.[side]);

    return Number.isFinite(max) && max > 0 ? max : 6;
}

/** บล็อกใหม่ของชนิดนี้ด้วยค่าปริยายของทุกช่อง */
export function newBlock(schema, type) {
    const spec = blockSpec(schema, type);
    if (!spec) return null;

    const block = { type: spec.type };
    (spec.fields ?? []).forEach((field) => { block[field.key] = field.default; });

    return block;
}

/**
 * จัดกฎให้อยู่ในรูปมาตรฐานสำหรับแสดง/แก้ — ไม่บีบตัวเลข (ผู้ใช้ต้องเห็นค่าที่ตัวเองพิมพ์พร้อมคำเตือน)
 * บล็อกที่รายการของเซิร์ฟเวอร์ไม่รู้จักถูกเก็บไว้ตามเดิมเพื่อให้ผู้ใช้เห็นและลบเอง ไม่หายไปเงียบๆ
 */
export function normalizeRules(rules, schema) {
    const out = emptyRules();
    const matches = Array.isArray(schema?.match) && schema.match.length ? schema.match : ['all', 'any'];

    RULE_SIDES.forEach((side) => {
        const group = rules && typeof rules === 'object' ? rules[side] : null;

        if (matches.includes(group?.match)) out[side].match = group.match;

        (Array.isArray(group?.blocks) ? group.blocks : []).forEach((raw) => {
            if (!raw || typeof raw !== 'object' || typeof raw.type !== 'string') return;

            const spec = blockSpec(schema, raw.type);
            if (!spec) {
                out[side].blocks.push({ ...raw });
                return;
            }

            const block = { type: spec.type };
            (spec.fields ?? []).forEach((field) => {
                const value = raw[field.key];
                block[field.key] = value === undefined || value === null ? field.default : value;
            });
            out[side].blocks.push(block);
        });
    });

    return out;
}

/** กฎที่ส่งให้เซิร์ฟเวอร์ — ตัดธง invalid และคีย์อื่นที่ไม่ใช่ entry/exit ออก */
export function rulesPayload(rules, schema) {
    const { entry, exit } = normalizeRules(rules, schema);

    return { entry, exit };
}

function isNumberInRange(value, field) {
    if (value === '' || value === null || typeof value === 'boolean') return false;

    const number = Number(value);
    if (!Number.isFinite(number)) return false;
    if (number < Number(field.min) || number > Number(field.max)) return false;

    return field.type !== 'int' || Number.isInteger(number);
}

/**
 * ปัญหาของบล็อกเดียว — คืนรายการ { code, field?, min?, max? } (ว่าง = ใช้ได้)
 * code: unknown_type | wrong_side | range | integer | option | ema_order
 */
export function blockProblems(block, side, schema) {
    const spec = blockSpec(schema, block?.type);
    if (!spec) return [{ code: 'unknown_type' }];
    if (!(spec.use ?? RULE_SIDES).includes(side)) return [{ code: 'wrong_side' }];

    const problems = [];

    (spec.fields ?? []).forEach((field) => {
        const value = block[field.key];

        if (field.type === 'select') {
            if (!(field.options ?? []).includes(value)) problems.push({ code: 'option', field: field.key });
            return;
        }

        if (!isNumberInRange(value, field)) {
            const whole = field.type === 'int' && Number.isFinite(Number(value)) && !Number.isInteger(Number(value));
            problems.push({ code: whole ? 'integer' : 'range', field: field.key, min: field.min, max: field.max });
        }
    });

    // กฎข้ามช่องเดียวที่มีตอนนี้: EMA เร็วต้องสั้นกว่า EMA ช้า (เซิร์ฟเวอร์ตรวจแบบเดียวกัน)
    if (!problems.length && spec.type === 'ema_cross' && Number(block.fast) >= Number(block.slow)) {
        problems.push({ code: 'ema_order' });
    }

    return problems;
}

/**
 * ปัญหาของกฎทั้งชุด — คืนรายการ { side, index?, code, ... } (ว่าง = ส่งได้)
 * code ระดับชุด: no_entry | too_many
 */
export function rulesProblems(rules, schema) {
    const clean = normalizeRules(rules, schema);
    const problems = [];

    RULE_SIDES.forEach((side) => {
        const blocks = clean[side].blocks;

        if (blocks.length > maxBlocks(schema, side)) {
            problems.push({ side, code: 'too_many', max: maxBlocks(schema, side) });
        }

        blocks.forEach((block, index) => {
            blockProblems(block, side, schema).forEach((problem) => problems.push({ side, index, type: block.type, ...problem }));
        });
    });

    if (!clean.entry.blocks.length) problems.unshift({ side: 'entry', code: 'no_entry' });

    return problems;
}

/**
 * ข้อความของปัญหาหนึ่งข้อ (จาก blockProblems / rulesProblems) ตามภาษาที่ใช้อยู่.
 *
 * @param {object} problem  { code, field?, min?, max?, type? }
 * @param {Function} t      ตัวแปลของ useTranslation
 * @param {string} locale   'th' | 'en' — ป้ายของช่องมาจาก schema (label / label_en)
 */
export function problemMessage(problem, schema, t, locale) {
    const field = (blockSpec(schema, problem.type)?.fields ?? []).find((f) => f.key === problem.field);
    const label = field ? ((locale === 'th' ? field.label : field.label_en || field.label) || field.key) : (problem.field ?? '');
    const params = { field: label, min: problem.min, max: problem.max };

    const key = {
        unknown_type: 'aiTrade.rules.unknownBlock',
        wrong_side: 'aiTrade.rules.wrongSide',
        range: 'aiTrade.rules.problemRange',
        integer: 'aiTrade.rules.problemInteger',
        option: 'aiTrade.rules.problemOption',
        ema_order: 'aiTrade.rules.problemEmaOrder',
        no_entry: 'aiTrade.rules.noEntry',
        too_many: 'aiTrade.rules.problemTooMany',
    }[problem.code];

    return key ? t(key, params) : '';
}

/** สองกฎเหมือนกันไหม (เทียบรูปมาตรฐาน) — ใช้จับว่าฟอร์มยังตรงกับเทมเพลต/ผลทดสอบล่าสุดอยู่หรือเปล่า */
export function sameRules(a, b, schema) {
    return JSON.stringify(rulesPayload(a, schema)) === JSON.stringify(rulesPayload(b, schema));
}

/** สำเนาลึกของค่าที่เป็นข้อมูลล้วน — กันฟอร์มไปแก้ของในแคตตาล็อก/เทมเพลตที่ใช้ร่วมกัน */
export function cloneValue(value) {
    return value !== null && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value;
}

/** นาทีต่อแท่งของกรอบเวลา (ไม่รู้จัก = null) */
export function timeframeMinutes(timeframe) {
    return { '1m': 1, '5m': 5, '15m': 15, '1h': 60, '4h': 240, '1d': 1440 }[timeframe] ?? null;
}
