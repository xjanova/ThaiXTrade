<script setup>
/**
 * PhaseCard — การ์ดแสดงข้อมูลแต่ละ phase ของรอบขาย
 * แสดงราคา, allocation, progress, vesting info
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { isPhaseOpen, phaseDisplayStatus } from '@/utils/salePhase';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    /** ข้อมูล phase */
    phase: { type: Object, required: true },
    /** phase นี้ถูกเลือกอยู่หรือไม่ */
    selected: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

// เปอร์เซ็นต์ที่ขายไปแล้วของ phase นี้
const percentSold = computed(() => props.phase.percent_sold || 0);

/*
 * สถานะที่ "ตรงกับความจริง" ไม่ใช่ตรงกับคอลัมน์ status
 *
 * เดิมการ์ดอ่าน props.phase.status ตรงๆ จึงขึ้นป้าย "Active Now" สีเขียว
 * ให้เฟสที่เลยวันปิดไปแล้ว — เป็นสัญญาณแรกที่ทำให้ผู้ใช้เชื่อว่ายังซื้อได้
 * แล้วเดินต่อไปจนโอนเงินจริง
 */
const displayStatus = computed(() => phaseDisplayStatus(props.phase));

// สี status badge
const statusColor = computed(() => {
    switch (displayStatus.value.key) {
        case 'active': return 'bg-trading-green/20 text-trading-green border-trading-green/30';
        case 'upcoming': return 'bg-primary-500/20 text-primary-400 border-primary-500/30';
        case 'completed': return 'bg-gray-500/20 text-gray-400 border-gray-500/30';
        case 'sold_out': return 'bg-trading-red/20 text-trading-red border-trading-red/30';
        default: return 'bg-gray-500/20 text-gray-400 border-gray-500/30';
    }
});

// label สำหรับ status — salePhase คืนป้ายภาษาอังกฤษตายตัว แปลงเป็นคีย์ตรงนี้
// (คีย์ 'completed' ใช้ร่วมกันหลายป้าย จึงแยกตามข้อความป้าย ไม่ใช่ตาม key)
const STATUS_LABEL_KEYS = {
    'Sold Out': 'soldOut',
    'Active Now': 'activeNow',
    'Coming Soon': 'comingSoon',
    'Closed': 'closed',
    'Next Tier': 'nextTier',
    'Completed': 'completed',
    'Cancelled': 'cancelled',
};
const statusLabel = computed(() => {
    const label = displayStatus.value.label;
    const key = STATUS_LABEL_KEYS[label];
    return key ? t(`ui.tokenSalePhaseCard.status.${key}`) : label;
});

/*
 * เลือกเฟสนี้เพื่อซื้อได้ไหม — ต้องใช้ด่านเดียวกับสโตร์และเซิร์ฟเวอร์
 * ห้ามกลับไปเช็ค props.phase.status เดี่ยวๆ อีก (ดู utils/salePhase.js)
 */
const canSelect = computed(() => isPhaseOpen(props.phase));

function formatNumber(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(0) + 'K';
    return n.toLocaleString();
}
</script>

<template>
    <div
        class="phase-card glass-dark"
        :class="{
            'ring-2 ring-primary-500 ring-offset-2 ring-offset-dark-900': selected,
            'hover:border-primary-500/50 cursor-pointer': canSelect,
            'opacity-60': phase.status === 'completed' || phase.status === 'sold_out',
        }"
        @click="canSelect && emit('select', phase.id)"
    >
        <!-- Header: ชื่อ + status -->
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white">{{ phase.name }}</h3>
            <span
                class="px-2.5 py-0.5 text-xs font-semibold rounded-full border"
                :class="statusColor"
            >
                {{ statusLabel }}
            </span>
        </div>

        <!-- ราคา -->
        <div class="mb-4">
            <span class="text-sm text-gray-400">{{ t('ui.tokenSalePhaseCard.pricePerTpix') }}</span>
            <p class="text-2xl font-bold text-primary-400">${{ phase.price_usd }}</p>
        </div>

        <!-- Progress bar ของ phase -->
        <div class="mb-4">
            <div class="flex justify-between text-xs text-gray-400 mb-1">
                <span>{{ t('ui.tokenSalePhaseCard.sold', { n: formatNumber(phase.sold) }) }}</span>
                <span>{{ t('ui.tokenSalePhaseCard.total', { n: formatNumber(phase.allocation) }) }}</span>
            </div>
            <div class="w-full h-2 rounded-full bg-white/5">
                <div
                    class="h-full rounded-full transition-all duration-500"
                    :class="phase.status === 'sold_out' ? 'bg-trading-red' : 'bg-primary-500'"
                    :style="{ width: Math.min(100, percentSold).toFixed(1) + '%' }"
                />
            </div>
            <div class="text-right text-xs text-gray-400 mt-1">{{ percentSold.toFixed(1) }}%</div>
        </div>

        <!-- รายละเอียด -->
        <div class="space-y-2 text-sm">
            <!-- Min/Max -->
            <div class="flex justify-between">
                <span class="text-gray-400">{{ t('ui.tokenSalePhaseCard.minPurchase') }}</span>
                <span class="text-white font-medium">{{ formatNumber(phase.min_purchase) }} TPIX</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-400">{{ t('ui.tokenSalePhaseCard.maxPurchase') }}</span>
                <span class="text-white font-medium">{{ formatNumber(phase.max_purchase) }} TPIX</span>
            </div>

            <!-- Vesting -->
            <div class="flex justify-between">
                <span class="text-gray-400">{{ t('ui.tokenSalePhaseCard.tgeUnlock') }}</span>
                <span class="text-trading-green font-medium">{{ phase.vesting_tge_percent }}%</span>
            </div>
            <div v-if="phase.vesting_cliff_days > 0" class="flex justify-between">
                <span class="text-gray-400">{{ t('ui.tokenSalePhaseCard.cliffPeriod') }}</span>
                <span class="text-white font-medium">{{ t('ui.tokenSalePhaseCard.days', { n: phase.vesting_cliff_days }) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-400">{{ t('ui.tokenSalePhaseCard.vesting') }}</span>
                <span class="text-white font-medium">{{ t('ui.tokenSalePhaseCard.days', { n: phase.vesting_duration_days }) }}</span>
            </div>

            <!-- Remaining -->
            <div class="flex justify-between pt-2 border-t border-white/5">
                <span class="text-gray-400">{{ t('ui.tokenSalePhaseCard.remaining') }}</span>
                <span class="text-primary-400 font-bold">{{ formatNumber(phase.remaining) }} TPIX</span>
            </div>
        </div>

        <!-- ปุ่มเลือก (ถ้า active) -->
        <button
            v-if="canSelect"
            class="w-full mt-4 btn-primary py-2 text-sm font-semibold"
            :class="{ 'opacity-80': selected }"
            @click.stop="emit('select', phase.id)"
        >
            {{ selected ? t('ui.tokenSalePhaseCard.selected') : t('ui.tokenSalePhaseCard.selectPhase') }}
        </button>
    </div>
</template>

<style scoped>
.phase-card {
    @apply p-5 rounded-xl border border-white/10 transition-all duration-200;
}
</style>
