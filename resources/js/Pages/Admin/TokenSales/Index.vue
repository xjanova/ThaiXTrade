<script setup>
/**
 * TPIX TRADE - Admin Token Sales Management
 * จัดการรอบขายเหรียญ TPIX — CRUD สำหรับ sales + phases
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    sales: { type: Array, default: () => [] },
});

// สถานะ modal
const showCreateModal = ref(false);
const showPhaseModal = ref(false);
const editingSale = ref(null);

// ฟอร์มสร้าง/แก้ไข sale
const saleForm = useForm({
    name: '',
    description: '',
    total_supply_for_sale: 700000000,
    accept_currencies: ['BNB', 'USDT'],
    accept_chain_id: 56,
    sale_wallet_address: '',
    starts_at: '',
    ends_at: '',
    status: 'draft',
});

// ฟอร์มสร้าง phase
const phaseForm = useForm({
    token_sale_id: null,
    name: '',
    price_usd: 0.05,
    allocation: 0,
    min_purchase: 100,
    max_purchase: 1000000,
    vesting_tge_percent: 10,
    vesting_cliff_days: 30,
    vesting_duration_days: 180,
    whitelist_only: false,
    status: 'upcoming',
    starts_at: '',
    ends_at: '',
});

function openCreateSale() {
    editingSale.value = null;
    saleForm.reset();
    showCreateModal.value = true;
}

function openEditSale(sale) {
    editingSale.value = sale;
    saleForm.name = sale.name;
    saleForm.description = sale.description || '';
    saleForm.total_supply_for_sale = sale.total_supply_for_sale;
    saleForm.accept_currencies = sale.accept_currencies || ['BNB', 'USDT'];
    saleForm.accept_chain_id = sale.accept_chain_id || 56;
    saleForm.sale_wallet_address = sale.sale_wallet_address || '';
    saleForm.starts_at = sale.starts_at || '';
    saleForm.ends_at = sale.ends_at || '';
    saleForm.status = sale.status;
    showCreateModal.value = true;
}

function openAddPhase(sale) {
    phaseForm.reset();
    phaseForm.token_sale_id = sale.id;
    showPhaseModal.value = true;
}

function submitSale() {
    if (editingSale.value) {
        saleForm.put(`/admin/token-sales/${editingSale.value.id}`, {
            onSuccess: () => { showCreateModal.value = false; },
        });
    } else {
        saleForm.post('/admin/token-sales', {
            onSuccess: () => { showCreateModal.value = false; },
        });
    }
}

function submitPhase() {
    phaseForm.post(`/admin/token-sales/${phaseForm.token_sale_id}/phases`, {
        onSuccess: () => { showPhaseModal.value = false; },
    });
}

function deleteSale(sale) {
    if (confirm(`Delete "${sale.name}"? This cannot be undone.`)) {
        router.delete(`/admin/token-sales/${sale.id}`);
    }
}

function formatNumber(n) {
    if (!n) return '0';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    return Number(n).toLocaleString();
}

function statusColor(status) {
    const colors = {
        draft: 'bg-gray-500/20 text-gray-400',
        active: 'bg-trading-green/20 text-trading-green',
        upcoming: 'bg-primary-500/20 text-primary-400',
        completed: 'bg-blue-500/20 text-blue-400',
        paused: 'bg-yellow-500/20 text-yellow-400',
    };
    return colors[status] || 'bg-gray-500/20 text-gray-400';
}
</script>

<template>
    <Head title="Token Sales Management" />

    <AdminLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-white">Token Sales</h1>
                    <p class="text-gray-400 text-sm">Manage TPIX token sale rounds and phases</p>
                </div>
                <button class="btn-primary px-4 py-2 text-sm font-semibold" @click="openCreateSale">
                    + New Sale
                </button>
            </div>

            <!-- Sales List -->
            <div class="space-y-4">
                <div
                    v-for="sale in sales"
                    :key="sale.id"
                    class="glass-dark rounded-xl border border-white/10 p-5"
                >
                    <!-- Sale Header -->
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-white">{{ sale.name }}</h3>
                            <span class="px-2 py-0.5 text-xs rounded-full" :class="statusColor(sale.status)">
                                {{ sale.status }}
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <button class="btn-secondary px-3 py-1 text-xs" @click="openAddPhase(sale)">
                                + Phase
                            </button>
                            <button class="btn-secondary px-3 py-1 text-xs" @click="openEditSale(sale)">
                                Edit
                            </button>
                            <button class="btn-danger px-3 py-1 text-xs" @click="deleteSale(sale)">
                                Delete
                            </button>
                        </div>
                    </div>

                    <!-- Sale Stats -->
                    <div class="grid grid-cols-4 gap-4 mb-4">
                        <div>
                            <span class="text-xs text-gray-400">Total Supply</span>
                            <p class="text-sm font-semibold text-white">{{ formatNumber(sale.total_supply_for_sale) }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-400">Total Sold</span>
                            <p class="text-sm font-semibold text-trading-green">{{ formatNumber(sale.total_sold) }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-400">Raised (USD)</span>
                            <p class="text-sm font-semibold text-primary-400">${{ formatNumber(sale.total_raised_usd) }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-400">Currencies</span>
                            <p class="text-sm text-white">{{ (sale.accept_currencies || []).join(', ') }}</p>
                        </div>
                    </div>

                    <!-- Phases -->
                    <div v-if="sale.phases?.length" class="border-t border-white/5 pt-4">
                        <h4 class="text-sm font-semibold text-gray-400 mb-2">Phases</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-gray-500 text-xs">
                                        <th class="text-left py-1 px-2">Name</th>
                                        <th class="text-right py-1 px-2">Price</th>
                                        <th class="text-right py-1 px-2">Allocation</th>
                                        <th class="text-right py-1 px-2">Sold</th>
                                        <th class="text-center py-1 px-2">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="phase in sale.phases"
                                        :key="phase.id"
                                        class="border-t border-white/5"
                                    >
                                        <td class="py-1.5 px-2 text-white">{{ phase.name }}</td>
                                        <td class="py-1.5 px-2 text-right text-primary-400">${{ phase.price_usd }}</td>
                                        <td class="py-1.5 px-2 text-right text-gray-300">{{ formatNumber(phase.allocation) }}</td>
                                        <td class="py-1.5 px-2 text-right text-trading-green">{{ formatNumber(phase.sold) }}</td>
                                        <td class="py-1.5 px-2 text-center">
                                            <span class="px-2 py-0.5 text-xs rounded-full" :class="statusColor(phase.status)">
                                                {{ phase.status }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="sales.length === 0" class="text-center py-16 text-gray-400">
                    <p class="text-lg mb-2">No token sales yet</p>
                    <p class="text-sm">Create your first token sale to get started.</p>
                </div>
            </div>

            <!-- ===== Create/Edit Sale Modal ===== -->
            <Teleport to="body">
                <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showCreateModal = false">
                    <div class="glass-dark rounded-xl border border-white/10 p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 class="text-xl font-bold text-white mb-4">
                            {{ editingSale ? 'Edit Sale' : 'Create Token Sale' }}
                        </h3>

                        <form @submit.prevent="submitSale" class="space-y-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Name</label>
                                <input v-model="saleForm.name" class="trading-input w-full" placeholder="TPIX Token Sale Round 1" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Description</label>
                                <textarea v-model="saleForm.description" class="trading-input w-full" rows="3" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Total Supply for Sale</label>
                                    <input v-model.number="saleForm.total_supply_for_sale" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Status</label>
                                    <select v-model="saleForm.status" class="trading-input w-full">
                                        <option value="draft">Draft</option>
                                        <option value="upcoming">Upcoming</option>
                                        <option value="active">Active</option>
                                        <option value="paused">Paused</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Sale Wallet Address (BSC)</label>
                                <input v-model="saleForm.sale_wallet_address" class="trading-input w-full font-mono text-sm" placeholder="0x..." />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Starts At</label>
                                    <input v-model="saleForm.starts_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Ends At</label>
                                    <input v-model="saleForm.ends_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary px-6 py-2 font-semibold" :disabled="saleForm.processing">
                                    {{ editingSale ? 'Update' : 'Create' }}
                                </button>
                                <button type="button" class="btn-secondary px-6 py-2" @click="showCreateModal = false">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- ===== Add Phase Modal ===== -->
            <Teleport to="body">
                <div v-if="showPhaseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showPhaseModal = false">
                    <div class="glass-dark rounded-xl border border-white/10 p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 class="text-xl font-bold text-white mb-4">Add Sale Phase</h3>

                        <form @submit.prevent="submitPhase" class="space-y-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Phase Name</label>
                                <input v-model="phaseForm.name" class="trading-input w-full" placeholder="Private Sale" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Price (USD)</label>
                                    <input v-model.number="phaseForm.price_usd" type="number" step="0.001" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Allocation (TPIX)</label>
                                    <input v-model.number="phaseForm.allocation" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Min Purchase</label>
                                    <input v-model.number="phaseForm.min_purchase" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Max Purchase</label>
                                    <input v-model.number="phaseForm.max_purchase" type="number" class="trading-input w-full" />
                                </div>
                            </div>

                            <h4 class="text-sm font-semibold text-white pt-2">Vesting</h4>
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">TGE %</label>
                                    <input v-model.number="phaseForm.vesting_tge_percent" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Cliff (days)</label>
                                    <input v-model.number="phaseForm.vesting_cliff_days" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Duration (days)</label>
                                    <input v-model.number="phaseForm.vesting_duration_days" type="number" class="trading-input w-full" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Status</label>
                                    <select v-model="phaseForm.status" class="trading-input w-full">
                                        <option value="upcoming">Upcoming</option>
                                        <option value="active">Active</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                                <div class="flex items-end">
                                    <label class="flex items-center gap-2 text-sm text-gray-300">
                                        <input v-model="phaseForm.whitelist_only" type="checkbox" class="rounded" />
                                        Whitelist Only
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Starts At</label>
                                    <input v-model="phaseForm.starts_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Ends At</label>
                                    <input v-model="phaseForm.ends_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary px-6 py-2 font-semibold" :disabled="phaseForm.processing">
                                    Add Phase
                                </button>
                                <button type="button" class="btn-secondary px-6 py-2" @click="showPhaseModal = false">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>
        </div>
    </AdminLayout>
</template>
