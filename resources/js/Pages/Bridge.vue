<script setup>
/**
 * TPIX TRADE — Cross-Chain Bridge (Production)
 * สะพานเชื่อม TPIX Chain (4289) ↔ BSC (56)
 *
 * Flow จริง:
 * BSC→TPIX: User sign wTPIX.burn() → submit tx_hash → backend verify → send native TPIX
 * TPIX→BSC: User sign native transfer → submit tx_hash → backend verify → mint wTPIX
 *
 * ไม่ต้อง paste tx_hash เอง — frontend sign แล้ว auto-submit
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { ethers } from 'ethers';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
const walletStore = useWalletStore();
const bridgeInfo = ref(null);
const direction = ref('bsc_to_tpix');
const amount = ref('');
const history = ref([]);
const isBridging = ref(false);
const bridgeStep = ref(''); // '', 'signing', 'confirming', 'submitting', 'processing'
const message = ref(null);
const activeBridgeId = ref(null);
let pollInterval = null;

// wTPIX ABI (เฉพาะ burn)
const WTPIX_ABI = ['function burn(uint256 amount) external', 'function balanceOf(address) view returns (uint256)'];

const chains = computed(() => {
    if (direction.value === 'bsc_to_tpix') {
        return { from: { id: 56, name: 'BSC', symbol: 'wTPIX', color: '#F3BA2F' }, to: { id: 4289, name: 'TPIX Chain', symbol: 'TPIX', color: '#06B6D4' } };
    }
    return { from: { id: 4289, name: 'TPIX Chain', symbol: 'TPIX', color: '#06B6D4' }, to: { id: 56, name: 'BSC', symbol: 'wTPIX', color: '#F3BA2F' } };
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

const canBridge = computed(() => {
    if (!walletStore.isConnected || !amount.value || isBridging.value) return false;
    const amt = parseFloat(amount.value);
    return amt >= (bridgeInfo.value?.min_amount || 10) && amt <= (bridgeInfo.value?.max_amount || 10000000);
});

function toggleDirection() { direction.value = direction.value === 'bsc_to_tpix' ? 'tpix_to_bsc' : 'bsc_to_tpix'; }

async function fetchInfo() { try { const { data } = await axios.get('/api/v1/bridge/info'); bridgeInfo.value = data.data; } catch {} }
async function fetchHistory() {
    if (!walletStore.address) return;
    try { const { data } = await axios.get(`/api/v1/bridge/history/${walletStore.address}`); history.value = data.data || []; } catch { history.value = []; }
}

// ================================================================
//  BRIDGE EXECUTION — sign tx ด้วย wallet แล้ว auto-submit
// ================================================================
async function executeBridge() {
    if (!canBridge.value) return;
    isBridging.value = true;
    message.value = null;
    activeBridgeId.value = null;

    try {
        const amountValue = amount.value;

        if (direction.value === 'bsc_to_tpix') {
            await executeBscToTpix(amountValue);
        } else {
            await executeTpixToBsc(amountValue);
        }
    } catch (err) {
        const errMsg = err?.reason || err?.message || 'Bridge failed';
        // User rejected → ไม่แสดง error
        if (errMsg.includes('user rejected') || errMsg.includes('ACTION_REJECTED')) {
            message.value = { type: 'info', text: 'Transaction cancelled.' };
        } else {
            message.value = { type: 'error', text: errMsg };
        }
    } finally {
        isBridging.value = false;
        bridgeStep.value = '';
    }
}

/**
 * BSC → TPIX: burn wTPIX on BSC
 * 1. Switch to BSC chain
 * 2. Call wTPIX.burn(amount)
 * 3. Submit tx_hash to backend
 */
async function executeBscToTpix(amountStr) {
    if (!walletStore.signer) throw new Error('Wallet not connected');
    const wtpixAddress = bridgeInfo.value?.wtpix_bsc_address;
    if (!wtpixAddress) throw new Error('wTPIX contract address not configured');

    // Switch to BSC if needed
    bridgeStep.value = 'signing';
    if (walletStore.chainId !== 56) {
        await walletStore.switchChain(56);
    }

    const amountWei = ethers.parseEther(amountStr);
    const wtpix = new ethers.Contract(wtpixAddress, WTPIX_ABI, walletStore.signer);

    // Check balance
    const balance = await wtpix.balanceOf(walletStore.address);
    if (balance < amountWei) {
        throw new Error(`Insufficient wTPIX balance. Have: ${ethers.formatEther(balance)}`);
    }

    // Sign burn transaction
    const tx = await wtpix.burn(amountWei);
    bridgeStep.value = 'confirming';

    // Wait for confirmation
    const receipt = await tx.wait(1);
    bridgeStep.value = 'submitting';

    // Submit to backend
    await submitBridge(amountStr, 'bsc_to_tpix', receipt.hash);
}

/**
 * TPIX → BSC: send native TPIX to treasury
 * 1. Switch to TPIX Chain
 * 2. Send native TPIX to treasury address
 * 3. Submit tx_hash to backend
 */
async function executeTpixToBsc(amountStr) {
    if (!walletStore.signer) throw new Error('Wallet not connected');
    const treasuryAddress = bridgeInfo.value?.treasury_address;
    if (!treasuryAddress) throw new Error('Treasury address not configured');

    bridgeStep.value = 'signing';
    if (walletStore.chainId !== 4289) {
        await walletStore.switchChain(4289);
    }

    const amountWei = ethers.parseEther(amountStr);

    // Send native TPIX to treasury (gasless on TPIX Chain)
    const tx = await walletStore.signer.sendTransaction({
        to: treasuryAddress,
        value: amountWei,
        gasPrice: 0,
    });
    bridgeStep.value = 'confirming';

    const receipt = await tx.wait(1);
    bridgeStep.value = 'submitting';

    await submitBridge(amountStr, 'tpix_to_bsc', receipt.hash);
}

/**
 * Submit to backend API → dispatch ProcessBridgeJob
 */
async function submitBridge(amountStr, dir, txHash) {
    const { data } = await axios.post('/api/v1/bridge/initiate', {
        wallet_address: walletStore.address,
        amount: amountStr,
        direction: dir,
        tx_hash: txHash,
    });

    if (data.success) {
        activeBridgeId.value = data.data.id;
        bridgeStep.value = 'processing';
        message.value = { type: 'success', text: `Bridge submitted! TX: ${txHash.slice(0, 16)}... — Processing...` };
        amount.value = '';
        fetchHistory();
        startPolling(data.data.id);
    } else {
        throw new Error(data.error?.message || 'Submit failed');
    }
}

// ================================================================
//  STATUS POLLING — ตรวจสถานะทุก 10 วินาที
// ================================================================
function startPolling(bridgeId) {
    stopPolling();
    pollInterval = setInterval(async () => {
        try {
            const { data } = await axios.get(`/api/v1/bridge/status/${bridgeId}`);
            const status = data.data?.status;
            if (status === 'completed') {
                message.value = { type: 'success', text: `✅ Bridge completed! Target TX: ${data.data.target_tx_hash ? data.data.target_tx_hash.slice(0, 16) + '...' : 'confirmed'}` };
                stopPolling();
                fetchHistory();
                bridgeStep.value = '';
            } else if (status === 'failed') {
                message.value = { type: 'error', text: `Bridge failed: ${data.data.error_message || 'Unknown error'}` };
                stopPolling();
                bridgeStep.value = '';
            }
        } catch {}
    }, 10_000);
}

function stopPolling() { if (pollInterval) { clearInterval(pollInterval); pollInterval = null; } }

async function retryBridge(txId) {
    try {
        await axios.post(`/api/v1/bridge/retry/${txId}`, { wallet_address: walletStore.address });
        message.value = { type: 'success', text: 'Retry dispatched — processing...' };
        startPolling(txId);
        fetchHistory();
    } catch (err) {
        message.value = { type: 'error', text: err.response?.data?.error?.message || 'Retry failed' };
    }
}

const statusCfg = { pending: { l: 'Pending', c: 'bg-yellow-500/20 text-yellow-400' }, processing: { l: 'Processing', c: 'bg-primary-500/20 text-primary-400' }, completed: { l: 'Completed', c: 'bg-trading-green/20 text-trading-green' }, failed: { l: 'Failed', c: 'bg-trading-red/20 text-trading-red' } };
const stepLabels = { signing: '✍️ Signing transaction...', confirming: '⏳ Waiting for confirmation...', submitting: '📤 Submitting to bridge...', processing: '🔄 Processing bridge transfer...' };
function shortHash(h) { return h ? h.slice(0, 10) + '...' + h.slice(-6) : '-'; }
function timeAgo(d) { const s = Math.floor((Date.now() - new Date(d)) / 1000); if (s < 60) return s + 's ago'; if (s < 3600) return Math.floor(s / 60) + 'm ago'; if (s < 86400) return Math.floor(s / 3600) + 'h ago'; return Math.floor(s / 86400) + 'd ago'; }

onMounted(() => { fetchInfo(); fetchHistory(); });
onUnmounted(() => { stopPolling(); });
</script>

<template>
    <Head title="Bridge — TPIX Chain ↔ BSC" />
    <AppLayout>
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">🌉 {{ t('bridge.title') }}</h1>
                <p class="text-dark-400">{{ t('bridge.subtitle') }}</p>
            </div>

            <!-- Message -->
            <div v-if="message" :class="['px-4 py-3 rounded-xl text-sm', message.type === 'success' ? 'bg-trading-green/20 text-trading-green' : message.type === 'info' ? 'bg-primary-500/20 text-primary-400' : 'bg-trading-red/20 text-trading-red']">{{ message.text }}</div>

            <!-- Progress Step -->
            <div v-if="bridgeStep && stepLabels[bridgeStep]" class="glass-card flex items-center gap-3 animate-pulse">
                <div class="w-8 h-8 rounded-full bg-primary-500/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-primary-400 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                </div>
                <p class="text-primary-400 text-sm font-medium">{{ stepLabels[bridgeStep] }}</p>
            </div>

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
                        <input v-model="amount" type="number" placeholder="0.00" :min="bridgeInfo?.min_amount || 10" class="bg-transparent text-right text-white text-2xl font-mono w-40 outline-none placeholder-dark-600" :disabled="isBridging" />
                    </div>
                </div>

                <!-- Toggle -->
                <div class="flex justify-center -my-2">
                    <button @click="toggleDirection" :disabled="isBridging" class="w-10 h-10 rounded-full bg-primary-500/20 border border-primary-500/30 flex items-center justify-center hover:bg-primary-500/30 transition-all hover:rotate-180 duration-300 disabled:opacity-50">
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

                <!-- Fee Info -->
                <div class="bg-dark-700/50 rounded-xl p-3 space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-dark-400">Fee (0.1%)</span><span class="text-white">{{ fee }} TPIX</span></div>
                    <div class="flex justify-between"><span class="text-dark-400">You will receive</span><span class="text-trading-green font-semibold">{{ receiveAmount }} {{ chains.to.symbol }}</span></div>
                    <div class="flex justify-between"><span class="text-dark-400">Estimated time</span><span class="text-white">2-5 min</span></div>
                </div>

                <!-- Bridge Button -->
                <button
                    @click="walletStore.isConnected ? executeBridge() : walletStore.openConnectModal()"
                    :disabled="walletStore.isConnected && !canBridge"
                    class="w-full py-4 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-bold text-lg hover:shadow-lg hover:shadow-primary-500/20 disabled:opacity-50 transition-all">
                    {{ isBridging ? stepLabels[bridgeStep] || '🔄 Processing...' : walletStore.isConnected ? '🌉 Bridge Now' : '🔗 Connect Wallet' }}
                </button>
            </div>

            <!-- How it works -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">How it works</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div v-for="(step, i) in direction === 'bsc_to_tpix' ? ['Burn wTPIX on BSC', 'Backend verifies', 'Send native TPIX', 'Receive on TPIX Chain'] : ['Send TPIX to treasury', 'Backend verifies', 'Mint wTPIX on BSC', 'Receive wTPIX']" :key="i" class="text-center">
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
                    <div v-for="tx in history" :key="tx.id" class="bg-dark-700/50 rounded-xl p-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-white text-sm font-medium">{{ parseFloat(tx.amount).toFixed(2) }} TPIX</span>
                                    <span class="text-dark-500 text-xs">{{ tx.direction === 'bsc_to_tpix' ? 'BSC → TPIX' : 'TPIX → BSC' }}</span>
                                </div>
                                <p class="text-dark-500 text-xs font-mono mt-1">{{ shortHash(tx.source_tx_hash) }}</p>
                                <p v-if="tx.target_tx_hash" class="text-trading-green text-xs font-mono">→ {{ shortHash(tx.target_tx_hash) }}</p>
                            </div>
                            <div class="text-right">
                                <span :class="['px-2 py-0.5 rounded-lg text-xs', statusCfg[tx.status]?.c]">{{ statusCfg[tx.status]?.l }}</span>
                                <p class="text-dark-500 text-xs mt-1">{{ timeAgo(tx.created_at) }}</p>
                                <button v-if="tx.status === 'failed'" @click="retryBridge(tx.id)" class="text-primary-400 text-xs hover:underline mt-1">Retry</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
