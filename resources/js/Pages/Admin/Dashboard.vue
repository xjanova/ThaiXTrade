<script setup>
/**
 * TPIX TRADE - Admin Dashboard
 * Overview stats, revenue analytics with charts, and quick actions
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/Admin/StatCard.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            totalVolume: '$0',
            totalTransactions: 0,
            activeChains: 0,
            activePairs: 0,
            openTickets: 0,
            feeCollectorWallet: '',
            feeCollectorConfigured: false,
            totalFeeCollected: '0',
            totalInternalTrades: 0,
            totalInternalVolume: '$0',
            totalInternalFees: '0',
            openOrders: 0,
            volume24h: '$0',
            trades24h: 0,
            swaps24h: 0,
        }),
    },
    recentTransactions: {
        type: Array,
        default: () => [],
    },
    revenue: {
        type: Object,
        default: () => ({
            wallets: [],
            wallet_configured: false,
            total: 0,
            trading_fees: 0,
            swap_fees: 0,
            factory_fees: 0,
            sources: [],
            token_breakdown: [],
            revenue_by_token: { tpix: { trading: 0, swap: 0, factory: 0, total: 0 }, wtpix: { trading: 0, swap: 0, factory: 0, total: 0 } },
            daily: [],
            factory_stats: { total_created: 0, total_deployed: 0, pending: 0 },
        }),
    },
    volumeTrend: { type: Number, default: 0 },
    transactionTrend: { type: Number, default: 0 },
});

const shortWallet = (addr) => {
    if (!addr) return 'Not Set';
    return addr.substring(0, 6) + '...' + addr.substring(addr.length - 4);
};

// ─── Revenue Chart ───
const chartPeriod = ref('30d');

const filteredDaily = computed(() => {
    const days = chartPeriod.value === '7d' ? 7 : chartPeriod.value === '14d' ? 14 : 30;
    return props.revenue.daily.slice(-days);
});

const maxDailyRevenue = computed(() => {
    return Math.max(...filteredDaily.value.map(d => d.total), 1);
});

// ─── Donut Chart SVG ───
const donutSegments = computed(() => {
    const sources = props.revenue.sources;
    if (!sources.length) return [];

    let offset = 0;
    const circumference = 2 * Math.PI * 40; // radius = 40

    return sources.map(s => {
        const length = (s.percentage / 100) * circumference;
        const segment = {
            ...s,
            dashArray: `${length} ${circumference - length}`,
            dashOffset: -offset,
        };
        offset += length;
        return segment;
    });
});

const formatNum = (n) => {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 });
};
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout title="Dashboard">
        <!-- Revenue Wallet Warning -->
        <div v-if="!revenue.wallet_configured" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 flex items-start gap-3">
            <svg class="w-6 h-6 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <h3 class="text-sm font-semibold text-red-400">Revenue Wallets Not Configured</h3>
                <p class="text-xs text-red-400/70 mt-1">ยังไม่ได้ตั้งกระเป๋ารับรายได้ (TPIX / wTPIX) — รายได้จากทุกช่องทางจะไม่ถูกเก็บ
                    <Link href="/admin/settings" class="underline hover:text-red-300">Settings &gt; Revenue Wallets</Link>
                </p>
            </div>
        </div>

        <!-- Primary Stats Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <StatCard title="Total Volume" :value="stats.totalVolume" icon="chart" :trend="volumeTrend" :trend-up="volumeTrend >= 0" />
            <StatCard title="Total Transactions" :value="stats.totalTransactions" icon="transaction" :trend="transactionTrend" :trend-up="transactionTrend >= 0" />
            <StatCard title="Active Chains" :value="stats.activeChains" icon="chain" />
            <StatCard title="Active Pairs" :value="stats.activePairs" icon="pair" />
            <StatCard title="Open Tickets" :value="stats.openTickets" icon="ticket" />
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- Revenue Analytics Section                                  -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Revenue Analytics
                </h2>
                <!-- Revenue Wallet Badges -->
                <div class="flex items-center gap-2 flex-wrap">
                    <template v-if="revenue.wallets && revenue.wallets.length">
                        <div v-for="w in revenue.wallets" :key="w.symbol" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" :style="{ backgroundColor: w.color + '10', borderColor: w.color + '30' }">
                            <span class="w-2 h-2 rounded-full animate-pulse" :style="{ backgroundColor: w.color }"></span>
                            <span class="text-xs font-medium" :style="{ color: w.color }">{{ w.symbol }}</span>
                            <span class="text-xs font-mono" :style="{ color: w.color }">{{ shortWallet(w.address) }}</span>
                        </div>
                    </template>
                    <div v-else class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-500/10 border border-red-500/20">
                        <span class="w-2 h-2 rounded-full bg-red-400"></span>
                        <span class="text-xs text-red-400">Wallet Not Set</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Total Revenue + Donut Chart -->
                <div class="bg-gradient-to-br from-white/5 to-white/[0.02] backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Total Revenue</p>
                    <p class="text-3xl font-bold text-white mb-1">{{ formatNum(revenue.total) }}</p>
                    <p class="text-sm text-gray-500 mb-4">TPIX + wTPIX</p>

                    <!-- Token Breakdown Mini -->
                    <div v-if="revenue.token_breakdown && revenue.token_breakdown.length" class="flex gap-2 mb-4">
                        <div v-for="tb in revenue.token_breakdown" :key="tb.symbol" class="flex-1 p-2 rounded-lg border" :style="{ backgroundColor: tb.color + '08', borderColor: tb.color + '20' }">
                            <p class="text-[10px] font-medium" :style="{ color: tb.color }">{{ tb.symbol }}</p>
                            <p class="text-sm font-bold text-white">{{ formatNum(tb.amount) }}</p>
                            <p class="text-[10px] text-gray-500">{{ tb.chain }}</p>
                        </div>
                    </div>

                    <!-- Donut Chart -->
                    <div class="flex items-center justify-center mb-4">
                        <div class="relative w-32 h-32">
                            <svg viewBox="0 0 100 100" class="w-full h-full -rotate-90">
                                <!-- Background circle -->
                                <circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="12" />
                                <!-- Revenue segments -->
                                <circle
                                    v-for="(seg, idx) in donutSegments"
                                    :key="idx"
                                    cx="50" cy="50" r="40"
                                    fill="none"
                                    :stroke="seg.color"
                                    stroke-width="12"
                                    :stroke-dasharray="seg.dashArray"
                                    :stroke-dashoffset="seg.dashOffset"
                                    stroke-linecap="round"
                                    class="transition-all duration-700"
                                />
                                <!-- Empty state -->
                                <circle v-if="!donutSegments.length" cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="12" stroke-dasharray="40 211" />
                            </svg>
                            <!-- Center text -->
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-xs text-gray-400">Sources</span>
                                <span class="text-lg font-bold text-white">{{ revenue.sources.length || 0 }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="space-y-2">
                        <div v-for="src in revenue.sources" :key="src.name" class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: src.color }"></span>
                                <span class="text-gray-300">{{ src.name_th }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-white font-mono text-xs">{{ formatNum(src.amount) }}</span>
                                <span class="text-gray-500 text-xs ml-1">({{ src.percentage }}%)</span>
                            </div>
                        </div>
                        <div v-if="!revenue.sources.length" class="text-center py-4 text-gray-500 text-sm">
                            ยังไม่มีรายได้
                        </div>
                    </div>
                </div>

                <!-- Daily Revenue Bar Chart -->
                <div class="lg:col-span-2 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-sm font-medium text-white">Daily Revenue Trend</p>
                        <div class="flex gap-1 bg-dark-800/50 rounded-lg p-0.5">
                            <button
                                v-for="period in [{ key: '7d', label: '7D' }, { key: '14d', label: '14D' }, { key: '30d', label: '30D' }]"
                                :key="period.key"
                                @click="chartPeriod = period.key"
                                class="px-2.5 py-1 rounded-md text-xs font-medium transition-all"
                                :class="chartPeriod === period.key ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white'"
                            >{{ period.label }}</button>
                        </div>
                    </div>

                    <!-- Bar Chart -->
                    <div class="relative h-48">
                        <!-- Y-axis labels -->
                        <div class="absolute left-0 top-0 bottom-6 w-10 flex flex-col justify-between text-right pr-2">
                            <span class="text-[10px] text-gray-500">{{ formatNum(maxDailyRevenue) }}</span>
                            <span class="text-[10px] text-gray-500">{{ formatNum(maxDailyRevenue / 2) }}</span>
                            <span class="text-[10px] text-gray-500">0</span>
                        </div>

                        <!-- Grid lines -->
                        <div class="absolute left-10 right-0 top-0 bottom-6">
                            <div class="absolute top-0 left-0 right-0 border-t border-white/5"></div>
                            <div class="absolute top-1/2 left-0 right-0 border-t border-white/5"></div>
                            <div class="absolute bottom-0 left-0 right-0 border-t border-white/10"></div>
                        </div>

                        <!-- Bars -->
                        <div class="absolute left-10 right-0 top-0 bottom-6 flex items-end gap-[2px]">
                            <div
                                v-for="(day, idx) in filteredDaily"
                                :key="day.date"
                                class="flex-1 flex flex-col items-center justify-end group relative"
                                :style="{ minWidth: '4px' }"
                            >
                                <!-- Stacked bar -->
                                <div class="w-full flex flex-col-reverse rounded-t overflow-hidden" :style="{ height: Math.max((day.total / maxDailyRevenue) * 100, day.total > 0 ? 2 : 0) + '%' }">
                                    <div v-if="day.trading > 0" class="w-full bg-cyan-500/70" :style="{ height: (day.trading / day.total) * 100 + '%', minHeight: '1px' }"></div>
                                    <div v-if="day.swap > 0" class="w-full bg-violet-500/70" :style="{ height: (day.swap / day.total) * 100 + '%', minHeight: '1px' }"></div>
                                    <div v-if="day.factory > 0" class="w-full bg-amber-500/70" :style="{ height: (day.factory / day.total) * 100 + '%', minHeight: '1px' }"></div>
                                </div>

                                <!-- Tooltip -->
                                <div class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 hidden group-hover:block z-10 pointer-events-none">
                                    <div class="bg-dark-800 border border-white/10 rounded-lg p-2 text-xs whitespace-nowrap shadow-xl">
                                        <p class="text-white font-medium mb-1">{{ day.label }}</p>
                                        <p v-if="day.trading > 0" class="text-cyan-400">Trading: {{ day.trading }} TPIX</p>
                                        <p v-if="day.swap > 0" class="text-violet-400">Swap: {{ day.swap }} TPIX</p>
                                        <p v-if="day.factory > 0" class="text-amber-400">Factory: {{ day.factory }} TPIX</p>
                                        <p class="text-white font-semibold border-t border-white/10 pt-1 mt-1">Total: {{ day.total }} TPIX</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- X-axis labels -->
                        <div class="absolute left-10 right-0 bottom-0 flex justify-between">
                            <span class="text-[10px] text-gray-500">{{ filteredDaily[0]?.label }}</span>
                            <span class="text-[10px] text-gray-500">{{ filteredDaily[Math.floor(filteredDaily.length / 2)]?.label }}</span>
                            <span class="text-[10px] text-gray-500">{{ filteredDaily[filteredDaily.length - 1]?.label }}</span>
                        </div>
                    </div>

                    <!-- Chart Legend -->
                    <div class="flex items-center gap-4 mt-4 pt-3 border-t border-white/5">
                        <div class="flex items-center gap-1.5 text-xs text-gray-400">
                            <span class="w-2.5 h-2.5 rounded-sm bg-cyan-500/70"></span> Trading
                        </div>
                        <div class="flex items-center gap-1.5 text-xs text-gray-400">
                            <span class="w-2.5 h-2.5 rounded-sm bg-violet-500/70"></span> Swap
                        </div>
                        <div class="flex items-center gap-1.5 text-xs text-gray-400">
                            <span class="w-2.5 h-2.5 rounded-sm bg-amber-500/70"></span> Factory
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">24h Volume</div>
                <div class="text-xl font-bold text-white">{{ stats.volume24h }}</div>
                <div class="text-xs text-gray-400 mt-1">{{ stats.trades24h }} trades / {{ stats.swaps24h }} swaps</div>
            </div>
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">Trading Fees</div>
                <div class="text-xl font-bold text-cyan-400">{{ formatNum(revenue.trading_fees) }}</div>
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-cyan-500/10 text-cyan-400">TPIX {{ formatNum(revenue.revenue_by_token?.tpix?.trading || 0) }}</span>
                    <span v-if="revenue.revenue_by_token?.wtpix?.trading > 0" class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400">wTPIX {{ formatNum(revenue.revenue_by_token.wtpix.trading) }}</span>
                </div>
            </div>
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">Swap Fees</div>
                <div class="text-xl font-bold text-violet-400">{{ formatNum(revenue.swap_fees) }}</div>
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-cyan-500/10 text-cyan-400">TPIX {{ formatNum(revenue.revenue_by_token?.tpix?.swap || 0) }}</span>
                    <span v-if="revenue.revenue_by_token?.wtpix?.swap > 0" class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400">wTPIX {{ formatNum(revenue.revenue_by_token.wtpix.swap) }}</span>
                </div>
            </div>
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">Token Factory</div>
                <div class="text-xl font-bold text-amber-400">{{ formatNum(revenue.factory_fees) }}</div>
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-cyan-500/10 text-cyan-400">TPIX {{ formatNum(revenue.revenue_by_token?.tpix?.factory || 0) }}</span>
                    <span v-if="revenue.revenue_by_token?.wtpix?.factory > 0" class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400">wTPIX {{ formatNum(revenue.revenue_by_token.wtpix.factory) }}</span>
                </div>
            </div>
            <!-- Per-Token Total -->
            <div class="bg-gradient-to-br from-cyan-500/5 to-amber-500/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">By Token</div>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                            <span class="text-xs text-gray-300">TPIX</span>
                        </div>
                        <span class="text-sm font-bold text-cyan-400">{{ formatNum(revenue.revenue_by_token?.tpix?.total || 0) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                            <span class="text-xs text-gray-300">wTPIX</span>
                        </div>
                        <span class="text-sm font-bold text-amber-400">{{ formatNum(revenue.revenue_by_token?.wtpix?.total || 0) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Transactions -->
            <div class="lg:col-span-2 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-white/5">
                    <h2 class="text-lg font-semibold text-white">Recent Transactions</h2>
                    <Link href="/admin/transactions" class="text-sm text-primary-400 hover:text-primary-300 transition-colors">
                        View All
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-dark-800/30">
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider py-3 px-4">UUID</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider py-3 px-4">Type</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider py-3 px-4">Amount</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider py-3 px-4">Status</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider py-3 px-4">Date</th>
                            </tr>
                        </thead>
                        <tbody v-if="recentTransactions.length > 0">
                            <tr
                                v-for="tx in recentTransactions"
                                :key="tx.uuid"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <td class="py-3 px-4 text-sm font-mono text-gray-300">{{ tx.uuid?.substring(0, 8) }}...</td>
                                <td class="py-3 px-4 text-sm text-white capitalize">{{ tx.type }}</td>
                                <td class="py-3 px-4 text-sm font-mono text-white">{{ tx.amount }}</td>
                                <td class="py-3 px-4"><StatusBadge :status="tx.status" type="transaction" /></td>
                                <td class="py-3 px-4 text-sm text-gray-400">{{ tx.created_at }}</td>
                            </tr>
                        </tbody>
                        <tbody v-else>
                            <tr>
                                <td colspan="5" class="py-12 text-center text-gray-400 text-sm">No recent transactions</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="space-y-4">
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">Quick Actions</h2>
                    <div class="space-y-3">
                        <Link href="/admin/settings" class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group">
                            <div class="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center group-hover:bg-amber-500/20 transition-colors">
                                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Revenue Wallets</p>
                                <p class="text-xs text-gray-400">ตั้งค่ากระเป๋า TPIX / wTPIX</p>
                            </div>
                        </Link>

                        <Link href="/admin/token-factory" class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group">
                            <div class="w-10 h-10 rounded-lg bg-primary-500/10 flex items-center justify-center group-hover:bg-primary-500/20 transition-colors">
                                <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Token Factory</p>
                                <p class="text-xs text-gray-400">{{ revenue.factory_stats.pending }} pending</p>
                            </div>
                        </Link>

                        <Link href="/admin/fees" class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group">
                            <div class="w-10 h-10 rounded-lg bg-cyan-500/10 flex items-center justify-center group-hover:bg-cyan-500/20 transition-colors">
                                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Fee Management</p>
                                <p class="text-xs text-gray-400">ตั้งค่าอัตราค่าธรรมเนียม</p>
                            </div>
                        </Link>

                        <Link href="/admin/support" class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group">
                            <div class="w-10 h-10 rounded-lg bg-yellow-500/10 flex items-center justify-center group-hover:bg-yellow-500/20 transition-colors">
                                <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Support Tickets</p>
                                <p class="text-xs text-gray-400">{{ stats.openTickets }} open</p>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- System Info -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">System Info</h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-400">Platform</span>
                            <span class="text-white">TPIX TRADE</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Chain</span>
                            <span class="text-white">TPIX (4289)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Framework</span>
                            <span class="text-white">Laravel 11</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Developer</span>
                            <span class="text-white">Xman Studio</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
