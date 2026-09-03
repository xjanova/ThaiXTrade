/**
 * TPIX TRADE — useTradeLayout
 * ผังการ์ดของหน้าเทรดที่ผู้ใช้ลากสลับ/ซ่อน/ย่อ/แบ่งคอลัมน์ย่อยได้เอง แล้วจำไว้ในเครื่อง
 *
 * โครงเป็นสองชั้น: คอลัมน์ประกอบด้วย "แถว" และแถวหนึ่งวางการ์ดเคียงกันได้ไม่เกิน 2 ใบ
 *   center: [ ['chart'], ['form', 'book'] ]   →  กราฟเต็มความกว้าง แล้วใต้กราฟแบ่งซ้าย/ขวา
 *
 * เป็น singleton ระดับโมดูลโดยตั้งใจ — หน้าเทรดมีผังเดียว การ์ดลูก (DraggableCard)
 * จึงเรียก useTradeLayout() ได้ตรงๆ ไม่ต้องส่ง prop ลงไปทีละชั้น
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';

// v4 = ผังเปลี่ยนจากอาร์เรย์การ์ดชั้นเดียวเป็นอาร์เรย์ของแถว
// ต้องขึ้นเวอร์ชันคีย์ ไม่งั้นค่าที่ผู้ใช้เดิมบันทึกไว้จะถูกอ่านผิดรูป
const STORAGE_KEY = 'tpix.tradeLayout.v4';

// ผังเวอร์ชันก่อน (การ์ดใบละช่อง ไม่มีแถว) — อ่านต่อได้เพราะ normalize รับค่าเดี่ยวอยู่แล้ว
// ไม่งั้นผู้ใช้ที่จัดผังไว้เองจะโดนรีเซ็ตกลับค่าเริ่มต้นทั้งหมดโดยไม่มีใครบอก
const LEGACY_STORAGE_KEY = 'tpix.tradeLayout.v3';

/** คอลัมน์ที่รองรับ — ลำดับนี้คือลำดับที่แสดงบนจอกว้าง */
export const COLUMNS = ['left', 'center', 'right', 'far'];

/**
 * การ์ดที่วางเคียงกันได้สูงสุดต่อแถว
 *
 * ที่หยุดไว้ที่ 2 เพราะคอลัมน์กลางบนจอ 1920 กว้างราว 900px — แบ่ง 3 แล้วเหลือ
 * ใบละ ~290px ซึ่งแคบกว่า minWidth ของฟอร์มซื้อขาย (260) บวกสมุดคำสั่ง (200) รวมกัน
 */
export const MAX_PER_ROW = 2;

/** ระยะห่างระหว่างการ์ดในแถวเดียวกัน — ต้องตรงกับ gap-3 ของ Tailwind */
const ROW_GAP = 12;

const DEFAULT_SPLIT = 50;
const MIN_SPLIT = 25;
const MAX_SPLIT = 75;

/**
 * ทะเบียนการ์ดทั้งหมดของหน้าเทรด
 * - `essential` = ซ่อนไม่ได้ (ไม่งั้นผู้ใช้ซ่อนฟอร์มเทรดแล้วหาทางกลับไม่เจอ)
 * - `titleKey`  = คีย์คำแปล (ห้ามเก็บข้อความตรงๆ ไม่งั้นสลับภาษาแล้วหัวการ์ดไม่เปลี่ยน)
 * - `art`       = ชื่อไฟล์ภาพประกอบจางๆ ใน /images/art/ (ไม่มีไฟล์ก็ไม่พัง — PageArt ซ่อนเอง)
 */
export const TRADE_CARDS = [
    { id: 'market', titleKey: 'trade.card.market', column: 'left', essential: false, art: 'card-market' },
    { id: 'ai', titleKey: 'trade.card.ai', column: 'left', essential: false, art: 'card-aitrade' },
    { id: 'chart', titleKey: 'trade.card.chart', column: 'center', essential: true, art: null },
    { id: 'form', titleKey: 'trade.card.form', column: 'center', essential: true, art: 'card-form' },
    // สมุดคำสั่งอยู่คอลัมน์ของตัวเอง — เป็นการ์ดที่ได้ประโยชน์จากความสูงมากที่สุด
    { id: 'book', titleKey: 'trade.card.book', column: 'right', essential: false, art: 'card-book' },
    { id: 'orders', titleKey: 'trade.card.orders', column: 'far', essential: false, art: 'card-orders' },
    { id: 'trades', titleKey: 'trade.card.trades', column: 'far', essential: false, art: 'card-trades' },
];

const CARD_IDS = TRADE_CARDS.map(c => c.id);

/**
 * พฤติกรรมการยืด/หดของการ์ด
 *  grow  = น้ำหนักการแบ่ง "ความสูง" ที่เหลือในโหมดพอดีหน้าจอ (0 = สูงตามเนื้อหา)
 *  min   = ความสูงต่ำสุดที่ยังอ่านข้อมูลข้างในรู้เรื่อง
 *  minW  = ความกว้างต่ำสุดที่การ์ดยังใช้งานได้จริง — ใช้ตอนคำนวณว่าแถวคู่ใส่ลงคอลัมน์ไหม
 *
 * ⚠️ minW ต้องแยกจาก min เด็ดขาด: ฟอร์มซื้อขายมี min เป็น 0 (สูงตามเนื้อหา) แต่
 *    ต้องการความกว้างอย่างน้อย 260px สำหรับช่องกรอก + ปุ่มซื้อ/ขาย ถ้าเอา min
 *    มาใช้เป็นความกว้างเหมือนเดิม แถว [ฟอร์ม, สมุดคำสั่ง] จะคำนวณได้ 200px แล้วบี้จนพัง
 */
export const CARD_FLEX = {
    market: { grow: 5, min: 220, minW: 220 },
    chart: { grow: 8, min: 240, minW: 320 },
    orders: { grow: 3, min: 170, minW: 200 },
    book: { grow: 5, min: 200, minW: 200 },
    // ฟอร์มสูงตามเนื้อหา (บีบช่องกรอกไม่ได้) แต่ต้องไม่กินคอลัมน์จนกราฟเหลือแค่ min
    // → หดได้ + เพดาน 58% ของคอลัมน์ แล้วเลื่อนข้างในเอาถ้ายังไม่พอ
    form: { grow: 0, min: 0, maxPct: 58, minW: 260 },
    ai: { grow: 2, min: 180, minW: 200 },
    trades: { grow: 3, min: 150, minW: 180 },
};

const FALLBACK_FLEX = { grow: 1, min: 120, minW: 160 };

const flexOf = id => CARD_FLEX[id] || FALLBACK_FLEX;

/** ความสูงที่ใช้เมื่อ "ไม่" พอดีหน้าจอ (โหมดเลื่อนหน้า) — null = สูงตามเนื้อหา */
const PREFERRED_HEIGHT = {
    market: 420,
    chart: null, // มาจาก chartHeightPx
    orders: null,
    book: 400,
    form: null,
    ai: null,
    trades: 280,
};

/**
 * ลำดับการ์ดบนจอแคบ (คอลัมน์และแถวยุบเป็นแถวเดียว)
 * ต่างจากลำดับในคอลัมน์โดยตั้งใจ — บนมือถือ "ฟอร์มซื้อขาย" ต้องอยู่ใต้กราฟทันที
 *
 * เขียนเป็นคลาสเต็มคำ ไม่ต่อสตริง เพราะ Tailwind สแกนไฟล์แบบข้อความ —
 * `'order-' + n` จะไม่ถูก generate ออกมาเลย
 */
export const STACK_CLASS = {
    chart: 'order-1 lg:order-none',
    form: 'order-2 lg:order-none',
    book: 'order-3 lg:order-none',
    market: 'order-4 lg:order-none',
    trades: 'order-5 lg:order-none',
    ai: 'order-6 lg:order-none',
    orders: 'order-7 lg:order-none',
};

/** ความสูงกราฟที่เลือกได้ — ใช้เฉพาะโหมดเลื่อนหน้า (โหมดพอดีจอกราฟยืดเอง) */
export const CHART_HEIGHTS = [
    { id: 'sm', labelKey: 'trade.layout.small', px: 340 },
    { id: 'md', labelKey: 'trade.layout.medium', px: 460 },
    { id: 'lg', labelKey: 'trade.layout.large', px: 600 },
];

/** คีย์ของแถว = id ของการ์ดใบแรก
 *
 * ที่ไม่ใช้ลำดับที่ (index) เพราะแถวถูกแทรก/ลบได้ตลอด พอลำดับเลื่อน สัดส่วนที่
 * ผู้ใช้ปรับไว้จะย้ายไปสวมแถวอื่นเงียบๆ — ผูกกับใบแรกแล้วสัดส่วนติดไปกับแถวจริง
 */
const rowKey = row => row?.[0] ?? null;

const clampSplit = pct => Math.min(MAX_SPLIT, Math.max(MIN_SPLIT, Math.round(pct)));

/**
 * ความกว้างขั้นต่ำที่คอลัมน์หนึ่งต้องการ = แถวที่ต้องการกว้างที่สุดในคอลัมน์นั้น
 *
 * แถวที่มี 2 ใบต้องกว้างพอสำหรับ "ทั้งคู่บวกช่องไฟ" ไม่ใช่แค่ใบที่กว้างสุด
 * ใช้สร้าง grid template แบบไดนามิก จึงไม่ต้อง hardcode ความกว้างตามชื่อคอลัมน์
 * (ซึ่งจะผิดทันทีที่ผู้ใช้ลากการ์ดสลับคอลัมน์กัน)
 */
function columnMinWidth(rows) {
    if (!rows?.length) return 0;

    return rows.reduce((widest, row) => {
        const rowMin = row.reduce((sum, id) => sum + flexOf(id).minW, 0) + (row.length - 1) * ROW_GAP;
        return Math.max(widest, rowMin);
    }, 0);
}

/** ผังตั้งต้น — การ์ดใบละแถว ตามคอลัมน์ที่ประกาศไว้ในทะเบียน */
function defaultColumns() {
    return COLUMNS.reduce((acc, col) => {
        acc[col] = TRADE_CARDS.filter(c => c.column === col).map(c => [c.id]);
        return acc;
    }, {});
}

// ── state (singleton) ───────────────────────────────────────────────────────
const columns = ref(defaultColumns());
const hidden = ref([]);
const collapsed = ref([]);
/** สัดส่วนกว้างของการ์ดใบซ้ายในแถวคู่ (%) — คีย์ = id ของใบแรกในแถว */
const rowSplit = ref({});
/** น้ำหนักความสูงที่ผู้ใช้ลากปรับเอง — คีย์ = id ของใบแรกในแถว */
const rowGrow = ref({});
const chartHeight = ref('md');
const fitScreen = ref(true);

/** การ์ดที่กำลังลาก + ตำแหน่งที่จะวาง — ใช้วาดเส้นบอกจุดแทรก */
const draggingId = ref(null);
const dropHint = ref(null);

let loaded = false;

/**
 * รวมผังที่บันทึกไว้เข้ากับทะเบียนการ์ดปัจจุบัน
 * - id ที่ไม่รู้จักแล้ว (ถอดออกจากเวอร์ชันใหม่) → ทิ้ง
 * - id ใหม่ที่ยังไม่เคยมีในผังเก่า → ใส่กลับคอลัมน์ตั้งต้นเป็นแถวใหม่ ไม่ใช่หายไปเฉยๆ
 * - แถวที่ยาวเกิน MAX_PER_ROW → ตัดเป็นหลายแถว แทนที่จะบี้การ์ดจนอ่านไม่ออก
 * ทำให้เพิ่มการ์ดใหม่ในอนาคตแล้วผู้ใช้เก่าเห็นด้วย โดยไม่ต้องรีเซ็ตผัง
 */
function normalize(saved) {
    const next = COLUMNS.reduce((acc, col) => ({ ...acc, [col]: [] }), {});
    const seen = new Set();

    COLUMNS.forEach(col => {
        (saved?.[col] || []).forEach(entry => {
            // รับทั้งแถว (['a','b']) และค่าเดี่ยว ('a') เผื่อค่าที่บันทึกไว้ถูกแก้มือ/เสียรูป
            const row = (Array.isArray(entry) ? entry : [entry])
                .filter(id => CARD_IDS.includes(id) && !seen.has(id));

            row.forEach(id => seen.add(id));

            for (let i = 0; i < row.length; i += MAX_PER_ROW) {
                next[col].push(row.slice(i, i + MAX_PER_ROW));
            }
        });
    });

    TRADE_CARDS.forEach(card => {
        if (!seen.has(card.id)) next[card.column].push([card.id]);
    });

    return next;
}

/**
 * เก็บเฉพาะสัดส่วนของแถวที่ยังมีอยู่จริง — การ์ดที่ถูกย้ายไปแล้วต้องไม่ทิ้งค่าค้าง
 *
 * กรองค่าที่ไม่ใช่ตัวเลขจำกัดทิ้งด้วย เพราะค่าใน localStorage แก้มือได้:
 * ถ้า grow เป็น NaN / Infinity / สตริง เบราว์เซอร์จะทิ้งทั้งบรรทัด flex เงียบๆ
 * แล้วแถวจะยุบเหลือความสูงเนื้อหาโดยไม่มีใครรู้ว่าค่าที่บันทึกไว้เสีย
 */
function pickLiveKeys(map) {
    const leads = new Set(COLUMNS.flatMap(col => columns.value[col].map(rowKey)));

    return Object.fromEntries(
        Object.entries(map || {})
            .filter(([id, value]) => leads.has(id) && Number.isFinite(value) && value >= 0)
    );
}

function load() {
    if (loaded || typeof window === 'undefined') return;
    loaded = true;

    try {
        const raw = JSON.parse(
            localStorage.getItem(STORAGE_KEY) || localStorage.getItem(LEGACY_STORAGE_KEY) || 'null'
        );
        columns.value = normalize(raw?.columns);
        hidden.value = (raw?.hidden || []).filter(id => CARD_IDS.includes(id));
        collapsed.value = (raw?.collapsed || []).filter(id => CARD_IDS.includes(id));
        rowSplit.value = pickLiveKeys(raw?.rowSplit);
        rowGrow.value = pickLiveKeys(raw?.rowGrow);
        if (CHART_HEIGHTS.some(h => h.id === raw?.chartHeight)) chartHeight.value = raw.chartHeight;
        if (typeof raw?.fitScreen === 'boolean') fitScreen.value = raw.fitScreen;
    } catch {
        // ค่าเสีย/โหมดส่วนตัว — ใช้ผังตั้งต้น ไม่ต้องแจ้งผู้ใช้
        columns.value = defaultColumns();
    }
}

function persist() {
    if (typeof window === 'undefined') return;
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            columns: columns.value,
            hidden: hidden.value,
            collapsed: collapsed.value,
            rowSplit: rowSplit.value,
            rowGrow: rowGrow.value,
            chartHeight: chartHeight.value,
            fitScreen: fitScreen.value,
        }));
    } catch {
        // โควตาเต็ม — ผังยังใช้ได้ในหน้านี้ แค่ไม่ถูกจำไว้
    }
}

let persistTimer = null;
watch([columns, hidden, collapsed, rowSplit, rowGrow, chartHeight, fitScreen], () => {
    clearTimeout(persistTimer);
    persistTimer = setTimeout(persist, 150);
}, { deep: true });

// ── การค้นหา/ย้ายการ์ด ───────────────────────────────────────────────────────

/** ตำแหน่งของการ์ดในผัง — { col, rowIndex, index } หรือ null ถ้าไม่เจอ */
function locate(id) {
    for (const col of COLUMNS) {
        const rows = columns.value[col];
        for (let r = 0; r < rows.length; r++) {
            const index = rows[r].indexOf(id);
            if (index > -1) return { col, rowIndex: r, index };
        }
    }

    return null;
}

/** แถวที่การ์ดใบนี้อยู่ (อาร์เรย์ของ id) — ใช้ตัดสินว่ายังรับเพื่อนข้างๆ ได้อีกไหม */
function rowOf(id) {
    const at = locate(id);
    return at ? columns.value[at.col][at.rowIndex] : null;
}

function columnOf(id) {
    return locate(id)?.col ?? null;
}

/**
 * ถอดการ์ดออกจากผัง แล้วเก็บแถวที่ว่างทิ้ง
 * คืนค่าตำแหน่งเดิมไว้ให้ผู้เรียกคำนวณจุดแทรกต่อ
 */
function removeCard(id) {
    const at = locate(id);
    if (!at) return null;

    const rows = columns.value[at.col];
    rows[at.rowIndex].splice(at.index, 1);
    if (!rows[at.rowIndex].length) rows.splice(at.rowIndex, 1);

    return at;
}

/** ล้างสัดส่วน/น้ำหนักที่ไม่มีแถวรองรับแล้ว — เรียกหลังทุกการย้าย */
function prune() {
    rowSplit.value = pickLiveKeys(rowSplit.value);
    rowGrow.value = pickLiveKeys(rowGrow.value);
}

/**
 * วางการ์ดที่ลากอยู่เทียบกับการ์ดเป้าหมาย
 *
 * @param {string} targetId
 * @param {'before'|'after'|'left'|'right'} position
 *   before/after = แถวใหม่เหนือ/ใต้แถวของเป้าหมาย
 *   left/right   = เข้าไปอยู่ในแถวเดียวกับเป้าหมาย (เต็มแล้วจะตกไปเป็นแถวใหม่ด้านล่าง)
 */
function dropOnCard(targetId, position = 'before') {
    const id = draggingId.value;
    if (!id || id === targetId) return;

    const target = locate(targetId);
    if (!target) return;

    const beside = position === 'left' || position === 'right';
    const targetRow = columns.value[target.col][target.rowIndex];

    // แถวเต็มแล้วและใบที่ลากไม่ได้อยู่ในแถวนั้นอยู่ก่อน → ไม่ยัดเพิ่ม ให้ตกไปเป็นแถวใหม่แทน
    if (beside && targetRow.length >= MAX_PER_ROW && !targetRow.includes(id)) {
        return dropOnCard(targetId, 'after');
    }

    removeCard(id);

    // ตำแหน่งของเป้าหมายอาจเลื่อนหลังถอดใบที่ลากออก จึงต้องหาใหม่
    const after = locate(targetId);
    if (!after) return;

    const rows = columns.value[after.col];

    if (beside) {
        rows[after.rowIndex].splice(position === 'left' ? after.index : after.index + 1, 0, id);
    } else {
        rows.splice(position === 'after' ? after.rowIndex + 1 : after.rowIndex, 0, [id]);
    }

    prune();
}

/** วางเป็นแถวใหม่ท้ายคอลัมน์ (ใช้เมื่อปล่อยในพื้นที่ว่างของคอลัมน์) */
function dropOnColumn(column) {
    const id = draggingId.value;
    if (!id || !COLUMNS.includes(column)) return;

    const at = locate(id);
    const rows = columns.value[column];
    // อยู่ท้ายคอลัมน์นั้นลำพังอยู่แล้ว → ไม่ต้องขยับ
    if (at?.col === column && at.rowIndex === rows.length - 1 && rows[at.rowIndex].length === 1) return;

    removeCard(id);
    columns.value[column].push([id]);
    prune();
}

function startDrag(id) {
    draggingId.value = id;
}

function endDrag() {
    draggingId.value = null;
    dropHint.value = null;
}

// ── สัดส่วนภายในแถว / ความสูงของแถว ─────────────────────────────────────────

/** สัดส่วนกว้าง (%) ของการ์ดใบนี้ในแถวคู่ */
function splitPct(row, id) {
    const pct = clampSplit(rowSplit.value[rowKey(row)] ?? DEFAULT_SPLIT);
    return row[0] === id ? pct : 100 - pct;
}

/** ตั้งสัดส่วนใหม่ให้แถว (ค่าที่ส่งเข้ามาคือ % ของใบซ้าย) */
function setRowSplit(row, pct) {
    const key = rowKey(row);
    if (!key) return;
    rowSplit.value = { ...rowSplit.value, [key]: clampSplit(pct) };
}

function resetRowSplit(row) {
    const key = rowKey(row);
    if (!key) return;
    const next = { ...rowSplit.value };
    delete next[key];
    rowSplit.value = next;
}

/**
 * พฤติกรรมความสูงของ "แถว" หนึ่งแถว
 * แถวใบเดียวใช้ค่าของการ์ดใบนั้นตรงๆ — ผังตั้งต้นจึงหน้าตาเหมือนเดิมทุกพิกเซล
 */
function rowFlex(row) {
    if (row.length === 1) return flexOf(row[0]);

    const parts = row.map(flexOf);

    return {
        grow: parts.reduce((sum, p) => sum + (p.grow || 0), 0),
        min: parts.reduce((tallest, p) => Math.max(tallest, p.min || 0), 0),
        minW: parts.reduce((sum, p) => sum + p.minW, 0) + (row.length - 1) * ROW_GAP,
        // เพดานความสูงใช้ได้ต่อเมื่อ "ทุกใบ" ในแถวมีเพดาน ไม่งั้นใบที่ยืดได้จะโดนตัดตามไปด้วย
        maxPct: parts.every(p => p.maxPct) ? Math.max(...parts.map(p => p.maxPct)) : undefined,
    };
}

/** น้ำหนักความสูงที่ใช้จริงของแถว (ค่าที่ผู้ใช้ลากไว้ ถ้าไม่มีก็ค่าตั้งต้น) */
function growOf(row) {
    return rowGrow.value[rowKey(row)] ?? rowFlex(row).grow;
}

/**
 * ตรึงความสูงของทุกแถวในคอลัมน์ตามที่เห็นอยู่ ณ ตอนนี้ แล้วขยับเฉพาะเส้นที่ลาก
 *
 * ที่ต้องตรึงทั้งคอลัมน์ ไม่ใช่แค่สองแถวที่ติดกัน เพราะถ้าแตะแค่คู่เดียว แถวอื่นที่
 * ยังใช้ grow ตั้งต้นจะถูกคำนวณสัดส่วนใหม่ตามไปด้วย — ผู้ใช้ลากเส้นล่างแล้วเห็นแถวบน
 * ขยับเองโดยไม่ได้แตะ
 *
 * @param {string[][]} rows  แถวทั้งหมดในคอลัมน์ (ตามที่แสดงจริง)
 * @param {number[]}   px    ความสูงจริงของแต่ละแถวเป็นพิกเซล
 */
function pinRowHeights(rows, px) {
    const total = px.reduce((sum, h) => sum + h, 0);
    if (total <= 0) return;

    const next = { ...rowGrow.value };
    rows.forEach((row, i) => {
        const key = rowKey(row);
        if (key) next[key] = Math.max(0.01, (px[i] / total) * 100);
    });

    rowGrow.value = next;
}

/** คืนความสูงของทุกแถวในคอลัมน์กลับไปใช้ค่าตั้งต้น (ดับเบิลคลิกที่เส้นแบ่ง) */
function resetRowHeights(rows) {
    const keys = new Set(rows.map(rowKey));

    rowGrow.value = Object.fromEntries(
        Object.entries(rowGrow.value).filter(([id]) => !keys.has(id))
    );
}

// ── ซ่อน / ย่อ / รีเซ็ต ──────────────────────────────────────────────────────

function toggleHidden(id) {
    const card = TRADE_CARDS.find(c => c.id === id);
    if (!card || card.essential) return;

    hidden.value = hidden.value.includes(id)
        ? hidden.value.filter(x => x !== id)
        : [...hidden.value, id];
}

function toggleCollapsed(id) {
    collapsed.value = collapsed.value.includes(id)
        ? collapsed.value.filter(x => x !== id)
        : [...collapsed.value, id];
}

function reset() {
    columns.value = defaultColumns();
    hidden.value = [];
    collapsed.value = [];
    rowSplit.value = {};
    rowGrow.value = {};
    chartHeight.value = 'md';
    fitScreen.value = true;
}

export function useTradeLayout() {
    load();

    /**
     * แถวที่แสดงจริงในแต่ละคอลัมน์ (ตัดการ์ดที่ซ่อนออก แล้วทิ้งแถวที่ว่างเปล่า)
     *
     * ไม่แก้ `columns` ตอนซ่อน — ผู้ใช้เลิกซ่อนแล้วการ์ดต้องกลับที่เดิม ไม่ใช่ไปต่อท้าย
     */
    const visibleRows = computed(() =>
        COLUMNS.reduce((acc, col) => {
            acc[col] = columns.value[col]
                .map(row => row.filter(id => !hidden.value.includes(id)))
                .filter(row => row.length);
            return acc;
        }, {})
    );

    /** การ์ดที่แสดงจริงในแต่ละคอลัมน์แบบแบนราบ */
    const visible = computed(() =>
        COLUMNS.reduce((acc, col) => {
            acc[col] = visibleRows.value[col].flat();
            return acc;
        }, {})
    );

    /** ลำดับที่ผู้ใช้เห็นจริงบนจอแคบ (ใช้ตรวจในเทสต์ — บนหน้าจอใช้คลาส order-* ของ Tailwind) */
    const stackedOrder = computed(() => {
        const keys = Object.keys(STACK_CLASS);
        return COLUMNS.flatMap(col => visible.value[col])
            .sort((a, b) => keys.indexOf(a) - keys.indexOf(b));
    });

    const chartHeightPx = computed(
        () => CHART_HEIGHTS.find(h => h.id === chartHeight.value)?.px ?? 460
    );

    const allCollapsed = row => row.every(id => collapsed.value.includes(id));

    /**
     * สไตล์ความสูงของ "แถว" — บนจอกว้างแถวเป็นตัวถือความสูง ไม่ใช่การ์ด
     * @param {string[]} row
     * @param {boolean}  packed  true = โหมดพอดีหน้าจอบนจอกว้าง
     */
    function rowStyle(row, packed) {
        if (allCollapsed(row)) {
            // ย่อแล้วต้องเหลือแค่แถบหัว ไม่ใช่กล่องเปล่าสูงเท่าเดิม
            return { flex: '0 0 auto', height: 'auto', minHeight: '0' };
        }

        if (!packed) {
            const pxs = row.map(id => (id === 'chart' ? chartHeightPx.value + 38 : PREFERRED_HEIGHT[id]));
            // null = สูงตามเนื้อหา — มีใบไหนขอตามเนื้อหา ทั้งแถวก็ต้องตามเนื้อหา
            return pxs.every(px => px) ? { height: `${Math.max(...pxs)}px` } : {};
        }

        const flex = rowFlex(row);
        const grow = growOf(row);

        if (grow > 0) return { flex: `${grow} 1 0%`, minHeight: `${flex.min}px` };

        // สูงตามเนื้อหา แต่ยอมให้หดได้ถ้าคอลัมน์ไม่พอ (เนื้อหาข้างในเลื่อนเอง)
        return flex.maxPct
            ? { flex: '0 1 auto', maxHeight: `${flex.maxPct}%`, minHeight: '0' }
            : { flex: '0 0 auto' };
    }

    /**
     * สไตล์ของการ์ดหนึ่งใบ "ภายในแถว" บนจอกว้าง — คุมแค่ความกว้าง
     * ความสูงมาจากแถว (align-items: stretch) จึงไม่ต้องกำหนดซ้ำ
     */
    function cardStyleInRow(id, row) {
        const style = {
            /*
             * ลบ ROW_GAP เต็มออกจากการ์ดแต่ละใบ ไม่ใช่ครึ่งเดียว
             *
             * แถวคู่มีสมาชิก 3 ตัว (การ์ด · เส้นแบ่ง · การ์ด) จึงมีช่องไฟ 2 ช่อง = 24px
             * ส่วนเส้นแบ่งกินความกว้างสุทธิ 0 (width 10 + margin-inline -5 สองข้าง)
             * ถ้าลบแค่ครึ่ง flex-basis จะเกินที่ว่างไป 12px แล้วเบราว์เซอร์หดให้เอง
             * ตามสัดส่วน — ได้ผลใกล้เคียงแต่ไม่ตรงเป๊ะกับตัวเลขที่ผู้ใช้ลากไว้
             */
            flexBasis: row.length > 1 ? `calc(${splitPct(row, id)}% - ${ROW_GAP}px)` : '100%',
            flexGrow: 0,
            flexShrink: 1,
            minWidth: 0,
        };

        // การ์ดที่ย่อแล้วต้องไม่ถูกยืดเต็มความสูงแถวตามเพื่อนข้างๆ
        if (collapsed.value.includes(id)) style.alignSelf = 'flex-start';

        return style;
    }

    /**
     * สไตล์ความสูงของการ์ดหนึ่งใบ — ใช้บนจอแคบ (ที่แถวยุบเป็น display:contents)
     * @param {string} id
     * @param {boolean} packed
     */
    function cardStyle(id, packed) {
        if (collapsed.value.includes(id)) {
            return { flex: '0 0 auto', height: 'auto', minHeight: '0' };
        }

        if (!packed) {
            const px = id === 'chart' ? chartHeightPx.value + 38 : PREFERRED_HEIGHT[id];
            return px ? { height: `${px}px` } : {};
        }

        const flex = flexOf(id);

        if (flex.grow > 0) return { flex: `${flex.grow} 1 0%`, minHeight: `${flex.min}px` };

        return flex.maxPct
            ? { flex: '0 1 auto', maxHeight: `${flex.maxPct}%`, minHeight: '0' }
            : { flex: '0 0 auto' };
    }

    return {
        // state
        columns,
        visible,
        visibleRows,
        stackedOrder,
        hidden,
        collapsed,
        rowSplit,
        rowGrow,
        chartHeight,
        chartHeightPx,
        fitScreen,
        draggingId,
        dropHint,
        // actions
        startDrag,
        endDrag,
        dropOnCard,
        dropOnColumn,
        toggleHidden,
        toggleCollapsed,
        reset,
        setRowSplit,
        resetRowSplit,
        pinRowHeights,
        resetRowHeights,
        // helpers
        rowStyle,
        cardStyle,
        cardStyleInRow,
        rowOf,
        columnOf,
        splitPct,
        rowMinHeight: row => rowFlex(row).min,
        canJoinRow: (targetId, cardId) => {
            const row = rowOf(targetId);
            return !!row && (row.length < MAX_PER_ROW || row.includes(cardId));
        },
        isHidden: id => hidden.value.includes(id),
        isCollapsed: id => collapsed.value.includes(id),
        cardMeta: id => TRADE_CARDS.find(c => c.id === id) || null,
        stackClass: id => STACK_CLASS[id] || '',
        columnMinWidth,
    };
}
