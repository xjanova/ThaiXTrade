<script setup>
/**
 * TPIX TRADE - Admin Tokens Management
 * Token management with create/edit/delete functionality
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    tokens: {
        type: Array,
        default: () => [],
    },
    chains: {
        type: Array,
        default: () => [],
    },
    chain: {
        type: Object,
        default: null,
    },
});

const isChainView = computed(() => !!props.chain);
const pageTitle = computed(() => isChainView.value ? `${props.chain.name} Tokens` : 'All Tokens');

const columns = computed(() => {
    const cols = [
        { key: 'symbol', label: 'Symbol', sortable: true },
        { key: 'name', label: 'Name', sortable: true },
    ];
    if (!isChainView.value) {
        cols.push({ key: 'chain', label: 'Chain' });
    }
    cols.push(
        { key: 'contract_address', label: 'Contract Address' },
        { key: 'decimals', label: 'Decimals', align: 'center' },
        { key: 'coingecko_id', label: 'CoinGecko ID' },
        { key: 'is_active', label: 'Active', align: 'center' },
        { key: 'actions', label: 'Actions', align: 'right' },
    );
    return cols;
});

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingToken = ref(null);
const deletingToken = ref(null);
const filterChainId = ref('');

const filteredTokens = computed(() => {
    if (!filterChainId.value) return props.tokens;
    return props.tokens.filter(t => String(t.chain_id) === String(filterChainId.value));
});

const form = useForm({
    chain_id: '',
    name: '',
    symbol: '',
    contract_address: '',
    decimals: 18,
    logo: '',
    coingecko_id: '',
    is_active: true,
    sort_order: 0,
});

const openCreateModal = () => {
    editingToken.value = null;
    form.reset();
    form.clearErrors();
    if (isChainView.value) {
        form.chain_id = props.chain.id;
    }
    showModal.value = true;
};

const openEditModal = (token) => {
    editingToken.value = token;
    form.chain_id = token.chain_id || '';
    form.name = token.name;
    form.symbol = token.symbol;
    form.contract_address = token.contract_address;
    form.decimals = token.decimals;
    form.logo = token.logo || '';
    form.coingecko_id = token.coingecko_id || '';
    form.is_active = token.is_active;
    form.sort_order = token.sort_order || 0;
    form.clearErrors();
    showModal.value = true;
};

const saveToken = () => {
    if (editingToken.value) {
        form.put(`/admin/tokens/${editingToken.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post('/admin/tokens', {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
};

const confirmDelete = (token) => {
    deletingToken.value = token;
    showDeleteConfirm.value = true;
};

const deleteToken = () => {
    router.delete(`/admin/tokens/${deletingToken.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head :title="pageTitle" />

    <AdminLayout :title="pageTitle">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <div class="flex items-center gap-3">
                    <Link v-if="isChainView" href="/admin/chains" class="text-dark-400 hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h2 class="text-xl font-semibold text-white">{{ pageTitle }}</h2>
                </div>
                <p class="text-sm text-dark-400 mt-1">
                    {{ isChainView ? `Manage tokens on ${chain.name}` : 'Manage all cryptocurrency tokens' }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <!-- Chain Filter (only in all-tokens view) -->
                <select
                    v-if="!isChainView && chains.length > 0"
                    v-model="filterChainId"
                    class="bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500"
                >
                    <option value="">All Chains</option>
                    <option v-for="c in chains" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Token
                </button>
            </div>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="filteredTokens">
            <template #cell-symbol="{ row }">
                <div class="flex items-center gap-2">
                    <div v-if="row.logo" class="w-6 h-6 rounded-full overflow-hidden bg-dark-800">
                        <img :src="row.logo" :alt="row.symbol" class="w-full h-full object-cover" />
                    </div>
                    <div v-else class="w-6 h-6 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
                        <span class="text-white font-bold text-xs">{{ (row.symbol || '?').charAt(0) }}</span>
                    </div>
                    <span class="font-semibold text-white">{{ row.symbol }}</span>
                </div>
            </template>
            <template #cell-name="{ value }">
                <span class="text-dark-300">{{ value }}</span>
            </template>
            <template #cell-chain="{ row }">
                <span class="text-dark-300">{{ row.chain?.name || '-' }}</span>
            </template>
            <template #cell-contract_address="{ value }">
                <span class="font-mono text-xs text-dark-300">{{ value ? `${value.substring(0, 6)}...${value.slice(-4)}` : '-' }}</span>
            </template>
            <template #cell-decimals="{ value }">
                <span class="font-mono text-dark-300">{{ value }}</span>
            </template>
            <template #cell-coingecko_id="{ value }">
                <span class="text-xs text-dark-400">{{ value || '-' }}</span>
            </template>
            <template #cell-is_active="{ row }">
                <span v-if="row.is_active" class="w-2.5 h-2.5 rounded-full bg-green-400 inline-block"></span>
                <span v-else class="w-2.5 h-2.5 rounded-full bg-dark-600 inline-block"></span>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-2" @click.stop>
                    <button @click="openEditModal(row)" class="p-1.5 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                    <button @click="confirmDelete(row)" class="p-1.5 rounded-lg text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- Empty State -->
        <div v-if="filteredTokens.length === 0" class="flex flex-col items-center justify-center py-16 text-center">
            <svg class="w-12 h-12 text-dark-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-dark-400 text-sm">No tokens found</p>
            <button @click="openCreateModal" class="mt-3 text-sm text-primary-400 hover:text-primary-300">Add your first token</button>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingToken ? 'Edit Token' : 'Add Token'" max-width="xl" @close="showModal = false">
            <form @submit.prevent="saveToken" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Chain</label>
                    <select v-model="form.chain_id" :class="inputClass" :disabled="isChainView">
                        <option value="">Select Chain</option>
                        <option v-for="c in chains" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <p v-if="form.errors.chain_id" class="mt-1 text-sm text-red-400">{{ form.errors.chain_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Token Name</label>
                        <input v-model="form.name" type="text" :class="inputClass" placeholder="Ethereum" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Symbol</label>
                        <input v-model="form.symbol" type="text" :class="inputClass" placeholder="ETH" />
                        <p v-if="form.errors.symbol" class="mt-1 text-sm text-red-400">{{ form.errors.symbol }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Contract Address</label>
                    <input v-model="form.contract_address" type="text" :class="inputClass" placeholder="0x..." />
                    <p v-if="form.errors.contract_address" class="mt-1 text-sm text-red-400">{{ form.errors.contract_address }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Decimals</label>
                        <input v-model.number="form.decimals" type="number" min="0" max="36" :class="inputClass" placeholder="18" />
                        <p v-if="form.errors.decimals" class="mt-1 text-sm text-red-400">{{ form.errors.decimals }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Sort Order</label>
                        <input v-model.number="form.sort_order" type="number" min="0" :class="inputClass" placeholder="0" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Logo URL</label>
                    <input v-model="form.logo" type="text" :class="inputClass" placeholder="https://example.com/logo.png" />
                    <p class="mt-1 text-xs text-dark-500">Direct URL to logo image. Leave empty to use default.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">CoinGecko ID</label>
                    <input v-model="form.coingecko_id" type="text" :class="inputClass" placeholder="ethereum" />
                    <p class="mt-1 text-xs text-dark-500">Used for fetching logos and price data from CoinGecko.</p>
                </div>

                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-dark-300">Active</label>
                    <button type="button" @click="form.is_active = !form.is_active" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_active ? 'bg-primary-500' : 'bg-dark-600'">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                    </button>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">Cancel</button>
                    <button @click="saveToken" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingToken ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Token"
            :message="`Are you sure you want to delete '${deletingToken?.name} (${deletingToken?.symbol})'? This may affect existing trading pairs.`"
            confirm-text="Delete"
            :danger="true"
            @confirm="deleteToken"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
