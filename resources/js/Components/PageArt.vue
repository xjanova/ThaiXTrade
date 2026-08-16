<script setup>
/**
 * TPIX TRADE — PageArt
 * เลเยอร์ภาพประกอบพื้นหลังของแต่ละ section (ภาพชุด /images/art/*.webp)
 *
 * ⚠️ ต้องวางไว้ใน element ที่ position ไม่ใช่ static (ใส่ `relative`)
 *    ไม่งั้นภาพจะไปยึดกับ ancestor ตัวอื่นแล้วทับเนื้อหา
 *    และเนื้อหาข้างในต้องมี `relative` (หรือ z-10) เพื่อให้อยู่เหนือภาพ
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';

const props = defineProps({
    /** ชื่อไฟล์ (ไม่ต้องมีนามสกุล) ใน public_html/images/art/ */
    art: { type: String, required: true },
    /** ความเข้มของภาพ 0–100 */
    opacity: { type: Number, default: 25 },
    /** object-position ของภาพ เช่น 'center', 'top', 'right center' */
    position: { type: String, default: 'center' },
    /** รูปแบบการไล่จาง: bottom | radial | edges | none */
    fade: { type: String, default: 'bottom' },
    /** มุมโค้งให้ตรงกับการ์ด/แบนเนอร์ที่ครอบอยู่ */
    rounded: { type: String, default: '' },
    /** ภาพที่อยู่เหนือ fold ต้องเป็น eager — lazy จะทำให้เห็นพื้นว่างตอนเปิดหน้า */
    loading: { type: String, default: 'lazy' },
});

// กันชื่อไฟล์แปลกปลอมหลุดลง src — รับเฉพาะ a-z 0-9 และขีด
const safeArt = computed(() => String(props.art).replace(/[^a-z0-9-]/gi, ''));
const src = computed(() => `/images/art/${safeArt.value}.webp`);
const alpha = computed(() => Math.min(100, Math.max(0, props.opacity)) / 100);
</script>

<template>
    <div
        :class="['absolute inset-0 overflow-hidden pointer-events-none select-none', rounded]"
        aria-hidden="true"
    >
        <img
            :src="src"
            alt=""
            :loading="loading"
            fetchpriority="low"
            decoding="async"
            class="w-full h-full object-cover"
            :style="{ opacity: alpha, objectPosition: position }"
        />

        <!-- ไล่จางให้ภาพกลืนกับพื้นหลังเพจ ไม่ตัดเป็นกล่องชัดๆ -->
        <div
            v-if="fade === 'bottom'"
            class="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/70 to-dark-950/20"
        />
        <div
            v-else-if="fade === 'radial'"
            class="absolute inset-0"
            style="background: radial-gradient(ellipse at center, rgba(2,6,23,0.15) 0%, rgba(2,6,23,0.85) 70%, rgb(2,6,23) 100%)"
        />
        <div v-else-if="fade === 'edges'" class="absolute inset-0">
            <div class="absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-dark-950 to-transparent" />
            <div class="absolute inset-y-0 right-0 w-1/3 bg-gradient-to-l from-dark-950 to-transparent" />
            <div class="absolute inset-x-0 top-0 h-1/4 bg-gradient-to-b from-dark-950 to-transparent" />
            <div class="absolute inset-x-0 bottom-0 h-1/4 bg-gradient-to-t from-dark-950 to-transparent" />
        </div>
    </div>
</template>
