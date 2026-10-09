<script>
/**
 * TPIX TRADE — โลโก้แบรนด์ (เวกเตอร์) จุดเดียวของทั้งเว็บ
 *
 *   variant="icon"    ตัวย่อ: โมโนแกรม TP + วงแหวน — ใช้ที่ขนาด ≤ 48px (แถบเมนู ไซด์บาร์ ท้ายหน้า)
 *   variant="mark"    ตราเต็ม: วงแหวนลายประจำยาม + ลายวงจร + ใบไม้ — ใช้ที่ขนาด ≥ 64px
 *   variant="lockup"  ตราเต็ม + ตัวอักษร TPIX TRADE
 *
 * ต้นฉบับอยู่ public_html/images/brand/*.svg
 * ⚠️ BRAND_VERSION ต้องบวกทุกครั้งที่แก้ไฟล์ภาพ — รูปใน public_html เสิร์ฟด้วย max-age หนึ่งปี
 *    และชื่อไฟล์ไม่มีแฮช Cloudflare จะจ่ายตัวเก่าต่อ (เคยวัดได้ cf-cache-status HIT ทั้งที่ต้นทางเปลี่ยนแล้ว)
 *
 * ค่าคงที่อยู่ใน <script> ธรรมดา ไม่ใช่ <script setup> — defineProps ถูกยกออกไปนอก setup()
 * จึงอ้างตัวแปรที่ประกาศใน setup ไม่ได้ (validator ใช้ FILES)
 *
 * Developed by Xman Studio
 */
const BRAND_VERSION = 1;
const FILES = {
    icon: 'tpix-trade-icon.svg',
    mark: 'tpix-trade-mark.svg',
    lockup: 'tpix-trade-lockup.svg',
};
</script>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'icon', validator: v => v in FILES },
    alt: { type: String, default: 'TPIX TRADE' },
});

const src = computed(() => `/images/brand/${FILES[props.variant] ?? FILES.icon}?v=${BRAND_VERSION}`);
</script>

<template>
    <img :src="src" :alt="alt" draggable="false" decoding="async" />
</template>
