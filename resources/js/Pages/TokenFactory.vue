<script setup>
/**
 * TPIX TRADE - Token Factory Page
 * หน้าสร้างเหรียญบน TPIX Chain — สร้าง Token, ดู Token ที่สร้าง, ดู Token ที่ Deploy แล้ว
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenFactoryStore } from '@/Stores/tokenFactoryStore';

const walletStore = useWalletStore();
const factoryStore = useTokenFactoryStore();

const activeTab = ref('create');
const showSuccess = ref(false);
const successMessage = ref('');

// Form data
const form = ref({
    name: '',
    symbol: '',
    decimals: 18,
    total_supply: '',
    description: '',
    website: '',
    token_type: 'standard',
});

const formErrors = ref({});
const isSubmitting = ref(false);

const tokenTypes = [
    { value: 'standard', label: 'Standard', desc: 'Fixed supply, no minting or burning' },
    { value: 'mintable', label: 'Mintable', desc: 'Owner can mint new tokens' },
    { value: 'burnable', label: 'Burnable', desc: 'Holders can burn their tokens' },
    { value: 'mintable_burnable', label: 'Mintable + Burnable', desc: 'Full functionality' },
];

const canCreate = computed(() => {
    return walletStore.isConnected && form.value.name && form.value.symbol && form.value.total_supply;
});

onMounted(async () => {
    await factoryStore.fetchTokens();
    if (walletStore.address) {
        await factoryStore.fetchMyTokens(walletStore.address);
    }
});

watch(() => walletStore.address, async (addr) => {
    if (addr) {
        await factoryStore.fetchMyTokens(addr);
    }
});

async function handleCreate() {
    if (!canCreate.value) return;
    formErrors.value = {};
    isSubmitting.value = true;

    try {
        await factoryStore.createToken({
            ...form.value,
            creator_address: walletStore.address,
            chain_id: 4289,
        });

        successMessage.value = `Token ${form.value.symbol} submitted for review!`;
        showSuccess.value = true;

        // Reset form
        form.value = { name: '', symbol: '', decimals: 18, total_supply: '', description: '', website: '', token_type: 'standard' };
        activeTab.value = 'my-tokens';

        setTimeout(() => { showSuccess.value = false; }, 5000);
    } catch (e) {
        formErrors.value.general = e.message;
    } finally {
        isSubmitting.value = false;
    }
}

function getStatusColor(status) {
    const colors = {
        pending: 'text-yellow-400 bg-yellow-500/10 border-yellow-500/20',
        deploying: 'text-blue-400 bg-blue-500/10 border-blue-500/20',
        deployed: 'text-green-400 bg-green-500/10 border-green-500/20',
        failed: 'text-red-400 bg-red-500/10 border-red-500/20',
        rejected: 'text-red-400 bg-red-500/10 border-red-500/20',
    };
    return colors[status] || 'text-gray-400 bg-gray-500/10 border-gray-500/20';
}

function formatSupply(val) {
    return Number(val).toLocaleString();
}
</script>

<template>
    <Head title="Token Factory — Create Your Token" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero -->
        <section class="relative py-12 sm:py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/4 left-1/3 w-80 h-80 rounded-full bg-accent-500/10 blur-[100px]" />
                <div class="absolute bottom-1/4 right-1/3 w-80 h-80 rounded-full bg-warm-500/8 blur-[100px]" />
            </div>

            <div class="relative max-w-6xl mx-auto px-4 sm:px-6 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-white mb-3">Token Factory</h1>
                <p class="text-gray-400 max-w-2xl mx-auto">
                    Create your own ERC-20 token on TPIX Chain with zero gas fees.
                    Deploy in minutes — no coding required.
                </p>
            </div>
        </section>

        <!-- Stats Bar -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6 mb-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-white">{{ factoryStore.tokens.length }}</p>
                    <p class="text-xs text-gray-400">Tokens Created</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-accent-400">TPIX</p>
                    <p class="text-xs text-gray-400">Chain</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-green-400">0</p>
                    <p class="text-xs text-gray-400">Gas Fee</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-2xl font-bold text-warm-400">2s</p>
                    <p class="text-xs text-gray-400">Block Time</p>
                </div>
            </div>
        </section>

        <!-- Success Alert -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showSuccess" class="max-w-6xl mx-auto px-4 sm:px-6 mb-6">
                <div class="p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ successMessage }}
                </div>
            </div>
        </Transition>

        <!-- Tabs -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="flex gap-1 mb-8 bg-dark-900/50 rounded-xl p-1 max-w-md">
                <button
                    v-for="tab in [
                        { id: 'create', label: 'Create Token' },
                        { id: 'my-tokens', label: 'My Tokens' },
                        { id: 'explore', label: 'Explore' },
                    ]"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="activeTab === tab.id ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white'"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Create Token Tab -->
            <div v-if="activeTab === 'create'" class="pb-16">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Form -->
                    <div class="glass-dark p-6 rounded-xl border border-white/10">
                        <h3 class="text-xl font-bold text-white mb-6">Create New Token</h3>

                        <div v-if="!walletStore.isConnected" class="text-center py-8">
                            <p class="text-gray-400 mb-4">Connect your wallet to create a token.</p>
                        </div>

                        <form v-else @submit.prevent="handleCreate" class="space-y-5">
                            <div v-if="formErrors.general" class="p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                                {{ formErrors.general }}
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Token Name *</label>
                                <input v-model="form.name" type="text" placeholder="e.g. My Token" class="trading-input w-full" maxlength="100" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Symbol *</label>
                                    <input v-model="form.symbol" type="text" placeholder="e.g. MTK" class="trading-input w-full uppercase" maxlength="20" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Decimals</label>
                                    <input v-model.number="form.decimals" type="number" min="0" max="18" class="trading-input w-full" />
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Total Supply *</label>
                                <input v-model="form.total_supply" type="number" placeholder="e.g. 1000000" min="1" class="trading-input w-full" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Token Type</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        v-for="tt in tokenTypes"
                                        :key="tt.value"
                                        type="button"
                                        @click="form.token_type = tt.value"
                                        class="p-3 rounded-lg border text-left transition-all"
                                        :class="form.token_type === tt.value
                                            ? 'border-primary-500/50 bg-primary-500/10'
                                            : 'border-white/10 hover:border-white/20'"
                                    >
                                        <p class="text-sm font-medium" :class="form.token_type === tt.value ? 'text-primary-400' : 'text-white'">{{ tt.label }}</p>
                                        <p class="text-xs text-gray-500 mt-0.5">{{ tt.desc }}</p>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Description</label>
                                <textarea v-model="form.description" rows="3" placeholder="Describe your token..." class="trading-input w-full resize-none" maxlength="1000"></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Website</label>
                                <input v-model="form.website" type="url" placeholder="https://..." class="trading-input w-full" />
                            </div>

                            <button
                                type="submit"
                                :disabled="!canCreate || isSubmitting"
                                class="w-full btn-primary py-3 font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="isSubmitting" class="flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    Creating...
                                </span>
                                <span v-else>Create Token</span>
                            </button>
                        </form>
                    </div>

                    <!-- Info Panel -->
                    <div class="space-y-6">
                        <div class="glass-dark p-6 rounded-xl border border-white/10">
                            <h3 class="text-lg font-bold text-white mb-4">How It Works</h3>
                            <div class="space-y-4">
                                <div v-for="(step, i) in [
                                    { title: 'Fill Token Details', desc: 'Enter name, symbol, supply, and type' },
                                    { title: 'Submit for Review', desc: 'Our team reviews your token request' },
                                    { title: 'Token Deployed', desc: 'Your token is deployed on TPIX Chain' },
                                    { title: 'Start Trading', desc: 'List on TPIX TRADE and start trading' },
                                ]" :key="i" class="flex gap-3">
                                    <div class="w-8 h-8 rounded-full bg-primary-500/20 border border-primary-500/30 flex items-center justify-center flex-shrink-0">
                                        <span class="text-xs font-bold text-primary-400">{{ i + 1 }}</span>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-white">{{ step.title }}</p>
                                        <p class="text-xs text-gray-400">{{ step.desc }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="glass-dark p-6 rounded-xl border border-white/10">
                            <h3 class="text-lg font-bold text-white mb-3">TPIX Chain Benefits</h3>
                            <ul class="space-y-2">
                                <li v-for="b in ['Zero gas fees for token transfers', '2-second block confirmations', 'ERC-20 compatible', 'Full DEX listing support', 'Cross-chain bridge ready']" :key="b" class="flex items-center gap-2 text-sm text-gray-300">
                                    <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    {{ b }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Tokens Tab -->
            <div v-if="activeTab === 'my-tokens'" class="pb-16">
                <div v-if="!walletStore.isConnected" class="text-center py-16">
                    <p class="text-gray-400">Connect your wallet to view your tokens.</p>
                </div>

                <div v-else-if="factoryStore.myTokens.length === 0" class="text-center py-16">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <p class="text-gray-400 mb-4">You haven't created any tokens yet.</p>
                    <button @click="activeTab = 'create'" class="btn-primary px-6">Create Your First Token</button>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="token in factoryStore.myTokens"
                        :key="token.id"
                        class="glass-dark p-5 rounded-xl border border-white/10 hover:border-white/20 transition-all"
                    >
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                    <span class="text-sm font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                                </div>
                                <div>
                                    <p class="font-semibold text-white">{{ token.name }}</p>
                                    <p class="text-xs text-gray-400">{{ token.symbol }}</p>
                                </div>
                            </div>
                            <span :class="['text-xs px-2 py-1 rounded-full border font-medium', getStatusColor(token.status)]">
                                {{ token.status }}
                            </span>
                        </div>

                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Supply</span>
                                <span class="text-white font-mono">{{ formatSupply(token.total_supply) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Type</span>
                                <span class="text-white">{{ token.token_type }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Decimals</span>
                                <span class="text-white">{{ token.decimals }}</span>
                            </div>
                            <div v-if="token.contract_address" class="flex justify-between">
                                <span class="text-gray-400">Contract</span>
                                <span class="text-primary-400 font-mono text-xs">{{ token.contract_address?.slice(0, 8) }}...{{ token.contract_address?.slice(-6) }}</span>
                            </div>
                        </div>

                        <div v-if="token.reject_reason" class="mt-3 p-2 rounded-lg bg-red-500/10 text-xs text-red-400">
                            Reason: {{ token.reject_reason }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Explore Tab -->
            <div v-if="activeTab === 'explore'" class="pb-16">
                <div v-if="factoryStore.tokens.length === 0" class="text-center py-16">
                    <p class="text-gray-400">No deployed tokens yet. Be the first to create one!</p>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="token in factoryStore.tokens"
                        :key="token.id"
                        class="glass-dark p-5 rounded-xl border border-white/10 hover:border-primary-500/30 transition-all"
                    >
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                <span class="text-lg font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-white">{{ token.name }}</p>
                                <p class="text-sm text-gray-400">{{ token.symbol }}</p>
                            </div>
                            <div v-if="token.is_verified" class="ml-auto" title="Verified">
                                <svg class="w-5 h-5 text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                        </div>

                        <p v-if="token.description" class="text-xs text-gray-400 mb-3 line-clamp-2">{{ token.description }}</p>

                        <div class="space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Supply</span>
                                <span class="text-white font-mono">{{ formatSupply(token.total_supply) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Contract</span>
                                <a
                                    :href="`https://explorer.tpix.online/address/${token.contract_address}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary-400 hover:text-primary-300 font-mono text-xs"
                                >
                                    {{ token.contract_address?.slice(0, 8) }}...{{ token.contract_address?.slice(-6) }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
