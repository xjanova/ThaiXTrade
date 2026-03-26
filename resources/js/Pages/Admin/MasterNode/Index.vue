<script setup>
/**
 * TPIX TRADE - Admin MasterNode Dashboard
 * Monitor node network, manage registry, on-chain stats
 * Developed by Xman Studio
 */
import { ref, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    registryAddress: { type: String, default: '' },
    rpcUrl: { type: String, default: '' },
    chainId: { type: Number, default: 4289 },
    settings: { type: Object, default: () => ({}) },
});

const stats = ref(props.stats);
const enabled = ref(props.settings.masternode_enabled ?? true);
const isRefreshing = ref(false);
const isToggling = ref(false);
const configNote = ref(props.registryAddress);
const configSaved = ref(false);

const fmt = (n) => Number(n || 0).toLocaleString();

async function refreshStats() {
    isRefreshing.value = true;
    try {
        const { data } = await axios.get('/admin/masternode/stats');
        if (data.success) stats.value = data.data;
    } catch {} finally { isRefreshing.value = false; }
}

async function toggleEnabled() {
    isToggling.value = true;
    try {
        const newValue = !enabled.value;
        await axios.post('/admin/masternode/toggle', { enabled: newValue });
        enabled.value = newValue;
    } catch {} finally { isToggling.value = false; }
}

async function saveConfig() {
    try {
        const { data } = await axios.put('/admin/masternode/config', {
            registry_address: configNote.value,
        });
        configSaved.value = true;
        setTimeout(() => configSaved.value = false, 3000);
    } catch {}
}

let pollInterval;
onMounted(() => {
    pollInterval = setInterval(refreshStats, 30000);
});
onUnmounted(() => clearInterval(pollInterval));

const tiers = [
    { name: 'Validator', stake: '10,000,000 TPIX', reward: '20%', lock: '180 days', color: 'text-red-400', bg: 'bg-red-500/15 border-red-500/30' },
    { name: 'Guardian', stake: '1,000,000 TPIX', reward: '35%', lock: '90 days', color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    { name: 'Sentinel', stake: '100,000 TPIX', reward: '30%', lock: '30 days', color: 'text-purple-400', bg: 'bg-purple-500/15 border-purple-500/30' },
    { name: 'Light', stake: '10,000 TPIX', reward: '15%', lock: '7 days', color: 'text-cyan-400', bg: 'bg-cyan-500/15 border-cyan-500/30' },
];
</script>

<template>
    <Head title="MasterNode Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">MasterNode Management</h1>
                    <p class="text-sm text-gray-400 mt-1">TPIX Chain node network monitoring and configuration</p>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="toggleEnabled" :disabled="isToggling"
                        :class="['px-4 py-2 text-sm font-medium rounded-xl border transition-all',
                            enabled
                                ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/30'
                                : 'bg-red-500/20 text-red-400 border-red-500/30 hover:bg-red-500/30']">
                        {{ enabled ? 'Enabled' : 'Disabled' }}
                    </button>
                    <button @click="refreshStats" :disabled="isRefreshing"
                        class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                        {{ isRefreshing ? 'Refreshing...' : 'Refresh' }}
                    </button>
                </div>
            </div>

            <!-- Connection Status -->
            <div :class="['flex items-center gap-3 p-4 rounded-xl border',
                stats.rpc_connected ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-red-500/5 border-red-500/20']">
                <div :class="['w-3 h-3 rounded-full', stats.rpc_connected ? 'bg-emerald-500 animate-pulse' : 'bg-red-500']" />
                <div>
                    <span :class="stats.rpc_connected ? 'text-emerald-400' : 'text-red-400'" class="font-bold text-sm">
                        {{ stats.rpc_connected ? 'Connected' : 'Disconnected' }}
                    </span>
                    <span class="text-gray-500 text-xs ml-2">RPC: {{ rpcUrl }}</span>
                </div>
                <div class="ml-auto text-xs text-gray-500">
                    Registry: <span :class="stats.registry_deployed ? 'text-emerald-400' : 'text-yellow-400'">
                        {{ stats.registry_deployed ? 'Deployed' : 'Not Deployed' }}
                    </span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div v-for="card in [
                    { label: 'Block Height', value: fmt(stats.block_height), color: 'text-white', icon: '&#9632;' },
                    { label: 'Total Nodes', value: fmt(stats.total_nodes), color: 'text-primary-400', icon: '&#9673;' },
                    { label: 'Total Staked', value: `${fmt(stats.total_staked)} TPIX`, color: 'text-emerald-400', icon: '&#9733;' },
                    { label: 'Rewards Distributed', value: `${fmt(stats.total_rewards_distributed)} TPIX`, color: 'text-yellow-400', icon: '&#9830;' },
                ]" :key="card.label"
                   class="glass-card rounded-xl p-4 text-center">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ card.label }}</div>
                    <div :class="['text-xl font-black', card.color]">{{ card.value }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Node Tiers Info -->
                <div class="glass-card rounded-xl p-5">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">Node Tiers</h3>
                    <div class="space-y-3">
                        <div v-for="tier in tiers" :key="tier.name"
                             :class="['p-4 rounded-xl border', tier.bg]">
                            <div class="flex items-center justify-between mb-2">
                                <span :class="['font-bold', tier.color]">{{ tier.name }}</span>
                                <span class="text-xs text-gray-400">Lock: {{ tier.lock }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span class="text-gray-500">Min Stake:</span>
                                    <span class="text-white ml-1 font-mono">{{ tier.stake }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Reward Share:</span>
                                    <span :class="[tier.color, 'ml-1 font-bold']">{{ tier.reward }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reward Pool -->
                <div class="glass-card rounded-xl p-5">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">Reward Pool</h3>
                    <div class="space-y-4">
                        <div class="text-center p-6 rounded-xl bg-gradient-to-br from-yellow-500/10 to-amber-500/5 border border-yellow-500/20">
                            <div class="text-xs text-gray-500 mb-1">Remaining Rewards</div>
                            <div class="text-3xl font-black text-yellow-400">{{ fmt(stats.remaining_rewards) }} TPIX</div>
                            <div class="text-xs text-gray-500 mt-1">1.4B total over 3 years (ending 2028)</div>
                        </div>

                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-400">
                                <span>Year 1 (2025-2026):</span>
                                <span class="text-white">600,000,000 TPIX</span>
                            </div>
                            <div class="flex justify-between text-gray-400">
                                <span>Year 2 (2026-2027):</span>
                                <span class="text-white">500,000,000 TPIX</span>
                            </div>
                            <div class="flex justify-between text-gray-400">
                                <span>Year 3 (2027-2028):</span>
                                <span class="text-white">300,000,000 TPIX</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Registry Configuration -->
            <div class="glass-card rounded-xl p-5">
                <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4">Registry Configuration</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs text-gray-500 uppercase mb-1 block">Registry Contract Address</label>
                        <input v-model="configNote" type="text" placeholder="0x..."
                            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white font-mono text-sm focus:border-primary-500/50 focus:outline-none" />
                        <p class="text-xs text-gray-600 mt-1">Set via MASTERNODE_REGISTRY_ADDRESS in .env</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 uppercase mb-1 block">RPC Endpoint</label>
                        <input :value="rpcUrl" disabled
                            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-gray-500 font-mono text-sm" />
                        <p class="text-xs text-gray-600 mt-1">Chain ID: {{ chainId }}</p>
                    </div>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <button @click="saveConfig"
                        class="px-5 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all">
                        Save Note
                    </button>
                    <span v-if="configSaved" class="text-xs text-emerald-400">Saved!</span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
