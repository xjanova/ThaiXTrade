<script setup>
/**
 * TPIX TRADE — Staking Page
 * Stake TPIX บน TPIX Chain — ได้ APY 5%-200%
 * Rewards pool: 1.4B TPIX over 3 years (ending 2028)
 * Developed by Xman Studio
 */
import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const walletStore = useWalletStore();
const pools = ref([]);
const positions = ref([]);
const stats = ref(null);
const isLoading = ref(true);
const message = ref(null);

// Stake form
const selectedPool = ref(null);
const stakeAmount = ref('');
const stakeTxHash = ref('');
const isStaking = ref(false);

// คำนวณ estimated rewards
const estimatedDaily = computed(() => {
    if (!selectedPool.value || !stakeAmount.value) return 0;
    return (parseFloat(stakeAmount.value) * selectedPool.value.apy_percent / 100 / 365).toFixed(4);
});
const estimatedMonthly = computed(() => (parseFloat(estimatedDaily.value) * 30).toFixed(2));
const estimatedYearly = computed(() => {
    if (!selectedPool.value || !stakeAmount.value) return 0;
    return (parseFloat(stakeAmount.value) * selectedPool.value.apy_percent / 100).toFixed(2);
});

async function fetchPools() {
    try { const { data } = await axios.get('/api/v1/staking/pools'); pools.value = data.data || []; } catch {}
}
async function fetchPositions() {
    if (!walletStore.address) return;
    try { const { data } = await axios.get(`/api/v1/staking/positions/${walletStore.address}`); positions.value = data.data || []; } catch { positions.value = []; }
}
async function fetchStats() {
    try { const { data } = await axios.get('/api/v1/staking/stats'); stats.value = data.data; } catch {}
}

function selectPool(pool) { selectedPool.value = pool; }

async function doStake() {
    if (!walletStore.isConnected) { message.value = { type: 'error', text: t('staking.errors.connectWallet') }; return; }
    if (!selectedPool.value) { message.value = { type: 'error', text: t('staking.errors.selectPool') }; return; }
    if (!stakeAmount.value || parseFloat(stakeAmount.value) < selectedPool.value.min_stake) { message.value = { type: 'error', text: t('staking.errors.minStake', { min: selectedPool.value.min_stake }) }; return; }
    isStaking.value = true; message.value = null;
    try {
        const { data } = await axios.post('/api/v1/staking/stake', { wallet_address: walletStore.address, pool_id: selectedPool.value.id, amount: stakeAmount.value, tx_hash: stakeTxHash.value || null });
        if (data.success) { message.value = { type: 'success', text: t('staking.success.staked', { amount: stakeAmount.value }) }; stakeAmount.value = ''; stakeTxHash.value = ''; fetchPositions(); fetchPools(); fetchStats(); }
    } catch (err) { message.value = { type: 'error', text: err.response?.data?.error?.message || t('staking.errors.stakeFailed') }; }
    isStaking.value = false;
}

async function claimRewards(posId) {
    try {
        await axios.post(`/api/v1/staking/claim/${posId}`, { wallet_address: walletStore.address });
        message.value = { type: 'success', text: t('staking.success.claimed') }; fetchPositions(); fetchStats();
    } catch (err) { message.value = { type: 'error', text: err.response?.data?.error?.message || t('staking.errors.claimFailed') }; }
}

async function unstake(posId) {
    try {
        await axios.post(`/api/v1/staking/unstake/${posId}`, { wallet_address: walletStore.address });
        message.value = { type: 'success', text: t('staking.success.unstaked') }; fetchPositions(); fetchPools(); fetchStats();
    } catch (err) { message.value = { type: 'error', text: err.response?.data?.error?.message || t('staking.errors.unstakeFailed') }; }
}

function formatNum(n) { return parseFloat(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }); }
function formatDate(d) { return d ? new Date(d).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US', { day: 'numeric', month: 'short', year: '2-digit' }) : '-'; }

const activePositions = computed(() => positions.value.filter(p => p.status === 'active'));
const myTotalStaked = computed(() => activePositions.value.reduce((s, p) => s + p.amount, 0));
const myTotalRewards = computed(() => activePositions.value.reduce((s, p) => s + p.pending_reward + p.reward_earned, 0));

onMounted(async () => {
    await Promise.all([fetchPools(), fetchPositions(), fetchStats()]);
    isLoading.value = false;
});
</script>

<template>
    <Head :title="t('staking.pageTitle')" />
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-6">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">🏦 {{ t('staking.title') }}</h1>
                <p class="text-dark-400">{{ t('staking.subtitle') }}</p>
            </div>

            <div v-if="message" :class="['px-4 py-3 rounded-xl text-sm', message.type === 'success' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red']">{{ message.text }}</div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="glass-card text-center py-4">
                    <p class="text-2xl font-bold text-primary-400">{{ formatNum(stats?.tvl) }}</p>
                    <p class="text-dark-400 text-xs">{{ t('staking.tvl') }}</p>
                </div>
                <div class="glass-card text-center py-4">
                    <p class="text-2xl font-bold text-white">{{ stats?.total_stakers || 0 }}</p>
                    <p class="text-dark-400 text-xs">{{ t('staking.stakers') }}</p>
                </div>
                <div class="glass-card text-center py-4">
                    <p class="text-2xl font-bold text-trading-green">{{ formatNum(myTotalStaked) }}</p>
                    <p class="text-dark-400 text-xs">{{ t('staking.myStaked') }}</p>
                </div>
                <div class="glass-card text-center py-4">
                    <p class="text-2xl font-bold text-yellow-400">{{ formatNum(myTotalRewards) }}</p>
                    <p class="text-dark-400 text-xs">{{ t('staking.myRewards') }}</p>
                </div>
            </div>

            <!-- Connect Wallet Banner -->
            <div v-if="!walletStore.isConnected" class="glass-card border border-primary-500/20 text-center py-8">
                <div class="w-14 h-14 rounded-2xl bg-primary-500/20 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-white mb-2">{{ t('staking.connect.title') }}</h3>
                <p class="text-dark-400 text-sm mb-4">{{ t('staking.connect.desc') }}</p>
                <button @click="walletStore.openConnectModal()" class="px-6 py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-semibold hover:shadow-lg hover:shadow-primary-500/20 transition-all">
                    {{ t('staking.connect.button') }}
                </button>
            </div>

            <!-- Pool Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <button v-for="pool in pools" :key="pool.id" @click="selectPool(pool)"
                    :class="['glass-card text-center transition-all cursor-pointer hover:border-primary-500/50',
                        selectedPool?.id === pool.id ? 'border-primary-500 ring-1 ring-primary-500/30' : '']">
                    <div class="mb-3">
                        <span :class="['text-3xl font-bold', pool.lock_days === 0 ? 'text-primary-400' : pool.apy_percent >= 100 ? 'text-trading-green' : 'text-yellow-400']">
                            {{ pool.apy_percent }}%
                        </span>
                        <p class="text-dark-400 text-xs">{{ t('staking.apy') }}</p>
                    </div>
                    <p class="text-white font-semibold text-sm">{{ pool.name }}</p>
                    <p class="text-dark-500 text-xs mt-1">{{ pool.lock_days === 0 ? t('staking.pool.flexible') : t('staking.pool.locked', { days: pool.lock_days }) }}</p>
                    <div class="mt-3">
                        <div class="h-1.5 bg-dark-600 rounded-full overflow-hidden">
                            <div class="h-full bg-primary-500 rounded-full" :style="{ width: Math.min(100, (pool.total_staked / pool.max_pool_size) * 100) + '%' }"></div>
                        </div>
                        <p class="text-dark-500 text-[10px] mt-1">{{ formatNum(pool.total_staked) }} / {{ formatNum(pool.max_pool_size) }}</p>
                    </div>
                </button>
            </div>

            <!-- Stake Form -->
            <div v-if="selectedPool" class="glass-card border border-primary-500/20">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('staking.form.title', { name: selectedPool.name, apy: selectedPool.apy_percent }) }}</h3>
                <div class="grid md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm text-dark-400 mb-1 block">{{ t('staking.form.amount') }}</label>
                            <input v-model="stakeAmount" type="number" :min="selectedPool.min_stake" :placeholder="t('staking.form.minPlaceholder', { min: selectedPool.min_stake })"
                                class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-3 text-white text-lg font-mono placeholder-dark-500 focus:border-primary-500 outline-none" />
                        </div>
                        <div>
                            <label class="text-sm text-dark-400 mb-1 block">{{ t('staking.form.txHash') }}</label>
                            <input v-model="stakeTxHash" type="text" placeholder="0x..."
                                class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm font-mono placeholder-dark-500 focus:border-primary-500 outline-none" />
                        </div>
                        <button @click="doStake" :disabled="isStaking || !stakeAmount"
                            class="w-full py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-bold hover:shadow-lg disabled:opacity-50 transition-all">
                            {{ isStaking ? '🔄 ' + t('staking.form.staking') : '🏦 ' + t('staking.stakeTpix') }}
                        </button>
                    </div>
                    <div class="bg-dark-700/50 rounded-xl p-4 space-y-3">
                        <h4 class="text-sm text-dark-400 font-semibold">{{ t('staking.estimatedRewards') }}</h4>
                        <div class="flex justify-between"><span class="text-dark-300 text-sm">{{ t('staking.estimate.daily') }}</span><span class="text-trading-green font-mono">{{ estimatedDaily }} TPIX</span></div>
                        <div class="flex justify-between"><span class="text-dark-300 text-sm">{{ t('staking.estimate.monthly') }}</span><span class="text-trading-green font-mono">{{ estimatedMonthly }} TPIX</span></div>
                        <div class="flex justify-between"><span class="text-dark-300 text-sm">{{ t('staking.estimate.yearly') }}</span><span class="text-trading-green font-mono text-lg font-bold">{{ estimatedYearly }} TPIX</span></div>
                        <div class="border-t border-white/5 pt-3 space-y-1 text-xs text-dark-500">
                            <p>{{ t('staking.details.lock', { value: selectedPool.lock_days === 0 ? t('staking.details.lockFlexible') : t('staking.details.lockDays', { days: selectedPool.lock_days }) }) }}</p>
                            <p>{{ t('staking.details.min', { amount: formatNum(selectedPool.min_stake) }) }}</p>
                            <p>{{ t('staking.details.max', { amount: formatNum(selectedPool.max_stake) }) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Positions -->
            <div v-if="activePositions.length" class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('staking.myPositions') }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-dark-400 text-xs uppercase border-b border-white/10">
                                <th class="text-left p-3">{{ t('staking.table.pool') }}</th>
                                <th class="text-right p-3">{{ t('staking.table.amount') }}</th>
                                <th class="text-right p-3 hidden sm:table-cell">{{ t('staking.table.apy') }}</th>
                                <th class="text-right p-3 hidden md:table-cell">{{ t('staking.table.pendingReward') }}</th>
                                <th class="text-center p-3 hidden sm:table-cell">{{ t('staking.table.unlock') }}</th>
                                <th class="text-right p-3">{{ t('staking.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="pos in activePositions" :key="pos.id" class="border-b border-white/5 hover:bg-white/5">
                                <td class="p-3 text-white font-medium">{{ pos.pool }}</td>
                                <td class="p-3 text-right text-white font-mono">{{ formatNum(pos.amount) }}</td>
                                <td class="p-3 text-right text-trading-green hidden sm:table-cell">{{ pos.apy_percent }}%</td>
                                <td class="p-3 text-right text-yellow-400 font-mono hidden md:table-cell">{{ parseFloat(pos.pending_reward).toFixed(4) }}</td>
                                <td class="p-3 text-center hidden sm:table-cell">
                                    <span v-if="pos.is_unlocked" class="text-trading-green text-xs">{{ t('staking.table.unlocked') }}</span>
                                    <span v-else class="text-dark-400 text-xs">{{ t('staking.table.daysLeft', { days: pos.days_remaining }) }}</span>
                                </td>
                                <td class="p-3 text-right space-x-2">
                                    <button @click="claimRewards(pos.id)" class="px-3 py-1 bg-yellow-500/20 text-yellow-400 rounded-lg text-xs hover:bg-yellow-500/30">{{ t('staking.claim') }}</button>
                                    <button v-if="pos.is_unlocked" @click="unstake(pos.id)" class="px-3 py-1 bg-trading-red/20 text-trading-red rounded-lg text-xs hover:bg-trading-red/30">{{ t('staking.unstake') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Info -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-3">{{ t('staking.howToStake') }}</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                    <div v-for="(s, i) in ['s1', 's2', 's3', 's4']" :key="i">
                        <div class="w-10 h-10 rounded-full bg-primary-500/20 flex items-center justify-center mx-auto mb-2">
                            <span class="text-primary-400 font-bold">{{ i + 1 }}</span>
                        </div>
                        <p class="text-dark-300 text-xs">{{ t('staking.steps.' + s) }}</p>
                    </div>
                </div>
                <p class="text-dark-500 text-xs mt-4 text-center">⚠️ {{ t('staking.disclaimer') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
