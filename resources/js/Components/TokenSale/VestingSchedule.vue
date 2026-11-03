<script setup>
/**
 * VestingSchedule — แสดงตาราง vesting ของ wallet
 * ข้อมูลซื้อ, ยอด claim ได้, ยอด locked, timeline
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    /** รายการ vesting entries จาก API */
    entries: { type: Array, default: () => [] },
    /** กำลังโหลดอยู่หรือไม่ */
    loading: { type: Boolean, default: false },
});

// คำนวณ summary
const totalAllocated = computed(() =>
    props.entries.reduce((sum, e) => sum + (e.tpix_amount || 0), 0)
);
const totalClaimable = computed(() =>
    props.entries.reduce((sum, e) => sum + (e.claimable || 0), 0)
);
const totalClaimed = computed(() =>
    props.entries.reduce((sum, e) => sum + (e.claimed || 0), 0)
);
const totalLocked = computed(() =>
    totalAllocated.value - totalClaimable.value - totalClaimed.value
);

function formatNumber(n) {
    if (!n) return '0';
    return Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

// แปลสถานะ — ค่าที่ไม่รู้จักแสดงตามข้อมูลจากเซิร์ฟเวอร์
function statusLabel(status) {
    return ['confirmed', 'pending', 'claimed'].includes(status) ? t(`ui.tokenSaleVestingSchedule.status.${status}`) : status;
}

function statusBadge(status) {
    switch (status) {
        case 'confirmed': return 'bg-trading-green/20 text-trading-green';
        case 'pending': return 'bg-yellow-500/20 text-yellow-400';
        case 'claimed': return 'bg-primary-500/20 text-primary-400';
        default: return 'bg-gray-500/20 text-gray-400';
    }
}
</script>

<template>
    <div class="vesting-schedule">
        <h3 class="text-xl font-bold text-white mb-4">{{ t('ui.tokenSaleVestingSchedule.title') }}</h3>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-8 text-gray-400">
            {{ t('ui.tokenSaleVestingSchedule.loading') }}
        </div>

        <!-- ไม่มีข้อมูล -->
        <div v-else-if="entries.length === 0" class="text-center py-8 text-gray-400">
            <p>{{ t('ui.tokenSaleVestingSchedule.emptyTitle') }}</p>
            <p class="text-sm mt-1">{{ t('ui.tokenSaleVestingSchedule.emptyHint') }}</p>
        </div>

        <!-- มีข้อมูล -->
        <div v-else>
            <!-- Summary cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-center">
                    <span class="text-xs text-gray-400">{{ t('ui.tokenSaleVestingSchedule.totalAllocated') }}</span>
                    <p class="text-lg font-bold text-white">{{ formatNumber(totalAllocated) }}</p>
                </div>
                <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-center">
                    <span class="text-xs text-gray-400">{{ t('ui.tokenSaleVestingSchedule.claimableNow') }}</span>
                    <p class="text-lg font-bold text-trading-green">{{ formatNumber(totalClaimable) }}</p>
                </div>
                <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-center">
                    <span class="text-xs text-gray-400">{{ t('ui.tokenSaleVestingSchedule.claimed') }}</span>
                    <p class="text-lg font-bold text-primary-400">{{ formatNumber(totalClaimed) }}</p>
                </div>
                <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-center">
                    <span class="text-xs text-gray-400">{{ t('ui.tokenSaleVestingSchedule.locked') }}</span>
                    <p class="text-lg font-bold text-yellow-400">{{ formatNumber(totalLocked) }}</p>
                </div>
            </div>

            <!-- ตาราง -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-gray-400 border-b border-white/10">
                            <th class="text-left py-2 px-3">{{ t('ui.tokenSaleVestingSchedule.table.date') }}</th>
                            <th class="text-right py-2 px-3">TPIX</th>
                            <th class="text-right py-2 px-3">{{ t('ui.tokenSaleVestingSchedule.table.paid') }}</th>
                            <th class="text-right py-2 px-3">{{ t('ui.tokenSaleVestingSchedule.table.claimable') }}</th>
                            <th class="text-center py-2 px-3">{{ t('ui.tokenSaleVestingSchedule.table.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in entries"
                            :key="entry.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="py-2.5 px-3 text-gray-300">
                                {{ formatDate(entry.created_at) }}
                            </td>
                            <td class="py-2.5 px-3 text-right text-white font-medium">
                                {{ formatNumber(entry.tpix_amount) }}
                            </td>
                            <td class="py-2.5 px-3 text-right text-gray-300">
                                {{ formatNumber(entry.payment_amount) }} {{ entry.payment_currency }}
                            </td>
                            <td class="py-2.5 px-3 text-right text-trading-green font-medium">
                                {{ formatNumber(entry.claimable) }}
                            </td>
                            <td class="py-2.5 px-3 text-center">
                                <span
                                    class="px-2 py-0.5 text-xs rounded-full"
                                    :class="statusBadge(entry.status)"
                                >
                                    {{ statusLabel(entry.status) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
