<script setup>
/**
 * TPIX TRADE - Admin Dashboard
 * Overview stats, recent transactions, fee analytics, and quick actions
 * Developed by Xman Studio
 */

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
    volumeTrend: { type: Number, default: 0 },
    transactionTrend: { type: Number, default: 0 },
});

const shortWallet = (addr) => {
    if (!addr) return 'Not Set';
    return addr.substring(0, 6) + '...' + addr.substring(addr.length - 4);
};
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout title="Dashboard">
        <!-- Fee Collector Warning -->
        <div v-if="!stats.feeCollectorConfigured" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 flex items-start gap-3">
            <svg class="w-6 h-6 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <h3 class="text-sm font-semibold text-red-400">Fee Collector Wallet Not Configured</h3>
                <p class="text-xs text-red-400/70 mt-1">Trading and swaps are blocked until a fee collector wallet is set. Go to
                    <Link href="/admin/settings" class="underline hover:text-red-300">Settings > Trading</Link> to configure.
                </p>
            </div>
        </div>

        <!-- Primary Stats Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <StatCard
                title="Total Volume"
                :value="stats.totalVolume"
                icon="chart"
                :trend="volumeTrend"
                :trend-up="volumeTrend >= 0"
            />
            <StatCard
                title="Total Transactions"
                :value="stats.totalTransactions"
                icon="transaction"
                :trend="transactionTrend"
                :trend-up="transactionTrend >= 0"
            />
            <StatCard
                title="Active Chains"
                :value="stats.activeChains"
                icon="chain"
            />
            <StatCard
                title="Active Pairs"
                :value="stats.activePairs"
                icon="pair"
            />
            <StatCard
                title="Open Tickets"
                :value="stats.openTickets"
                icon="ticket"
            />
        </div>

        <!-- Trading & Fee Analytics Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-2">24h Volume</div>
                <div class="text-xl font-bold text-white">{{ stats.volume24h }}</div>
                <div class="text-xs text-dark-400 mt-1">{{ stats.trades24h }} trades / {{ stats.swaps24h }} swaps</div>
            </div>
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-2">Internal Order Book</div>
                <div class="text-xl font-bold text-white">{{ stats.totalInternalTrades }} trades</div>
                <div class="text-xs text-dark-400 mt-1">{{ stats.openOrders }} open orders</div>
            </div>
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-2">Fees Collected (Swap)</div>
                <div class="text-xl font-bold text-trading-green">{{ stats.totalFeeCollected }}</div>
                <div class="text-xs text-dark-400 mt-1">Internal fees: {{ stats.totalInternalFees }}</div>
            </div>
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
                <div class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-2">Fee Collector</div>
                <div :class="['text-sm font-mono', stats.feeCollectorConfigured ? 'text-trading-green' : 'text-red-400']">
                    {{ shortWallet(stats.feeCollectorWallet) }}
                </div>
                <div class="mt-2">
                    <span v-if="stats.feeCollectorConfigured" class="inline-flex items-center gap-1 text-xs text-trading-green bg-trading-green/10 px-2 py-0.5 rounded-full">
                        <span class="w-1.5 h-1.5 rounded-full bg-trading-green"></span> Active
                    </span>
                    <span v-else class="inline-flex items-center gap-1 text-xs text-red-400 bg-red-500/10 px-2 py-0.5 rounded-full">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span> Not Set
                    </span>
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
                                <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">UUID</th>
                                <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Type</th>
                                <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Amount</th>
                                <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Status</th>
                                <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tx in recentTransactions"
                                :key="tx.uuid"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <td class="py-3 px-4 text-sm font-mono text-dark-300">
                                    {{ tx.uuid?.substring(0, 8) }}...
                                </td>
                                <td class="py-3 px-4 text-sm text-white capitalize">{{ tx.type }}</td>
                                <td class="py-3 px-4 text-sm font-mono text-white">{{ tx.amount }}</td>
                                <td class="py-3 px-4">
                                    <StatusBadge :status="tx.status" type="transaction" />
                                </td>
                                <td class="py-3 px-4 text-sm text-dark-400">{{ tx.created_at }}</td>
                            </tr>
                        </tbody>
                        <tbody v-if="recentTransactions.length === 0">
                            <tr>
                                <td colspan="5" class="py-12 text-center text-dark-400 text-sm">
                                    No recent transactions
                                </td>
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
                        <Link
                            href="/admin/chains"
                            class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group"
                        >
                            <div class="w-10 h-10 rounded-lg bg-primary-500/10 flex items-center justify-center group-hover:bg-primary-500/20 transition-colors">
                                <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Add Chain</p>
                                <p class="text-xs text-dark-400">Register a new blockchain</p>
                            </div>
                        </Link>

                        <Link
                            href="/admin/trading-pairs"
                            class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group"
                        >
                            <div class="w-10 h-10 rounded-lg bg-green-500/10 flex items-center justify-center group-hover:bg-green-500/20 transition-colors">
                                <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Add Trading Pair</p>
                                <p class="text-xs text-dark-400">Create a new trading pair</p>
                            </div>
                        </Link>

                        <Link
                            href="/admin/fees"
                            class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group"
                        >
                            <div class="w-10 h-10 rounded-lg bg-cyan-500/10 flex items-center justify-center group-hover:bg-cyan-500/20 transition-colors">
                                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Fee Management</p>
                                <p class="text-xs text-dark-400">Configure trading fees</p>
                            </div>
                        </Link>

                        <Link
                            href="/admin/support"
                            class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group"
                        >
                            <div class="w-10 h-10 rounded-lg bg-yellow-500/10 flex items-center justify-center group-hover:bg-yellow-500/20 transition-colors">
                                <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">View Tickets</p>
                                <p class="text-xs text-dark-400">{{ stats.openTickets }} open tickets</p>
                            </div>
                        </Link>

                        <Link
                            href="/admin/settings"
                            class="flex items-center gap-3 p-3 rounded-xl bg-dark-800/50 hover:bg-dark-800 border border-white/5 hover:border-white/10 transition-all duration-200 group"
                        >
                            <div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center group-hover:bg-purple-500/20 transition-colors">
                                <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Site Settings</p>
                                <p class="text-xs text-dark-400">Configure platform</p>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- System Info -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h2 class="text-lg font-semibold text-white mb-4">System Info</h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-dark-400">Platform</span>
                            <span class="text-white">TPIX TRADE</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Framework</span>
                            <span class="text-white">Laravel 11</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Frontend</span>
                            <span class="text-white">Vue 3 + Inertia</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Developer</span>
                            <span class="text-white">Xman Studio</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
