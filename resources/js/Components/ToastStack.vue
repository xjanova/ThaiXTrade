<script setup>
/**
 * TPIX TRADE — ที่แสดงแถบแจ้งเตือนลอย
 *
 * วางไว้ที่ AppLayout ครั้งเดียว ทุกหน้าจึงเรียก showToast() ได้เลย
 * ลอยทับเนื้อหา (fixed) — ไม่กินพื้นที่ในผัง ไม่ดันการ์ดให้เลื่อน
 *
 * Developed by Xman Studio
 */
import { useToasts } from '@/Composables/useToasts';
import { useTranslation } from '@/Composables/useTranslation';

const { toasts, dismissToast } = useToasts();
const { t } = useTranslation();

const tone = {
    success: 'bg-trading-green/90 text-white',
    error: 'bg-trading-red/90 text-white',
    pending: 'bg-primary-500/90 text-white',
    info: 'bg-dark-800/95 text-white ring-1 ring-white/10',
};
</script>

<template>
    <!-- z สูงกว่าโมดัลกระเป๋า เพื่อให้ข้อความผลลัพธ์ไม่โดนบัง -->
    <div class="fixed top-4 right-4 z-[80] flex flex-col gap-2 max-w-[92vw] pointer-events-none">
        <TransitionGroup
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
            leave-active-class="transition-all duration-200 ease-in absolute"
            leave-from-class="opacity-100 translate-x-0"
            leave-to-class="opacity-0 translate-x-4"
            move-class="transition-transform duration-200"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="['pointer-events-auto px-4 py-3 rounded-xl shadow-lg text-sm font-medium flex items-center gap-3', tone[toast.type] || tone.info]"
                role="status"
            >
                <div v-if="toast.type === 'pending'" class="spinner !w-4 !h-4 !border-white/30 !border-t-white shrink-0"></div>
                <svg v-else-if="toast.type === 'success'" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <svg v-else-if="toast.type === 'error'" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>

                <span class="min-w-0">{{ toast.text }}</span>

                <a
                    v-if="toast.link"
                    :href="toast.link.href"
                    target="_blank"
                    rel="noopener"
                    class="underline font-semibold whitespace-nowrap hover:opacity-80 shrink-0"
                >{{ toast.link.label }}</a>

                <!-- ปิดเองได้เสมอ ไม่ต้องรอครบ 10 วิ -->
                <button
                    type="button"
                    class="ml-1 -mr-1 p-1 rounded-lg opacity-70 hover:opacity-100 transition-opacity shrink-0"
                    :aria-label="t('common.close')"
                    @click="dismissToast(toast.id)"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
