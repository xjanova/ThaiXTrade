<script setup>
/**
 * ป้ายแจ้ง "ระบบรอติดตั้งสัญญาบนเชน"
 *
 * ใช้ร่วมกันระหว่างหน้าซื้อมาสเตอร์โหนดกับหน้าสร้างเหรียญ เพราะทั้งสองหน้าติดเหตุผลเดียวกัน:
 * โค้ดพร้อมแล้ว เทสต์ผ่านแล้ว แต่ยังไม่มีสัญญาอยู่บนเชนให้เรียก
 *
 * ทำไมต้องมี: ก่อนหน้านี้หน้าเว็บบอกว่า "พร้อม" แล้วปล่อยให้ผู้ใช้กรอกฟอร์ม
 * เซ็นธุรกรรม หรือจ่ายค่าธรรมเนียม แล้วค่อยไปล้มตอนสุดท้ายโดยไม่มีคำอธิบาย
 * ป้ายนี้บอกความจริงตั้งแต่วินาทีแรกที่เปิดหน้า
 */
import { computed } from 'vue';

const props = defineProps({
    /** 'buy' = หน้าซื้อโหนด · 'create' = หน้าสร้างเหรียญ */
    action: { type: String, default: 'buy' },
    /** รายการสิ่งที่ยังขาด — แสดงให้ผู้ดูแลเห็น ผู้ใช้ทั่วไปไม่ต้องสนใจ */
    issues: { type: Array, default: () => [] },
    /** เชนตอบอยู่ไหม — ถ้าเชนล่มด้วยต้องบอกแยก ไม่ใช่เหมารวมว่า "รอ deploy" */
    rpcConnected: { type: Boolean, default: true },
    /** ให้ผู้ดูแลเห็นรายละเอียดทางเทคนิค */
    showDetails: { type: Boolean, default: false },
});

const copy = computed(() => (props.action === 'create'
    ? {
        title: 'ยังสร้างเหรียญไม่ได้ — ระบบรอติดตั้งสัญญาบนเชน',
        body: 'ตัวสร้างเหรียญเขียนเสร็จและทดสอบผ่านครบทุกประเภทแล้ว เหลือขั้นตอนเดียวคือติดตั้งสัญญาแฟกทอรีลงบนเชน TPIX',
        assurance: 'ตอนนี้ยังกดสร้างไม่ได้ และ ไม่มีการเรียกเก็บค่าธรรมเนียมใด ๆ',
    }
    : {
        title: 'ยังเปิดให้ลงทะเบียนโหนดไม่ได้ — ระบบรอติดตั้งสัญญาบนเชน',
        body: 'ระบบมาสเตอร์โหนดเขียนเสร็จและทดสอบผ่านครบทุกชั้นแล้ว เหลือขั้นตอนเดียวคือติดตั้งสัญญา NodeRegistry ลงบนเชน TPIX',
        assurance: 'ตอนนี้ยังกดวางเงินค้ำไม่ได้ และ ไม่มีการหักเงินจากกระเป๋าของคุณ',
    }));
</script>

<template>
    <div class="relative">
        <div class="absolute -inset-1 bg-gradient-to-r from-amber-500/15 via-yellow-500/10 to-orange-500/15 rounded-3xl blur-xl opacity-70" />

        <div class="glass relative rounded-2xl p-6 border-l-4 border-amber-500">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-500/15 flex items-center justify-center text-2xl shrink-0">
                    🚧
                </div>

                <div class="flex-1 min-w-0 space-y-3">
                    <div>
                        <h3 class="text-amber-400 font-bold text-base leading-snug">{{ copy.title }}</h3>
                        <p class="text-sm text-gray-300 mt-1.5 leading-relaxed">{{ copy.body }}</p>
                    </div>

                    <!-- คำรับรองที่ผู้ใช้อยากรู้ที่สุด: จะเสียเงินไหม -->
                    <div class="flex items-start gap-2 p-3 rounded-xl bg-white/5 border border-white/10">
                        <span class="text-trading-green text-sm leading-none mt-0.5">✓</span>
                        <p class="text-xs text-gray-300 leading-relaxed">{{ copy.assurance }}</p>
                    </div>

                    <!-- เชนล่มเป็นคนละเรื่องกับรอ deploy ต้องแยกให้ชัด -->
                    <div v-if="!rpcConnected" class="flex items-start gap-2 text-xs text-trading-red">
                        <span class="leading-none mt-0.5">⚠</span>
                        <span>ตอนนี้เชื่อมต่อกับเชน TPIX ไม่ได้ด้วย — สถานะด้านล่างอาจไม่อัปเดต</span>
                    </div>

                    <p class="text-xs text-gray-500">
                        ดูรายละเอียดข้างล่างได้ตามปกติ เมื่อติดตั้งเสร็จหน้านี้จะเปิดใช้งานเองทันที ไม่ต้องรอประกาศ
                    </p>

                    <!-- รายละเอียดสำหรับผู้ดูแล -->
                    <details v-if="showDetails && issues.length" class="text-xs">
                        <summary class="cursor-pointer text-gray-500 hover:text-gray-300 select-none">
                            รายละเอียดสำหรับผู้ดูแล ({{ issues.length }})
                        </summary>
                        <ul class="mt-2 space-y-1 pl-4 list-disc text-gray-400">
                            <li v-for="(issue, i) in issues" :key="i">{{ issue }}</li>
                        </ul>
                    </details>
                </div>
            </div>
        </div>
    </div>
</template>
