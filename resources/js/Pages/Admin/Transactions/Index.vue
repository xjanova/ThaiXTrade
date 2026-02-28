<script setup>
/**
 * TPIX TRADE - Admin Transactions Index
 * Filterable transaction history with pagination
 * Developed by Xman Studio
 */

import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    transactions: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    transactionTypes: {
        type: Array,
        default: () => ['swap', 'trade', 'deposit', 'withdrawal', 'transfer'],
    },
    statuses: {
        type: Array,
        default: () => ['pending', 'processing', 'completed', 'failed', 'cancelled'],
    },
});

const columns = [
    { key: 'uuid', label: 'UUID' },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'wallet', label: 'Wallet' },
    { key: 'from_to', label: 'From / To' },
    { key: 'amount', label: 'Amount', sortable: true, align: 'right' },
    { key: 'fee', label: 'Fee', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'tx_hash', label: 'TX Hash' },
    { key: 'created_at', label: 'Date', sortable: true },
];

const filterType = ref(props.filters.type || '');
const filterStatus = ref(props.filters.status || '');
const filterWallet = ref(props.filters.wallet || '');
const filterDateFrom = ref(props.filters.date_from || '');
const filterDateTo = ref(props.filters.date_to || '');

const applyFilters = () => {
    router.get('/admin/transactions', {
        type: filterType.value || undefined,
        status: filterStatus.value || undefined,
        wallet: filterWallet.value || undefined,
        date_from: filterDateFrom.value || undefined,
        date_to: filterDateTo.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filterType.value = '';
    filterStatus.value = '';
    filterWallet.value = '';
    filterDateFrom.value = '';
    filterDateTo.value = '';
    router.get('/admin/transactions', {}, { preserveState: true });
};

const viewTransaction = (tx) => {
    router.get(`/admin/transactions/${tx.id}`);
};

const selectClass = 'bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500';
const inputClass = 'bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white placeholder-dark-500 focus:outline-none focus:border-primary-500';
</script>

<template>
    <Head title="Transactions" />

    <AdminLayout title="Transactions">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Transactions</h2>
                <p class="text-sm text-dark-400 mt-1">View and filter all platform transactions</p>
            </div>
            <button class="btn-secondary px-4 py-2.5 text-sm opacity-50 cursor-not-allowed" disabled title="Coming soon">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Export CSV
            </button>
        </div>

        <!-- Filters -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Type</label>
                    <select v-model="filterType" :class="selectClass">
                        <option value="">All Types</option>
                        <option v-for="t in transactionTypes" :key="t" :value="t">{{ t.charAt(0).toUpperCase() + t.slice(1) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Status</label>
                    <select v-model="filterStatus" :class="selectClass">
                        <option value="">All Statuses</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ s.charAt(0).toUpperCase() + s.slice(1) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Wallet</label>
                    <input v-model="filterWallet" type="text" :class="inputClass" placeholder="0x..." class="w-48" />
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">From</label>
                    <input v-model="filterDateFrom" type="date" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">To</label>
                    <input v-model="filterDateTo" type="date" :class="inputClass" />
                </div>
                <div class="flex items-center gap-2">
                    <button @click="applyFilters" class="btn-primary px-4 py-2.5 text-sm">Filter</button>
                    <button @click="resetFilters" class="px-4 py-2.5 text-sm text-dark-400 hover:text-white transition-colors">Reset</button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="transactions.data" @row-click="viewTransaction">
            <template #cell-uuid="{ value }">
                <span class="font-mono text-xs text-dark-300">{{ value?.substring(0, 8) }}...</span>
            </template>
            <template #cell-type="{ value }">
                <span class="capitalize">{{ value }}</span>
            </template>
            <template #cell-wallet="{ row }">
                <span class="font-mono text-xs text-dark-300">{{ row.wallet_address?.substring(0, 6) }}...{{ row.wallet_address?.slice(-4) }}</span>
            </template>
            <template #cell-from_to="{ row }">
                <div class="flex items-center gap-1 text-sm">
                    <span class="text-dark-300">{{ row.from_token?.symbol || '-' }}</span>
                    <svg class="w-3 h-3 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                    <span class="text-dark-300">{{ row.to_token?.symbol || '-' }}</span>
                </div>
            </template>
            <template #cell-amount="{ value }">
                <span class="font-mono">{{ value }}</span>
            </template>
            <template #cell-fee="{ value }">
                <span class="font-mono text-dark-400">{{ value || '-' }}</span>
            </template>
            <template #cell-status="{ value }">
                <StatusBadge :status="value" type="transaction" />
            </template>
            <template #cell-tx_hash="{ value }">
                <span v-if="value" class="font-mono text-xs text-primary-400">{{ value.substring(0, 8) }}...</span>
                <span v-else class="text-dark-500">-</span>
            </template>
            <template #cell-created_at="{ value }">
                <span class="text-dark-400 text-xs">{{ value }}</span>
            </template>
        </DataTable>

        <!-- Pagination -->
        <div v-if="transactions.links && transactions.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in transactions.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="px-3 py-2 rounded-lg text-sm transition-colors"
                    :class="link.active ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5'"
                    v-html="link.label"
                    preserve-scroll
                />
                <span v-else class="px-3 py-2 text-sm text-dark-600" v-html="link.label" />
            </template>
        </div>
    </AdminLayout>
</template>
