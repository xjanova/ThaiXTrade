<script setup>
/**
 * TPIX TRADE — Cross-Chain Bridge
 * สะพานเชื่อม TPIX Chain (4289) ↔ BSC (56)
 * lock/mint mechanism — fee 0.1% (min 1 TPIX)
 * Developed by Xman Studio
 */
import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
const walletStore = useWalletStore();
const bridgeInfo = ref(null);
const direction = ref('bsc_to_tpix');
const amount = ref('');
const txHash = ref('');
const history = ref([]);
const isBridging = ref(false);
const message = ref(null);

const chains = computed(() => {
    if (direction.value === 'bsc_to_tpix') {
        return { from: { name: 'BSC', symbol: 'WTPIX', color: '#F3BA2F' }, to: { name: 'TPIX Chain', symbol: 'TPIX', color: '#06B6D4' } };
    }
    return { from: { name: 'TPIX Chain', symbol: 'TPIX', color: '#06B6D4' }, to: { name: 'BSC', symbol: 'WTPIX', color: '#F3BA2F' } };
});

const fee = computed(() => {
    if (!amount.value || !bridgeInfo.value) return '0';
    const f = parseFloat(amount.value) * (bridgeInfo.value.fee_percent / 100);
    return Math.max(f, bridgeInfo.value.min_fee || 1).toFixed(4);
});

const receiveAmount = computed(() => {
    if (!amount.value) return '0';
    return Math.max(0, parseFloat(amount.value) - parseFloat(fee.value)).toFixed(4);
});

function toggleDirection() { direction.value = direction.value === 'bsc_to_tpix' ? 'tpix_to_bsc' : 'bsc_to_tpix'; }

async function fetchInfo() { try { const { data } = await axios.get('/api/v1/bridge/info'); bridgeInfo.value = data.data; } catch {} }
async function fetchHistory() {
    if (!walletStore.address) return;
    try { const { data } = await axios.get(`/api/v1/bridge/history/${walletStore.address}`); history.value = data.data || []; } catch { history.value = []; }
}

async function initiateBridge() {
    if (!walletStore.isConnected) { message.value = { type: 'error', text: 'กรุณาเชื่อมต่อ Wallet ก่อน' }; return; }
    if (!amount.value || parseFloat(amount.value) < (bridgeInfo.value?.min_amount || 10)) { message.value = { type: 'error', text: `ขั้นต่ำ ${bridgeInfo.value?.min_amount || 10} TPIX` }; return; }
    isBridging.value = true; message.value = null;
    try {
        const { data } = await axios.post('/api/v1/bridge/initiate', { wallet_address: walletStore.address, amount: amount.value, direction: direction.value, tx_hash: txHash.value || null });
        if (data.success) { message.value = { type: 'success', text: 'Bridge สร้างสำเร็จ! กำลังดำเนินการ...' }; amount.value = ''; txHash.value = ''; fetchHistory(); }
    } catch (err) { message.value = { type: 'error', text: err.response?.data?.error?.message || 'Bridge failed' }; }
    isBridging.value = false;
}

const statusCfg = { pending: { l: 'Pending', c: 'bg-yellow-500/20 text-yellow-400' }, processing: { l: 'Processing', c: 'bg-primary-500/20 text-primary-400' }, completed: { l: 'Completed', c: 'bg-trading-green/20 text-trading-green' }, failed: { l: 'Failed', c: 'bg-trading-red/20 text-trading-red' } };
function shortHash(h) { return h ? h.slice(0, 10) + '...' + h.slice(-6) : '-'; }
function timeAgo(d) { const s = Math.floor((Date.now() - new Date(d)) / 1000); if (s < 60) return s + 's ago'; if (s < 3600) return Math.floor(s / 60) + 'm ago'; return Math.floor(s / 3600) + 'h ago'; }

onMounted(() => { fetchInfo(); fetchHistory(); });
</script>

<template>
    <Head title="Bridge — TPIX Chain ↔ BSC" />
    <AppLayout>
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">🌉 {{ t('bridge.title') }}</h1>
                <p class="text-dark-400">{{ t('bridge.subtitle') }}</p>
            </div>

            <div v-if="message" :class="['px-4 py-3 rounded-xl text-sm', message.type === 'success' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red']">{{ message.text }}</div>

            <!-- Bridge Card -->
            <div class="glass-card space-y-6">
                <!-- From -->
                <div>
                    <label class="text-sm text-dark-400 mb-2 block">{{ t('bridge.from') }}</label>
                    <div class="bg-dark-700 rounded-xl p-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center" :style="{ background: chains.from.color + '20' }">
                                <span class="text-sm font-bold" :style="{ color: chains.from.color }">{{ chains.from.name.slice(0, 1) }}</span>
                            </div>
                            <div><p class="text-white font-semibold">{{ chains.from.name }}</p><p class="text-dark-400 text-xs">{{ chains.from.symbol }}</p></div>
                        </div>
                        <input v-model="amount" type="number" placeholder="0.00" min="10" class="bg-transparent text-right text-white text-2xl font-mono w-40 outline-none placeholder-dark-600" />
                    </div>
                </div>

                <!-- Toggle -->
                <div class="flex justify-center -my-2">
                    <button @click="toggleDirection" class="w-10 h-10 rounded-full bg-primary-500/20 border border-primary-500/30 flex items-center justify-center hover:bg-primary-500/30 transition-all hover:rotate-180 duration-300">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                    </button>
                </div>

                <!-- To -->
                <div>
                    <label class="text-sm text-dark-400 mb-2 block">{{ t('bridge.to') }}</label>
                    <div class="bg-dark-700 rounded-xl p-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center" :style="{ background: chains.to.color + '20' }">
                                <span class="text-sm font-bold" :style="{ color: chains.to.color }">{{ chains.to.name.slice(0, 1) }}</span>
                            </div>
                            <div><p class="text-white font-semibold">{{ chains.to.name }}</p><p class="text-dark-400 text-xs">{{ chains.to.symbol }}</p></div>
                        </div>
                        <p class="text-2xl font-mono text-trading-green">{{ receiveAmount }}</p>
                    </div>
                </div>

                <!-- Fee -->
                <div class="bg-dark-700/50 rounded-xl p-3 space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-dark-400">Fee (0.1%)</span><span class="text-white">{{ fee }} TPIX</span></div>
                    <div class="flex justify-between"><span class="text-dark-400">คุณจะได้รับ</span><span class="text-trading-green font-semibold">{{ receiveAmount }} {{ chains.to.symbol }}</span></div>
                    <div class="flex justify-between"><span class="text-dark-400">เวลาโดยประมาณ</span><span class="text-white">2-5 นาที</span></div>
                </div>

                <!-- TX Hash -->
                <div>
                    <label class="text-sm text-dark-400 mb-1 block">Transaction Hash (ถ้ามี)</label>
                    <input v-model="txHash" type="text" placeholder="0x..." class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm font-mono placeholder-dark-500 focus:border-primary-500 outline-none" />
                </div>

                <!-- Bridge Button -->
                <button @click="initiateBridge" :disabled="isBridging || !amount"
                    class="w-full py-4 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-bold text-lg hover:shadow-lg hover:shadow-primary-500/20 disabled:opacity-50 transition-all">
                    {{ isBridging ? '🔄 กำลังดำเนินการ...' : walletStore.isConnected ? '🌉 ' + t('bridge.bridgeNow') : '🔗 ' + t('bridge.connectFirst') }}
                </button>
            </div>

            <!-- Steps -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('bridge.steps') }}</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div v-for="(step, i) in ['Approve Token', 'ส่ง Transaction', 'รอยืนยัน', 'รับเหรียญ']" :key="i" class="text-center">
                        <div class="w-10 h-10 rounded-full bg-primary-500/20 flex items-center justify-center mx-auto mb-2">
                            <span class="text-primary-400 font-bold text-sm">{{ i + 1 }}</span>
                        </div>
                        <p class="text-dark-300 text-xs">{{ step }}</p>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div v-if="history.length" class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('bridge.history') }}</h3>
                <div class="space-y-3">
                    <div v-for="tx in history" :key="tx.id" class="bg-dark-700/50 rounded-xl p-3 flex items-center justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-white text-sm font-medium">{{ parseFloat(tx.amount).toFixed(2) }} TPIX</span>
                                <span class="text-dark-500 text-xs">{{ tx.direction === 'bsc_to_tpix' ? 'BSC → TPIX' : 'TPIX → BSC' }}</span>
                            </div>
                            <p class="text-dark-500 text-xs font-mono mt-1">{{ shortHash(tx.source_tx_hash) }}</p>
                        </div>
                        <div class="text-right">
                            <span :class="['px-2 py-0.5 rounded-lg text-xs', statusCfg[tx.status]?.c]">{{ statusCfg[tx.status]?.l }}</span>
                            <p class="text-dark-500 text-xs mt-1">{{ timeAgo(tx.created_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
