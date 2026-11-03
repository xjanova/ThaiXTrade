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
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

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

const columns = computed(() => [
    { key: 'symbol', label: t('admin.tradingPairs.columns.pair'), sortable: true },
    { key: 'base_token', label: t('admin.tradingPairs.columns.baseToken') },
    { key: 'quote_token', label: t('admin.tradingPairs.columns.quoteToken') },
    { key: 'chain', label: t('admin.tradingPairs.columns.chain') },
    { key: 'min_amount', label: t('admin.tradingPairs.columns.minAmount'), align: 'right' },
    { key: 'max_amount', label: t('admin.tradingPairs.columns.maxAmount'), align: 'right' },
    { key: 'maker_fee', label: t('admin.tradingPairs.columns.fee'), align: 'right' },
    { key: 'is_active', label: t('admin.tradingPairs.columns.active'), align: 'center' },
    { key: 'actions', label: t('admin.tradingPairs.columns.actions'), align: 'right' },
]);

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
    return props.tokens.filter(tok => String(tok.chain_id) === String(selectedChainId.value));
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
    <Head :title="t('admin.tradingPairs.title')" />

    <AdminLayout :title="t('admin.tradingPairs.title')">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">{{ t('admin.tradingPairs.title') }}</h2>
                <p class="text-sm text-dark-400 mt-1">{{ t('admin.tradingPairs.subtitle') }}</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ t('admin.tradingPairs.addPair') }}
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
                <!--
                    คู่ execution_mode=index อ้างอิงราคาจาก Binance เฉยๆ swap จริงไม่ได้
                    ต้องติดป้ายให้ชัด ไม่งั้นหลังบ้านจะอ่านว่า "คู่นี้เทรดได้บนเชนนี้" ซึ่งไม่จริง
                -->
                <span
                    v-if="row.execution_mode === 'index'"
                    class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-white/5 text-dark-400 ring-1 ring-white/10"
                    :title="t('admin.tradingPairs.mode.indexTitle')"
                >{{ t('admin.tradingPairs.mode.index') }}</span>
                <span
                    v-else
                    class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-trading-green/10 text-trading-green ring-1 ring-trading-green/20"
                    :title="t('admin.tradingPairs.mode.swapTitle')"
                >{{ t('admin.tradingPairs.mode.swap') }}</span>
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
                    <button @click="openEditModal(row)" :title="t('common.edit')" :aria-label="t('common.edit')" class="p-1.5 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                    <button @click="confirmDelete(row)" :title="t('common.delete')" :aria-label="t('common.delete')" class="p-1.5 rounded-lg text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingPair ? t('admin.tradingPairs.modal.editTitle') : t('admin.tradingPairs.modal.createTitle')" max-width="xl" @close="showModal = false">
            <form @submit.prevent="savePair" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.chain') }}</label>
                    <select v-model="selectedChainId" :class="inputClass">
                        <option value="">{{ t('admin.tradingPairs.form.selectChain') }}</option>
                        <option v-for="chain in chains" :key="chain.id" :value="chain.id">{{ chain.name }}</option>
                    </select>
                    <p v-if="form.errors.chain_id" class="mt-1 text-sm text-red-400">{{ form.errors.chain_id }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.baseToken') }}</label>
                        <select v-model="form.base_token_id" :class="inputClass" :disabled="!selectedChainId">
                            <option value="">{{ t('admin.tradingPairs.form.selectBaseToken') }}</option>
                            <option v-for="token in filteredTokens" :key="token.id" :value="token.id">{{ token.symbol }} - {{ token.name }}</option>
                        </select>
                        <p v-if="form.errors.base_token_id" class="mt-1 text-sm text-red-400">{{ form.errors.base_token_id }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.quoteToken') }}</label>
                        <select v-model="form.quote_token_id" :class="inputClass" :disabled="!selectedChainId">
                            <option value="">{{ t('admin.tradingPairs.form.selectQuoteToken') }}</option>
                            <option v-for="token in filteredTokens" :key="token.id" :value="token.id">{{ token.symbol }} - {{ token.name }}</option>
                        </select>
                        <p v-if="form.errors.quote_token_id" class="mt-1 text-sm text-red-400">{{ form.errors.quote_token_id }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.pricePrecision') }}</label>
                        <input v-model.number="form.price_precision" type="number" min="0" max="18" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.amountPrecision') }}</label>
                        <input v-model.number="form.amount_precision" type="number" min="0" max="18" :class="inputClass" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.minAmount') }}</label>
                        <input v-model.number="form.min_amount" type="number" step="0.00000001" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.maxAmount') }}</label>
                        <input v-model.number="form.max_amount" type="number" step="0.00000001" :class="inputClass" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.makerFeeOverride') }}</label>
                        <input v-model="form.maker_fee_override" type="number" step="0.001" :class="inputClass" :placeholder="t('admin.tradingPairs.form.feePlaceholder')" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">{{ t('admin.tradingPairs.form.takerFeeOverride') }}</label>
                        <input v-model="form.taker_fee_override" type="number" step="0.001" :class="inputClass" :placeholder="t('admin.tradingPairs.form.feePlaceholder')" />
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-dark-300">{{ t('admin.tradingPairs.form.active') }}</label>
                    <button type="button" @click="form.is_active = !form.is_active" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_active ? 'bg-primary-500' : 'bg-dark-600'">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                    </button>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">{{ t('common.cancel') }}</button>
                    <button @click="savePair" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? t('admin.tradingPairs.modal.saving') : (editingPair ? t('admin.tradingPairs.modal.update') : t('admin.tradingPairs.modal.create')) }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            :title="t('admin.tradingPairs.delete.title')"
            :message="t('admin.tradingPairs.delete.message', { symbol: deletingPair?.symbol ?? '' })"
            :confirm-text="t('common.delete')"
            :danger="true"
            @confirm="deletePair"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
