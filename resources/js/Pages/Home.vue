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
 * Developed by Xman Studio
 */
import { defineAsyncComponent } from 'vue';
import HomeClassic from '@/Components/Home/HomeClassic.vue';
import { useHomeMode } from '@/Composables/useHomeMode';
import { useTranslation } from '@/Composables/useTranslation';

const Home3D = defineAsyncComponent({
    loader: () => import('@/Components/Home3D/Home3D.vue'),
    // โหลดโค้ด 3D ไม่สำเร็จ (เน็ตหลุด/ไฟล์หาย) → หน้าเดิม ไม่ปล่อยจอว่าง
    onError(error, retry, fail) {
        fail();
        home.fallback('chunk');
    },
});

const home = useHomeMode();
const { t } = useTranslation();
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
