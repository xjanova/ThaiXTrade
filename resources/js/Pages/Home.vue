<script setup>
/**
 * TPIX TRADE - Home Page
 *
 * หน้าแรกมี 2 แบบ:
 *  - Home3D   — โลก 3D เต็มจอ (กระดานเทรด 3 มิติ + น้อง TPIX นำทาง) สำหรับเครื่องที่แรงพอ
 *  - Classic  — หน้าเดิมทุกอย่าง สำหรับเครื่องที่ไม่แรง / ผู้ใช้เลือกเอง / 3D ล้ม
 *
 * Home3D โหลดแยก chunk (รวม three.js) — เครื่องที่ได้หน้าเดิมไม่ต้องดาวน์โหลดเลย
 * วิธีเลือกอยู่ใน Composables/useHomeMode.js
 *
 * จอโหลด (หลอดดาวน์โหลด + น้อง TPIX) ขึ้นเฉพาะเปิดหน้านี้แบบเต็มหน้า — ไฟล์นี้คือจุดส่งไม้จากช่วง A (ก่อน JS)
 * ไปช่วง B (ความคืบหน้าจริง) ดู Composables/useHomeSplash.js
 *
 * Developed by Xman Studio
 */
import { defineAsyncComponent, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import HomeClassic from '@/Components/Home/HomeClassic.vue';
import { useHomeMode } from '@/Composables/useHomeMode';
import { useTranslation } from '@/Composables/useTranslation';
import { useHomeSplash } from '@/Composables/useHomeSplash';

const home = useHomeMode();
const { t } = useTranslation();
// สร้างตอนนี้ = จับค่าที่หลอดคืบมาได้ในช่วง A เป็นจุดตั้งต้นของช่วง B
const splash = useHomeSplash();

const Home3D = defineAsyncComponent({
    // ก้อนโค้ด 3D + three.js คืองานใหญ่สุดของการเปิดหน้าแรก → นับเป็นช่วงแรกของหลอด
    loader: () => splash.track('chunk', import('@/Components/Home3D/Home3D.vue')),
    // โหลดโค้ด 3D ไม่สำเร็จ (เน็ตหลุด/ไฟล์หาย) → หน้าเดิม ไม่ปล่อยจอว่าง
    onError(error, retry, fail) {
        fail();
        home.fallback('chunk');
    },
});

// ฟอนต์เว็บ (Inter/Noto Sans Thai) — รอให้ครบก่อนจอโหลดจาง ข้อความจะได้ไม่กระโดดต่อหน้าผู้ใช้
if (typeof document !== 'undefined' && document.fonts?.ready) {
    splash.track('fonts', document.fonts.ready).catch(() => {});
}

onMounted(() => {
    // หน้าเดิม (เครื่องไม่รองรับ 3D / ผู้ใช้เลือกเอง / ?view=classic) ไม่มีอะไรต้องรอ — เนื้อหาวาดเสร็จแล้วตอนนี้
    if (home.mode.value !== '3d') splash.finish();
});

// 3D ล้มกลางทาง (โหลดโค้ดไม่ได้ / GPU ไม่ไหว / บูตไม่ขึ้น) → หน้าเดิมขึ้นแทนทันที จอโหลดจบตาม ไม่รอหมดเวลา
watch(
    () => home.mode.value,
    (mode) => {
        if (mode !== '3d') nextTick(() => splash.finish());
    },
);

// ผู้ใช้เปลี่ยนหน้าไปก่อนโหลดเสร็จ → ปิดจอโหลดทันที ไม่ให้ค้างบังหน้าใหม่
onBeforeUnmount(() => splash.dismiss());
</script>

<template>
    <Home3D v-if="home.mode.value === '3d'" @fallback="home.fallback" @lite="home.choose('classic')" />
    <template v-else>
        <HomeClassic />
        <!-- เครื่องที่รองรับ 3D แต่กำลังดูหน้าเดิม: ให้สลับกลับได้ -->
        <button
            v-if="home.capable.value"
            type="button"
            class="fixed bottom-6 left-6 z-40 inline-flex items-center gap-2 rounded-full border border-primary-500/30 bg-dark-900/85 px-4 py-2 text-xs font-semibold text-primary-300 shadow-lg backdrop-blur-md hover:bg-primary-500/15 transition-colors"
            @click="home.choose('3d')"
        >
            <span class="text-sm">✨</span>
            {{ t('home3d.switchTo3d') }}
        </button>
    </template>
</template>
