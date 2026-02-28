<script setup>
/**
 * TPIX TRADE - Admin Trading Pairs Management
 * Trading pair creation and management
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    pairs: {
        type: Array,
        default: () => [],
    },
    chains: {
        type: Array,
        default: () => [],
    },
    tokens: {
        type: Array,
        default: () => [],
    },
});

const columns = [
    { key: 'symbol', label: 'Pair', sortable: true },
    { key: 'base_token', label: 'Base Token' },
    { key: 'quote_token', label: 'Quote Token' },
    { key: 'chain', label: 'Chain' },
    { key: 'min_amount', label: 'Min Amount', align: 'right' },
    { key: 'max_amount', label: 'Max Amount', align: 'right' },
    { key: 'maker_fee', label: 'Fee %', align: 'right' },
    { key: 'is_active', label: 'Active', align: 'center' },
    { key: 'actions', label: 'Actions', align: 'right' },
];

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingPair = ref(null);
const deletingPair = ref(null);

const form = useForm({
    chain_id: '',
    base_token_id: '',
    quote_token_id: '',
    price_precision: 8,
    amount_precision: 8,
    min_amount: 0,
    max_amount: 0,
    maker_fee_override: null,
    taker_fee_override: null,
    is_active: true,
});

const selectedChainId = ref('');

const filteredTokens = computed(() => {
    if (!selectedChainId.value) return [];
    return props.tokens.filter(t => String(t.chain_id) === String(selectedChainId.value));
});

watch(selectedChainId, (val) => {
    form.chain_id = val;
    form.base_token_id = '';
    form.quote_token_id = '';
});

const openCreateModal = () => {
    editingPair.value = null;
    form.reset();
    form.clearErrors();
    selectedChainId.value = '';
    showModal.value = true;
};

const openEditModal = (pair) => {
    editingPair.value = pair;
    selectedChainId.value = pair.chain_id || '';
    form.chain_id = pair.chain_id || '';
    form.base_token_id = pair.base_token_id || '';
    form.quote_token_id = pair.quote_token_id || '';
    form.price_precision = pair.price_precision || 8;
    form.amount_precision = pair.amount_precision || 8;
    form.min_amount = pair.min_amount || 0;
    form.max_amount = pair.max_amount || 0;
    form.maker_fee_override = pair.maker_fee_override;
    form.taker_fee_override = pair.taker_fee_override;
    form.is_active = pair.is_active;
    form.clearErrors();
    showModal.value = true;
};

const savePair = () => {
    if (editingPair.value) {
        form.put(`/admin/trading-pairs/${editingPair.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post('/admin/trading-pairs', {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
};

const confirmDelete = (pair) => {
    deletingPair.value = pair;
    showDeleteConfirm.value = true;
};

const deletePair = () => {
    router.delete(`/admin/trading-pairs/${deletingPair.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
};

const toggleActive = (pair) => {
    router.patch(`/admin/trading-pairs/${pair.id}/toggle`, {}, { preserveScroll: true });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head title="Trading Pairs" />

    <AdminLayout title="Trading Pairs">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Trading Pairs</h2>
                <p class="text-sm text-dark-400 mt-1">Manage trading pair configurations</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Pair
            </button>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="pairs">
            <template #cell-symbol="{ row }">
                <span class="font-semibold text-white">{{ row.symbol }}</span>
            </template>
            <template #cell-base_token="{ row }">
                <span class="text-dark-300">{{ row.base_token?.symbol || '-' }}</span>
            </template>
            <template #cell-quote_token="{ row }">
                <span class="text-dark-300">{{ row.quote_token?.symbol || '-' }}</span>
            </template>
            <template #cell-chain="{ row }">
                <span class="text-dark-300">{{ row.chain?.name || '-' }}</span>
            </template>
            <template #cell-min_amount="{ value }">
                <span class="font-mono text-dark-300">{{ value || '-' }}</span>
            </template>
            <template #cell-max_amount="{ value }">
                <span class="font-mono text-dark-300">{{ value || '-' }}</span>
            </template>
            <template #cell-maker_fee="{ row }">
                <span class="font-mono text-dark-300">{{ row.maker_fee_override ?? row.maker_fee ?? '-' }}%</span>
            </template>
            <template #cell-is_active="{ row }">
                <button @click.stop="toggleActive(row)" class="flex items-center justify-center">
                    <span v-if="row.is_active" class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                    <span v-else class="w-2.5 h-2.5 rounded-full bg-dark-600"></span>
                </button>
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

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingPair ? 'Edit Trading Pair' : 'Create Trading Pair'" max-width="xl" @close="showModal = false">
            <form @submit.prevent="savePair" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Chain</label>
                    <select v-model="selectedChainId" :class="inputClass">
                        <option value="">Select Chain</option>
                        <option v-for="chain in chains" :key="chain.id" :value="chain.id">{{ chain.name }}</option>
                    </select>
                    <p v-if="form.errors.chain_id" class="mt-1 text-sm text-red-400">{{ form.errors.chain_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Base Token</label>
                        <select v-model="form.base_token_id" :class="inputClass" :disabled="!selectedChainId">
                            <option value="">Select Base Token</option>
                            <option v-for="token in filteredTokens" :key="token.id" :value="token.id">{{ token.symbol }} - {{ token.name }}</option>
                        </select>
                        <p v-if="form.errors.base_token_id" class="mt-1 text-sm text-red-400">{{ form.errors.base_token_id }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Quote Token</label>
                        <select v-model="form.quote_token_id" :class="inputClass" :disabled="!selectedChainId">
                            <option value="">Select Quote Token</option>
                            <option v-for="token in filteredTokens" :key="token.id" :value="token.id">{{ token.symbol }} - {{ token.name }}</option>
                        </select>
                        <p v-if="form.errors.quote_token_id" class="mt-1 text-sm text-red-400">{{ form.errors.quote_token_id }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Price Precision</label>
                        <input v-model.number="form.price_precision" type="number" min="0" max="18" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Amount Precision</label>
                        <input v-model.number="form.amount_precision" type="number" min="0" max="18" :class="inputClass" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Min Amount</label>
                        <input v-model.number="form.min_amount" type="number" step="0.00000001" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Max Amount</label>
                        <input v-model.number="form.max_amount" type="number" step="0.00000001" :class="inputClass" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Maker Fee Override %</label>
                        <input v-model="form.maker_fee_override" type="number" step="0.001" :class="inputClass" placeholder="Leave empty for default" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Taker Fee Override %</label>
                        <input v-model="form.taker_fee_override" type="number" step="0.001" :class="inputClass" placeholder="Leave empty for default" />
                    </div>
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
                    <button @click="savePair" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingPair ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Trading Pair"
            :message="`Are you sure you want to delete '${deletingPair?.symbol}'?`"
            confirm-text="Delete"
            :danger="true"
            @confirm="deletePair"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
