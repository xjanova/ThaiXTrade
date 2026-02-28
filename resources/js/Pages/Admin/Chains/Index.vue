<script setup>
/**
 * TPIX TRADE - Admin Chains Management
 * Blockchain chains management with create/edit/toggle
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    chains: {
        type: Array,
        default: () => [],
    },
});

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingChain = ref(null);
const deletingChain = ref(null);

const form = useForm({
    name: '',
    symbol: '',
    chain_id: '',
    rpc_url: '',
    explorer_url: '',
    logo: null,
    is_active: true,
    is_testnet: false,
    native_currency_name: '',
    native_currency_symbol: '',
    native_currency_decimals: 18,
    block_confirmations: 12,
});

const logoPreview = ref(null);

const handleLogoChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.logo = file;
        logoPreview.value = URL.createObjectURL(file);
    }
};

const openCreateModal = () => {
    editingChain.value = null;
    form.reset();
    form.clearErrors();
    logoPreview.value = null;
    showModal.value = true;
};

const openEditModal = (chain) => {
    editingChain.value = chain;
    form.name = chain.name;
    form.symbol = chain.symbol;
    form.chain_id = chain.chain_id;
    form.rpc_url = chain.rpc_url;
    form.explorer_url = chain.explorer_url || '';
    form.is_active = chain.is_active;
    form.is_testnet = chain.is_testnet;
    form.native_currency_name = chain.native_currency_name || '';
    form.native_currency_symbol = chain.native_currency_symbol || '';
    form.native_currency_decimals = chain.native_currency_decimals || 18;
    form.block_confirmations = chain.block_confirmations || 12;
    form.logo = null;
    logoPreview.value = chain.logo_url || null;
    form.clearErrors();
    showModal.value = true;
};

const saveChain = () => {
    if (editingChain.value) {
        form.post(`/admin/chains/${editingChain.value.id}`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post('/admin/chains', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
};

const confirmDelete = (chain) => {
    deletingChain.value = chain;
    showDeleteConfirm.value = true;
};

const deleteChain = () => {
    router.delete(`/admin/chains/${deletingChain.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
};

const toggleActive = (chain) => {
    router.put(`/admin/chains/${chain.id}/toggle`, {}, { preserveScroll: true });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head title="Chains Management" />

    <AdminLayout title="Chains Management">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Blockchain Chains</h2>
                <p class="text-sm text-dark-400 mt-1">Manage supported blockchain networks</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Chain
            </button>
        </div>

        <!-- Chains Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div
                v-for="chain in chains"
                :key="chain.id"
                class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 hover:bg-white/[0.07] transition-all duration-200 group"
            >
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div v-if="chain.logo_url" class="w-10 h-10 rounded-full overflow-hidden bg-dark-800">
                            <img :src="chain.logo_url" :alt="chain.name" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
                            <span class="text-white font-bold text-sm">{{ (chain.symbol || chain.name || '?').charAt(0) }}</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">{{ chain.name }}</h3>
                            <p class="text-xs text-dark-400">{{ chain.symbol }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge v-if="chain.is_testnet" status="testnet" />
                        <button @click="toggleActive(chain)">
                            <span v-if="chain.is_active" class="w-2.5 h-2.5 rounded-full bg-green-400 inline-block"></span>
                            <span v-else class="w-2.5 h-2.5 rounded-full bg-dark-600 inline-block"></span>
                        </button>
                    </div>
                </div>

                <div class="space-y-2 text-sm mb-4">
                    <div class="flex justify-between">
                        <span class="text-dark-400">Chain ID</span>
                        <span class="font-mono text-white">{{ chain.chain_id }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-dark-400">RPC URL</span>
                        <span class="text-dark-300 truncate ml-4 max-w-[180px]" :title="chain.rpc_url">{{ chain.rpc_url }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-3 border-t border-white/5">
                    <Link
                        :href="`/admin/tokens?chain_id=${chain.id}`"
                        class="flex-1 text-center px-3 py-2 rounded-lg text-xs font-medium text-primary-400 hover:bg-primary-500/10 transition-colors"
                    >
                        View Tokens
                    </Link>
                    <button
                        @click="openEditModal(chain)"
                        class="px-3 py-2 rounded-lg text-xs font-medium text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                    >
                        Edit
                    </button>
                    <button
                        @click="confirmDelete(chain)"
                        class="px-3 py-2 rounded-lg text-xs font-medium text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                    >
                        Delete
                    </button>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="chains.length === 0" class="col-span-full flex flex-col items-center justify-center py-16 text-center">
                <svg class="w-12 h-12 text-dark-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
                <p class="text-dark-400 text-sm">No chains configured yet</p>
                <button @click="openCreateModal" class="mt-3 text-sm text-primary-400 hover:text-primary-300">Add your first chain</button>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingChain ? 'Edit Chain' : 'Add Chain'" max-width="xl" @close="showModal = false">
            <form @submit.prevent="saveChain" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Chain Name</label>
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
                    <label class="block text-sm font-medium text-dark-300 mb-2">Chain ID</label>
                    <input v-model="form.chain_id" type="number" :class="inputClass" placeholder="1" />
                    <p v-if="form.errors.chain_id" class="mt-1 text-sm text-red-400">{{ form.errors.chain_id }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">RPC URL</label>
                    <input v-model="form.rpc_url" type="url" :class="inputClass" placeholder="https://mainnet.infura.io/v3/..." />
                    <p v-if="form.errors.rpc_url" class="mt-1 text-sm text-red-400">{{ form.errors.rpc_url }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Explorer URL</label>
                    <input v-model="form.explorer_url" type="url" :class="inputClass" placeholder="https://etherscan.io" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Logo</label>
                    <div class="flex items-center gap-4">
                        <div v-if="logoPreview" class="w-10 h-10 rounded-full overflow-hidden bg-dark-800">
                            <img :src="logoPreview" alt="Logo" class="w-full h-full object-cover" />
                        </div>
                        <label class="cursor-pointer px-4 py-2 rounded-xl bg-dark-800 border border-white/10 text-sm text-dark-300 hover:text-white hover:bg-dark-700 transition-colors">
                            Choose File
                            <input type="file" @change="handleLogoChange" accept="image/*" class="hidden" />
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Currency Name</label>
                        <input v-model="form.native_currency_name" type="text" :class="inputClass" placeholder="Ether" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Currency Symbol</label>
                        <input v-model="form.native_currency_symbol" type="text" :class="inputClass" placeholder="ETH" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Decimals</label>
                        <input v-model.number="form.native_currency_decimals" type="number" :class="inputClass" placeholder="18" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Block Confirmations</label>
                    <input v-model.number="form.block_confirmations" type="number" :class="inputClass" placeholder="12" />
                </div>

                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-dark-300">Active</label>
                        <button type="button" @click="form.is_active = !form.is_active" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_active ? 'bg-primary-500' : 'bg-dark-600'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-dark-300">Testnet</label>
                        <button type="button" @click="form.is_testnet = !form.is_testnet" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_testnet ? 'bg-purple-500' : 'bg-dark-600'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_testnet ? 'translate-x-6' : 'translate-x-1'"></span>
                        </button>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">Cancel</button>
                    <button @click="saveChain" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingChain ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Chain"
            :message="`Are you sure you want to delete '${deletingChain?.name}'? All associated tokens and pairs will be affected.`"
            confirm-text="Delete"
            :danger="true"
            @confirm="deleteChain"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
