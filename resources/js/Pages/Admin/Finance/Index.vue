<script setup>
/**
 * TPIX TRADE - Admin Finance Dashboard
 * Real-time Stripe balance, payment history, revenue reports
 * Developed by Xman Studio
 */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useAdminPolling } from '@/Composables/useAdminPolling';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    stripeBalance: { type: Object, default: () => ({}) },
    recentPayments: { type: Array, default: () => [] },
    salesSummary: { type: Array, default: () => [] },
    dailyRevenue: { type: Array, default: () => [] },
    stripeEnabled: { type: Boolean, default: false },
    // คืนเงินจริงผ่าน Stripe — สั่งได้เฉพาะ super_admin ระดับอื่นเปิดดูได้อย่างเดียว
    canManageMoney: { type: Boolean, default: false },
});

const balance = ref(props.stripeBalance);
const payments = ref(props.recentPayments);
const summary = ref(props.summary);
const isRefreshing = ref(false);
const refundingId = ref(null);

const dateLocale = () => (locale.value === 'th' ? 'th-TH' : 'en-US');
const formatDate = (d) => new Date(d).toLocaleDateString(dateLocale());

const fmt = (n) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function refreshBalance() {
    isRefreshing.value = true;
    try {
        const { data } = await axios.get('/admin/finance/stripe-balance');
        if (data.success) balance.value = data.data;
    } catch {} finally { isRefreshing.value = false; }
}

async function refreshPayments() {
    try {
        const { data } = await axios.get('/admin/finance/recent-payments?limit=30');
        if (data.success) payments.value = data.data;
    } catch {}
}

async function refundPayment(txId) {
    if (!confirm(t('admin.finance.refund.confirm'))) return;
    refundingId.value = txId;
    try {
        const { data } = await axios.post('/admin/finance/refund', { transaction_id: txId });
        if (data.success) {
            alert(t('admin.finance.refund.success', { amount: data.data.amount }));
            refreshPayments();
        }
    } catch (e) {
        alert(e.response?.data?.error?.message || t('admin.finance.refund.failed'));
    } finally { refundingId.value = null; }
}

useAdminPolling(() => {
    refreshBalance();
    refreshPayments();
}, 30000);

const statusColor = (status) => ({
    confirmed: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    refunded: 'bg-orange-500/15 text-orange-400 border-orange-500/30',
    disputed: 'bg-red-500/15 text-red-400 border-red-500/30',
    pending: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30',
}[status] || 'bg-gray-500/15 text-gray-400 border-gray-500/30');

// disputed เป็นแค่ป้าย ไม่ใช่ค่าใน status — ปุ่ม Refund ต้องดู tx.status จริงต่อไป
const badgeStatus = (tx) => (tx.dispute_open ? 'disputed' : tx.status);

// ป้ายสถานะตามภาษา — สถานะที่ไม่รู้จักแสดงค่าจากเซิร์ฟเวอร์เป็นตัวพิมพ์ใหญ่
const statusLabel = (status) => {
    const key = `admin.finance.status.${status}`;
    const label = t(key);
    return label === key ? String(status || '').toUpperCase() : label;
};
</script>

<template>
    <Head :title="t('admin.finance.title')" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.finance.title') }}</h1>
                    <p class="text-sm text-gray-400 mt-1">{{ t('admin.finance.subtitle') }}</p>
                </div>
                <button @click="refreshBalance(); refreshPayments()" :disabled="isRefreshing"
                    class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                    {{ isRefreshing ? t('admin.finance.refreshing') : t('admin.finance.refresh') }}
                </button>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <div v-for="card in [
                    { id: 'raised', label: t('admin.finance.cards.totalRaised'), value: `$${fmt(summary.total_raised_usd)}`, color: 'text-emerald-400', sub: t('admin.finance.cards.tpixSold', { amount: fmt(summary.total_tpix_sold) }) },
                    { id: 'stripe', label: t('admin.finance.cards.stripeRevenue'), value: `$${fmt(summary.stripe_revenue)}`, color: 'text-blue-400', sub: t('admin.finance.cards.payments', { count: summary.stripe_payments ?? 0 }) },
                    { id: 'refunds', label: t('admin.finance.cards.refunds'), value: `$${fmt(summary.refund_amount)}`, color: 'text-orange-400', sub: t('admin.finance.cards.refundCount', { count: summary.total_refunds ?? 0 }) },
                    { id: 'fees', label: t('admin.finance.cards.tradingFees'), value: `$${fmt(summary.trading_fee_revenue)}`, color: 'text-purple-400', sub: t('admin.finance.cards.tradingFeesSub') },
                    { id: 'net', label: t('admin.finance.cards.netRevenue'), value: `$${fmt(summary.net_revenue)}`, color: 'text-white', sub: t('admin.finance.cards.netRevenueSub') },
                ]" :key="card.id"
                   class="glass-card rounded-xl p-4">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ card.label }}</div>
                    <div :class="['text-xl font-black', card.color]">{{ card.value }}</div>
                    <div class="text-xs text-gray-500 mt-1">{{ card.sub }}</div>
                </div>
            </div>

            <!-- Disputes Alert -->
            <div v-if="summary.total_disputes > 0"
                 class="flex items-center gap-3 p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                <span class="text-red-400 text-lg font-bold">!</span>
                <div>
                    <div class="text-red-400 font-bold">{{ t('admin.finance.disputes.title', { count: summary.total_disputes }) }}</div>
                    <div class="text-xs text-red-300/70">{{ t('admin.finance.disputes.hint') }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Stripe Balance (Real-time) -->
                <div class="glass-card rounded-xl p-5">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                        {{ t('admin.finance.balance.title') }}
                    </h3>

                    <div v-if="!props.stripeEnabled" class="text-center py-6">
                        <div class="text-gray-500 text-sm">{{ t('admin.finance.balance.disabled') }}</div>
                        <div class="text-xs text-gray-600 mt-1">{{ t('admin.finance.balance.enableHint') }}</div>
                    </div>
                    <div v-else-if="balance.error" class="text-center py-6">
                        <div class="text-red-400 text-sm">{{ balance.error }}</div>
                    </div>
                    <div v-else class="space-y-4">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ t('admin.finance.balance.available') }}</div>
                            <div v-for="b in (balance.available || [])" :key="b.currency"
                                 class="flex items-center justify-between">
                                <span class="text-emerald-400 text-2xl font-black">${{ fmt(b.amount) }}</span>
                                <span class="text-xs text-gray-500">{{ b.currency }}</span>
                            </div>
                            <div v-if="!balance.available?.length" class="text-gray-500 text-sm">$0.00</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ t('admin.finance.balance.pending') }}</div>
                            <div v-for="b in (balance.pending || [])" :key="b.currency"
                                 class="flex items-center justify-between">
                                <span class="text-yellow-400 text-lg font-bold">${{ fmt(b.amount) }}</span>
                                <span class="text-xs text-gray-500">{{ b.currency }}</span>
                            </div>
                            <div v-if="!balance.pending?.length" class="text-gray-500 text-sm">$0.00</div>
                        </div>
                    </div>
                </div>

                <!-- Token Sale Progress -->
                <div class="lg:col-span-2 glass-card rounded-xl p-5">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">{{ t('admin.finance.sales.title') }}</h3>
                    <div v-if="salesSummary.length === 0" class="text-gray-500 text-sm text-center py-6">{{ t('admin.finance.sales.empty') }}</div>
                    <div v-for="sale in salesSummary" :key="sale.id" class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-white font-semibold">{{ sale.name }}</span>
                            <span class="text-sm text-gray-400">{{ t('admin.finance.sales.raised', { amount: fmt(sale.total_raised_usd) }) }}</span>
                        </div>
                        <div v-for="phase in sale.phases" :key="phase.name" class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span :class="phase.is_active ? 'text-emerald-400' : 'text-gray-500'">
                                    {{ phase.name }} ({{ phase.is_active ? t('admin.finance.sales.phaseActive') : t('admin.finance.sales.phaseClosed') }})
                                </span>
                                <span class="text-gray-400">{{ fmt(phase.sold) }} / {{ fmt(phase.allocation) }} TPIX ({{ phase.percent }}%)</span>
                            </div>
                            <div class="w-full h-2 bg-white/5 rounded-full overflow-hidden">
                                <div :class="phase.is_active ? 'bg-emerald-500' : 'bg-gray-600'"
                                     :style="{ width: `${Math.min(phase.percent, 100)}%` }"
                                     class="h-full rounded-full transition-all duration-500" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Chart (simple bar chart) -->
            <div class="glass-card rounded-xl p-5">
                <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">{{ t('admin.finance.daily.title') }}</h3>
                <div class="flex items-end gap-1 h-32">
                    <div v-for="day in dailyRevenue" :key="day.date"
                         class="group relative flex-1 flex flex-col items-center justify-end">
                        <div class="absolute bottom-full mb-1 hidden group-hover:block z-10 bg-dark-800 px-2 py-1 rounded text-xs text-white whitespace-nowrap shadow-lg border border-white/10">
                            {{ t('admin.finance.daily.tooltip', { date: day.date, amount: fmt(day.net), count: day.tx_count }) }}
                        </div>
                        <div class="w-full rounded-t transition-all"
                             :class="day.net >= 0 ? 'bg-emerald-500/60 hover:bg-emerald-500' : 'bg-red-500/60 hover:bg-red-500'"
                             :style="{ height: `${Math.max(Math.abs(day.net) / Math.max(...dailyRevenue.map(d => Math.abs(d.net || 1))) * 100, 2)}%` }">
                        </div>
                    </div>
                </div>
                <div class="flex justify-between text-xs text-gray-600 mt-2">
                    <span>{{ dailyRevenue[0]?.date }}</span>
                    <span>{{ dailyRevenue[dailyRevenue.length - 1]?.date }}</span>
                </div>
            </div>

            <!-- Recent Stripe Payments Table -->
            <div class="glass-card rounded-xl overflow-hidden">
                <div class="p-5 border-b border-white/5 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">{{ t('admin.finance.payments.title') }}</h3>
                    <button @click="refreshPayments" class="text-xs text-primary-400 hover:text-primary-300">{{ t('admin.finance.refresh') }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 uppercase border-b border-white/5">
                                <th class="text-left p-3">{{ t('admin.finance.payments.date') }}</th>
                                <th class="text-left p-3">{{ t('admin.finance.payments.wallet') }}</th>
                                <th class="text-right p-3">{{ t('admin.finance.payments.amountUsd') }}</th>
                                <th class="text-right p-3 hidden sm:table-cell">TPIX</th>
                                <th class="text-right p-3 hidden md:table-cell">{{ t('admin.finance.payments.price') }}</th>
                                <th class="text-center p-3">{{ t('common.status') }}</th>
                                <th class="text-center p-3">{{ t('admin.finance.payments.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in payments" :key="tx.id" class="border-b border-white/5 hover:bg-white/2">
                                <td class="p-3 text-gray-400 font-mono text-xs">{{ formatDate(tx.created_at) }}</td>
                                <td class="p-3">
                                    <span class="font-mono text-xs text-white">{{ tx.wallet?.slice(0, 6) }}...{{ tx.wallet?.slice(-4) }}</span>
                                </td>
                                <td class="p-3 text-right text-white font-bold">${{ fmt(tx.amount_usd) }}</td>
                                <td class="p-3 text-right text-gray-300 font-mono hidden sm:table-cell">{{ fmt(tx.tpix_amount) }}</td>
                                <td class="p-3 text-right text-gray-400 font-mono hidden md:table-cell">${{ tx.price_per_tpix }}</td>
                                <td class="p-3 text-center">
                                    <span :class="['text-[10px] font-bold px-2 py-1 rounded-lg border', statusColor(badgeStatus(tx))]">
                                        {{ statusLabel(badgeStatus(tx)) }}
                                    </span>
                                </td>
                                <td class="p-3 text-center">
                                    <button v-if="canManageMoney && tx.status === 'confirmed'"
                                        @click="refundPayment(tx.id)"
                                        :disabled="refundingId === tx.id"
                                        class="text-xs text-orange-400 hover:text-orange-300 disabled:opacity-50">
                                        {{ refundingId === tx.id ? t('admin.finance.refund.processing') : t('admin.finance.refund.button') }}
                                    </button>
                                    <span v-else class="text-xs text-gray-600">—</span>
                                </td>
                            </tr>
                            <tr v-if="payments.length === 0">
                                <td colspan="7" class="p-6 text-center text-gray-500">{{ t('admin.finance.payments.empty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
