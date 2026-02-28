<script setup>
/**
 * TPIX TRADE - Admin Transaction Detail
 * Full transaction detail view with metadata and timeline
 * Developed by Xman Studio
 */

import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    transaction: {
        type: Object,
        required: true,
    },
    statusHistory: {
        type: Array,
        default: () => [],
    },
});

const explorerUrl = (hash) => {
    if (!hash || !props.transaction.chain?.explorer_url) return null;
    return `${props.transaction.chain.explorer_url}/tx/${hash}`;
};

const formatMetadata = (meta) => {
    if (!meta) return null;
    try {
        if (typeof meta === 'string') return JSON.parse(meta);
        return meta;
    } catch {
        return meta;
    }
};
</script>

<template>
    <Head :title="`Transaction ${transaction.uuid?.substring(0, 8)}...`" />

    <AdminLayout title="Transaction Detail">
        <!-- Back Link -->
        <div class="mb-6">
            <Link href="/admin/transactions" class="inline-flex items-center gap-2 text-sm text-dark-400 hover:text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Transactions
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Transaction Card -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-white">Transaction Details</h2>
                        <StatusBadge :status="transaction.status" type="transaction" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-4">
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">UUID</p>
                                <p class="font-mono text-sm text-white break-all">{{ transaction.uuid }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Type</p>
                                <p class="text-sm text-white capitalize">{{ transaction.type }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Wallet Address</p>
                                <p class="font-mono text-sm text-white break-all">{{ transaction.wallet_address }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Chain</p>
                                <p class="text-sm text-white">{{ transaction.chain?.name || '-' }}</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">From Token</p>
                                <p class="text-sm text-white">{{ transaction.from_token?.symbol || '-' }} ({{ transaction.from_token?.name || '-' }})</p>
                            </div>
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">To Token</p>
                                <p class="text-sm text-white">{{ transaction.to_token?.symbol || '-' }} ({{ transaction.to_token?.name || '-' }})</p>
                            </div>
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Amount</p>
                                <p class="font-mono text-lg font-semibold text-white">{{ transaction.amount }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Fee</p>
                                <p class="font-mono text-sm text-dark-300">{{ transaction.fee || '0' }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- TX Hash -->
                    <div v-if="transaction.tx_hash" class="mt-6 pt-4 border-t border-white/5">
                        <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Transaction Hash</p>
                        <div class="flex items-center gap-3">
                            <p class="font-mono text-sm text-primary-400 break-all">{{ transaction.tx_hash }}</p>
                            <a
                                v-if="explorerUrl(transaction.tx_hash)"
                                :href="explorerUrl(transaction.tx_hash)"
                                target="_blank"
                                class="flex-shrink-0 p-1.5 rounded-lg text-dark-400 hover:text-primary-400 hover:bg-primary-500/10 transition-colors"
                                title="View on Block Explorer"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>
                    </div>

                    <!-- Dates -->
                    <div class="mt-6 pt-4 border-t border-white/5 grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Created At</p>
                            <p class="text-sm text-dark-300">{{ transaction.created_at }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Updated At</p>
                            <p class="text-sm text-dark-300">{{ transaction.updated_at }}</p>
                        </div>
                    </div>
                </div>

                <!-- Metadata -->
                <div v-if="transaction.metadata" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Metadata</h3>
                    <pre class="bg-dark-800/50 border border-dark-600 rounded-xl p-4 text-sm text-dark-300 font-mono overflow-x-auto">{{ JSON.stringify(formatMetadata(transaction.metadata), null, 2) }}</pre>
                </div>
            </div>

            <!-- Sidebar: Timeline -->
            <div class="space-y-6">
                <!-- Status Timeline -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Status Timeline</h3>
                    <div v-if="statusHistory.length > 0" class="space-y-0">
                        <div v-for="(entry, idx) in statusHistory" :key="idx" class="relative pl-6 pb-6 last:pb-0">
                            <!-- Line -->
                            <div v-if="idx < statusHistory.length - 1" class="absolute left-[9px] top-3 w-0.5 h-full bg-dark-700"></div>
                            <!-- Dot -->
                            <div class="absolute left-0 top-1 w-[18px] h-[18px] rounded-full border-2 flex items-center justify-center"
                                :class="{
                                    'border-green-400 bg-green-400/20': entry.status === 'completed',
                                    'border-yellow-400 bg-yellow-400/20': entry.status === 'pending' || entry.status === 'processing',
                                    'border-red-400 bg-red-400/20': entry.status === 'failed' || entry.status === 'cancelled',
                                    'border-primary-400 bg-primary-400/20': !['completed','pending','processing','failed','cancelled'].includes(entry.status),
                                }"
                            >
                                <div class="w-2 h-2 rounded-full"
                                    :class="{
                                        'bg-green-400': entry.status === 'completed',
                                        'bg-yellow-400': entry.status === 'pending' || entry.status === 'processing',
                                        'bg-red-400': entry.status === 'failed' || entry.status === 'cancelled',
                                        'bg-primary-400': !['completed','pending','processing','failed','cancelled'].includes(entry.status),
                                    }"
                                ></div>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white capitalize">{{ entry.status }}</p>
                                <p class="text-xs text-dark-400 mt-0.5">{{ entry.created_at }}</p>
                                <p v-if="entry.note" class="text-xs text-dark-500 mt-1">{{ entry.note }}</p>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-6">
                        <p class="text-sm text-dark-400">No status history available</p>
                    </div>
                </div>

                <!-- Quick Info -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Summary</h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-dark-400">Status</span>
                            <StatusBadge :status="transaction.status" />
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Amount</span>
                            <span class="font-mono text-white">{{ transaction.amount }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Fee</span>
                            <span class="font-mono text-dark-300">{{ transaction.fee || '0' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Net Amount</span>
                            <span class="font-mono text-white">{{ transaction.net_amount || transaction.amount }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
