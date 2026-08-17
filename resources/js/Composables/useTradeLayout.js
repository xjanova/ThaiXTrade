/**
 * TPIX TRADE — useTradeLayout
 * ผังการ์ดของหน้าเทรด 3 คอลัมน์ ที่ผู้ใช้ลากสลับ/ซ่อน/ย่อได้เอง แล้วจำไว้ในเครื่อง
 *
 * เป็น singleton ระดับโมดูลโดยตั้งใจ — หน้าเทรดมีผังเดียว การ์ดลูก (DraggableCard)
 * จึงเรียก useTradeLayout() ได้ตรงๆ ไม่ต้องส่ง prop ลงไปทีละชั้น
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';

// v3 = เพิ่มคอลัมน์ที่ 4 — ต้องขึ้นเวอร์ชันคีย์ ไม่งั้นผู้ใช้เดิมที่มีผัง 3 คอลัมน์
// บันทึกไว้จะได้คอลัมน์ที่ 4 ว่างเปล่าตลอดไป และไม่มีทางเห็นผังใหม่เลย
const STORAGE_KEY = 'tpix.tradeLayout.v3';

/** คอลัมน์ที่รองรับ — ลำดับนี้คือลำดับที่แสดงบนจอกว้าง */
export const COLUMNS = ['left', 'center', 'right', 'far'];

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
 * พฤติกรรมการยืด/หดของการ์ดในโหมด "พอดีหน้าจอ"
 *  grow = น้ำหนักการแบ่งพื้นที่ที่เหลือ (0 = สูงตามเนื้อหา ห้ามบีบ)
 *  min  = ความสูงต่ำสุดที่ยังอ่านข้อมูลข้างในรู้เรื่อง
 * ถ้ารวม min ของทั้งคอลัมน์แล้วเกินจอ คอลัมน์จะเลื่อนแทนการบีบจนอ่านไม่ออก
 */
export const CARD_FLEX = {
    market: { grow: 5, min: 220 },
    chart: { grow: 8, min: 240 },
    orders: { grow: 3, min: 170 },
    book: { grow: 5, min: 200 },
    // ฟอร์มสูงตามเนื้อหา (บีบช่องกรอกไม่ได้) แต่ต้องไม่กินคอลัมน์จนกราฟเหลือแค่ min
    // → หดได้ + เพดาน 58% ของคอลัมน์ แล้วเลื่อนข้างในเอาถ้ายังไม่พอ
    form: { grow: 0, min: 0, maxPct: 58 },
    ai: { grow: 2, min: 180 },
    trades: { grow: 3, min: 150 },
};

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
 * ลำดับการ์ดบนจอแคบ (คอลัมน์ยุบเป็นแถวเดียว)
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

/**
 * ความกว้างขั้นต่ำที่คอลัมน์หนึ่งต้องการ = ค่า min ที่มากที่สุดของการ์ดในคอลัมน์นั้น
 *
 * ใช้สร้าง grid template แบบไดนามิก — คอลัมน์ที่ถือสมุดคำสั่ง (min 200) ต้องกว้าง
 * กว่าคอลัมน์ที่ถือเฉพาะประวัติเทรด (min 150) โดยไม่ต้อง hardcode ความกว้างตามชื่อคอลัมน์
 * (ซึ่งจะผิดทันทีที่ผู้ใช้ลากการ์ดสลับคอลัมน์กัน)
 */
function columnMinWidth(cardIds) {
    if (!cardIds?.length) return 0;

    return cardIds.reduce((widest, id) => Math.max(widest, CARD_FLEX[id]?.min ?? 160), 0);
}

function defaultColumns() {
    return COLUMNS.reduce((acc, col) => {
        acc[col] = TRADE_CARDS.filter(c => c.column === col).map(c => c.id);
        return acc;
    }, {});
}

// ── state (singleton) ───────────────────────────────────────────────────────
const columns = ref(defaultColumns());
const hidden = ref([]);
const collapsed = ref([]);
const chartHeight = ref('md');
const fitScreen = ref(true);

/** การ์ดที่กำลังลาก + ตำแหน่งที่จะวาง — ใช้วาดเส้นบอกจุดแทรก */
const draggingId = ref(null);
const dropHint = ref(null);

let loaded = false;

/**
 * รวมผังที่บันทึกไว้เข้ากับทะเบียนการ์ดปัจจุบัน
 * - id ที่ไม่รู้จักแล้ว (ถอดออกจากเวอร์ชันใหม่) → ทิ้ง
 * - id ใหม่ที่ยังไม่เคยมีในผังเก่า → ใส่กลับคอลัมน์ตั้งต้น ไม่ใช่หายไปเฉยๆ
 * ทำให้เพิ่มการ์ดใหม่ในอนาคตแล้วผู้ใช้เก่าเห็นด้วย โดยไม่ต้องรีเซ็ตผัง
 */
function normalize(saved) {
    const next = COLUMNS.reduce((acc, col) => ({ ...acc, [col]: [] }), {});
    const seen = new Set();

    COLUMNS.forEach(col => {
        (saved?.[col] || []).forEach(id => {
            if (CARD_IDS.includes(id) && !seen.has(id)) {
                next[col].push(id);
                seen.add(id);
            }
        });
    });

    TRADE_CARDS.forEach(card => {
        if (!seen.has(card.id)) next[card.column].push(card.id);
    });

    return next;
}

function load() {
    if (loaded || typeof window === 'undefined') return;
    loaded = true;

    try {
        const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        columns.value = normalize(raw?.columns);
        hidden.value = (raw?.hidden || []).filter(id => CARD_IDS.includes(id));
        collapsed.value = (raw?.collapsed || []).filter(id => CARD_IDS.includes(id));
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
            chartHeight: chartHeight.value,
            fitScreen: fitScreen.value,
        }));
    } catch {
        // โควตาเต็ม — ผังยังใช้ได้ในหน้านี้ แค่ไม่ถูกจำไว้
    }
}

let persistTimer = null;
watch([columns, hidden, collapsed, chartHeight, fitScreen], () => {
    clearTimeout(persistTimer);
    persistTimer = setTimeout(persist, 150);
}, { deep: true });

// ── การย้ายการ์ด ─────────────────────────────────────────────────────────────

function removeFromColumns(id) {
    COLUMNS.forEach(col => {
        const idx = columns.value[col].indexOf(id);
        if (idx > -1) columns.value[col].splice(idx, 1);
    });
}

function columnOf(id) {
    return COLUMNS.find(col => columns.value[col].includes(id)) || null;
}

/** วางการ์ดที่ลากอยู่ก่อน/หลังการ์ดเป้าหมาย */
function dropOnCard(targetId, position = 'before') {
    const id = draggingId.value;
    if (!id || id === targetId) return;

    const col = columnOf(targetId);
    if (!col) return;

    removeFromColumns(id);

    const list = columns.value[col];
    const targetIndex = list.indexOf(targetId);
    list.splice(position === 'after' ? targetIndex + 1 : targetIndex, 0, id);
}

/** วางท้ายคอลัมน์ (ใช้เมื่อปล่อยในพื้นที่ว่างของคอลัมน์) */
function dropOnColumn(column) {
    const id = draggingId.value;
    if (!id || !COLUMNS.includes(column)) return;
    if (columnOf(id) === column && columns.value[column].at(-1) === id) return;

    removeFromColumns(id);
    columns.value[column].push(id);
}

function startDrag(id) {
    draggingId.value = id;
}

function endDrag() {
    draggingId.value = null;
    dropHint.value = null;
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
    chartHeight.value = 'md';
    fitScreen.value = true;
}

export function useTradeLayout() {
    load();

    /** การ์ดที่แสดงจริงในแต่ละคอลัมน์ (ตัดตัวที่ซ่อนออกแล้ว) */
    const visible = computed(() =>
        COLUMNS.reduce((acc, col) => {
            acc[col] = columns.value[col].filter(id => !hidden.value.includes(id));
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

    /**
     * สไตล์ความสูงของการ์ดหนึ่งใบ
     * @param {string} id
     * @param {boolean} packed  true = โหมดพอดีหน้าจอบนจอกว้าง
     */
    function cardStyle(id, packed) {
        if (collapsed.value.includes(id)) {
            // ย่อแล้วต้องเหลือแค่แถบหัว ไม่ใช่กล่องเปล่าสูงเท่าเดิม
            return { flex: '0 0 auto', height: 'auto', minHeight: '0' };
        }

        if (!packed) {
            const px = id === 'chart' ? chartHeightPx.value + 38 : PREFERRED_HEIGHT[id];
            return px ? { height: `${px}px` } : {};
        }

        const flex = CARD_FLEX[id] || { grow: 1, min: 120 };

        if (flex.grow > 0) {
            return { flex: `${flex.grow} 1 0%`, minHeight: `${flex.min}px` };
        }

        // สูงตามเนื้อหา แต่ยอมให้หดได้ถ้าคอลัมน์ไม่พอ (เนื้อหาข้างในเลื่อนเอง)
        return flex.maxPct
            ? { flex: '0 1 auto', maxHeight: `${flex.maxPct}%`, minHeight: '0' }
            : { flex: '0 0 auto' };
    }

    return {
        // state
        columns,
        visible,
        stackedOrder,
        hidden,
        collapsed,
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
        cardStyle,
        isHidden: id => hidden.value.includes(id),
        isCollapsed: id => collapsed.value.includes(id),
        cardMeta: id => TRADE_CARDS.find(c => c.id === id) || null,
        stackClass: id => STACK_CLASS[id] || '',
        columnMinWidth,
    };
}
