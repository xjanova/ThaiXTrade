<script setup>
/**
 * SaleProgressBar — แถบแสดงความคืบหน้าของการขาย
 * แสดง % ที่ขายไปแล้ว, จำนวน TPIX, ยอดเงิน
 * Developed by Xman Studio
 */
import { computed } from 'vue';

const props = defineProps({
    /** จำนวนที่ขายไปแล้ว */
    sold: { type: Number, default: 0 },
    /** จำนวนทั้งหมดที่เปิดขาย */
    total: { type: Number, default: 1 },
    /** ยอดเงินที่ระดมได้ (USD) */
    raisedUsd: { type: Number, default: 0 },
    /** จำนวนผู้ซื้อ */
    buyers: { type: Number, default: 0 },
});

// เปอร์เซ็นต์ที่ขายไปแล้ว
const percent = computed(() => {
    if (props.total <= 0) return 0;
    return Math.min(100, (props.sold / props.total) * 100);
});

// format ตัวเลข
function formatNumber(n) {
    if (n >= 1e9) return (n / 1e9).toFixed(2) + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(2) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
    return n.toLocaleString();
}

function formatUsd(n) {
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <div class="sale-progress">
        <!-- ข้อมูลสรุปด้านบน -->
        <div class="flex items-center justify-between mb-3">
            <div>
                <span class="text-sm text-gray-400">Total Sold</span>
                <p class="text-lg font-bold text-white">{{ formatNumber(sold) }} TPIX</p>
            </div>
            <div class="text-right">
                <span class="text-sm text-gray-400">Total Supply</span>
                <p class="text-lg font-bold text-white">{{ formatNumber(total) }} TPIX</p>
            </div>
        </div>

        <!-- แถบ progress -->
        <div class="progress-track">
            <div
                class="progress-fill"
                :style="{ width: percent.toFixed(1) + '%' }"
            >
                <!-- แสดง % ถ้ามากพอ -->
                <span v-if="percent >= 10" class="progress-text">
                    {{ percent.toFixed(1) }}%
                </span>
            </div>
        </div>

        <!-- % ถ้าน้อยเกินไปจะแสดงข้างนอก -->
        <div v-if="percent < 10" class="text-sm text-primary-400 mt-1">
            {{ percent.toFixed(1) }}% sold
        </div>

        <!-- สถิติด้านล่าง -->
        <div class="grid grid-cols-3 gap-4 mt-4">
            <div class="stat-item">
                <span class="stat-value text-trading-green">{{ formatUsd(raisedUsd) }}</span>
                <span class="stat-label">Raised</span>
            </div>
            <div class="stat-item">
                <span class="stat-value text-primary-400">{{ formatNumber(total - sold) }}</span>
                <span class="stat-label">Remaining</span>
            </div>
            <div class="stat-item">
                <span class="stat-value text-accent-400">{{ formatNumber(buyers) }}</span>
                <span class="stat-label">Buyers</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.progress-track {
    @apply w-full h-6 rounded-full bg-white/5 border border-white/10 overflow-hidden;
}
.progress-fill {
    @apply h-full rounded-full flex items-center justify-end pr-2 transition-all duration-700 ease-out;
    background: linear-gradient(90deg, var(--color-primary), var(--color-accent));
}
.progress-text {
    @apply text-xs font-bold text-white;
}
.stat-item {
    @apply flex flex-col items-center text-center;
}
.stat-value {
    @apply text-lg font-bold;
}
.stat-label {
    @apply text-xs text-gray-400 mt-0.5;
}
</style>
