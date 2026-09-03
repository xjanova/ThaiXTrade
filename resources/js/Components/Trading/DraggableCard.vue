<script setup>
/**
 * TPIX TRADE — DraggableCard
 * กล่องครอบการ์ดในหน้าเทรด: ลากสลับตำแหน่ง, ย่อ, ซ่อน, ภาพประกอบจางๆ
 *
 * ลากได้เฉพาะเมื่อกดที่ "ที่จับ" (grip) เท่านั้น — ถ้าตั้ง draggable ไว้ทั้งใบ
 * เบราว์เซอร์จะกินการคลิกในสมุดคำสั่ง/ฟอร์ม ทำให้กดเลือกราคาไม่ได้
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch } from 'vue';
import PageArt from '@/Components/PageArt.vue';
import { useTradeLayout } from '@/Composables/useTradeLayout';
import { useTranslation } from '@/Composables/useTranslation';
import { usePointerCapability } from '@/Composables/usePointerCapability';

const props = defineProps({
    cardId: { type: String, required: true },
    /** ปิดการลาก/ย่อ/ซ่อน (จอแคบ — DnD ด้วยนิ้วไม่เสถียร) */
    locked: { type: Boolean, default: false },
    /** คลาสเพิ่มของกล่องเนื้อหา */
    bodyClass: { type: String, default: '' },
    /** ความเข้มของภาพประกอบในการ์ด (0 = ไม่แสดง) */
    artOpacity: { type: Number, default: 14 },
});

const { t } = useTranslation();
const layout = useTradeLayout();
const { hasFinePointer } = usePointerCapability();

const meta = computed(() => layout.cardMeta(props.cardId));
const title = computed(() => (meta.value?.titleKey ? t(meta.value.titleKey) : props.cardId));
const isCollapsed = computed(() => layout.collapsed.value.includes(props.cardId));
const isDragging = computed(() => layout.draggingId.value === props.cardId);

// draggable ต้องเป็น true ก่อน dragstart จะยิง — ติดอาวุธตอนกด grip แล้วปลดตอนจบ
const armed = ref(false);
const hint = ref(null); // 'before' | 'after' | 'left' | 'right'

/** ปรับแต่งการ์ดได้ไหม (ย่อ/ซ่อน) — ใช้ได้ทั้งเมาส์และนิ้ว */
const canDrag = computed(() => !props.locked);

/*
 * ที่จับลากแยกจาก canDrag
 *
 * ย่อ/ซ่อนกดด้วยนิ้วได้ปกติ แต่ลากวางแบบ HTML5 ใช้กับการแตะไม่ได้เลย
 * ปิดรวมกันทั้งชุดจะเสียความสามารถที่ยังใช้ได้ไปฟรีๆ บนแท็บเล็ต
 */
const canReorder = computed(() => canDrag.value && hasFinePointer.value);

function arm() {
    if (canReorder.value) armed.value = true;
}

function disarm() {
    armed.value = false;
}

function onDragStart(e) {
    if (!armed.value) {
        e.preventDefault();
        return;
    }
    layout.startDrag(props.cardId);
    e.dataTransfer.effectAllowed = 'move';
    // Firefox ไม่เริ่มลากถ้าไม่มี data — ใส่ id ไว้ให้ครบ
    e.dataTransfer.setData('text/plain', props.cardId);
}

function onDragEnd() {
    disarm();
    hint.value = null;
    layout.endDrag();
}

/**
 * ⭐ ล้างเส้นบอกจุดวางของ "ทุกใบ" เมื่อการลากจบลง
 *
 * dragend ยิงที่ใบที่ถูกลากใบเดียว ส่วนใบที่เป็นเป้าหมายอาศัย dragleave ล้างตัวเอง
 * ซึ่งไม่ยิงเลยถ้าผู้ใช้ปล่อยมือนอกการ์ด (พื้นที่ว่างของคอลัมน์ / นอกหน้าต่าง)
 * ผลคือเส้นเรืองแสงค้างอยู่บนการ์ดจนกว่าจะลากผ่านมันอีกครั้ง
 */
watch(() => layout.draggingId.value, id => {
    if (!id) hint.value = null;
});

/**
 * การ์ดหนึ่งใบมีจุดวาง 4 จุด
 *   ขอบซ้าย/ขวา → เข้าไปอยู่ "แถวเดียวกัน" เคียงข้างใบนี้
 *   ครึ่งบน/ล่าง → เป็นแถวใหม่เหนือ/ใต้แถวนี้
 *
 * แถบขอบกว้าง 28% แต่ไม่เกิน 72px — บนการ์ดกว้างๆ อย่างกราฟ ถ้าใช้ % ล้วน
 * แถบขอบจะกินเข้ามาเกือบครึ่งใบ แล้วผู้ใช้จะเล็ง "วางเป็นแถวใหม่" ไม่ได้เลย
 */
function onDragOver(e) {
    if (!layout.draggingId.value || layout.draggingId.value === props.cardId) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    const rect = e.currentTarget.getBoundingClientRect();

    // เสนอ "วางเคียงกัน" เฉพาะตอนที่แถวนี้ยังรับได้อีกใบ ไม่งั้นเป็นเส้นหลอก
    if (layout.canJoinRow(props.cardId, layout.draggingId.value)) {
        const edge = Math.min(72, rect.width * 0.28);

        if (e.clientX < rect.left + edge) {
            hint.value = 'left';
            return;
        }

        if (e.clientX > rect.right - edge) {
            hint.value = 'right';
            return;
        }
    }

    hint.value = e.clientY < rect.top + rect.height / 2 ? 'before' : 'after';
}

/**
 * dragleave ยิงตอนตัวชี้ย้ายเข้าลูกของการ์ดด้วย ไม่ใช่แค่ตอนออกนอกการ์ด
 *
 * ไม่เช็ก relatedTarget แล้วเส้นบอกจุดวางจะกะพริบทุกครั้งที่ลากผ่านหัวการ์ด
 * ตารางราคา หรือปุ่ม — คือแทบทุกจุดที่ลากผ่าน ผู้ใช้จะเล็งจุดวางไม่ได้เลย
 *
 * relatedTarget เป็น null ตอนลากออกนอกหน้าต่าง — contains(null) คืน false
 * จึงล้างเส้นให้ถูกต้องอยู่แล้ว
 */
function onDragLeave(e) {
    if (e.currentTarget.contains(e.relatedTarget)) return;
    hint.value = null;
}

function onDrop(e) {
    if (!layout.draggingId.value) return;
    e.preventDefault();
    e.stopPropagation(); // อย่าให้คอลัมน์รับต่อแล้วโยนไปท้ายสุด
    layout.dropOnCard(props.cardId, hint.value || 'before');
    hint.value = null;
    layout.endDrag();
}
</script>

<template>
    <div
        class="trade-card relative"
        :class="[
            isDragging && 'opacity-40',
            hint === 'before' && 'trade-card--drop-before',
            hint === 'after' && 'trade-card--drop-after',
            hint === 'left' && 'trade-card--drop-left',
            hint === 'right' && 'trade-card--drop-right',
        ]"
        :draggable="armed"
        @dragstart="onDragStart"
        @dragend="onDragEnd"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <div class="trade-card__shell">
            <!-- ภาพประกอบจางๆ ของการ์ด — ไม่มีไฟล์ก็ไม่พัง PageArt ซ่อนตัวเอง -->
            <PageArt
                v-if="meta?.art && artOpacity > 0 && !isCollapsed"
                :art="meta.art"
                :opacity="artOpacity"
                fade="radial"
                rounded="rounded-2xl"
            />

            <!-- แถบหัวการ์ด: ไล่สีเข้ม + ลายทแยง + เส้นไฮไลต์บน = ดูนูนขึ้นมา -->
            <div class="trade-card__head">
                <button
                    v-if="canReorder"
                    type="button"
                    class="trade-card__grip"
                    :aria-label="t('trade.layout.drag', { name: title })"
                    @pointerdown="arm"
                    @pointerup="disarm"
                    @pointercancel="disarm"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <circle cx="6" cy="3" r="1.3" /><circle cx="10" cy="3" r="1.3" />
                        <circle cx="6" cy="8" r="1.3" /><circle cx="10" cy="8" r="1.3" />
                        <circle cx="6" cy="13" r="1.3" /><circle cx="10" cy="13" r="1.3" />
                    </svg>
                </button>

                <span class="trade-card__dot" aria-hidden="true"></span>

                <h3 class="trade-card__title min-w-0 truncate">{{ title }}</h3>

                <!-- ปุ่ม/ตัวเลือกเฉพาะการ์ด -->
                <div class="ml-auto flex items-center gap-1 flex-shrink-0">
                    <slot name="actions" />

                    <button
                        v-if="canDrag"
                        type="button"
                        class="trade-card__btn"
                        :aria-label="isCollapsed ? t('trade.layout.expand') : t('trade.layout.collapse')"
                        :title="isCollapsed ? t('trade.layout.expand') : t('trade.layout.collapse')"
                        @click="layout.toggleCollapsed(cardId)"
                    >
                        <svg
                            class="w-3.5 h-3.5 transition-transform"
                            :class="isCollapsed && '-rotate-90'"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <button
                        v-if="canDrag && !meta?.essential"
                        type="button"
                        class="trade-card__btn hover:!text-trading-red"
                        :aria-label="t('trade.layout.hide')"
                        :title="t('trade.layout.hide')"
                        @click="layout.toggleHidden(cardId)"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- เนื้อหา -->
            <div v-show="!isCollapsed" :class="['relative z-10 flex-1 flex flex-col min-h-0', bodyClass]">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* เปลือกการ์ด — ขอบสว่างด้านบน + เงาซ้อนสองชั้น ให้รู้สึกลอยขึ้นมาจากพื้น */
.trade-card__shell {
    @apply relative flex flex-col h-full overflow-hidden rounded-2xl;
    background: linear-gradient(180deg, rgba(30, 41, 59, 0.72) 0%, rgba(15, 23, 42, 0.82) 100%);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1px solid rgba(255, 255, 255, 0.07);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.09),
        inset 0 -1px 0 rgba(0, 0, 0, 0.35),
        0 10px 28px -12px rgba(0, 0, 0, 0.75);
}

.trade-card:hover .trade-card__shell {
    border-color: rgba(255, 255, 255, 0.12);
}

/* แถบหัว — ลายทแยงจางๆ + เส้นไล่สีแบรนด์ที่ขอบล่าง */
/*
 * min-w-0 + overflow-hidden ที่หัวการ์ด
 *
 * ถ้าไม่มี พอผู้ใช้ลากการ์ดหลายใบไปกองไว้คอลัมน์เดียวจนคอลัมน์แคบ
 * หัวการ์ด (ชื่อ + ปุ่ม) จะกว้างเกินกล่องแล้วล้นทะลุออกไปทับการ์ดข้างๆ
 * วัดได้จริงที่คอลัมน์กว้าง 288px หัวการ์ดต้องการ 375px
 */
.trade-card__head {
    @apply relative z-10 flex items-center gap-2 px-3 py-2 flex-shrink-0 min-w-0 overflow-hidden;
    background:
        repeating-linear-gradient(
            135deg,
            rgba(255, 255, 255, 0.028) 0px,
            rgba(255, 255, 255, 0.028) 1px,
            transparent 1px,
            transparent 7px
        ),
        linear-gradient(180deg, rgba(255, 255, 255, 0.07) 0%, rgba(255, 255, 255, 0.015) 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.10);
}

.trade-card__head::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 1px;
    background: linear-gradient(
        90deg,
        rgba(139, 92, 246, 0.45),
        rgba(6, 182, 212, 0.45),
        rgba(249, 115, 22, 0.28),
        transparent 85%
    );
}

/* จุดไฟเล็กๆ หน้าชื่อการ์ด — จุดเดียวแต่ทำให้หัวการ์ดดูเป็นแผงเครื่องมือจริง */
.trade-card__dot {
    @apply w-1.5 h-1.5 rounded-full flex-shrink-0;
    background: linear-gradient(135deg, #22d3ee, #8b5cf6);
    box-shadow: 0 0 6px rgba(6, 182, 212, 0.8);
}

.trade-card__title {
    @apply text-[13px] font-semibold text-white truncate tracking-wide;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.6);
}

.trade-card__grip {
    @apply p-1 -ml-1 rounded text-dark-500 hover:text-primary-400 hover:bg-white/5 transition-colors;
    cursor: grab;
    touch-action: none;
}

.trade-card__grip:active {
    cursor: grabbing;
}

.trade-card__btn {
    @apply p-1 rounded text-dark-500 hover:text-white hover:bg-white/5 transition-colors;
}

/* เส้นบอกจุดแทรกตอนลาก — ใช้ pseudo element จึงไม่ดันเลย์เอาต์ */
.trade-card--drop-before::before,
.trade-card--drop-after::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    height: 3px;
    border-radius: 9999px;
    background: linear-gradient(90deg, #8b5cf6, #06b6d4, #f97316);
    box-shadow: 0 0 12px rgba(6, 182, 212, 0.7);
    z-index: 20;
}

.trade-card--drop-before::before {
    top: -8px;
}

.trade-card--drop-after::after {
    bottom: -8px;
}

/*
 * เส้นแนวตั้ง = วางเคียงกันในแถวเดียวกัน (คนละความหมายกับเส้นแนวนอน)
 * ต้องต่างทิศกันชัดๆ ไม่งั้นผู้ใช้แยกไม่ออกว่ากำลังจะได้แถวใหม่หรือคอลัมน์ย่อย
 */
.trade-card--drop-left::before,
.trade-card--drop-right::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    width: 3px;
    border-radius: 9999px;
    background: linear-gradient(180deg, #8b5cf6, #06b6d4, #f97316);
    box-shadow: 0 0 12px rgba(6, 182, 212, 0.7);
    z-index: 20;
}

.trade-card--drop-left::before {
    left: -8px;
}

.trade-card--drop-right::after {
    right: -8px;
}
</style>
