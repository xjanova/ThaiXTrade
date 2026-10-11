<script setup>
/**
 * TPIX TRADE — สะพาน TPIX (เชน 4289) ⇄ wTPIX (BEP-20 บน BSC)
 *
 * TPIX → BSC: เรียก TPIXBridgeVault.lock() บนเชน 4289 (ไม่ใช่โอนตรง — โอนตรงต้องให้แก๊สเกิน 21,000
 *             กระเป๋า/กระดานเทรดที่ฟิกซ์ 21,000 จะ revert) → เซิร์ฟเวอร์ mint wTPIX ให้ที่อยู่เดียวกันบน BSC
 * BSC → TPIX: เรียก wTPIX.burn() บน BSC (ไม่ต้อง approve) → เซิร์ฟเวอร์ปล่อย TPIX จากคลังให้ที่อยู่เดียวกัน
 *
 * หน้านี้ไม่ได้ "แจ้ง" เซิร์ฟเวอร์ — เซิร์ฟเวอร์อ่านจากเชนเอง หน้าเว็บแค่ถามสถานะด้วย tx hash
 * ปิดหน้าไปกลางทางก็ไม่เสียอะไร
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';
import { Contract, JsonRpcProvider } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { explorerTx, friendlyWalletError } from '@/utils/bridge';
import {
    WTPIX_VAULT_ABI,
    WTPIX_TOKEN_ABI,
    parseTpixAmount,
    formatTpix,
    receiveWei,
    checkWtpixAmount,
    wtpixStateBadge,
    loadWtpixTracked,
    saveWtpixTracked,
    addWtpixTracked,
} from '@/utils/wtpixBridge';

const { t } = useTranslation();
const walletStore = useWalletStore();

const info = ref(null);
const loadError = ref(false);
const tab = ref('to_bsc');
const notice = ref(null);
const busy = ref(false);
const busyLabel = ref('');

const isOpen = computed(() => info.value?.enabled === true);
const tpixChainId = computed(() => info.value?.tpix?.chain_id ?? 4289);
const bscChainId = computed(() => info.value?.bsc?.chain_id ?? 56);
const bscName = computed(() => info.value?.bsc?.name ?? 'BNB Smart Chain');
const limits = computed(() => info.value?.limits ?? {});
// เพดานของ "สัญญาปลายทาง" ของทิศที่เลือก — ล็อก = minter บน BSC · เผา = คลังบนเชน TPIX
const destCaps = computed(() => (tab.value === 'to_tpix' ? info.value?.vault : info.value?.minter) ?? {});

async function fetchInfo() {
    try {
        const { data } = await axios.get('/api/v1/bridge/info', { params: { asset: 'tpix' } });
        info.value = data.data;
        loadError.value = false;
    } catch {
        loadError.value = true;
    }
}

/** สลับเชนแล้วยืนยันว่าสลับจริง — ไม่งั้นธุรกรรมถัดไปจะไปเกิดบนเชนผิด */
async function ensureChain(chainId) {
    if (walletStore.chainId === chainId) return;
    await walletStore.switchChain(chainId);
    if (walletStore.chainId !== chainId) throw new Error('SWITCH_FAILED');
}

// อ่านยอดผ่าน RPC ของแต่ละเชนตรง ๆ — ได้ยอดแม้กระเป๋าอยู่คนละเชน
const readers = {};
function reader(chainId, url) {
    if (!url) return null;
    readers[chainId] ??= new JsonRpcProvider(url, chainId, { staticNetwork: true });
    return readers[chainId];
}

// ── TPIX → wTPIX ─────────────────────────────────────────────────────────
const lockAmount = ref('');
const tpixBalance = ref(null);
const ackLock = ref(false);
const confirmingLock = ref(false);

const lockWei = computed(() => parseTpixAmount(lockAmount.value));
const lockCheck = computed(() => (lockAmount.value === '' ? { error: null, warning: null } : checkWtpixAmount(lockWei.value, 'to_bsc', info.value)));
const lockReceive = computed(() => (lockWei.value === null ? 0n : receiveWei(lockWei.value, limits.value.lock_fee)));
const lockInsufficient = computed(() => tpixBalance.value !== null && lockWei.value !== null && lockWei.value > tpixBalance.value);
const canLock = computed(() => isOpen.value && walletStore.isConnected && !busy.value && ackLock.value
    && lockWei.value !== null && !lockCheck.value.error && !lockInsufficient.value && !!info.value?.tpix?.vault_address);

async function refreshTpixBalance() {
    tpixBalance.value = null;
    const provider = reader(tpixChainId.value, info.value?.tpix?.rpc);
    if (!walletStore.address || !provider) return;
    try {
        tpixBalance.value = await provider.getBalance(walletStore.address);
    } catch {
        tpixBalance.value = null; // อ่านไม่ได้ ≠ ศูนย์ — ไม่บล็อกปุ่ม ให้กระเป๋าเป็นคนตัดสิน
    }
}

function askLock() {
    if (canLock.value) confirmingLock.value = true;
}

async function submitLock() {
    confirmingLock.value = false;
    if (!canLock.value) return;
    busy.value = true;
    notice.value = null;

    try {
        busyLabel.value = t('bridge.waitingWallet');
        await ensureChain(tpixChainId.value);

        const vault = new Contract(info.value.tpix.vault_address, WTPIX_VAULT_ABI, walletStore.signer);
        // lock() ไม่ใช่โอนตรง: กระเป๋าประมาณแก๊สให้เอง (receive() ต้องการเกิน 21,000) · แก๊สเชน TPIX = 0
        const tx = await vault.lock({ value: lockWei.value, gasPrice: 0 });

        tracked.value = addWtpixTracked(tracked.value, { direction: 'to_bsc', chainId: tpixChainId.value, txHash: tx.hash });
        saveWtpixTracked(tracked.value);
        lockAmount.value = '';
        notice.value = { type: 'success', text: t('bridge.wtpix.sentNotice') };
        pollOnce();
    } catch (err) {
        notice.value = { type: 'error', text: err?.message === 'SWITCH_FAILED' ? t('bridge.switchFailed') : t(friendlyWalletError(err)) };
    } finally {
        busy.value = false;
        busyLabel.value = '';
        refreshTpixBalance();
    }
}

// ── wTPIX → TPIX ─────────────────────────────────────────────────────────
const burnAmount = ref('');
const wtpixBalance = ref(null);
const ackBurn = ref(false);
const confirmingBurn = ref(false);

const burnWei = computed(() => parseTpixAmount(burnAmount.value));
const burnCheck = computed(() => (burnAmount.value === '' ? { error: null, warning: null } : checkWtpixAmount(burnWei.value, 'to_tpix', info.value)));
const burnReceive = computed(() => (burnWei.value === null ? 0n : receiveWei(burnWei.value, limits.value.burn_fee)));
const burnInsufficient = computed(() => wtpixBalance.value !== null && burnWei.value !== null && burnWei.value > wtpixBalance.value);
const canBurn = computed(() => isOpen.value && walletStore.isConnected && !busy.value && ackBurn.value
    && burnWei.value !== null && !burnCheck.value.error && !burnInsufficient.value && !!info.value?.bsc?.wtpix_address);

async function refreshWtpixBalance() {
    wtpixBalance.value = null;
    const provider = reader(bscChainId.value, info.value?.bsc?.rpc);
    if (!walletStore.address || !provider || !info.value?.bsc?.wtpix_address) return;
    try {
        const token = new Contract(info.value.bsc.wtpix_address, WTPIX_TOKEN_ABI, provider);
        wtpixBalance.value = await token.balanceOf(walletStore.address);
    } catch {
        wtpixBalance.value = null;
    }
}

function askBurn() {
    if (canBurn.value) confirmingBurn.value = true;
}

async function submitBurn() {
    confirmingBurn.value = false;
    if (!canBurn.value) return;
    busy.value = true;
    notice.value = null;

    try {
        busyLabel.value = t('bridge.waitingWallet');
        await ensureChain(bscChainId.value);

        // burn() เผาของตัวเอง — ไม่ต้อง approve · ผู้รับ TPIX = ที่อยู่ที่เผา (สัญญาไม่มีช่องให้เลือกผู้รับ)
        const token = new Contract(info.value.bsc.wtpix_address, WTPIX_TOKEN_ABI, walletStore.signer);
        const tx = await token.burn(burnWei.value);

        tracked.value = addWtpixTracked(tracked.value, { direction: 'to_tpix', chainId: bscChainId.value, txHash: tx.hash });
        saveWtpixTracked(tracked.value);
        burnAmount.value = '';
        notice.value = { type: 'success', text: t('bridge.wtpix.sentNotice') };
        pollOnce();
    } catch (err) {
        notice.value = { type: 'error', text: err?.message === 'SWITCH_FAILED' || err?.message === 'EMBEDDED_WALLET_SINGLE_CHAIN'
            ? t('bridge.switchFailed') : t(friendlyWalletError(err)) };
    } finally {
        busy.value = false;
        busyLabel.value = '';
        refreshWtpixBalance();
    }
}

// ── เพิ่ม wTPIX ลงกระเป๋า ────────────────────────────────────────────────
const addingToken = ref(false);

async function addWtpix() {
    if (addingToken.value || !info.value?.bsc?.wtpix_address) return;
    addingToken.value = true;
    try {
        await ensureChain(bscChainId.value);
        const ok = await walletStore.watchAsset({
            address: info.value.bsc.wtpix_address,
            symbol: info.value.token?.symbol ?? 'wTPIX',
            decimals: info.value.token?.decimals ?? 18,
            image: info.value.token?.logo,
        });
        notice.value = ok ? { type: 'success', text: t('bridge.wtpix.addWtpixDone') } : { type: 'error', text: t('bridge.wtpix.addWtpixFailed') };
    } catch {
        notice.value = { type: 'error', text: t('bridge.wtpix.addWtpixFailed') };
    } finally {
        addingToken.value = false;
    }
}

// ── ติดตาม ────────────────────────────────────────────────────────────────
const tracked = ref(loadWtpixTracked());
const statuses = ref({});
const manualDirection = ref('to_bsc');
const manualHash = ref('');
let pollTimer = null;
let polling = false;

const trackKey = (e) => `${e.direction}:${e.chainId}:${e.txHash}`;

async function pollOnce() {
    if (polling) return;
    polling = true;
    try {
        for (const entry of tracked.value) {
            const key = trackKey(entry);
            if (statuses.value[key] && wtpixStateBadge(statuses.value[key].state).done) continue;
            try {
                const { data } = await axios.get(`/api/v1/bridge/status/${entry.chainId}/${entry.txHash}`, { params: { asset: 'tpix' } });
                statuses.value = { ...statuses.value, [key]: data.data };
            } catch { /* ลองใหม่รอบหน้า */ }
        }
    } finally {
        polling = false;
    }
}

function addManual() {
    const hash = manualHash.value.trim();
    if (!/^0x[0-9a-fA-F]{64}$/.test(hash)) return;
    const chainId = manualDirection.value === 'to_bsc' ? tpixChainId.value : bscChainId.value;
    tracked.value = addWtpixTracked(tracked.value, { direction: manualDirection.value, chainId, txHash: hash });
    saveWtpixTracked(tracked.value);
    manualHash.value = '';
    pollOnce();
}

function removeTracked(entry) {
    tracked.value = tracked.value.filter((e) => trackKey(e) !== trackKey(entry));
    saveWtpixTracked(tracked.value);
}

const explorerFor = (chainId) => (chainId === tpixChainId.value ? info.value?.tpix?.explorer : info.value?.bsc?.explorer);
const hasMinted = computed(() => Object.values(statuses.value).some((s) => s?.state === 'minted'));

const toneClass = {
    success: 'bg-trading-green/15 text-trading-green border-trading-green/30',
    info: 'bg-primary-500/15 text-primary-300 border-primary-500/30',
    warning: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    danger: 'bg-trading-red/15 text-trading-red border-trading-red/30',
    muted: 'bg-white/5 text-dark-300 border-white/10',
};

// ── ประวัติของกระเป๋า ────────────────────────────────────────────────────
const history = ref([]);

async function fetchHistory() {
    if (!walletStore.address) {
        history.value = [];
        return;
    }
    try {
        const { data } = await axios.get(`/api/v1/bridge/history/${walletStore.address}`, { params: { asset: 'tpix' } });
        history.value = data.data?.transfers ?? [];
    } catch {
        /* ประวัติเป็นของเสริม ไม่โชว์ error ดิบ */
    }
}

const short = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-6)}` : '—');
const fmt = (wei) => (wei === null || wei === undefined ? '—' : formatTpix(wei));

onMounted(async () => {
    await fetchInfo();
    pollOnce();
    fetchHistory();
    refreshTpixBalance();
    refreshWtpixBalance();
    pollTimer = setInterval(pollOnce, 10_000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
});

// สลับบัญชีในกระเป๋า — ยอด/ประวัติต้องตามคนใหม่ ไม่ค้างของคนเก่า และต้องติ๊กยอมรับใหม่
watch(() => walletStore.address, () => {
    ackLock.value = false;
    ackBurn.value = false;
    fetchHistory();
    refreshTpixBalance();
    refreshWtpixBalance();
});
</script>

<template>
    <div class="space-y-6">
        <div v-if="loadError" class="glass-card text-sm text-dark-300 text-center">{{ t('bridge.wtpix.loadFailed') }}</div>

        <!-- ยังไม่เปิด / หยุดชั่วคราว -->
        <div v-if="info && !isOpen" class="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex gap-3" data-test="wtpix-closed">
            <span class="text-lg shrink-0">🚧</span>
            <div>
                <p class="text-sm font-semibold text-amber-300">{{ t('bridge.wtpix.notReady') }}</p>
                <p class="text-xs text-dark-300 leading-relaxed mt-1">{{ info.installed ? t('bridge.wtpix.pausedBody') : t('bridge.wtpix.notReadyBody') }}</p>
            </div>
        </div>

        <div v-if="notice" :class="['px-4 py-3 rounded-xl text-sm border', notice.type === 'success' ? 'bg-trading-green/10 text-trading-green border-trading-green/30' : 'bg-trading-red/10 text-trading-red border-trading-red/30']">
            {{ notice.text }}
        </div>

        <div class="flex gap-2 p-1 rounded-xl bg-dark-800/60 border border-white/10">
            <button v-for="k in ['to_bsc', 'to_tpix', 'track']" :key="k" type="button" @click="tab = k" :data-test="`wtpix-tab-${k}`"
                :class="['flex-1 py-2 rounded-lg text-sm font-medium transition-all', tab === k ? 'bg-primary-500/20 text-primary-300' : 'text-dark-400 hover:text-white']">
                {{ k === 'to_bsc' ? t('bridge.wtpix.tabToBsc') : k === 'to_tpix' ? t('bridge.wtpix.tabToTpix') : t('bridge.tabTrack') }}
            </button>
        </div>

        <!-- คำเตือนตัวใหญ่ — ความผิดพลาดแบบนี้เรียกคืนไม่ได้ -->
        <div v-if="tab !== 'track'" class="rounded-xl border-2 border-trading-red/40 bg-trading-red/[0.06] p-4">
            <p class="text-sm font-bold text-trading-red mb-2">⚠️ {{ t('bridge.warnTitle') }}</p>
            <ul class="space-y-1.5 text-xs text-dark-200 leading-relaxed list-disc pl-4">
                <li><strong class="text-white">{{ t('bridge.wtpix.warnNoExchange') }}</strong></li>
                <li>{{ t('bridge.wtpix.warnSameAddress') }}</li>
                <li v-if="tab === 'to_bsc'">{{ t('bridge.wtpix.warnUseLock') }}</li>
                <li>{{ t('bridge.wtpix.warnCaps', { perTx: destCaps.max_per_tx ?? '100000', daily: destCaps.daily_cap ?? '500000' }) }}</li>
                <li v-if="tab === 'to_bsc' && walletStore.isEmbedded">{{ t('bridge.wtpix.warnEmbedded') }}</li>
            </ul>
        </div>

        <!-- ═══════════════ TPIX → wTPIX ═══════════════ -->
        <div v-show="tab === 'to_bsc'" class="glass-card space-y-4">
            <div class="flex items-center justify-between text-xs">
                <span class="text-dark-400">{{ t('bridge.wtpix.from') }}: <b class="text-white">TPIX Chain</b> → {{ t('bridge.wtpix.to') }}: <b class="text-white">{{ bscName }}</b></span>
            </div>
            <div>
                <div class="flex justify-between text-xs text-dark-400 mb-1">
                    <label>{{ t('bridge.wtpix.amountTpix') }}</label>
                    <button v-if="tpixBalance !== null" type="button" class="hover:text-white" @click="lockAmount = fmt(tpixBalance)">
                        {{ t('bridge.balance') }}: {{ fmt(tpixBalance) }} TPIX
                    </button>
                </div>
                <input v-model.trim="lockAmount" type="text" inputmode="decimal" placeholder="0.0" :disabled="busy" data-test="wtpix-lock-amount"
                    class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white text-xl font-mono outline-none focus:border-primary-500/50" />
                <p v-if="lockCheck.error" class="text-xs text-trading-red mt-1">{{ t(lockCheck.error, lockCheck.params) }}</p>
                <p v-else-if="lockCheck.warning" class="text-xs text-amber-300 mt-1">{{ t(lockCheck.warning, lockCheck.warningParams) }}</p>
                <p v-else-if="lockInsufficient" class="text-xs text-trading-red mt-1">{{ t('bridge.errInsufficient') }}</p>
            </div>

            <div class="rounded-xl bg-dark-800/40 p-3 space-y-1 text-xs">
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.wtpix.fee') }}</span><span class="text-white">{{ limits.lock_fee ?? '—' }} TPIX</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.youReceive') }}</span><span class="text-trading-green font-semibold" data-test="wtpix-lock-receive">{{ lockWei !== null && !lockCheck.error ? fmt(lockReceive) : '—' }} wTPIX · {{ bscName }}</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.eta') }}</span><span class="text-white">{{ t('bridge.etaMinutes', { minutes: info?.bsc?.eta_minutes ?? 2 }) }}</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.minDeposit') }} / {{ t('bridge.maxPerTx') }}</span><span class="text-white">{{ limits.min_lock ?? '—' }} / {{ limits.max_lock_per_tx ?? '—' }} TPIX</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.dailyPerAddress') }}</span><span class="text-white">{{ limits.max_lock_per_address_daily ?? '—' }} TPIX</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.wtpix.remainingToday') }}</span><span class="text-white">{{ info?.minter?.remaining_today ?? '—' }} wTPIX</span></div>
            </div>

            <label class="flex items-start gap-2 text-xs text-dark-200 cursor-pointer">
                <input v-model="ackLock" type="checkbox" class="mt-0.5" data-test="wtpix-ack-lock" />
                <span>{{ t('bridge.wtpix.ackLock') }}</span>
            </label>

            <button v-if="!walletStore.isConnected" type="button" @click="walletStore.openConnectModal()"
                class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500">
                🔗 {{ t('bridge.connectWallet') }}
            </button>
            <button v-else type="button" :disabled="!canLock" @click="askLock" data-test="wtpix-lock-button"
                class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500 disabled:opacity-50">
                {{ busy ? (busyLabel || t('bridge.sending')) : t('bridge.wtpix.lockButton', { chain: bscName }) }}
            </button>

            <ol class="space-y-2 text-xs text-dark-300 pt-2 border-t border-white/5">
                <li v-for="(k, i) in ['howToBsc1', 'howToBsc2', 'howToBsc3']" :key="k" class="flex gap-2">
                    <span class="w-5 h-5 shrink-0 rounded-full bg-primary-500/20 text-primary-300 flex items-center justify-center text-[10px] font-bold">{{ i + 1 }}</span>
                    <span>{{ t(`bridge.wtpix.${k}`, { chain: bscName }) }}</span>
                </li>
            </ol>
        </div>

        <!-- ═══════════════ wTPIX → TPIX ═══════════════ -->
        <div v-show="tab === 'to_tpix'" class="glass-card space-y-4">
            <div class="flex items-center justify-between text-xs">
                <span class="text-dark-400">{{ t('bridge.wtpix.from') }}: <b class="text-white">{{ bscName }}</b> → {{ t('bridge.wtpix.to') }}: <b class="text-white">TPIX Chain</b></span>
            </div>
            <div>
                <div class="flex justify-between text-xs text-dark-400 mb-1">
                    <label>{{ t('bridge.wtpix.amountWtpix') }}</label>
                    <button v-if="wtpixBalance !== null" type="button" class="hover:text-white" @click="burnAmount = fmt(wtpixBalance)">
                        {{ t('bridge.balance') }}: {{ fmt(wtpixBalance) }} wTPIX
                    </button>
                </div>
                <input v-model.trim="burnAmount" type="text" inputmode="decimal" placeholder="0.0" :disabled="busy" data-test="wtpix-burn-amount"
                    class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white text-xl font-mono outline-none focus:border-primary-500/50" />
                <p v-if="burnCheck.error" class="text-xs text-trading-red mt-1">{{ t(burnCheck.error, burnCheck.params) }}</p>
                <p v-else-if="burnCheck.warning" class="text-xs text-amber-300 mt-1">{{ t(burnCheck.warning, burnCheck.warningParams) }}</p>
                <p v-else-if="burnInsufficient" class="text-xs text-trading-red mt-1">{{ t('bridge.errInsufficient') }}</p>
            </div>

            <div class="rounded-xl bg-dark-800/40 p-3 space-y-1 text-xs">
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.wtpix.fee') }}</span><span class="text-white">{{ limits.burn_fee ?? '—' }} TPIX</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.youReceive') }}</span><span class="text-trading-green font-semibold" data-test="wtpix-burn-receive">{{ burnWei !== null && !burnCheck.error ? fmt(burnReceive) : '—' }} TPIX · TPIX Chain</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.eta') }}</span><span class="text-white">{{ t('bridge.etaMinutes', { minutes: info?.tpix?.eta_minutes ?? 3 }) }}</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.minDeposit') }} / {{ t('bridge.maxPerTx') }}</span><span class="text-white">{{ limits.min_burn ?? '—' }} / {{ limits.max_burn_per_tx ?? '—' }} wTPIX</span></div>
                <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.wtpix.remainingToday') }}</span><span class="text-white">{{ info?.vault?.remaining_today ?? '—' }} TPIX</span></div>
            </div>

            <label class="flex items-start gap-2 text-xs text-dark-200 cursor-pointer">
                <input v-model="ackBurn" type="checkbox" class="mt-0.5" data-test="wtpix-ack-burn" />
                <span>{{ t('bridge.wtpix.ackBurn') }}</span>
            </label>

            <button v-if="!walletStore.isConnected" type="button" @click="walletStore.openConnectModal()"
                class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500">
                🔗 {{ t('bridge.connectWallet') }}
            </button>
            <button v-else type="button" :disabled="!canBurn" @click="askBurn" data-test="wtpix-burn-button"
                class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500 disabled:opacity-50">
                {{ busy ? (busyLabel || t('bridge.sending')) : t('bridge.wtpix.burnButton') }}
            </button>

            <ol class="space-y-2 text-xs text-dark-300 pt-2 border-t border-white/5">
                <li v-for="(k, i) in ['howToTpix1', 'howToTpix2', 'howToTpix3']" :key="k" class="flex gap-2">
                    <span class="w-5 h-5 shrink-0 rounded-full bg-primary-500/20 text-primary-300 flex items-center justify-center text-[10px] font-bold">{{ i + 1 }}</span>
                    <span>{{ t(`bridge.wtpix.${k}`, { chain: bscName }) }}</span>
                </li>
            </ol>
        </div>

        <!-- ═══════════════ ติดตามด้วย tx hash ═══════════════ -->
        <div v-show="tab === 'track'" class="glass-card space-y-3">
            <h3 class="text-sm font-semibold text-white">{{ t('bridge.trackTitle') }}</h3>
            <p class="text-xs text-dark-400">{{ t('bridge.wtpix.trackHint') }}</p>
            <div class="flex flex-col sm:flex-row gap-2">
                <select v-model="manualDirection" class="bg-dark-800 border border-white/10 rounded-xl px-3 py-2 text-sm text-white">
                    <option value="to_bsc">{{ t('bridge.wtpix.trackLock') }}</option>
                    <option value="to_tpix">{{ t('bridge.wtpix.trackBurn', { chain: bscName }) }}</option>
                </select>
                <input v-model.trim="manualHash" type="text" :placeholder="t('bridge.trackTxHash')" spellcheck="false"
                    class="flex-1 min-w-0 bg-dark-800 border border-white/10 rounded-xl px-3 py-2 text-sm text-white font-mono" />
                <button type="button" @click="addManual" :disabled="!/^0x[0-9a-fA-F]{64}$/.test(manualHash)"
                    class="px-4 py-2 rounded-xl text-sm font-semibold bg-primary-500/20 text-primary-300 border border-primary-500/30 disabled:opacity-40">
                    {{ t('bridge.trackButton') }}
                </button>
            </div>
        </div>

        <!-- รายการที่กำลังติดตาม -->
        <div class="glass-card">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h3 class="text-sm font-semibold text-white">{{ t('bridge.tracked') }}</h3>
                <button v-if="walletStore.isConnected && info?.bsc?.wtpix_address" type="button" :disabled="addingToken" @click="addWtpix" data-test="wtpix-add-token"
                    :class="['px-3 py-1.5 rounded-lg text-xs font-semibold border disabled:opacity-40', hasMinted ? 'bg-trading-green/20 text-trading-green border-trading-green/30' : 'bg-white/5 text-dark-200 border-white/10']">
                    ➕ {{ t('bridge.wtpix.addWtpix') }}
                </button>
            </div>
            <p v-if="!tracked.length" class="text-xs text-dark-500">{{ t('bridge.noTracked') }}</p>
            <div v-else class="space-y-2">
                <div v-for="entry in tracked" :key="trackKey(entry)" class="rounded-xl bg-dark-800/50 border border-white/5 p-3 text-xs space-y-1.5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-white font-medium">{{ entry.direction === 'to_bsc' ? `TPIX → wTPIX (${bscName})` : `wTPIX (${bscName}) → TPIX` }}</p>
                            <a :href="explorerTx(explorerFor(entry.chainId), entry.txHash)" target="_blank" rel="noopener" class="font-mono text-dark-400 hover:text-primary-300">{{ short(entry.txHash) }}</a>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span v-if="statuses[trackKey(entry)]" :class="['px-2 py-0.5 rounded-md border', toneClass[wtpixStateBadge(statuses[trackKey(entry)].state).tone]]">
                                {{ t(wtpixStateBadge(statuses[trackKey(entry)].state).labelKey) }}
                            </span>
                            <button type="button" class="text-dark-500 hover:text-trading-red" @click="removeTracked(entry)">{{ t('bridge.remove') }}</button>
                        </div>
                    </div>
                    <template v-if="statuses[trackKey(entry)]">
                        <p v-if="statuses[trackKey(entry)].state === 'confirming'" class="text-dark-400">
                            {{ statuses[trackKey(entry)].final ? t('bridge.wtpix.finalSoon') : t('bridge.confirmations', { n: statuses[trackKey(entry)].confirmations ?? 0 }) }}
                            · {{ statuses[trackKey(entry)].amount }}
                        </p>
                        <div v-for="r in statuses[trackKey(entry)].transfers || []" :key="r.id" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="text-white">{{ r.receive_amount }} {{ r.direction === 'to_bsc' ? 'wTPIX' : 'TPIX' }}</span>
                            <a v-if="r.dest_url" :href="r.dest_url" target="_blank" rel="noopener" class="text-primary-300 hover:underline" data-test="wtpix-dest-link">
                                {{ r.direction === 'to_bsc' ? t('bridge.wtpix.viewMint', { chain: bscName }) : t('bridge.wtpix.viewRelease') }}
                            </a>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ประวัติ -->
        <div v-if="walletStore.isConnected" class="glass-card">
            <h3 class="text-sm font-semibold text-white mb-3">{{ t('bridge.history') }}</h3>
            <p v-if="!history.length" class="text-xs text-dark-500">{{ t('bridge.historyEmpty') }}</p>
            <div v-else class="space-y-1.5 text-xs">
                <div v-for="r in history" :key="r.id" class="flex justify-between gap-2 bg-dark-800/40 rounded-lg px-3 py-2">
                    <span class="text-white">{{ r.direction === 'to_bsc' ? 'TPIX → wTPIX' : 'wTPIX → TPIX' }} · {{ r.amount }}</span>
                    <span :class="['px-2 py-0.5 rounded-md border', toneClass[wtpixStateBadge(r.state).tone]]">{{ t(wtpixStateBadge(r.state).labelKey) }}</span>
                </div>
            </div>
        </div>

        <!-- ยืนยัน — ล็อก/เผาแล้วยกเลิกไม่ได้ -->
        <div v-if="confirmingLock || confirmingBurn" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
            @click.self="confirmingLock = false; confirmingBurn = false">
            <div class="w-full max-w-md glass-card space-y-4">
                <h3 class="text-lg font-bold text-white">{{ confirmingLock ? t('bridge.wtpix.confirmLockTitle') : t('bridge.wtpix.confirmBurnTitle') }}</h3>
                <p class="text-sm text-dark-200 break-words">
                    {{ confirmingLock
                        ? t('bridge.wtpix.confirmLockBody', { amount: lockAmount, receive: fmt(lockReceive), address: walletStore.address, chain: bscName })
                        : t('bridge.wtpix.confirmBurnBody', { amount: burnAmount, receive: fmt(burnReceive), address: walletStore.address, chain: bscName }) }}
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 rounded-xl text-sm bg-white/5 text-dark-200 border border-white/10" @click="confirmingLock = false; confirmingBurn = false">{{ t('bridge.cancel') }}</button>
                    <button type="button" class="px-4 py-2 rounded-xl text-sm font-semibold bg-primary-500 text-white" data-test="wtpix-confirm"
                        @click="confirmingLock ? submitLock() : submitBurn()">{{ t('bridge.confirm') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
