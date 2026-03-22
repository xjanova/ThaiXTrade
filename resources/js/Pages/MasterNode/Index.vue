<script setup>
/**
 * TPIX Master Node Network Dashboard
 * แสดงข้อมูลเครือข่าย Master Node ทั้งหมด
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    nodes: { type: Array, default: () => [] },
});

// Real-time data (polled every 30s)
const networkStats = ref({
    totalNodes: props.stats?.total_nodes || 0,
    validatorNodes: props.stats?.validator_nodes || 0,
    sentinelNodes: props.stats?.sentinel_nodes || 0,
    lightNodes: props.stats?.light_nodes || 0,
    totalStaked: props.stats?.total_staked || '0',
    totalRewardsDistributed: props.stats?.total_rewards_distributed || '0',
    remainingRewards: props.stats?.remaining_rewards || '1,400,000,000',
    currentYear: props.stats?.current_year || 1,
    blockHeight: props.stats?.block_height || 0,
    blockRewardPerBlock: props.stats?.block_reward || '25.5',
});

const activeNodes = ref(props.nodes || []);
const selectedTier = ref('all');

const filteredNodes = computed(() => {
    if (selectedTier.value === 'all') return activeNodes.value;
    return activeNodes.value.filter(n => n.tier === selectedTier.value);
});

const rewardPoolUsed = computed(() => {
    const distributed = parseFloat(networkStats.value.totalRewardsDistributed.replace(/,/g, '')) || 0;
    return ((distributed / 1400000000) * 100).toFixed(2);
});

// Tier badge styling
function tierClass(tier) {
    switch (tier) {
        case 'validator': return 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30';
        case 'sentinel': return 'bg-purple-500/15 text-purple-400 border-purple-500/30';
        case 'light': return 'bg-cyan-500/15 text-cyan-400 border-cyan-500/30';
        default: return 'bg-gray-500/15 text-gray-400 border-gray-500/30';
    }
}

function statusClass(status) {
    switch (status) {
        case 'active': return 'text-green-400';
        case 'slashed': return 'text-red-400';
        default: return 'text-gray-400';
    }
}

function truncateAddr(addr) {
    if (!addr || addr.length < 12) return addr;
    return addr.slice(0, 6) + '...' + addr.slice(-4);
}

function formatNum(n) {
    return Number(n).toLocaleString();
}

// Poll for updates
let pollInterval;
onMounted(() => {
    pollInterval = setInterval(async () => {
        try {
            const resp = await fetch('/api/v1/masternode/stats');
            if (resp.ok) {
                const data = await resp.json();
                if (data.success) {
                    networkStats.value = data.data.stats;
                    activeNodes.value = data.data.nodes || activeNodes.value;
                }
            }
        } catch (e) { /* silent */ }
    }, 30000);
});

onUnmounted(() => {
    clearInterval(pollInterval);
});
</script>

<template>
    <Head title="Master Node Network" />

    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Master Node Network</h1>
                    <p class="text-sm text-gray-400 mt-1">Real-time overview of TPIX blockchain validators and node operators</p>
                </div>
                <a href="https://github.com/xjanova/TPIX-Coin/releases"
                   target="_blank"
                   class="btn-primary text-sm px-4 py-2">
                    Run a Node
                </a>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <div class="glass-card p-4 text-center">
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Total Nodes</div>
                    <div class="text-2xl font-bold text-white mt-1">{{ formatNum(networkStats.totalNodes) }}</div>
                </div>
                <div class="glass-card p-4 text-center">
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Validators</div>
                    <div class="text-2xl font-bold text-yellow-400 mt-1">{{ networkStats.validatorNodes }}</div>
                </div>
                <div class="glass-card p-4 text-center">
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Sentinels</div>
                    <div class="text-2xl font-bold text-purple-400 mt-1">{{ networkStats.sentinelNodes }}</div>
                </div>
                <div class="glass-card p-4 text-center">
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Light Nodes</div>
                    <div class="text-2xl font-bold text-cyan-400 mt-1">{{ networkStats.lightNodes }}</div>
                </div>
                <div class="glass-card p-4 text-center">
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Total Staked</div>
                    <div class="text-xl font-bold text-trading-green mt-1">{{ networkStats.totalStaked }}</div>
                    <div class="text-xs text-gray-500">TPIX</div>
                </div>
                <div class="glass-card p-4 text-center">
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Block Height</div>
                    <div class="text-xl font-bold text-white mt-1">{{ formatNum(networkStats.blockHeight) }}</div>
                </div>
            </div>

            <!-- Reward Pool Progress -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-white uppercase tracking-wide">Reward Pool (1.4B TPIX over 5 Years)</h3>
                    <span class="text-sm text-cyan-400 font-mono">Year {{ networkStats.currentYear }} &middot; {{ networkStats.blockRewardPerBlock }} TPIX/block</span>
                </div>
                <div class="w-full h-3 bg-gray-800 rounded-full overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-1000"
                         :style="{ width: rewardPoolUsed + '%' }"></div>
                </div>
                <div class="flex justify-between mt-2 text-xs text-gray-400">
                    <span>Distributed: {{ networkStats.totalRewardsDistributed }} TPIX</span>
                    <span>Remaining: {{ networkStats.remainingRewards }} TPIX</span>
                </div>

                <!-- Emission Schedule -->
                <div class="grid grid-cols-5 gap-2 mt-4">
                    <div v-for="(em, i) in [
                        { year: 1, amount: '400M', pct: 28.6 },
                        { year: 2, amount: '350M', pct: 25.0 },
                        { year: 3, amount: '300M', pct: 21.4 },
                        { year: 4, amount: '200M', pct: 14.3 },
                        { year: 5, amount: '150M', pct: 10.7 },
                    ]" :key="em.year"
                       :class="['p-2 rounded-lg text-center text-xs border',
                                networkStats.currentYear === em.year
                                    ? 'bg-cyan-500/15 border-cyan-500/30 text-cyan-300'
                                    : 'bg-gray-800/50 border-gray-700/30 text-gray-500']">
                        <div class="font-semibold">Y{{ em.year }}</div>
                        <div class="text-[10px]">{{ em.amount }}</div>
                    </div>
                </div>
            </div>

            <!-- Active Nodes Table -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-white uppercase tracking-wide">Active Nodes</h3>
                    <div class="flex gap-2">
                        <button v-for="f in ['all', 'validator', 'sentinel', 'light']" :key="f"
                                @click="selectedTier = f"
                                :class="['px-3 py-1 rounded-lg text-xs font-semibold border transition',
                                         selectedTier === f
                                             ? 'bg-cyan-500/20 border-cyan-500/30 text-cyan-300'
                                             : 'bg-gray-800/50 border-gray-700/30 text-gray-400 hover:text-white']">
                            {{ f === 'all' ? 'All' : f.charAt(0).toUpperCase() + f.slice(1) }}
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="text-left py-2 px-3 text-gray-400">#</th>
                                <th class="text-left py-2 px-3 text-gray-400">Operator</th>
                                <th class="text-left py-2 px-3 text-gray-400">Tier</th>
                                <th class="text-right py-2 px-3 text-gray-400">Staked</th>
                                <th class="text-right py-2 px-3 text-gray-400">Rewards</th>
                                <th class="text-right py-2 px-3 text-gray-400">Uptime</th>
                                <th class="text-left py-2 px-3 text-gray-400">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(node, i) in filteredNodes" :key="node.operator || i"
                                class="border-b border-white/5 hover:bg-white/[0.02] transition">
                                <td class="py-2 px-3 text-gray-500">{{ i + 1 }}</td>
                                <td class="py-2 px-3">
                                    <span class="font-mono text-cyan-400 text-xs">{{ truncateAddr(node.operator) }}</span>
                                    <div v-if="node.name" class="text-[10px] text-gray-500">{{ node.name }}</div>
                                </td>
                                <td class="py-2 px-3">
                                    <span :class="['inline-flex px-2 py-0.5 rounded text-[10px] font-bold border', tierClass(node.tier)]">
                                        {{ node.tier?.toUpperCase() }}
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-right text-white font-semibold">{{ node.staked || '—' }}</td>
                                <td class="py-2 px-3 text-right text-trading-green">{{ node.rewards || '—' }}</td>
                                <td class="py-2 px-3 text-right">
                                    <span :class="node.uptime >= 95 ? 'text-green-400' : node.uptime >= 80 ? 'text-yellow-400' : 'text-red-400'">
                                        {{ node.uptime ? node.uptime.toFixed(1) + '%' : '—' }}
                                    </span>
                                </td>
                                <td class="py-2 px-3">
                                    <span :class="statusClass(node.status)" class="flex items-center gap-1">
                                        <span v-if="node.status === 'active'" class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
                                        {{ node.status || '—' }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="filteredNodes.length === 0">
                                <td colspan="7" class="py-8 text-center text-gray-500">No nodes found</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- How to Run a Node -->
            <div class="glass-card p-6">
                <h3 class="text-lg font-bold text-white mb-4">How to Run a Master Node</h3>
                <div class="grid md:grid-cols-3 gap-4">
                    <div class="p-4 rounded-xl bg-yellow-500/5 border border-yellow-500/20">
                        <div class="text-yellow-400 font-bold mb-2">Validator Node</div>
                        <div class="text-sm text-gray-300 space-y-1">
                            <div>Stake: 1,000,000 TPIX</div>
                            <div>APY: 12-15%</div>
                            <div>Max: 100 nodes</div>
                            <div class="text-xs text-gray-500 mt-2">8 CPU, 16GB RAM, 500GB SSD</div>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-purple-500/5 border border-purple-500/20">
                        <div class="text-purple-400 font-bold mb-2">Sentinel Node</div>
                        <div class="text-sm text-gray-300 space-y-1">
                            <div>Stake: 100,000 TPIX</div>
                            <div>APY: 7-10%</div>
                            <div>Max: 500 nodes</div>
                            <div class="text-xs text-gray-500 mt-2">4 CPU, 8GB RAM, 200GB SSD</div>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/20">
                        <div class="text-cyan-400 font-bold mb-2">Light Node</div>
                        <div class="text-sm text-gray-300 space-y-1">
                            <div>Stake: 10,000 TPIX</div>
                            <div>APY: 4-6%</div>
                            <div>Max: Unlimited</div>
                            <div class="text-xs text-gray-500 mt-2">2 CPU, 4GB RAM, 100GB SSD</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Install -->
                <div class="mt-4 p-4 rounded-xl bg-gray-900/50 border border-gray-700/30">
                    <div class="text-xs text-gray-400 uppercase tracking-wide mb-2">Quick Install</div>
                    <div class="space-y-2">
                        <div>
                            <span class="text-xs text-gray-500">Linux:</span>
                            <code class="block mt-1 text-xs text-cyan-300 bg-black/30 p-2 rounded font-mono">curl -fsSL https://raw.githubusercontent.com/xjanova/TPIX-Coin/main/masternode/scripts/install.sh | bash</code>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Windows (PowerShell as Admin):</span>
                            <code class="block mt-1 text-xs text-cyan-300 bg-black/30 p-2 rounded font-mono">irm https://raw.githubusercontent.com/xjanova/TPIX-Coin/main/masternode/scripts/install.ps1 | iex</code>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Docker:</span>
                            <code class="block mt-1 text-xs text-cyan-300 bg-black/30 p-2 rounded font-mono">docker run -d -p 3847:3847 -p 30303:30303 -e TPIX_WALLET=0xYour tpix-node:latest</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
