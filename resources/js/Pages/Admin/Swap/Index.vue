<script setup>
/**
 * TPIX TRADE - Admin Swap Configuration
 * DEX swap router configurations management
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    configs: {
        type: Array,
        default: () => [],
    },
    chains: {
        type: Array,
        default: () => [],
    },
});

const protocols = [
    'UniswapV2',
    'UniswapV3',
    'PancakeSwap',
    'SushiSwap',
    'Custom',
];

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'chain', label: 'Chain' },
    { key: 'protocol', label: 'Protocol', sortable: true },
    { key: 'router_address', label: 'Router Address' },
    { key: 'default_slippage', label: 'Slippage %', align: 'right' },
    { key: 'is_active', label: 'Active', align: 'center' },
    { key: 'actions', label: 'Actions', align: 'right' },
];

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingConfig = ref(null);
const deletingConfig = ref(null);

const form = useForm({
    name: '',
    chain_id: '',
    protocol: 'UniswapV2',
    router_address: '',
    factory_address: '',
    default_slippage: 0.5,
    is_active: true,
});

const openCreateModal = () => {
    editingConfig.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEditModal = (config) => {
    editingConfig.value = config;
    form.name = config.name;
    form.chain_id = config.chain_id || '';
    form.protocol = config.protocol;
    form.router_address = config.router_address;
    form.factory_address = config.factory_address || '';
    form.default_slippage = config.default_slippage;
    form.is_active = config.is_active;
    form.clearErrors();
    showModal.value = true;
};

const saveConfig = () => {
    if (editingConfig.value) {
        form.put(`/admin/swap/${editingConfig.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post('/admin/swap', {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
};

const confirmDelete = (config) => {
    deletingConfig.value = config;
    showDeleteConfirm.value = true;
};

const deleteConfig = () => {
    router.delete(`/admin/swap/${deletingConfig.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
};

const toggleActive = (config) => {
    router.patch(`/admin/swap/${config.id}/toggle`, {}, { preserveScroll: true });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head title="Swap Configuration" />

    <AdminLayout title="Swap Configuration">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Swap Configuration</h2>
                <p class="text-sm text-dark-400 mt-1">Manage DEX swap router settings</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Config
            </button>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="configs">
            <template #cell-name="{ value }">
                <span class="font-medium text-white">{{ value }}</span>
            </template>
            <template #cell-chain="{ row }">
                <span class="text-dark-300">{{ row.chain?.name || '-' }}</span>
            </template>
            <template #cell-protocol="{ value }">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20">
                    {{ value }}
                </span>
            </template>
            <template #cell-router_address="{ value }">
                <span class="font-mono text-xs text-dark-300">{{ value ? `${value.substring(0, 6)}...${value.slice(-4)}` : '-' }}</span>
            </template>
            <template #cell-default_slippage="{ value }">
                <span class="font-mono">{{ value }}%</span>
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
        <Modal :show="showModal" :title="editingConfig ? 'Edit Swap Config' : 'Create Swap Config'" @close="showModal = false">
            <form @submit.prevent="saveConfig" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Name</label>
                    <input v-model="form.name" type="text" :class="inputClass" placeholder="Uniswap V2 - Ethereum" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">{{ form.errors.name }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Chain</label>
                        <select v-model="form.chain_id" :class="inputClass">
                            <option value="">Select Chain</option>
                            <option v-for="chain in chains" :key="chain.id" :value="chain.id">{{ chain.name }}</option>
                        </select>
                        <p v-if="form.errors.chain_id" class="mt-1 text-sm text-red-400">{{ form.errors.chain_id }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Protocol</label>
                        <select v-model="form.protocol" :class="inputClass">
                            <option v-for="p in protocols" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Router Address</label>
                    <input v-model="form.router_address" type="text" :class="inputClass" placeholder="0x..." />
                    <p v-if="form.errors.router_address" class="mt-1 text-sm text-red-400">{{ form.errors.router_address }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Factory Address (Optional)</label>
                    <input v-model="form.factory_address" type="text" :class="inputClass" placeholder="0x..." />
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Default Slippage (%)</label>
                    <input v-model.number="form.default_slippage" type="number" step="0.1" min="0" :class="inputClass" />
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
                    <button @click="saveConfig" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingConfig ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Swap Config"
            :message="`Are you sure you want to delete '${deletingConfig?.name}'?`"
            confirm-text="Delete"
            :danger="true"
            @confirm="deleteConfig"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
