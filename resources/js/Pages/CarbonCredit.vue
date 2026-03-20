<script setup>
/**
 * TPIX TRADE - Carbon Credit Page
 * หน้า Carbon Credit — ซื้อ/retire carbon credits ผ่าน blockchain
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch, inject } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useCarbonCreditStore } from '@/Stores/carbonCreditStore';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
const walletStore = useWalletStore();
const openWalletModal = inject('openWalletModal', () => {});
const carbonStore = useCarbonCreditStore();

const activeTab = ref('marketplace');
const showBuyModal = ref(false);
const showRetireModal = ref(false);
const selectedProject = ref(null);
const selectedCredit = ref(null);

// Buy form
const buyForm = ref({ amount: '', payment_currency: 'TPIX' });
const buyLoading = ref(false);

// Retire form
const retireForm = ref({ amount: '', beneficiary_name: '', retirement_reason: '' });
const retireLoading = ref(false);

const projectTypes = {
    reforestation: { label: 'Reforestation', icon: 'tree', color: 'text-green-400' },
    renewable_energy: { label: 'Renewable Energy', icon: 'bolt', color: 'text-yellow-400' },
    methane_capture: { label: 'Methane Capture', icon: 'flame', color: 'text-orange-400' },
    ocean_cleanup: { label: 'Ocean Cleanup', icon: 'wave', color: 'text-blue-400' },
    carbon_capture: { label: 'Carbon Capture', icon: 'filter', color: 'text-purple-400' },
    biodiversity: { label: 'Biodiversity', icon: 'leaf', color: 'text-emerald-400' },
    other: { label: 'Other', icon: 'globe', color: 'text-gray-400' },
};

const totalCostUsd = computed(() => {
    if (!selectedProject.value || !buyForm.value.amount) return 0;
    return (parseFloat(buyForm.value.amount) * parseFloat(selectedProject.value.price_per_credit_usd)).toFixed(2);
});

onMounted(async () => {
    await carbonStore.loadAll(walletStore.address);
});

watch(() => walletStore.address, async (addr) => {
    if (addr) {
        await carbonStore.fetchMyCredits(addr);
        await carbonStore.fetchMyRetirements(addr);
    }
});

function openBuyModal(project) {
    selectedProject.value = project;
    buyForm.value = { amount: '', payment_currency: 'TPIX' };
    showBuyModal.value = true;
}

function openRetireModal(credit) {
    selectedCredit.value = credit;
    retireForm.value = { amount: '', beneficiary_name: '', retirement_reason: '' };
    showRetireModal.value = true;
}

async function handleBuy() {
    if (!selectedProject.value || !buyForm.value.amount) return;
    buyLoading.value = true;
    try {
        await carbonStore.purchaseCredits({
            project_id: selectedProject.value.id,
            amount: parseFloat(buyForm.value.amount),
            wallet_address: walletStore.address,
            payment_currency: buyForm.value.payment_currency,
            payment_amount: totalCostUsd.value,
        });
        showBuyModal.value = false;
        await carbonStore.fetchProjects();
    } catch {
        // Error handled by store
    } finally {
        buyLoading.value = false;
    }
}

async function handleRetire() {
    if (!selectedCredit.value || !retireForm.value.amount) return;
    retireLoading.value = true;
    try {
        await carbonStore.retireCredits({
            credit_id: selectedCredit.value.id,
            amount: parseFloat(retireForm.value.amount),
            wallet_address: walletStore.address,
            beneficiary_name: retireForm.value.beneficiary_name,
            retirement_reason: retireForm.value.retirement_reason,
        });
        showRetireModal.value = false;
        await carbonStore.fetchMyCredits(walletStore.address);
    } catch {
        // Error handled by store
    } finally {
        retireLoading.value = false;
    }
}

function formatNumber(val) {
    return Number(val).toLocaleString(undefined, { maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Carbon Credits — Offset Your Carbon Footprint" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero -->
        <section class="relative py-12 sm:py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/4 left-1/4 w-96 h-96 rounded-full bg-green-500/8 blur-[120px]" />
                <div class="absolute bottom-1/4 right-1/4 w-80 h-80 rounded-full bg-emerald-500/8 blur-[120px]" />
            </div>

            <div class="relative max-w-6xl mx-auto px-4 sm:px-6 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-white mb-3">{{ t('carbonCredit.title') }}</h1>
                <p class="text-gray-400 max-w-2xl mx-auto">
                    {{ t('carbonCredit.subtitle') }}
                </p>
            </div>
        </section>

        <!-- Stats -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6 mb-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-green-400">{{ formatNumber(carbonStore.stats?.total_credits_retired || 0) }}</p>
                    <p class="text-xs text-gray-400">tCO2 Retired</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-white">{{ carbonStore.stats?.active_projects || 0 }}</p>
                    <p class="text-xs text-gray-400">Active Projects</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-accent-400">{{ formatNumber(carbonStore.stats?.total_credits_available || 0) }}</p>
                    <p class="text-xs text-gray-400">Credits Available</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-warm-400">{{ carbonStore.stats?.unique_buyers || 0 }}</p>
                    <p class="text-xs text-gray-400">Participants</p>
                </div>
            </div>
        </section>

        <!-- Tabs -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="flex gap-1 mb-8 bg-dark-900/50 rounded-xl p-1 max-w-lg">
                <button
                    v-for="tab in [
                        { id: 'marketplace', label: 'Marketplace' },
                        { id: 'my-credits', label: 'My Credits' },
                        { id: 'retirements', label: 'Retirements' },
                    ]"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="activeTab === tab.id ? 'bg-green-500/20 text-green-400' : 'text-gray-400 hover:text-white'"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Marketplace Tab -->
            <div v-if="activeTab === 'marketplace'" class="pb-16">
                <div v-if="carbonStore.projects.length === 0 && !carbonStore.isLoading" class="text-center py-16">
                    <p class="text-gray-400">No carbon credit projects available yet.</p>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div
                        v-for="project in carbonStore.projects"
                        :key="project.id"
                        class="glass-dark rounded-xl border border-white/10 overflow-hidden hover:border-green-500/30 transition-all group"
                    >
                        <!-- Image / Placeholder -->
                        <div class="h-40 bg-gradient-to-br from-green-900/30 to-emerald-900/20 flex items-center justify-center">
                            <svg class="w-16 h-16 text-green-500/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>

                        <div class="p-5">
                            <!-- Type Badge -->
                            <div class="flex items-center gap-2 mb-2">
                                <span :class="['text-xs font-medium', projectTypes[project.project_type]?.color || 'text-gray-400']">
                                    {{ projectTypes[project.project_type]?.label || project.project_type }}
                                </span>
                                <span v-if="project.is_featured" class="text-xs px-2 py-0.5 rounded-full bg-yellow-500/10 text-yellow-400 border border-yellow-500/20">Featured</span>
                            </div>

                            <h3 class="text-lg font-bold text-white mb-1 group-hover:text-green-400 transition-colors">{{ project.name }}</h3>
                            <p class="text-xs text-gray-400 mb-3 flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                {{ project.location }}
                            </p>

                            <p class="text-xs text-gray-400 line-clamp-2 mb-4">{{ project.description }}</p>

                            <!-- Progress -->
                            <div class="mb-4">
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="text-gray-400">Available</span>
                                    <span class="text-white">{{ formatNumber(project.available_credits) }} / {{ formatNumber(project.total_credits) }} tCO2</span>
                                </div>
                                <div class="w-full h-1.5 bg-dark-800 rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-gradient-to-r from-green-500 to-emerald-400 rounded-full transition-all"
                                        :style="{ width: `${Math.max(0, (1 - project.available_credits / project.total_credits) * 100)}%` }"
                                    ></div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-gray-400">Price per tCO2</p>
                                    <p class="text-lg font-bold text-green-400">${{ project.price_per_credit_usd }}</p>
                                </div>
                                <button
                                    @click="walletStore.isConnected ? openBuyModal(project) : openWalletModal()"
                                    :disabled="project.available_credits <= 0"
                                    class="btn-primary px-4 py-2 text-sm disabled:opacity-50"
                                >
                                    {{ project.available_credits <= 0 ? 'Sold Out' : walletStore.isConnected ? 'Buy Credits' : 'Connect Wallet' }}
                                </button>
                            </div>

                            <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
                                <span>{{ project.standard }}</span>
                                <span v-if="project.vintage_year">{{ project.vintage_year }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Credits Tab -->
            <div v-if="activeTab === 'my-credits'" class="pb-16">
                <div v-if="!walletStore.isConnected" class="text-center py-16">
                    <p class="text-gray-400 mb-4">Connect your wallet to view your carbon credits.</p>
                    <button @click="openWalletModal" class="btn-primary px-6 py-2.5">Connect Wallet</button>
                </div>

                <div v-else-if="carbonStore.myCredits.length === 0" class="text-center py-16">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-gray-400 mb-4">You don't own any carbon credits yet.</p>
                    <button @click="activeTab = 'marketplace'" class="btn-primary px-6">Browse Projects</button>
                </div>

                <div v-else class="space-y-4">
                    <div
                        v-for="credit in carbonStore.myCredits"
                        :key="credit.id"
                        class="glass-dark p-5 rounded-xl border border-white/10 flex flex-col sm:flex-row sm:items-center gap-4"
                    >
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="font-semibold text-white">{{ credit.project?.name }}</p>
                                <span :class="[
                                    'text-xs px-2 py-0.5 rounded-full border font-medium',
                                    credit.status === 'active' ? 'text-green-400 bg-green-500/10 border-green-500/20' :
                                    credit.status === 'retired' ? 'text-gray-400 bg-gray-500/10 border-gray-500/20' :
                                    'text-yellow-400 bg-yellow-500/10 border-yellow-500/20'
                                ]">{{ credit.status }}</span>
                            </div>
                            <p class="text-xs text-gray-400">Serial: {{ credit.serial_number }}</p>
                        </div>

                        <div class="text-right">
                            <p class="text-lg font-bold text-white">{{ formatNumber(credit.amount) }} <span class="text-xs text-gray-400">tCO2</span></p>
                            <p class="text-xs text-gray-400">Paid: ${{ formatNumber(credit.price_paid_usd) }}</p>
                        </div>

                        <button
                            v-if="credit.status === 'active'"
                            @click="openRetireModal(credit)"
                            class="px-4 py-2 rounded-lg bg-green-500/10 border border-green-500/20 text-green-400 text-sm font-medium hover:bg-green-500/20 transition-all"
                        >
                            Retire
                        </button>
                    </div>
                </div>
            </div>

            <!-- Retirements Tab -->
            <div v-if="activeTab === 'retirements'" class="pb-16">
                <div v-if="!walletStore.isConnected" class="text-center py-16">
                    <p class="text-gray-400 mb-4">Connect your wallet to view your retirements.</p>
                    <button @click="openWalletModal" class="btn-primary px-6 py-2.5">Connect Wallet</button>
                </div>

                <div v-else-if="carbonStore.myRetirements.length === 0" class="text-center py-16">
                    <p class="text-gray-400">You haven't retired any credits yet.</p>
                </div>

                <div v-else class="space-y-4">
                    <div
                        v-for="ret in carbonStore.myRetirements"
                        :key="ret.id"
                        class="glass-dark p-5 rounded-xl border border-green-500/20 bg-green-500/5"
                    >
                        <div class="flex items-center gap-2 mb-2">
                            <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="font-semibold text-white">{{ ret.amount }} tCO2 Retired</p>
                        </div>
                        <p class="text-sm text-gray-400">Project: {{ ret.credit?.project?.name }}</p>
                        <p v-if="ret.beneficiary_name" class="text-sm text-gray-400">Beneficiary: {{ ret.beneficiary_name }}</p>
                        <p v-if="ret.retirement_reason" class="text-sm text-gray-400">Reason: {{ ret.retirement_reason }}</p>
                        <p v-if="ret.certificate_hash" class="text-xs text-primary-400 mt-2 font-mono">
                            Certificate: {{ ret.certificate_hash?.slice(0, 12) }}...{{ ret.certificate_hash?.slice(-8) }}
                        </p>
                        <p class="text-xs text-gray-500 mt-1">{{ new Date(ret.created_at).toLocaleDateString() }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Buy Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showBuyModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showBuyModal = false"></div>
                    <div class="relative glass-dark p-6 rounded-2xl border border-white/10 max-w-md w-full">
                        <h3 class="text-xl font-bold text-white mb-4">Purchase Carbon Credits</h3>
                        <p class="text-sm text-gray-400 mb-6">{{ selectedProject?.name }}</p>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Amount (tCO2)</label>
                                <input
                                    v-model="buyForm.amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    :max="selectedProject?.available_credits"
                                    class="trading-input w-full"
                                    placeholder="e.g. 10"
                                />
                                <p class="text-xs text-gray-500 mt-1">Available: {{ formatNumber(selectedProject?.available_credits || 0) }} tCO2</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Payment Currency</label>
                                <select v-model="buyForm.payment_currency" class="trading-input w-full">
                                    <option value="TPIX">TPIX</option>
                                    <option value="BNB">BNB</option>
                                    <option value="USDT">USDT</option>
                                </select>
                            </div>

                            <div class="p-3 rounded-lg bg-white/5 border border-white/10">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-400">Total Cost</span>
                                    <span class="text-white font-bold">${{ totalCostUsd }}</span>
                                </div>
                            </div>

                            <div v-if="carbonStore.error" class="p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                                {{ carbonStore.error }}
                            </div>

                            <div class="flex gap-3">
                                <button @click="showBuyModal = false" class="flex-1 btn-secondary py-2.5">Cancel</button>
                                <button
                                    @click="handleBuy"
                                    :disabled="!buyForm.amount || buyLoading"
                                    class="flex-1 btn-primary py-2.5 disabled:opacity-50"
                                >
                                    {{ buyLoading ? 'Processing...' : 'Confirm Purchase' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Retire Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showRetireModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showRetireModal = false"></div>
                    <div class="relative glass-dark p-6 rounded-2xl border border-white/10 max-w-md w-full">
                        <h3 class="text-xl font-bold text-white mb-4">Retire Carbon Credits</h3>
                        <p class="text-sm text-gray-400 mb-6">Serial: {{ selectedCredit?.serial_number }}</p>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Amount (tCO2)</label>
                                <input
                                    v-model="retireForm.amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    :max="selectedCredit?.amount"
                                    class="trading-input w-full"
                                />
                                <p class="text-xs text-gray-500 mt-1">Available: {{ formatNumber(selectedCredit?.amount || 0) }} tCO2</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Beneficiary Name (optional)</label>
                                <input v-model="retireForm.beneficiary_name" type="text" class="trading-input w-full" placeholder="e.g. Company Name" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Reason (optional)</label>
                                <textarea v-model="retireForm.retirement_reason" rows="2" class="trading-input w-full resize-none" placeholder="e.g. Offsetting 2026 Q1 emissions"></textarea>
                            </div>

                            <div class="p-3 rounded-lg bg-green-500/5 border border-green-500/20 text-xs text-green-400">
                                Retiring credits permanently removes them from circulation, representing verified carbon offset.
                            </div>

                            <div class="flex gap-3">
                                <button @click="showRetireModal = false" class="flex-1 btn-secondary py-2.5">Cancel</button>
                                <button
                                    @click="handleRetire"
                                    :disabled="!retireForm.amount || retireLoading"
                                    class="flex-1 py-2.5 rounded-lg bg-green-500/20 border border-green-500/30 text-green-400 font-medium hover:bg-green-500/30 transition-all disabled:opacity-50"
                                >
                                    {{ retireLoading ? 'Processing...' : 'Retire Credits' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>
