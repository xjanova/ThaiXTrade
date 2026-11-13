<script setup>
/**
 * TPIX TRADE — ค่าบริการวางไม้ที่ผู้ใช้ต้องเห็นก่อนกดยืนยัน
 *
 * เจ้าของสั่งว่า "ต้องชี้แจงรายละเอียดให้ครบ" และ "แนะนำให้ใช้ TPIX"
 *
 * สามอย่างที่ต้องเห็นก่อนตัดสินใจ:
 *   1. ค่าบริการของไม้นี้เท่าไร (เก็บก่อนวางไม้ ไม่ใช่หลังปิดไม้)
 *   2. ยกเลิกแล้วได้คืนเท่าไร — TPIX คืนเต็ม · เหรียญบนเชนโดนหักค่าแก๊ส
 *   3. ถ้าเครดิตไม่พอ ต้องเติมอีกเท่าไร
 *
 * ⚠️ ความต่างเรื่องคืนเงินคือเหตุผลเดียวที่คนจะยอมถือ TPIX
 *    ซ่อนไว้เมื่อไหร่ก็เหลือแค่ "ค่าธรรมเนียมสองแบบที่ไม่รู้จะเลือกอันไหน"
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    /** ผลจาก /api/v1/trading-fee/quote */
    quote: { type: Object, default: null },
    /** ทางที่ผู้ใช้เลือกจ่าย: tpix_credit | onchain */
    method: { type: String, default: 'tpix_credit' },
    /** สกุลหลักของคู่นี้ ใช้แสดงหน่วยของค่าธรรมเนียมแบบเดิม */
    quoteSymbol: { type: String, default: 'USDT' },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['select-method', 'topup']);

const enabled = computed(() => props.quote?.enabled === true);

/*
 * เช่าบอทอยู่ = ไม่เก็บค่าวางไม้เลย (เจ้าของสั่ง 28 ส.ค. 2026 ให้เหมารวมในค่าเช่า)
 *
 * ต้องขึ้นเป็นแผงของตัวเอง ไม่ใช่โชว์ "0 TPIX" ในตัวเลือกเดิมสองปุ่ม —
 * ปุ่มให้เลือกทางจ่ายทั้งที่ไม่ต้องจ่ายอะไรเลยคือความสับสนที่ไม่จำเป็น
 * และผู้ใช้ที่จ่ายค่าเช่าไปแล้วควรเห็นชัดว่าได้อะไรกลับมา
 */
const waived = computed(() => props.quote?.waived === true);
const tpix = computed(() => props.quote?.tpix ?? null);
const onchain = computed(() => props.quote?.onchain ?? null);
const tpixUsable = computed(() => tpix.value?.available === true);
const hasEnough = computed(() => tpix.value?.has_enough === true);
/** เซิร์ฟเวอร์ปิดทาง "จ่ายเป็นเหรียญ" ของไม้นี้ (TPIX Chain: ค่าวางไม้ต้องจ่ายด้วยเครดิต TPIX ทุกไม้) */
const onchainClosed = computed(() => onchain.value?.available === false);

/** ตัวเลขเล็กมากต้องไม่ปัดจนกลายเป็น 0 ที่อ่านว่า "ฟรี" */
function fmt(value, maxDecimals = 8) {
    const n = Number(value) || 0;
    if (n === 0) return '0';
    if (Math.abs(n) >= 1) return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
    if (Math.abs(n) >= 0.01) return n.toFixed(4);

    return n.toFixed(maxDecimals).replace(/0+$/, '');
}
</script>

<template>
    <!-- เช่าบอทอยู่ = ไม่มีอะไรให้เลือก บอกให้จบในบรรทัดเดียว -->
    <div
        v-if="waived"
        class="rounded-lg border border-trading-green/30 bg-trading-green/10 px-2.5 py-2 flex items-center gap-2"
    >
        <svg class="w-4 h-4 text-trading-green shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold text-trading-green leading-tight">{{ t('ui.tradingOrderFeeNotice.waived.title') }}</p>
            <p class="text-[10px] text-dark-400 leading-tight mt-0.5">
                {{ t('ui.tradingOrderFeeNotice.waived.description') }}
            </p>
        </div>
    </div>

    <!-- ปิดระบบค่าบริการ TPIX ไว้ = ไม่ต้องรบกวนผู้ใช้ด้วยตัวเลือกที่ยังไม่มี -->
    <div v-else-if="enabled" class="rounded-lg border border-white/10 bg-black/25 p-2.5 space-y-2">
        <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] text-dark-400">{{ t('ui.tradingOrderFeeNotice.title') }}</span>
            <span class="text-[10px] text-dark-500">{{ t('ui.tradingOrderFeeNotice.chargedOnPlace') }}</span>
        </div>

        <!-- สองทางเลือก วางคู่กันให้เทียบได้ในสายตาเดียว -->
        <div class="grid grid-cols-2 gap-1.5">
            <button
                type="button"
                :disabled="!tpixUsable"
                :class="['fee-option', method === 'tpix_credit' && tpixUsable && 'fee-option--on',
                    !tpixUsable && 'opacity-40 cursor-not-allowed']"
                @click="tpixUsable && emit('select-method', 'tpix_credit')"
            >
                <span class="flex items-center justify-between gap-1">
                    <span class="text-[10px] text-dark-400">{{ t('ui.tradingOrderFeeNotice.payWithTpix') }}</span>
                    <span v-if="tpixUsable && hasEnough" class="text-[8px] px-1 rounded bg-trading-green/20 text-trading-green">{{ t('ui.tradingOrderFeeNotice.recommended') }}</span>
                </span>
                <span class="block text-sm font-mono font-semibold text-white">
                    {{ tpixUsable ? fmt(tpix.fee_tpix) : '—' }}
                    <span class="text-[10px] text-primary-300">TPIX</span>
                </span>
                <span class="block text-[9px] text-trading-green leading-tight">{{ t('ui.tradingOrderFeeNotice.fullRefund') }}</span>
            </button>

            <button
                type="button"
                data-test="fee-option-onchain"
                :disabled="onchainClosed"
                :class="['fee-option', method === 'onchain' && !onchainClosed && 'fee-option--on',
                    onchainClosed && 'opacity-40 cursor-not-allowed']"
                @click="!onchainClosed && emit('select-method', 'onchain')"
            >
                <span class="text-[10px] text-dark-400 block">{{ t('ui.tradingOrderFeeNotice.payOnchain') }}</span>
                <!-- ทางนี้ถูกปิดสำหรับไม้นี้ — ไม่โชว์ตัวเลขที่จ่ายไม่ได้ บอกเหตุผลแทน -->
                <template v-if="onchainClosed">
                    <span class="block text-sm font-mono font-semibold text-dark-400">—</span>
                    <span class="block text-[9px] text-dark-400 leading-tight">{{ onchain?.reason || t('ui.tradingOrderFeeNotice.onchainClosed') }}</span>
                </template>
                <template v-else>
                    <span class="block text-sm font-mono font-semibold text-white">
                        {{ fmt(onchain?.fee_usd) }}
                        <span class="text-[10px] text-primary-300">{{ quoteSymbol }}</span>
                    </span>
                    <span class="block text-[9px] text-amber-300/90 leading-tight">{{ t('ui.tradingOrderFeeNotice.refundMinusGas') }}</span>
                </template>
            </button>
        </div>

        <!-- เครดิตไม่พอ: บอกยอดที่ขาดให้ชัด แล้วพาไปเติมได้ในคลิกเดียว -->
        <div v-if="tpixUsable && !hasEnough" class="rounded-md bg-amber-500/10 border border-amber-500/20 px-2 py-1.5">
            <p class="text-[10px] text-amber-300 leading-relaxed">
                {{ t('ui.tradingOrderFeeNotice.shortfall.before') }} <span class="font-mono font-semibold">{{ fmt(tpix.shortfall) }} TPIX</span>
                {{ t('ui.tradingOrderFeeNotice.shortfall.after') }}
            </p>
            <button type="button" class="mt-1 text-[10px] text-primary-400 hover:text-primary-300 underline" @click="emit('topup')">
                {{ t('ui.tradingOrderFeeNotice.topup') }}
            </button>
        </div>

        <p v-else-if="!tpixUsable" class="text-[10px] text-dark-500 leading-relaxed">
            {{ tpix?.reason || t('ui.tradingOrderFeeNotice.tpixUnavailable') }}
        </p>

        <!-- ⚠️ ข้อความนี้คือสิ่งที่เจ้าของสั่งให้ "แจ้งให้ทราบ" — ห้ามตัดออก -->
        <p v-if="!compact" class="text-[9px] text-dark-500 leading-relaxed border-t border-white/5 pt-1.5">
            <template v-if="method === 'onchain' && !onchainClosed">
                {{ t('ui.tradingOrderFeeNotice.note.onchain.before') }}
                <span class="text-amber-300/90">{{ t('ui.tradingOrderFeeNotice.note.onchain.highlight') }}</span> ·
                {{ t('ui.tradingOrderFeeNotice.note.onchain.after') }}
            </template>
            <template v-else>
                {{ t('ui.tradingOrderFeeNotice.note.tpix.before') }}
                <span class="text-trading-green">{{ t('ui.tradingOrderFeeNotice.note.tpix.highlight') }}</span>
            </template>
        </p>
    </div>
</template>

<style scoped>
.fee-option {
    @apply text-left rounded-md border border-white/10 bg-white/5 px-2 py-1.5 transition-all;
    @apply hover:border-white/25;
}

/* ทางที่เลือกอยู่ต้องอ่านออกทันที — ตัดสินใจเรื่องเงินจากตรงนี้ */
.fee-option--on {
    @apply border-primary-500/60 bg-primary-500/10 ring-1 ring-primary-500/30;
}
</style>
