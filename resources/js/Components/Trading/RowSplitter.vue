<script setup>
/**
 * TPIX TRADE — RowSplitter
 * เส้นแบ่งแนวตั้งระหว่างการ์ด 2 ใบในแถวเดียวกัน — ลากปรับสัดส่วนซ้าย/ขวา
 *
 * ใช้ Pointer Events + setPointerCapture ไม่ใช่ mousemove บน window
 * เพราะ pointer capture ส่งเหตุการณ์มาที่เส้นนี้ต่อแม้ตัวชี้จะวิ่งออกนอกกรอบ
 * ทำให้ลากเร็วๆ แล้วเส้นไม่หลุดมือ และไม่ต้องคอยถอด listener ตอน unmount
 *
 * Developed by Xman Studio
 */
import { ref, computed, onBeforeUnmount } from 'vue';
import { useTradeLayout } from '@/Composables/useTradeLayout';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    /** แถวที่เส้นนี้อยู่ (อาร์เรย์ id การ์ด 2 ใบ) */
    row: { type: Array, required: true },
});

const layout = useTradeLayout();
const { t } = useTranslation();

const active = ref(false);
let rowRect = null;
let handle = null;

const pct = computed(() => layout.splitPct(props.row, props.row[0]));

function onPointerDown(event) {
    const rowEl = event.currentTarget.parentElement;
    if (!rowEl) return;

    rowRect = rowEl.getBoundingClientRect();
    if (rowRect.width <= 0) return;

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

function onPointerMove(event) {
    if (!active.value || !rowRect) return;

    event.preventDefault();
    layout.setRowSplit(props.row, ((event.clientX - rowRect.left) / rowRect.width) * 100);
}

function stop(event) {
    if (!active.value) return;

    active.value = false;
    rowRect = null;
    try {
        handle?.releasePointerCapture?.(event.pointerId);
    } catch {
        // ไม่เคย capture ไว้ตั้งแต่แรก
    }
    handle = null;
}

/** ลูกศรซ้าย/ขวาปรับทีละ 2% — เมาส์ไม่ใช่ทางเดียวที่ต้องใช้ปรับผังได้ */
function onKeydown(event) {
    const step = { ArrowLeft: -2, ArrowRight: 2, Home: -100, End: 100 }[event.key];
    if (step === undefined) return;

    event.preventDefault();
    layout.setRowSplit(props.row, pct.value + step);
}

onBeforeUnmount(() => {
    active.value = false;
    rowRect = null;
    handle = null;
});
</script>

<template>
    <div
        class="row-splitter"
        :class="active && 'row-splitter--active'"
        role="separator"
        aria-orientation="vertical"
        :aria-valuenow="pct"
        aria-valuemin="25"
        aria-valuemax="75"
        tabindex="0"
        :aria-label="t('trade.layout.splitWidth')"
        :title="`${t('trade.layout.splitWidth')} · ${t('trade.layout.resetSplit')}`"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="stop"
        @pointercancel="stop"
        @dblclick="layout.resetRowSplit(row)"
        @keydown="onKeydown"
    >
        <span class="row-splitter__grip" aria-hidden="true"></span>
    </div>
</template>

<style scoped>
/*
 * กว้างจริง 10px แต่แถบที่เห็นแค่ 2px — พื้นที่กดต้องใหญ่กว่าที่ตาเห็น
 * ไม่งั้นผู้ใช้ต้องเล็งเส้น 2px ซึ่งจับแทบไม่ติดบนจอความละเอียดสูง
 */
/*
 * ⚠️ ต่ำกว่า xl แถวเป็น display:contents — เส้นแบ่งจะหลุดไปเป็นลูกของกริดกระดาน
 *    แล้วโผล่เป็นขีดตั้งลอยๆ คั่นกลางกองการ์ดบนมือถือ ต้องซ่อนให้ขาด
 *    (ซ่อนด้วย CSS ไม่ใช่ v-if เพราะ v-if ต้องผูกกับ isNarrow ที่เป็น JS —
 *     หมุนจอแล้วต้องเปลี่ยนตามทันที ไม่ต้องรอ matchMedia ยิง)
 */
.row-splitter {
    @apply relative flex-shrink-0 self-stretch items-center justify-center rounded-full;
    display: none;
    width: 10px;
    margin-inline: -5px;
    cursor: col-resize;
    touch-action: none;
    z-index: 15;
}

@media (min-width: 1280px) {
    .row-splitter {
        display: flex;
    }
}

.row-splitter__grip {
    @apply block h-10 rounded-full transition-all duration-150;
    width: 2px;
    background: rgba(255, 255, 255, 0.12);
}

.row-splitter:hover .row-splitter__grip,
.row-splitter:focus-visible .row-splitter__grip,
.row-splitter--active .row-splitter__grip {
    @apply h-16;
    width: 3px;
    background: linear-gradient(180deg, #8b5cf6, #06b6d4);
    box-shadow: 0 0 10px rgba(6, 182, 212, 0.65);
}

.row-splitter:focus-visible {
    @apply outline-none;
}
</style>
