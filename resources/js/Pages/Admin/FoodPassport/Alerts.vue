<script setup>
/**
 * TPIX TRADE - Admin FoodPassport Temperature Alerts
 * แจ้งเตือนอุณหภูมิเกินกำหนด — configurable thresholds
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    violations: Object,
    threshold: Object,
    hours: Number,
});

const minTemp = ref(props.threshold?.min ?? 0);
const maxTemp = ref(props.threshold?.max ?? 40);
const hoursFilter = ref(props.hours ?? 24);

function applyFilters() {
    router.get('/admin/food-passport/alerts', {
        min_temp: minTemp.value,
        max_temp: maxTemp.value,
        hours: hoursFilter.value,
    }, { preserveState: true });
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function getTempSeverity(temp, min, max) {
    if (temp < min - 10 || temp > max + 10) return 'text-red-500 font-bold';
    if (temp < min || temp > max) return 'text-orange-400 font-semibold';
    return 'text-green-400';
}

// ป้าย « Previous / Next » ของ Laravel มาเป็นภาษาอังกฤษเสมอ — ถอด entity แล้วแปลตามภาษาที่เลือก
function pageLabel(html) {
    const txt = document.createElement('textarea');
    txt.innerHTML = html ?? '';
    const s = txt.value;
    if (/previous/i.test(s) || s.includes('«')) return '« ' + t('common.previous');
    if (/next/i.test(s) || s.includes('»')) return t('common.next') + ' »';
    return s;
}

// computed — ป้ายเปลี่ยนภาษาทันทีเมื่อสลับ TH/EN
const hoursOptions = computed(() => [
    { value: 1, label: t('admin.foodPassportAlerts.hoursOption.one') },
    { value: 6, label: t('admin.foodPassportAlerts.hoursOption.many', { n: 6 }) },
    { value: 12, label: t('admin.foodPassportAlerts.hoursOption.many', { n: 12 }) },
    { value: 24, label: t('admin.foodPassportAlerts.hoursOption.many', { n: 24 }) },
    { value: 48, label: t('admin.foodPassportAlerts.hoursOption.many', { n: 48 }) },
    { value: 168, label: t('admin.foodPassportAlerts.hoursOption.days', { n: 7 }) },
]);
</script>

<template>
    <AdminLayout :title="t('admin.foodPassportAlerts.title')">
        <!-- Header -->
        <div class="mb-6">
            <Link href="/admin/food-passport" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                FoodPassport
            </Link>
            <h1 class="text-2xl font-bold text-white">{{ t('admin.foodPassportAlerts.title') }}</h1>
            <p class="text-sm text-gray-400 mt-1">{{ t('admin.foodPassportAlerts.subtitle') }}</p>
        </div>

        <!-- Alert Summary -->
        <div class="glass-dark rounded-xl border border-red-500/20 p-6 mb-6">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl bg-red-500/10 flex items-center justify-center">
                    <span class="text-3xl">🌡️</span>
                </div>
                <div>
                    <p class="text-3xl font-bold text-white">{{ violations?.total || 0 }}</p>
                    <p class="text-sm text-gray-400">
                        {{ hours > 1 ? t('admin.foodPassportAlerts.summary.many', { n: hours }) : t('admin.foodPassportAlerts.summary.one', { n: hours }) }}
                        <span class="text-gray-500">{{ t('admin.foodPassportAlerts.summary.range', { min: threshold?.min, max: threshold?.max }) }}</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-dark rounded-xl border border-white/10 p-4 mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">{{ t('admin.foodPassportAlerts.filters.minTemp') }}</label>
                    <input
                        v-model.number="minTemp"
                        type="number"
                        step="0.1"
                        class="bg-dark-800 border border-white/10 rounded-lg px-3 py-2 text-white text-sm w-28 focus:outline-none focus:ring-1 focus:ring-primary-500"
                    />
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">{{ t('admin.foodPassportAlerts.filters.maxTemp') }}</label>
                    <input
                        v-model.number="maxTemp"
                        type="number"
                        step="0.1"
                        class="bg-dark-800 border border-white/10 rounded-lg px-3 py-2 text-white text-sm w-28 focus:outline-none focus:ring-1 focus:ring-primary-500"
                    />
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">{{ t('admin.foodPassportAlerts.filters.timeRange') }}</label>
                    <select
                        v-model.number="hoursFilter"
                        class="bg-dark-800 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:ring-1 focus:ring-primary-500"
                    >
                        <option v-for="opt in hoursOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>
                <button
                    @click="applyFilters"
                    class="px-4 py-2 rounded-lg bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors"
                >
                    {{ t('admin.foodPassportAlerts.filters.apply') }}
                </button>
            </div>
        </div>

        <!-- Violations Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.foodPassportAlerts.table.time') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.foodPassportAlerts.table.product') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden sm:table-cell">{{ t('admin.foodPassportAlerts.table.stage') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden lg:table-cell">{{ t('admin.foodPassportAlerts.table.location') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.foodPassportAlerts.table.temperature') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden md:table-cell">{{ t('admin.foodPassportAlerts.table.humidity') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden md:table-cell">{{ t('admin.foodPassportAlerts.table.deviation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="v in violations?.data || []"
                            :key="v.id"
                            class="border-b border-white/5 hover:bg-red-500/5 transition-colors"
                        >
                            <td class="p-4 text-gray-400 text-xs whitespace-nowrap">{{ formatDate(v.recorded_at || v.created_at) }}</td>
                            <td class="p-4">
                                <Link
                                    v-if="v.product"
                                    :href="`/admin/food-passport/products/${v.product.id}`"
                                    class="text-primary-400 hover:text-primary-300 transition-colors"
                                >
                                    {{ v.product.name }}
                                </Link>
                                <span v-else class="text-gray-500">{{ t('admin.foodPassportAlerts.unknownProduct') }}</span>
                            </td>
                            <td class="p-4 text-gray-300 capitalize hidden sm:table-cell">{{ v.stage?.replace(/_/g, ' ') || '—' }}</td>
                            <td class="p-4 text-gray-300 hidden lg:table-cell">{{ v.location || '—' }}</td>
                            <td class="p-4">
                                <span :class="getTempSeverity(v.temperature, threshold.min, threshold.max)">
                                    {{ v.temperature }}°C
                                </span>
                            </td>
                            <td class="p-4 text-gray-300 hidden md:table-cell">{{ v.humidity != null ? `${v.humidity}%` : '—' }}</td>
                            <td class="p-4 hidden md:table-cell">
                                <span v-if="v.temperature < threshold.min" class="text-xs px-2 py-1 rounded-full bg-blue-500/20 text-blue-400">
                                    {{ t('admin.foodPassportAlerts.below', { n: (threshold.min - v.temperature).toFixed(1) }) }}
                                </span>
                                <span v-else-if="v.temperature > threshold.max" class="text-xs px-2 py-1 rounded-full bg-red-500/20 text-red-400">
                                    {{ t('admin.foodPassportAlerts.above', { n: (v.temperature - threshold.max).toFixed(1) }) }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!violations?.data?.length">
                            <td colspan="7" class="p-8 text-center text-gray-400">
                                <div class="text-4xl mb-2">✅</div>
                                {{ t('admin.foodPassportAlerts.empty') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="violations?.last_page > 1" class="flex items-center justify-between p-4 border-t border-white/5">
                <p class="text-xs text-gray-400">
                    {{ t('admin.foodPassportAlerts.pagination', { from: violations.from, to: violations.to, total: violations.total }) }}
                </p>
                <div class="flex gap-1">
                    <Link
                        v-for="link in violations.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        :class="link.active ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:bg-white/5'"
                        v-text="pageLabel(link.label)"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
