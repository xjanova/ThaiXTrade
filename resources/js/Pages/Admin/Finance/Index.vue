<script setup>
/**
 * TPIX TRADE - Admin Finance Dashboard
 * Real-time Stripe balance, payment history, revenue reports
 * Developed by Xman Studio
 */
import { ref, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    stripeBalance: { type: Object, default: () => ({}) },
    recentPayments: { type: Array, default: () => [] },
    salesSummary: { type: Array, default: () => [] },
    dailyRevenue: { type: Array, default: () => [] },
    stripeEnabled: { type: Boolean, default: false },
});

const balance = ref(props.stripeBalance);
const payments = ref(props.recentPayments);
const summary = ref(props.summary);
const isRefreshing = ref(false);
const refundingId = ref(null);

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
    if (!confirm('Issue Stripe refund for this payment? This action cannot be undone.')) return;
    refundingId.value = txId;
    try {
        const { data } = await axios.post('/admin/finance/refund', { transaction_id: txId });
        if (data.success) {
            alert(`Refund successful: $${data.data.amount}`);
            refreshPayments();
        }
    } catch (e) {
        alert(e.response?.data?.error?.message || 'Refund failed');
    } finally { refundingId.value = null; }
}

let pollInterval;
onMounted(() => {
    pollInterval = setInterval(() => {
        refreshBalance();
        refreshPayments();
    }, 30000);
});
onUnmounted(() => clearInterval(pollInterval));

const statusColor = (status) => ({
    confirmed: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    refunded: 'bg-orange-500/15 text-orange-400 border-orange-500/30',
    disputed: 'bg-red-500/15 text-red-400 border-red-500/30',
    pending: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30',
}[status] || 'bg-gray-500/15 text-gray-400 border-gray-500/30');
</script>

<template>
    <Head title="Finance Dashboard" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Finance Dashboard</h1>
                    <p class="text-sm text-gray-400 mt-1">Stripe payments, revenue reports, and financial overview</p>
                </div>
                <button @click="refreshBalance(); refreshPayments()" :disabled="isRefreshing"
                    class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                    {{ isRefreshing ? 'Refreshing...' : 'Refresh' }}
                </button>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <div v-for="card in [
                    { label: 'Total Raised', value: `$${fmt(summary.total_raised_usd)}`, color: 'text-emerald-400', sub: `${fmt(summary.total_tpix_sold)} TPIX sold` },
                    { label: 'Stripe Revenue', value: `$${fmt(summary.stripe_revenue)}`, color: 'text-blue-400', sub: `${summary.stripe_payments} payments` },
                    { label: 'Refunds', value: `$${fmt(summary.refund_amount)}`, color: 'text-orange-400', sub: `${summary.total_refunds} refunds` },
                    { label: 'Trading Fees', value: `$${fmt(summary.trading_fee_revenue)}`, color: 'text-purple-400', sub: 'Swap + order fees' },
                    { label: 'Net Revenue', value: `$${fmt(summary.net_revenue)}`, color: 'text-white', sub: 'After refunds + fees' },
                ]" :key="card.label"
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
                    <div class="text-red-400 font-bold">{{ summary.total_disputes }} Active Dispute(s)</div>
                    <div class="text-xs text-red-300/70">Requires immediate attention in Stripe Dashboard</div>
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
                        Stripe Balance
                    </h3>

                    <div v-if="!props.stripeEnabled" class="text-center py-6">
                        <div class="text-gray-500 text-sm">Stripe is disabled</div>
                        <div class="text-xs text-gray-600 mt-1">Enable in Settings → Payment</div>
                    </div>
                    <div v-else-if="balance.error" class="text-center py-6">
                        <div class="text-red-400 text-sm">{{ balance.error }}</div>
                    </div>
                    <div v-else class="space-y-4">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Available</div>
                            <div v-for="b in (balance.available || [])" :key="b.currency"
                                 class="flex items-center justify-between">
                                <span class="text-emerald-400 text-2xl font-black">${{ fmt(b.amount) }}</span>
                                <span class="text-xs text-gray-500">{{ b.currency }}</span>
                            </div>
                            <div v-if="!balance.available?.length" class="text-gray-500 text-sm">$0.00</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Pending</div>
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
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">Token Sale Progress</h3>
                    <div v-if="salesSummary.length === 0" class="text-gray-500 text-sm text-center py-6">No token sales configured</div>
                    <div v-for="sale in salesSummary" :key="sale.id" class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-white font-semibold">{{ sale.name }}</span>
                            <span class="text-sm text-gray-400">Raised: ${{ fmt(sale.total_raised_usd) }}</span>
                        </div>
                        <div v-for="phase in sale.phases" :key="phase.name" class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span :class="phase.is_active ? 'text-emerald-400' : 'text-gray-500'">
                                    {{ phase.name }} ({{ phase.is_active ? 'ACTIVE' : 'closed' }})
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
                <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">Daily Revenue (30 days)</h3>
                <div class="flex items-end gap-1 h-32">
                    <div v-for="day in dailyRevenue" :key="day.date"
                         class="group relative flex-1 flex flex-col items-center justify-end">
                        <div class="absolute bottom-full mb-1 hidden group-hover:block z-10 bg-dark-800 px-2 py-1 rounded text-xs text-white whitespace-nowrap shadow-lg border border-white/10">
                            {{ day.date }}: ${{ fmt(day.net) }} ({{ day.tx_count }} tx)
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
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">Recent Stripe Payments</h3>
                    <button @click="refreshPayments" class="text-xs text-primary-400 hover:text-primary-300">Refresh</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 uppercase border-b border-white/5">
                                <th class="text-left p-3">Date</th>
                                <th class="text-left p-3">Wallet</th>
                                <th class="text-right p-3">Amount (USD)</th>
                                <th class="text-right p-3 hidden sm:table-cell">TPIX</th>
                                <th class="text-right p-3 hidden md:table-cell">Price</th>
                                <th class="text-center p-3">Status</th>
                                <th class="text-center p-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in payments" :key="tx.id" class="border-b border-white/5 hover:bg-white/2">
                                <td class="p-3 text-gray-400 font-mono text-xs">{{ new Date(tx.created_at).toLocaleDateString() }}</td>
                                <td class="p-3">
                                    <span class="font-mono text-xs text-white">{{ tx.wallet?.slice(0, 6) }}...{{ tx.wallet?.slice(-4) }}</span>
                                </td>
                                <td class="p-3 text-right text-white font-bold">${{ fmt(tx.amount_usd) }}</td>
                                <td class="p-3 text-right text-gray-300 font-mono hidden sm:table-cell">{{ fmt(tx.tpix_amount) }}</td>
                                <td class="p-3 text-right text-gray-400 font-mono hidden md:table-cell">${{ tx.price_per_tpix }}</td>
                                <td class="p-3 text-center">
                                    <span :class="['text-[10px] font-bold px-2 py-1 rounded-lg border', statusColor(tx.status)]">
                                        {{ tx.status.toUpperCase() }}
                                    </span>
                                </td>
                                <td class="p-3 text-center">
                                    <button v-if="tx.status === 'confirmed'"
                                        @click="refundPayment(tx.id)"
                                        :disabled="refundingId === tx.id"
                                        class="text-xs text-orange-400 hover:text-orange-300 disabled:opacity-50">
                                        {{ refundingId === tx.id ? 'Processing...' : 'Refund' }}
                                    </button>
                                    <span v-else class="text-xs text-gray-600">—</span>
                                </td>
                            </tr>
                            <tr v-if="payments.length === 0">
                                <td colspan="7" class="p-6 text-center text-gray-500">No Stripe payments yet</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
