<script>
/**
 * TPIX TRADE — โลโก้แบรนด์ (เวกเตอร์) จุดเดียวของทั้งเว็บ — ใช้กับโลโก้เหรียญ TPIX ด้วย
 *
 *   variant="icon"    ตัวย่อ: โมโนแกรม TP + วงแหวน — ใช้ที่ขนาด ≤ 48px (ไอคอนเหรียญ ไซด์บาร์ กระเป๋า)
 *   variant="mark"    ตราเต็ม: วงแหวนลายประจำยาม + ลายวงจร + ใบไม้ — ใช้ที่ขนาด ≥ 56px
 *   variant="lockup"  ตราเต็ม + ตัวอักษร TPIX / TRADE (หัวเว็บ ท้ายเว็บ) — กำหนดแค่ความสูง กว้างตามสัดส่วน
 *
 * ที่อยู่ไฟล์ + เลขกันแคช Cloudflare อยู่ใน utils/brand.js (BRAND_VERSION)
 *
 * FILES อยู่ใน <script> ธรรมดา ไม่ใช่ <script setup> — defineProps ถูกยกออกไปนอก setup()
 * จึงอ้างตัวแปรที่ประกาศใน setup ไม่ได้ (validator ใช้ FILES)
 *
 * Developed by Xman Studio
 */
const FILES = {
    icon: 'tpix-trade-icon.svg',
    mark: 'tpix-trade-mark.svg',
    lockup: 'tpix-trade-lockup.svg',
};
</script>

<script setup>
import { computed } from 'vue';
import { brandAsset } from '@/utils/brand';

const props = defineProps({
    variant: { type: String, default: 'icon', validator: v => v in FILES },
    alt: { type: String, default: 'TPIX TRADE' },
});

const src = computed(() => brandAsset(FILES[props.variant] ?? FILES.icon));
</script>

<template>
    <img :src="src" :alt="alt" draggable="false" decoding="async" />
</template>
