<script setup>
/**
 * TPIX TRADE — RowResizer
 * เส้นแบ่งแนวนอนระหว่างสองแถวในคอลัมน์เดียวกัน — ลากปรับความสูงบน/ล่าง
 *
 * มีเฉพาะโหมด "พอดีหน้าจอ" เท่านั้น เพราะโหมดเลื่อนหน้าความสูงมาจากค่าคงที่
 * ของการ์ดแต่ละใบ (และปุ่มเลือกขนาดกราฟ) — ลากตรงนี้จะไม่มีอะไรให้แย่งกัน
 *
 * Developed by Xman Studio
 */
import { ref, onBeforeUnmount } from 'vue';
import { useTradeLayout } from '@/Composables/useTradeLayout';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    /** ทุกแถวที่แสดงอยู่ในคอลัมน์นี้ (เรียงตามที่เห็นบนจอ) */
    rows: { type: Array, required: true },
    /** เส้นนี้คั่นระหว่าง rows[index] กับ rows[index + 1] */
    index: { type: Number, required: true },
});

const layout = useTradeLayout();
const { t } = useTranslation();

const active = ref(false);
let startY = 0;
let heights = null;
let handle = null;

/** ความสูงต่ำสุดที่แถวยังเหลือหัวการ์ด + เนื้อหาพอให้เห็นว่าเป็นอะไร */
function floorOf(row) {
    return Math.max(64, layout.rowMinHeight(row));
}

function onPointerDown(event) {
    const col = event.currentTarget.parentElement;
    if (!col) return;

    const els = [...col.querySelectorAll(':scope > [data-trade-row]')];
    // ผังกำลังเปลี่ยนอยู่ (ลากการ์ด/ซ่อน) — จำนวนกล่องกับจำนวนแถวไม่ตรงกัน อย่าเดา
    if (els.length !== props.rows.length) return;

    heights = els.map(el => el.getBoundingClientRect().height);
    startY = event.clientY;
    handle = event.currentTarget;
    active.value = true;
    // เบราว์เซอร์บางตัวโยน NotFoundError ถ้า pointerId ไม่ใช่ของจริง (เช่นเหตุการณ์สังเคราะห์)
    // จับไว้เฉยๆ — ไม่มี capture ก็ยังลากได้ตราบใดที่ตัวชี้ยังอยู่บนเส้น
    try {
        handle.setPointerCapture?.(event.pointerId);
    } catch {
        // ไม่เป็นไร
    }
    event.preventDefault();
}

/**
 * ย้ายพิกเซลจากแถวหนึ่งไปอีกแถว โดยผลรวมของทั้งคอลัมน์คงเดิม
 * @param {number} delta ระยะที่ลาก (บวก = ดันเส้นลง = แถวบนสูงขึ้น)
 */
function applyDelta(delta) {
    if (!heights) return;

    const i = props.index;
    const pair = heights[i] + heights[i + 1];
    const minA = floorOf(props.rows[i]);
    const minB = floorOf(props.rows[i + 1]);

    // ถ้าสองแถวรวมกันยังไม่พอสำหรับความสูงต่ำสุดของทั้งคู่ ก็ไม่มีอะไรให้ปรับ
    if (pair < minA + minB) return;

    const next = [...heights];
    next[i] = Math.min(pair - minB, Math.max(minA, heights[i] + delta));
    next[i + 1] = pair - next[i];

    layout.pinRowHeights(props.rows, next);
}

function onPointerMove(event) {
    if (!active.value) return;

    event.preventDefault();
    applyDelta(event.clientY - startY);
}

function stop(event) {
    if (!active.value) return;

    active.value = false;
    heights = null;
    try {
        handle?.releasePointerCapture?.(event.pointerId);
    } catch {
        // ไม่เคย capture ไว้ตั้งแต่แรก
    }
    handle = null;
}

/** ลูกศรขึ้น/ลงปรับทีละ 16px — ต้องวัดสดทุกครั้งเพราะไม่มีจังหวะ pointerdown ให้จำ */
function onKeydown(event) {
    const step = { ArrowUp: -16, ArrowDown: 16 }[event.key];
    if (step === undefined) return;

    event.preventDefault();

    const col = event.currentTarget.parentElement;
    const els = [...(col?.querySelectorAll(':scope > [data-trade-row]') || [])];
    if (els.length !== props.rows.length) return;

    heights = els.map(el => el.getBoundingClientRect().height);
    applyDelta(step);
    heights = null;
}

onBeforeUnmount(() => {
    active.value = false;
    heights = null;
    handle = null;
});
</script>

<template>
    <div
        class="row-resizer"
        :class="active && 'row-resizer--active'"
        role="separator"
        aria-orientation="horizontal"
        tabindex="0"
        :aria-label="t('trade.layout.splitHeight')"
        :title="`${t('trade.layout.splitHeight')} · ${t('trade.layout.resetSplit')}`"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="stop"
        @pointercancel="stop"
        @dblclick="layout.resetRowHeights(rows)"
        @keydown="onKeydown"
    >
        <span class="row-resizer__grip" aria-hidden="true"></span>
    </div>
</template>

<style scoped>
/* สูงจริง 10px แต่แถบที่เห็น 2px — พื้นที่กดต้องใหญ่กว่าที่ตาเห็น (ดู RowSplitter) */
.row-resizer {
    @apply relative flex-shrink-0 flex items-center justify-center rounded-full;
    height: 10px;
    margin-block: -5px;
    cursor: row-resize;
    touch-action: none;
    z-index: 15;
}

.row-resizer__grip {
    @apply block w-16 rounded-full transition-all duration-150;
    height: 2px;
    background: rgba(255, 255, 255, 0.12);
}

.row-resizer:hover .row-resizer__grip,
.row-resizer:focus-visible .row-resizer__grip,
.row-resizer--active .row-resizer__grip {
    @apply w-28;
    height: 3px;
    background: linear-gradient(90deg, #8b5cf6, #06b6d4);
    box-shadow: 0 0 10px rgba(6, 182, 212, 0.65);
}

.row-resizer:focus-visible {
    @apply outline-none;
}
</style>
