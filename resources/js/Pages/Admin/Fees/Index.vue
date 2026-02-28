<script setup>
/**
 * TPIX TRADE - Admin Fees Management
 * Fee tiers table with create/edit/delete functionality
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    fees: {
        type: Array,
        default: () => [],
    },
    chains: {
        type: Array,
        default: () => [],
    },
    feeTypes: {
        type: Array,
        default: () => ['trading', 'swap', 'withdrawal', 'deposit'],
    },
});

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'maker_fee', label: 'Maker Fee %', sortable: true, align: 'right' },
    { key: 'taker_fee', label: 'Taker Fee %', sortable: true, align: 'right' },
    { key: 'min_amount', label: 'Min Amount', align: 'right' },
    { key: 'max_amount', label: 'Max Amount', align: 'right' },
    { key: 'chain', label: 'Chain' },
    { key: 'is_active', label: 'Active', align: 'center' },
    { key: 'actions', label: 'Actions', align: 'right' },
];

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingFee = ref(null);
const deletingFee = ref(null);
const filterType = ref('');

const form = useForm({
    name: '',
    type: 'trading',
    maker_fee: 0,
    taker_fee: 0,
    min_amount: 0,
    max_amount: 0,
    chain_id: '',
    is_active: true,
});

const filteredFees = ref(props.fees);

const applyFilter = () => {
    if (!filterType.value) {
        filteredFees.value = props.fees;
    } else {
        filteredFees.value = props.fees.filter(f => f.type === filterType.value);
    }
};

const openCreateModal = () => {
    editingFee.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEditModal = (fee) => {
    editingFee.value = fee;
    form.name = fee.name;
    form.type = fee.type;
    form.maker_fee = fee.maker_fee;
    form.taker_fee = fee.taker_fee;
    form.min_amount = fee.min_amount;
    form.max_amount = fee.max_amount;
    form.chain_id = fee.chain_id || '';
    form.is_active = fee.is_active;
    form.clearErrors();
    showModal.value = true;
};

const saveFee = () => {
    if (editingFee.value) {
        form.put(`/admin/fees/${editingFee.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post('/admin/fees', {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
};

const confirmDelete = (fee) => {
    deletingFee.value = fee;
    showDeleteConfirm.value = true;
};

const deleteFee = () => {
    router.delete(`/admin/fees/${deletingFee.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
};

const toggleActive = (fee) => {
    router.patch(`/admin/fees/${fee.id}/toggle`, {}, { preserveScroll: true });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head title="Fee Management" />

    <AdminLayout title="Fee Management">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Fees</h2>
                <p class="text-sm text-dark-400 mt-1">Manage trading and platform fee tiers</p>
            </div>
            <div class="flex items-center gap-3">
                <!-- Type Filter -->
                <select v-model="filterType" @change="applyFilter" class="bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500">
                    <option value="">All Types</option>
                    <option v-for="t in feeTypes" :key="t" :value="t">{{ t.charAt(0).toUpperCase() + t.slice(1) }}</option>
                </select>
                <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Fee
                </button>
            </div>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="filteredFees">
            <template #cell-type="{ value }">
                <span class="capitalize text-dark-300">{{ value }}</span>
            </template>
            <template #cell-maker_fee="{ value }">
                <span class="font-mono">{{ value }}%</span>
            </template>
            <template #cell-taker_fee="{ value }">
                <span class="font-mono">{{ value }}%</span>
            </template>
            <template #cell-min_amount="{ value }">
                <span class="font-mono text-dark-300">{{ value || '-' }}</span>
            </template>
            <template #cell-max_amount="{ value }">
                <span class="font-mono text-dark-300">{{ value || '-' }}</span>
            </template>
            <template #cell-chain="{ row }">
                <span class="text-dark-300">{{ row.chain?.name || 'All' }}</span>
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
        <Modal :show="showModal" :title="editingFee ? 'Edit Fee' : 'Create Fee'" @close="showModal = false">
            <form @submit.prevent="saveFee" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Name</label>
                    <input v-model="form.name" type="text" :class="inputClass" placeholder="Standard Trading Fee" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Type</label>
                    <select v-model="form.type" :class="inputClass">
                        <option v-for="t in feeTypes" :key="t" :value="t">{{ t.charAt(0).toUpperCase() + t.slice(1) }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Maker Fee %</label>
                        <input v-model.number="form.maker_fee" type="number" step="0.001" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Taker Fee %</label>
                        <input v-model.number="form.taker_fee" type="number" step="0.001" :class="inputClass" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Min Amount</label>
                        <input v-model.number="form.min_amount" type="number" step="0.01" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Max Amount</label>
                        <input v-model.number="form.max_amount" type="number" step="0.01" :class="inputClass" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Chain (Optional)</label>
                    <select v-model="form.chain_id" :class="inputClass">
                        <option value="">All Chains</option>
                        <option v-for="chain in chains" :key="chain.id" :value="chain.id">{{ chain.name }}</option>
                    </select>
                </div>
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-dark-300">Active</label>
                    <button
                        type="button"
                        @click="form.is_active = !form.is_active"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                        :class="form.is_active ? 'bg-primary-500' : 'bg-dark-600'"
                    >
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                    </button>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">Cancel</button>
                    <button @click="saveFee" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingFee ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Fee"
            :message="`Are you sure you want to delete '${deletingFee?.name}'? This action cannot be undone.`"
            confirm-text="Delete"
            :danger="true"
            @confirm="deleteFee"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
