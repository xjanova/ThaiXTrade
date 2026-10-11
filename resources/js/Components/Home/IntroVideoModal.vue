<script setup>
/**
 * TPIX TRADE - หน้าต่างคลิปแนะนำแบบเต็มจอ (ใช้บนหน้าแรก 3D)
 *
 * Teleport ไป body — แผงของหน้า 3D มี transform/backdrop-filter ทำให้ position: fixed ข้างในไม่อิงจอ
 * ปิดได้ 3 ทาง: ปุ่มปิด / Esc / คลิกพื้นหลัง · ระหว่างเปิดล็อกการเลื่อนหน้า (หน้า 3D ผูกฉากกับการเลื่อน)
 * การพักฉาก 3D / ปิดเสียงฉาก / ถอดตัวเลื่อนสถานี เป็นหน้าที่ของหน้าที่เปิด (ฟัง open/close)
 *
 * Developed by Xman Studio
 */
import { onMounted, onBeforeUnmount, ref, nextTick } from 'vue';
import IntroVideo from '@/Components/Home/IntroVideo.vue';
import { useTranslation } from '@/Composables/useTranslation';

const emit = defineEmits(['close']);
const { t } = useTranslation();
const closeBtn = ref(null);
let prevOverflow = '';
let prevFocus = null;

function onKey(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        emit('close');
    }
}

onMounted(async () => {
    prevFocus = document.activeElement;
    prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    await nextTick();
    closeBtn.value?.focus({ preventScroll: true });
});

onBeforeUnmount(() => {
    document.documentElement.style.overflow = prevOverflow;
    document.removeEventListener('keydown', onKey);
    prevFocus?.focus?.({ preventScroll: true });
});
</script>

<template>
    <Teleport to="body">
        <div
            class="ivm"
            role="dialog"
            aria-modal="true"
            :aria-label="t('introVideo.title')"
            @click.self="emit('close')"
        >
            <div class="ivm__box">
                <div class="ivm__head">
                    <p class="ivm__title">{{ t('introVideo.title') }}</p>
                    <button ref="closeBtn" type="button" class="ivm__close" :aria-label="t('introVideo.close')" @click="emit('close')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </button>
                </div>
                <IntroVideo autoplay />
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.ivm {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgb(2 6 23 / 0.86);
    backdrop-filter: blur(6px);
}

.ivm__box {
    width: min(1100px, 100%, calc((100vh - 7rem) * 16 / 9));
}

.ivm__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.ivm__title {
    font-size: 1.05rem;
    font-weight: 600;
    color: #fff;
}

.ivm__close {
    display: grid;
    place-items: center;
    width: 2.5rem;
    height: 2.5rem;
    flex-shrink: 0;
    border-radius: 9999px;
    color: #e2e8f0;
    background: rgb(255 255 255 / 0.08);
    border: 1px solid rgb(255 255 255 / 0.16);
    transition: background-color 0.2s ease;
}

.ivm__close:hover {
    background: rgb(255 255 255 / 0.16);
}

.ivm__close:focus-visible {
    outline: 2px solid #22d3ee;
    outline-offset: 2px;
}

.ivm__close svg {
    width: 1.1rem;
    height: 1.1rem;
}
</style>
