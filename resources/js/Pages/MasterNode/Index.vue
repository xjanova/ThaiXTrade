<script setup>
/**
 * TPIX Master Node — Premium Setup + Network Dashboard
 * Connect wallet → auto-check balance → choose tier → one-click stake
 * Design: Glass-morphism dark with brand gradients & glow effects
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { addTPIXChainToWallet } from '@/utils/web3';

const walletStore = useWalletStore();

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    nodes: { type: Array, default: () => [] },
    registryAddress: { type: String, default: '' },
});

// ============================================================
//  Node Tiers
// ============================================================
const tiers = [
    {
        id: 'validator', name: 'Validator Node', icon: '/logo.png',
        minStake: 1_000_000, apy: '12-15%', lock: '90 days',
        maxNodes: 100, rewardShare: '50%',
        hardware: '8 CPU · 16GB · 500GB SSD',
        desc: 'Block producer with highest rewards and governance power',
        gradient: 'from-yellow-500/30 via-amber-500/20 to-orange-500/10',
        border: 'border-yellow-500/30',
        glow: 'shadow-[0_0_40px_rgba(245,158,11,0.15)]',
        badge: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40',
        ring: 'ring-yellow-500/30',
        accent: 'text-yellow-400',
    },
    {
        id: 'sentinel', name: 'Sentinel Node', icon: '/logo.png',
        minStake: 100_000, apy: '7-10%', lock: '30 days',
        maxNodes: 500, rewardShare: '30%',
        hardware: '4 CPU · 8GB · 200GB SSD',
        desc: 'Data relay and light validation for network integrity',
        gradient: 'from-purple-500/30 via-violet-500/20 to-fuchsia-500/10',
        border: 'border-purple-500/30',
        glow: 'shadow-[0_0_40px_rgba(139,92,246,0.15)]',
        badge: 'bg-purple-500/20 text-purple-300 border-purple-500/40',
        ring: 'ring-purple-500/30',
        accent: 'text-purple-400',
    },
    {
        id: 'light', name: 'Light Node', icon: '/logo.png',
        minStake: 10_000, apy: '4-6%', lock: '7 days',
        maxNodes: null, rewardShare: '20%',
        hardware: '2 CPU · 4GB · 100GB SSD',
        desc: 'Easiest entry — support the network and earn rewards',
        gradient: 'from-cyan-500/30 via-blue-500/20 to-teal-500/10',
        border: 'border-cyan-500/30',
        glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        badge: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',
        ring: 'ring-cyan-500/30',
        accent: 'text-cyan-400',
    },
];

// ============================================================
//  State
// ============================================================
const tpixBalance = ref(0);
const isLoadingBalance = ref(false);
const selectedTier = ref(null);
const isStaking = ref(false);
const stakeTxHash = ref('');
const stakeError = ref('');
const myNodes = ref([]);
const activeTab = ref('setup'); // setup | network
const isOnTPIXChain = computed(() => walletStore.chainId === 4289);

const networkStats = ref({
    totalNodes: props.stats?.total_nodes || 0,
    validatorNodes: props.stats?.validator_nodes || 0,
    sentinelNodes: props.stats?.sentinel_nodes || 0,
    lightNodes: props.stats?.light_nodes || 0,
    totalStaked: props.stats?.total_staked || '0',
    remainingRewards: props.stats?.remaining_rewards || '1,400,000,000',
    currentYear: props.stats?.current_year || 1,
    blockRewardPerBlock: props.stats?.block_reward || '25.5',
    blockHeight: props.stats?.block_height || 0,
});

const affordableTiers = computed(() =>
    tiers.map(t => ({ ...t, canAfford: tpixBalance.value >= t.minStake, nodeCount: t.minStake > 0 ? Math.floor(tpixBalance.value / t.minStake) : 0 }))
);

// ============================================================
//  Wallet & Chain
// ============================================================
function connectWallet() { walletStore.showConnectModal = true; }

async function ensureTPIXChain() {
    if (walletStore.chainId !== 4289) {
        try { await walletStore.switchChain(4289); } catch {
            if (walletStore.provider) {
                await addTPIXChainToWallet(walletStore.provider);
                await walletStore.switchChain(4289);
            }
        }
    }
}

async function fetchBalance() {
    if (!walletStore.isConnected || !walletStore.address) return;
    isLoadingBalance.value = true;
    try {
        await ensureTPIXChain();
        if (walletStore.provider) {
            const bal = await walletStore.provider.getBalance(walletStore.address);
            tpixBalance.value = parseFloat(bal.toString()) / 1e18;
        }
    } catch {
        try {
            const resp = await fetch(`/api/v1/wallet/balances?wallet_address=${walletStore.address}&chain_id=4289`);
            if (resp.ok) { const d = await resp.json(); if (d.success && d.data?.native) tpixBalance.value = parseFloat(d.data.native.balance || 0); }
        } catch {}
    } finally { isLoadingBalance.value = false; }
}

async function fetchMyNodes() {
    if (!walletStore.address) return;
    try {
        const resp = await fetch(`/api/v1/masternode/my-nodes?wallet=${walletStore.address}`);
        if (resp.ok) { const d = await resp.json(); if (d.success) myNodes.value = d.data || []; }
    } catch {}
}

// ============================================================
//  Staking
// ============================================================
async function stakeAndRegister(tier) {
    if (!walletStore.isConnected) return connectWallet();
    stakeError.value = ''; stakeTxHash.value = '';
    if (tpixBalance.value < tier.minStake) { stakeError.value = `Need ${fmtNum(tier.minStake)} TPIX. You have ${fmtNum(Math.floor(tpixBalance.value))}.`; return; }

    isStaking.value = true; selectedTier.value = tier.id;
    try {
        await ensureTPIXChain();
        const signer = walletStore.signer;
        if (!signer) throw new Error('No signer');
        const tierIndex = tiers.findIndex(t => t.id === tier.id);
        const stakeWei = BigInt(tier.minStake) * BigInt(1e18);
        const iface = new (await import('ethers')).Interface(['function registerNode(uint8 _tier, string _endpoint) payable']);
        const data = iface.encodeFunctionData('registerNode', [tierIndex, `${walletStore.address.slice(0, 10)}.tpix.online`]);
        const tx = await signer.sendTransaction({ to: props.registryAddress, data, value: stakeWei, gasPrice: 0 });
        stakeTxHash.value = tx.hash;
        await tx.wait();
        await fetchBalance(); await fetchMyNodes();
    } catch (e) { stakeError.value = e.message || 'Transaction failed'; } finally { isStaking.value = false; selectedTier.value = null; }
}

// ============================================================
//  Watchers & Lifecycle
// ============================================================
watch(() => walletStore.address, (a) => { if (a) { fetchBalance(); fetchMyNodes(); } else { tpixBalance.value = 0; myNodes.value = []; } });
watch(() => walletStore.chainId, () => { if (walletStore.isConnected) fetchBalance(); });

let pollInterval;
onMounted(() => { if (walletStore.isConnected) { fetchBalance(); fetchMyNodes(); } pollInterval = setInterval(async () => { try { const r = await fetch('/api/v1/masternode/stats'); if (r.ok) { const d = await r.json(); if (d.success) networkStats.value = d.data; } } catch {} }, 30000); });
onUnmounted(() => clearInterval(pollInterval));

function fmtNum(n) { return Number(n).toLocaleString(); }
</script>

<template>
    <Head title="Master Node" />
    <AppLayout>
        <div class="relative min-h-screen overflow-hidden">

            <!-- ============================================================ -->
            <!--  Animated Background Glow (3 layers like Home.vue)           -->
            <!-- ============================================================ -->
            <div class="fixed inset-0 pointer-events-none -z-10">
                <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] bg-accent-500/8 rounded-full blur-3xl animate-float" />
                <div class="absolute top-1/2 right-1/3 w-[700px] h-[700px] bg-primary-500/6 rounded-full blur-3xl" style="animation: float 8s ease-in-out infinite reverse" />
                <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] bg-warm-500/5 rounded-full blur-3xl animate-float" style="animation-delay: -3s" />
            </div>

            <div class="max-w-6xl mx-auto px-4 py-8 space-y-8 relative z-10">

                <!-- ============================================================ -->
                <!--  HERO: Not Connected                                         -->
                <!-- ============================================================ -->
                <div v-if="!walletStore.isConnected" class="relative">
                    <!-- Hero glow backdrop -->
                    <div class="absolute -inset-2 bg-gradient-to-r from-accent-500/20 via-primary-500/15 to-warm-500/20 rounded-3xl blur-xl opacity-60" />

                    <div class="glass-brand relative rounded-3xl p-10 md:p-16 text-center overflow-hidden">
                        <!-- Floating particles -->
                        <div class="absolute top-6 left-10 w-2 h-2 bg-cyan-400/40 rounded-full animate-float" />
                        <div class="absolute top-20 right-16 w-1.5 h-1.5 bg-purple-400/40 rounded-full animate-float" style="animation-delay: -2s" />
                        <div class="absolute bottom-12 left-1/4 w-1 h-1 bg-yellow-400/40 rounded-full animate-float" style="animation-delay: -4s" />

                        <!-- TPIX Logo -->
                        <div class="relative inline-block mb-6">
                            <div class="absolute -inset-4 bg-gradient-to-r from-accent-500/30 via-primary-500/30 to-warm-500/30 rounded-full blur-2xl animate-glow-brand" />
                            <img src="/logo.png" alt="TPIX" class="relative w-24 h-24 shadow-2xl ring-2 ring-white/10" />
                        </div>

                        <h1 class="text-4xl md:text-5xl font-black mb-3">
                            <span class="text-gradient-brand">Master Node</span>
                        </h1>
                        <p class="text-lg text-gray-300 mb-2 max-w-xl mx-auto">
                            Secure the TPIX blockchain and earn rewards
                        </p>
                        <p class="text-sm text-gray-500 mb-8 max-w-md mx-auto">
                            Connect your wallet, check your balance, and start running a node in seconds. No coding required.
                        </p>

                        <!-- Stats preview -->
                        <div class="grid grid-cols-3 gap-4 max-w-md mx-auto mb-8">
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">APY up to</div>
                                <div class="text-xl font-black text-trading-green">15%</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">Reward Pool</div>
                                <div class="text-xl font-black text-gradient">1.4B</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">Min Stake</div>
                                <div class="text-xl font-black text-cyan-400">10K</div>
                            </div>
                        </div>

                        <button @click="connectWallet"
                                class="btn-brand px-10 py-4 text-lg font-bold rounded-2xl shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-300">
                            Connect Wallet
                        </button>
                        <p class="text-xs text-gray-500 mt-4">
                            MetaMask · Trust Wallet · Coinbase · OKX · TPIX Wallet
                        </p>

                        <!-- Guide + Download Links -->
                        <div class="flex items-center justify-center gap-4 mt-6">
                            <Link href="/masternode/guide"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                                📖 คู่มือการตั้งค่า
                            </Link>
                            <a href="https://github.com/xjanova/TPIX-Coin/releases/latest" target="_blank"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-gray-400 hover:bg-white/5 transition">
                                📥 ดาวน์โหลดโปรแกรม PC
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  CONNECTED STATE                                             -->
                <!-- ============================================================ -->
                <template v-else>

                    <!-- Wallet Bar with glow -->
                    <div class="relative group">
                        <div class="absolute -inset-0.5 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-2xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
                        <div class="glass relative rounded-2xl p-5 flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <!-- Logo avatar -->
                                <div class="relative">
                                    <div class="absolute -inset-1 bg-gradient-to-r from-cyan-500 to-blue-500 rounded-full blur-sm opacity-50" />
                                    <img src="/logo.png" alt="TPIX" class="relative w-12 h-12 ring-2 ring-white/20" />
                                </div>
                                <div>
                                    <div class="font-mono text-sm text-cyan-400 font-semibold">{{ walletStore.shortAddress }}</div>
                                    <div class="text-xs flex items-center gap-2 mt-0.5">
                                        <span v-if="isOnTPIXChain" class="flex items-center gap-1 text-green-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse" />
                                            TPIX Chain
                                        </span>
                                        <span v-else class="text-yellow-400 flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-yellow-400 animate-pulse" />
                                            Wrong Network
                                            <button @click="ensureTPIXChain" class="underline hover:text-yellow-300 ml-1">Switch</button>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Balance display -->
                            <div class="text-right">
                                <div class="text-[10px] text-gray-400 uppercase tracking-widest">Your TPIX</div>
                                <div class="text-3xl font-black leading-tight" :class="tpixBalance > 0 ? 'text-gradient' : 'text-gray-600'">
                                    <span v-if="isLoadingBalance" class="animate-pulse text-gray-500">···</span>
                                    <span v-else>{{ fmtNum(Math.floor(tpixBalance)) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Guide + Download Links -->
                    <div class="flex items-center justify-center gap-4">
                        <Link href="/masternode/guide"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                            📖 คู่มือการตั้งค่า
                        </Link>
                        <a href="https://github.com/xjanova/TPIX-Coin/releases/latest" target="_blank"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-gray-400 hover:bg-white/5 transition">
                            📥 ดาวน์โหลดโปรแกรม PC
                        </a>
                    </div>

                    <!-- Switch network CTA -->
                    <div v-if="!isOnTPIXChain"
                         class="glass rounded-2xl p-5 border-l-4 border-yellow-500 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-yellow-500/20 flex items-center justify-center text-2xl shrink-0">⚡</div>
                        <div class="flex-1">
                            <div class="text-yellow-400 font-bold">Switch to TPIX Chain</div>
                            <div class="text-xs text-gray-400">TPIX Chain will be added automatically if not in your wallet</div>
                        </div>
                        <button @click="ensureTPIXChain" class="btn-primary px-6 py-2.5 rounded-xl font-bold shrink-0">
                            Switch Network
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="flex gap-1 p-1 glass-sm rounded-xl">
                        <button v-for="tab in [{id:'setup',label:'Run a Node',icon:'🚀'},{id:'network',label:'Network',icon:'🌐'}]" :key="tab.id"
                                @click="activeTab = tab.id"
                                :class="['flex-1 py-2.5 rounded-lg text-sm font-semibold transition-all',
                                         activeTab === tab.id ? 'glass text-white shadow-lg' : 'text-gray-400 hover:text-white']">
                            {{ tab.icon }} {{ tab.label }}
                        </button>
                    </div>

                    <!-- ============================================================ -->
                    <!--  TAB: Setup (Node Tier Cards)                                -->
                    <!-- ============================================================ -->
                    <div v-show="activeTab === 'setup'" class="space-y-6">

                        <div class="text-center">
                            <h2 class="text-2xl font-bold text-white">Choose Your Node</h2>
                            <p class="text-sm text-gray-400 mt-1">
                                You have <span class="text-gradient font-bold">{{ fmtNum(Math.floor(tpixBalance)) }} TPIX</span> —
                                select a tier to start earning
                            </p>
                        </div>

                        <!-- Tier Cards -->
                        <div class="grid md:grid-cols-3 gap-6">
                            <div v-for="tier in affordableTiers" :key="tier.id"
                                 :class="[
                                     'group relative rounded-2xl overflow-hidden transition-all duration-500',
                                     tier.canAfford
                                         ? 'hover:scale-[1.03] hover:-translate-y-1 cursor-pointer'
                                         : 'opacity-40 grayscale pointer-events-none'
                                 ]">

                                <!-- Glow backdrop on hover -->
                                <div :class="['absolute -inset-1 bg-gradient-to-b rounded-3xl blur-xl transition-opacity duration-500 opacity-0 group-hover:opacity-100', tier.gradient]" />

                                <!-- Card body -->
                                <div :class="['relative glass rounded-2xl p-6 h-full flex flex-col', tier.border, tier.glow]">

                                    <!-- Header -->
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="relative">
                                            <div :class="['absolute -inset-2 rounded-xl blur-lg opacity-40', tier.gradient]" />
                                            <img src="/logo.png" alt="TPIX" class="relative w-14 h-14 ring-1 ring-white/10" />
                                        </div>
                                        <div :class="['px-3 py-1 rounded-full text-xs font-black border', tier.badge]">
                                            {{ tier.apy }} APY
                                        </div>
                                    </div>

                                    <!-- Tier name -->
                                    <h3 class="text-xl font-bold text-white mb-1">{{ tier.name }}</h3>
                                    <p class="text-xs text-gray-400 mb-5 leading-relaxed">{{ tier.desc }}</p>

                                    <!-- Stats grid -->
                                    <div class="space-y-3 mb-5 flex-1">
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs text-gray-500">Min Stake</span>
                                            <span :class="['text-sm font-bold', tier.accent]">{{ fmtNum(tier.minStake) }} TPIX</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs text-gray-500">Lock Period</span>
                                            <span class="text-sm text-gray-300">{{ tier.lock }}</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs text-gray-500">Block Reward</span>
                                            <span class="text-sm text-gray-300">{{ tier.rewardShare }}</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs text-gray-500">Max Nodes</span>
                                            <span class="text-sm text-gray-300">{{ tier.maxNodes || '∞' }}</span>
                                        </div>
                                    </div>

                                    <!-- Capacity indicator -->
                                    <div v-if="tier.canAfford" class="mb-4 p-2.5 rounded-xl bg-green-500/10 border border-green-500/20">
                                        <div class="flex items-center gap-2 text-xs text-green-400 font-semibold">
                                            <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse" />
                                            You can run {{ tier.nodeCount }} node{{ tier.nodeCount > 1 ? 's' : '' }}
                                        </div>
                                    </div>
                                    <div v-else class="mb-4 p-2.5 rounded-xl bg-red-500/5 border border-red-500/10">
                                        <div class="text-xs text-red-400/70">
                                            Need {{ fmtNum(tier.minStake - Math.floor(tpixBalance)) }} more TPIX
                                        </div>
                                    </div>

                                    <!-- Stake button -->
                                    <button @click="stakeAndRegister(tier)"
                                            :disabled="!tier.canAfford || isStaking"
                                            :class="[
                                                'w-full py-3 rounded-xl font-bold text-sm transition-all duration-300',
                                                tier.canAfford && !isStaking
                                                    ? 'btn-brand hover:shadow-lg hover:scale-[1.02]'
                                                    : 'bg-gray-800/50 text-gray-600 cursor-not-allowed'
                                            ]">
                                        <span v-if="isStaking && selectedTier === tier.id" class="flex items-center justify-center gap-2">
                                            <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.42" stroke-dashoffset="10"/></svg>
                                            Staking...
                                        </span>
                                        <span v-else>Stake {{ fmtNum(tier.minStake) }} TPIX</span>
                                    </button>

                                    <!-- Hardware -->
                                    <div class="text-[10px] text-gray-600 mt-3 text-center">{{ tier.hardware }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- TX Success -->
                        <div v-if="stakeTxHash" class="glass rounded-2xl p-5 border-l-4 border-green-500">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-xl bg-green-500/20 flex items-center justify-center text-xl shrink-0">✅</div>
                                <div>
                                    <div class="text-green-400 font-bold mb-1">Node Registered!</div>
                                    <a :href="'https://explorer.tpix.online/tx/' + stakeTxHash" target="_blank"
                                       class="text-xs text-cyan-400 hover:text-cyan-300 font-mono break-all">
                                        {{ stakeTxHash }}
                                    </a>
                                    <p class="text-xs text-gray-400 mt-2">
                                        Download the <a href="https://github.com/xjanova/TPIX-Coin/releases" target="_blank" class="text-cyan-400 underline">TPIX Node app</a> to run on your server.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- TX Error -->
                        <div v-if="stakeError" class="glass rounded-2xl p-4 border-l-4 border-red-500">
                            <div class="text-red-400 text-sm">{{ stakeError }}</div>
                        </div>

                        <!-- My Nodes -->
                        <div v-if="myNodes.length > 0" class="glass rounded-2xl p-5">
                            <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4 flex items-center gap-2">
                                <img src="/logo.png" alt="" class="w-5 h-5" />
                                Your Active Nodes
                            </h3>
                            <div class="space-y-2">
                                <div v-for="(nd, i) in myNodes" :key="i"
                                     class="flex items-center justify-between p-3 rounded-xl glass-sm">
                                    <div class="flex items-center gap-3">
                                        <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border',
                                            nd.tier === 0 ? 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30'
                                                : nd.tier === 1 ? 'bg-purple-500/15 text-purple-400 border-purple-500/30'
                                                : 'bg-cyan-500/15 text-cyan-400 border-cyan-500/30']">
                                            {{ ['Validator', 'Sentinel', 'Light'][nd.tier] }}
                                        </span>
                                        <span class="text-sm text-white font-semibold">{{ fmtNum(parseFloat(nd.staked || 0)) }} TPIX</span>
                                    </div>
                                    <div class="text-xs text-trading-green font-bold">+{{ nd.reward || '0' }} TPIX</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================================ -->
                    <!--  TAB: Network Stats                                          -->
                    <!-- ============================================================ -->
                    <div v-show="activeTab === 'network'" class="space-y-6">

                        <!-- Stats cards with glow -->
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                            <div v-for="stat in [
                                { label: 'Total Nodes', value: fmtNum(networkStats.totalNodes), color: 'text-white' },
                                { label: 'Validators', value: networkStats.validatorNodes, color: 'text-yellow-400' },
                                { label: 'Sentinels', value: networkStats.sentinelNodes, color: 'text-purple-400' },
                                { label: 'Light Nodes', value: networkStats.lightNodes, color: 'text-cyan-400' },
                                { label: 'Block Height', value: fmtNum(networkStats.blockHeight), color: 'text-white' },
                                { label: 'Reward/Block', value: networkStats.blockRewardPerBlock + ' TPIX', color: 'text-trading-green' },
                            ]" :key="stat.label"
                               class="glass-card rounded-xl p-4 text-center group hover:scale-105 transition-transform">
                                <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">{{ stat.label }}</div>
                                <div :class="['text-2xl font-black', stat.color]">{{ stat.value }}</div>
                            </div>
                        </div>

                        <!-- Reward Pool -->
                        <div class="glass rounded-2xl p-6">
                            <div class="flex items-center gap-3 mb-4">
                                <img src="/logo.png" alt="TPIX" class="w-8 h-8 ring-1 ring-white/10" />
                                <div>
                                    <h3 class="text-sm font-bold text-white">Reward Pool</h3>
                                    <p class="text-xs text-gray-400">1,400,000,000 TPIX distributed over 5 years</p>
                                </div>
                                <span class="ml-auto text-sm font-bold text-cyan-400">Year {{ networkStats.currentYear }}</span>
                            </div>

                            <!-- Progress bar -->
                            <div class="relative h-3 bg-dark-800 rounded-full overflow-hidden mb-4">
                                <div class="absolute inset-0 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-full" />
                                <div class="h-full bg-gradient-to-r from-cyan-500 via-blue-500 to-purple-500 rounded-full transition-all duration-1000 relative"
                                     style="width: 0%">
                                    <div class="absolute right-0 top-1/2 -translate-y-1/2 w-3 h-3 bg-white rounded-full shadow-lg shadow-cyan-500/50" />
                                </div>
                            </div>

                            <!-- Emission cards -->
                            <div class="grid grid-cols-5 gap-3">
                                <div v-for="em in [
                                    { y: 1, amt: '400M', pb: '~25.5/block', pct: '28.6%' },
                                    { y: 2, amt: '350M', pb: '~22.3/block', pct: '25.0%' },
                                    { y: 3, amt: '300M', pb: '~19.1/block', pct: '21.4%' },
                                    { y: 4, amt: '200M', pb: '~12.7/block', pct: '14.3%' },
                                    { y: 5, amt: '150M', pb: '~9.6/block', pct: '10.7%' },
                                ]" :key="em.y"
                                     :class="[
                                         'rounded-xl p-3 text-center border transition-all',
                                         networkStats.currentYear === em.y
                                             ? 'glass border-cyan-500/40 shadow-[0_0_20px_rgba(6,182,212,0.15)] scale-105'
                                             : 'glass-sm border-white/5 opacity-60'
                                     ]">
                                    <div :class="['text-xs font-black', networkStats.currentYear === em.y ? 'text-cyan-400' : 'text-gray-500']">
                                        Year {{ em.y }}
                                    </div>
                                    <div :class="['text-lg font-bold mt-1', networkStats.currentYear === em.y ? 'text-white' : 'text-gray-400']">
                                        {{ em.amt }}
                                    </div>
                                    <div class="text-[10px] text-gray-500 mt-0.5">{{ em.pb }}</div>
                                    <div class="text-[10px] text-gray-600">{{ em.pct }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Reward split visualization -->
                        <div class="glass rounded-2xl p-6">
                            <h3 class="text-sm font-bold text-white mb-4">Block Reward Split</h3>
                            <div class="flex rounded-xl overflow-hidden h-8 mb-3">
                                <div class="bg-gradient-to-r from-yellow-500 to-amber-500 flex items-center justify-center text-[10px] font-bold text-black" style="width:50%">
                                    50% Validator
                                </div>
                                <div class="bg-gradient-to-r from-purple-500 to-violet-500 flex items-center justify-center text-[10px] font-bold text-white" style="width:30%">
                                    30% Sentinel
                                </div>
                                <div class="bg-gradient-to-r from-cyan-500 to-blue-500 flex items-center justify-center text-[10px] font-bold text-white" style="width:20%">
                                    20% Light
                                </div>
                            </div>
                            <p class="text-xs text-gray-400">
                                Each block (~2 seconds) distributes {{ networkStats.blockRewardPerBlock }} TPIX to node operators, weighted by tier and uptime score.
                            </p>
                        </div>

                        <!-- Download CTA -->
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-3xl blur-xl opacity-60 group-hover:opacity-100 transition-opacity" />
                            <div class="glass-brand relative rounded-2xl p-8 text-center">
                                <img src="/logo.png" alt="TPIX" class="w-16 h-16 mx-auto mb-4 ring-2 ring-white/10" />
                                <h3 class="text-xl font-bold text-white mb-2">Run Your Own Node</h3>
                                <p class="text-sm text-gray-400 mb-5">Download the TPIX Node app for Windows or Linux</p>
                                <div class="flex justify-center gap-3 flex-wrap">
                                    <a href="https://github.com/xjanova/TPIX-Coin/releases" target="_blank"
                                       class="btn-brand px-6 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                                        Download for Windows / Linux
                                    </a>
                                    <a href="https://github.com/xjanova/TPIX-Coin" target="_blank"
                                       class="btn-secondary px-6 py-2.5 rounded-xl font-bold text-sm">
                                        View Source Code
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                </template>
            </div>
        </div>
    </AppLayout>
</template>
