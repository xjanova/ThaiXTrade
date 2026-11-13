<script setup>
/**
 * TPIX TRADE — ราคาแพลนเช่าบอท: โชว์สิ่งที่ถูกตัดจริง
 *
 * เซิร์ฟเวอร์ตัด `credits_per_day × จำนวนวัน` เป็น "เครดิต" ไม่ได้ตัดเป็น TPIX
 * เดิมหน้านี้โชว์ `price_tpix_per_day` ("300 TPIX/วัน") ซึ่งไม่ใช่ยอดที่ถูกตัด และเครดิตก็ขายเป็นแพ็ก
 * ที่เรตต่างจากตัวเลขนั้น — ผู้ใช้เห็นราคาหนึ่งแต่จ่ายอีกราคาหนึ่ง
 *
 * ตัวเลขหลัก = เครดิต/วัน และเครดิตรวมของจำนวนวันที่เลือก
 * บรรทัดรอง = TPIX โดยประมาณ คิดจากเรตแพ็กเครดิตเล็กสุด และติดป้ายว่า "โดยประมาณ" เสมอ
 * (แพ็กใหญ่ถูกกว่าและมีโบนัส จึงไม่มีราคา TPIX ตัวเดียวที่ถูกต้อง)
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    /** แพลนจากแคตตาล็อก — ใช้ credits_per_day เท่านั้น */
    plan: { type: Object, required: true },
    days: { type: Number, default: 1 },
    /** ราคา TPIX ต่อ 1 เครดิตจากแพ็กเล็กสุด (null = ยังไม่รู้ ไม่โชว์บรรทัดประมาณการ) */
    tpixPerCredit: { type: Number, default: null },
});

const { t } = useTranslation();

const creditsPerDay = computed(() => {
    const n = Number(props.plan?.credits_per_day);
    return Number.isFinite(n) && n > 0 ? n : 0;
});

const isPaid = computed(() => creditsPerDay.value > 0);
const totalCredits = computed(() => creditsPerDay.value * Math.max(1, Number(props.days) || 1));

const approxTpix = computed(() => {
    const rate = Number(props.tpixPerCredit);
    if (!isPaid.value || !Number.isFinite(rate) || rate <= 0) return null;

    const total = totalCredits.value * rate;
    // ยอดเล็กเก็บทศนิยมไว้ ไม่งั้นปัดเป็น 0 TPIX
    return total.toLocaleString('en-US', { maximumFractionDigits: total >= 100 ? 0 : 2 });
});
</script>

<template>
    <div data-testid="plan-price">
        <template v-if="isPaid">
            <p class="text-2xl font-black font-mono text-white leading-none" data-testid="plan-price-headline">
                {{ creditsPerDay.toLocaleString('en-US') }}
                <span class="text-[11px] font-normal text-dark-400">{{ t('aiTrade.creditsPerDay') }}</span>
            </p>
            <p class="text-[11px] text-dark-300 mt-1 font-mono" data-testid="plan-price-total">
                {{ t('aiTrade.totalCost', { days, credits: totalCredits.toLocaleString('en-US') }) }}
            </p>
            <p v-if="approxTpix" class="text-[10px] text-dark-500 mt-0.5 mb-3" data-testid="plan-price-approx">
                {{ t('aiTrade.approxTpix', { tpix: approxTpix }) }}
            </p>
            <div v-else class="mb-3"></div>
        </template>

        <template v-else>
            <p class="text-2xl font-black font-mono text-trading-green leading-none">
                {{ t('aiTrade.freeForever') }}
            </p>
            <p class="text-[11px] text-dark-500 mt-1 mb-3">{{ t('aiTrade.noPaymentNeeded') }}</p>
        </template>
    </div>
</template>
