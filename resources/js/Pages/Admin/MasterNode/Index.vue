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

// index = ตัวเลข enum NodeTier บนสัญญา { Guardian=0, Sentinel=1, Light=2, Validator=3 }
// countKey = คีย์ใน stats ที่ backend นับ activeNodes มาจาก getTierInfo() จริง
const tiers = [
    { name: 'Validator', index: 3, countKey: 'validator_nodes', max: 21, stake: '10,000,000 TPIX', reward: '20%', lock: '180 days', color: 'text-red-400', bg: 'bg-red-500/15 border-red-500/30' },
    { name: 'Guardian', index: 0, countKey: 'guardian_nodes', max: 100, stake: '1,000,000 TPIX', reward: '35%', lock: '90 days', color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    { name: 'Sentinel', index: 1, countKey: 'sentinel_nodes', max: 500, stake: '100,000 TPIX', reward: '30%', lock: '30 days', color: 'text-purple-400', bg: 'bg-purple-500/15 border-purple-500/30' },
    { name: 'Light', index: 2, countKey: 'light_nodes', max: 0, stake: '10,000 TPIX', reward: '15%', lock: '7 days', color: 'text-cyan-400', bg: 'bg-cyan-500/15 border-cyan-500/30' },
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

            <!-- ที่อยู่สัญญาตั้งไว้แล้ว แต่ eth_getCode คืน 0x = ไม่มีโค้ดอยู่ตรงนั้น
                 เกิดตอนเชน regenesis แล้ว .env ยังค้างที่อยู่เก่า — เงียบมาก ถ้าไม่เตือนตรงนี้
                 หน้าเว็บจะแสดง "0 โหนด" เหมือนระบบปกติทุกประการ -->
            <div v-if="stats.registry_code_missing"
                 class="flex items-start gap-3 p-4 rounded-xl border bg-red-500/5 border-red-500/30">
                <div class="w-3 h-3 rounded-full bg-red-500 mt-1 shrink-0" />
                <div class="text-xs">
                    <div class="text-red-400 font-bold">ที่อยู่สัญญาชี้ไปที่ address ที่ไม่มีโค้ด</div>
                    <div class="text-gray-400 mt-1">
                        <code class="font-mono text-gray-300">{{ registryAddress }}</code> —
                        eth_getCode คืน 0x แปลว่าสัญญาไม่เคย deploy หรือหายไปตอน regenesis
                        ต้อง deploy ใหม่แล้วอัปเดต <code class="font-mono">MASTERNODE_REGISTRY_ADDRESS</code> ใน .env
                    </div>
                </div>
            </div>

            <!-- พูลรางวัลว่าง = ผู้ใช้กด "รับรางวัล" แล้วได้ 0 ทั้งที่ยอดสะสมขึ้น -->
            <div v-else-if="stats.registry_deployed && Number(stats.reward_pool_available) <= 0"
                 class="flex items-start gap-3 p-4 rounded-xl border bg-yellow-500/5 border-yellow-500/30">
                <div class="w-3 h-3 rounded-full bg-yellow-500 mt-1 shrink-0" />
                <div class="text-xs">
                    <div class="text-yellow-400 font-bold">พูลรางวัลยังไม่ถูกเติมเงิน</div>
                    <div class="text-gray-400 mt-1">
                        สัญญาจ่ายรางวัลได้เฉพาะเงินส่วนที่เกินจากเงินต้นของผู้วางค้ำ ตอนนี้ส่วนนั้นเป็น 0
                        รางวัลจะสะสมต่อไปแต่ผู้ใช้กดรับไม่ได้ — โอน TPIX เข้าสัญญาผ่าน
                        <code class="font-mono text-gray-300">fundRewardPool()</code> จากกระเป๋า Master Node Rewards
                    </div>
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
                                <span :class="['font-bold', tier.color]">
                                    {{ tier.name }}
                                    <span class="text-[10px] text-gray-500 font-normal ml-1">tier {{ tier.index }}</span>
                                </span>
                                <span class="text-xs text-gray-400">Lock: {{ tier.lock }}</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span class="text-gray-500">Min Stake:</span>
                                    <span class="text-white ml-1 font-mono">{{ tier.stake }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Reward Share:</span>
                                    <span :class="[tier.color, 'ml-1 font-bold']">{{ tier.reward }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Active Nodes:</span>
                                    <span class="text-white ml-1 font-mono">
                                        {{ fmt(stats[tier.countKey]) }}{{ tier.max ? ` / ${fmt(tier.max)}` : ' / ∞' }}
                                    </span>
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
                            <div class="text-xs text-gray-500 mb-1">Remaining Rewards (schedule cap)</div>
                            <div class="text-3xl font-black text-yellow-400">{{ fmt(stats.remaining_rewards) }} TPIX</div>
                            <div class="text-xs text-gray-500 mt-1">1.4B total over 3 years (ending 2028)</div>
                        </div>

                        <!-- เพดานตามตาราง ≠ เงินที่มีจริง — สองตัวนี้ห้ามเอามารวมกัน
                             totalRewardPool เป็นแค่ตัวเลขบัญชี ส่วนที่จ่ายได้จริงคือ balance - totalStaked -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <div class="text-[10px] text-gray-500 uppercase tracking-wider">Funded Into Pool</div>
                                <div class="text-lg font-black text-white mt-1">{{ fmt(stats.reward_pool_funded) }}</div>
                            </div>
                            <div class="p-4 rounded-xl border text-center"
                                 :class="Number(stats.reward_pool_available) > 0
                                     ? 'bg-emerald-500/5 border-emerald-500/20'
                                     : 'bg-yellow-500/5 border-yellow-500/20'">
                                <div class="text-[10px] text-gray-500 uppercase tracking-wider">Payable Right Now</div>
                                <div class="text-lg font-black mt-1"
                                     :class="Number(stats.reward_pool_available) > 0 ? 'text-emerald-400' : 'text-yellow-400'">
                                    {{ fmt(stats.reward_pool_available) }}
                                </div>
                            </div>
                        </div>

                        <div v-if="stats.kyc_contract" class="text-xs text-gray-500">
                            KYC contract: <code class="font-mono text-gray-300">{{ stats.kyc_contract }}</code>
                        </div>
                        <div v-else-if="stats.registry_deployed" class="text-xs text-yellow-400/80">
                            ยังไม่ได้ผูกสัญญา KYC — ชั้น Validator ลงทะเบียนไม่ได้จนกว่าจะเรียก setKYCContract()
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
