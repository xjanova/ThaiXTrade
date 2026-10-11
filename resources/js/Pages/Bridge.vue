<script setup>
/**
 * TPIX TRADE — สะพาน USDT หลายเชน → TPIX Chain
 *
 * ฝาก: ผู้ใช้โอน USDT (ERC-20 transfer ธรรมดา) จากกระเป๋าตัวเองเข้า vault บนเชนต้นทาง
 *      → ตัวสแกนบนเซิร์ฟเวอร์เห็นเมื่อบล็อก final → mint USDT_TPIX ให้ที่อยู่เดียวกันบนเชน 4289
 * ถอน: ผู้ใช้เรียก USDT_TPIX.bridgeBurn(จำนวน, เชนปลายทาง, ที่อยู่) บนเชน 4289
 *      → ระบบจ่าย USDT จริงบนเชนปลายทาง (หักค่าแก๊สคงที่ต่อเชน)
 *
 * หน้านี้ไม่ได้ "แจ้ง" เซิร์ฟเวอร์ว่าโอนแล้ว — เซิร์ฟเวอร์อ่านจากเชนเอง หน้าเว็บแค่ถามสถานะ
 * ปิดหน้าไปกลางทางก็ไม่เสียอะไร (สะพานเดิมต้องจอง/แนบ hash ถ้าหลุดกลางทางเงินค้าง)
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Contract, JsonRpcProvider } from 'ethers';
import AppLayout from '@/Layouts/AppLayout.vue';
import WtpixBridgePanel from '@/Components/Bridge/WtpixBridgePanel.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import {
    USDT_TPIX_DECIMALS,
    BRIDGE_ERC20_ABI,
    USDT_TPIX_ABI,
    parseAmountToUnits,
    formatUnitsPlain,
    usdtToUnits,
    withdrawReceiveUnits,
    checkDepositAmount,
    isValidEvmAddress,
    stateBadge,
    explorerTx,
    loadTracked,
    saveTracked,
    addTracked,
    friendlyWalletError,
} from '@/utils/bridge';

const { t } = useTranslation();
const walletStore = useWalletStore();

/*
 * สองสะพานในหน้าเดียว: USDT (หลายเชน → USDT_TPIX) | TPIX ⇄ wTPIX (BSC)
 * จำไว้ใน URL (?asset=tpix) ให้แชร์ลิงก์ตรงไปฝั่ง TPIX ได้ — ไม่โหลดหน้าใหม่ตอนสลับ
 */
function initialAsset() {
    try {
        return new URLSearchParams(window.location.search).get('asset') === 'tpix' ? 'tpix' : 'usdt';
    } catch {
        return 'usdt';
    }
}
const asset = ref(initialAsset());

function setAsset(next) {
    asset.value = next;
    try {
        const url = new URL(window.location.href);
        if (next === 'tpix') url.searchParams.set('asset', 'tpix');
        else url.searchParams.delete('asset');
        window.history.replaceState(window.history.state, '', url.toString());
    } catch { /* เบราว์เซอร์ไม่ให้แก้ URL ก็แค่ไม่จำ */ }
}

const info = ref(null);
const loadError = ref(false);
const tab = ref('deposit');
const notice = ref(null); // { type, text }
const busy = ref(false);
const busyLabel = ref('');

// ── ข้อมูลจากเซิร์ฟเวอร์ ─────────────────────────────────────────────────
const chains = computed(() => info.value?.chains ?? []);
const enabledChains = computed(() => chains.value.filter((c) => c.enabled));
const isOpen = computed(() => info.value?.enabled === true);
const tpixChainId = computed(() => info.value?.tpix?.chain_id ?? 4289);
const limits = computed(() => info.value?.limits ?? {});

async function fetchInfo() {
    try {
        const { data } = await axios.get('/api/v1/bridge/info');
        info.value = data.data;
        loadError.value = false;
        if (!selectedChainId.value && enabledChains.value.length) selectedChainId.value = enabledChains.value[0].chain_id;
        if (!targetChainId.value && enabledChains.value.length) targetChainId.value = enabledChains.value[0].chain_id;
    } catch {
        loadError.value = true;
    }
}

// ── ฝาก ──────────────────────────────────────────────────────────────────
const selectedChainId = ref(null);
const depositAmount = ref('');
const sourceBalance = ref(null); // BigInt หน่วยของเหรียญต้นทาง
const copiedKey = ref('');
// ต้องติ๊กยอมรับก่อนส่งทุกครั้งที่เปิดหน้า — โอนจากกระดานเทรดคือความผิดพลาดที่เรียกเงินคืนไม่ได้
const ackOwnWallet = ref(false);

const sourceChain = computed(() => chains.value.find((c) => c.chain_id === selectedChainId.value) ?? null);
const depositUnits = computed(() => (sourceChain.value ? parseAmountToUnits(depositAmount.value, sourceChain.value.usdt_decimals) : null));
// หน่วย 6 ทศนิยมสำหรับเทียบวงเงิน (เชน 18 ทศนิยมหารลง — กรอกได้แค่ 6 ตำแหน่งอยู่แล้ว เศษเป็นศูนย์)
const depositUnits6 = computed(() => {
    if (depositUnits.value === null || !sourceChain.value) return null;
    const diff = sourceChain.value.usdt_decimals - USDT_TPIX_DECIMALS;
    return diff >= 0 ? depositUnits.value / 10n ** BigInt(diff) : depositUnits.value * 10n ** BigInt(-diff);
});
const depositCheck = computed(() => (depositAmount.value === '' ? { error: null, warning: null } : checkDepositAmount(depositUnits6.value, limits.value)));
const onSourceChain = computed(() => walletStore.chainId === selectedChainId.value);
const insufficientSource = computed(() => sourceBalance.value !== null && depositUnits.value !== null && depositUnits.value > sourceBalance.value);
const canDeposit = computed(() => isOpen.value && sourceChain.value?.enabled && walletStore.isConnected && !busy.value && ackOwnWallet.value
    && depositUnits.value !== null && !depositCheck.value.error && !insufficientSource.value);

async function copy(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => { if (copiedKey.value === key) copiedKey.value = ''; }, 1500);
    } catch { /* คัดลอกไม่ได้ (http / สิทธิ์) — ผู้ใช้เลือกข้อความเองได้ */ }
}

/** สลับเชนแล้วยืนยันว่าสลับจริง — ไม่งั้นธุรกรรมถัดไปจะไปเกิดบนเชนผิด */
async function ensureChain(chainId) {
    if (walletStore.chainId === chainId) return;
    await walletStore.switchChain(chainId);
    if (walletStore.chainId !== chainId) throw new Error('SWITCH_FAILED');
}

async function refreshSourceBalance() {
    sourceBalance.value = null;
    if (!sourceChain.value || !walletStore.isConnected || !onSourceChain.value || !walletStore.provider) return;
    try {
        const token = new Contract(sourceChain.value.usdt_address, BRIDGE_ERC20_ABI, walletStore.provider);
        sourceBalance.value = await token.balanceOf(walletStore.address);
    } catch {
        sourceBalance.value = null; // อ่านไม่ได้ ≠ ศูนย์ — ไม่บล็อกปุ่ม ให้กระเป๋าเป็นคนตัดสิน
    }
}

async function switchToSource() {
    notice.value = null;
    try {
        await ensureChain(selectedChainId.value);
        await refreshSourceBalance();
    } catch {
        notice.value = { type: 'error', text: t('bridge.switchFailed') };
    }
}

async function submitDeposit() {
    if (!canDeposit.value) return;
    busy.value = true;
    notice.value = null;

    try {
        busyLabel.value = t('bridge.waitingWallet');
        await ensureChain(selectedChainId.value);

        const token = new Contract(sourceChain.value.usdt_address, BRIDGE_ERC20_ABI, walletStore.signer);
        const tx = await token.transfer(info.value.vault_address, depositUnits.value);

        // เก็บ hash ทันทีที่กระเป๋าคืนมา — ปิดหน้าตอนรอยืนยันก็ยังติดตามต่อได้
        tracked.value = addTracked(tracked.value, { kind: 'deposit', chainId: selectedChainId.value, txHash: tx.hash });
        saveTracked(tracked.value);
        depositAmount.value = '';
        notice.value = { type: 'success', text: t('bridge.sentNotice') };
        pollNow();
    } catch (err) {
        notice.value = { type: 'error', text: err?.message === 'SWITCH_FAILED' ? t('bridge.switchFailed') : t(friendlyWalletError(err)) };
    } finally {
        busy.value = false;
        busyLabel.value = '';
        refreshSourceBalance();
    }
}

// ── ถอน ──────────────────────────────────────────────────────────────────
const targetChainId = ref(null);
const withdrawAmount = ref('');
const targetAddress = ref('');
const tpixBalance = ref(null); // BigInt 6 ทศนิยม

const targetChain = computed(() => chains.value.find((c) => c.chain_id === targetChainId.value) ?? null);
const withdrawUnits = computed(() => parseAmountToUnits(withdrawAmount.value, USDT_TPIX_DECIMALS));
const receiveUnits = computed(() => (withdrawUnits.value === null || !targetChain.value ? 0n : withdrawReceiveUnits(withdrawUnits.value, targetChain.value.withdraw_fee)));
const withdrawError = computed(() => {
    if (withdrawAmount.value === '') return null;
    if (withdrawUnits.value === null || withdrawUnits.value <= 0n) return 'bridge.errAmountInvalid';
    if (receiveUnits.value <= 0n) return 'bridge.errFeeTooHigh';
    if (withdrawUnits.value < usdtToUnits(limits.value.min_withdraw ?? '0')) return 'bridge.errBelowMinWithdraw';
    if (tpixBalance.value !== null && withdrawUnits.value > tpixBalance.value) return 'bridge.errInsufficient';
    return null;
});
const addressError = computed(() => (targetAddress.value !== '' && !isValidEvmAddress(targetAddress.value) ? 'bridge.errAddressInvalid' : null));
const canWithdraw = computed(() => isOpen.value && targetChain.value?.enabled && walletStore.isConnected && !busy.value
    && withdrawAmount.value !== '' && !withdrawError.value && targetAddress.value !== '' && !addressError.value);

let tpixReader = null;
function tpixProvider() {
    // อ่านยอดจาก RPC ของเชน TPIX โดยตรง — ได้ยอดแม้กระเป๋าอยู่คนละเชน (เบราว์เซอร์มี UA ผ่าน Cloudflare ได้)
    tpixReader ??= new JsonRpcProvider('https://rpc.tpix.online', 4289, { staticNetwork: true });
    return tpixReader;
}

async function refreshTpixBalance() {
    tpixBalance.value = null;
    if (!walletStore.address || !info.value?.tpix?.usdt_tpix) return;
    try {
        const usdt = new Contract(info.value.tpix.usdt_tpix, USDT_TPIX_ABI, tpixProvider());
        tpixBalance.value = await usdt.balanceOf(walletStore.address);
    } catch {
        tpixBalance.value = null;
    }
}

// ถอน = เผาบนเชนแล้วยกเลิกไม่ได้ — ให้เห็นสรุป (ยอดสุทธิ + ที่อยู่ + เชน) อีกครั้งก่อนเปิดกระเป๋า
const confirmingWithdraw = ref(false);

function askWithdraw() {
    if (canWithdraw.value) confirmingWithdraw.value = true;
}

async function confirmWithdraw() {
    confirmingWithdraw.value = false;
    await submitWithdraw();
}

function useMyAddress() {
    targetAddress.value = walletStore.address ?? '';
}

async function submitWithdraw() {
    if (!canWithdraw.value) return;
    busy.value = true;
    notice.value = null;

    try {
        busyLabel.value = t('bridge.waitingWallet');
        await ensureChain(tpixChainId.value);

        const usdt = new Contract(info.value.tpix.usdt_tpix, USDT_TPIX_ABI, walletStore.signer);
        // แก๊สบนเชน TPIX เป็นศูนย์ — ensureChain ข้างบนยืนยันแล้วว่าอยู่เชนถูก
        const tx = await usdt.bridgeBurn(withdrawUnits.value, BigInt(targetChainId.value), targetAddress.value.trim(), { gasPrice: 0 });

        tracked.value = addTracked(tracked.value, { kind: 'withdraw', chainId: tpixChainId.value, txHash: tx.hash, targetChainId: targetChainId.value });
        saveTracked(tracked.value);
        withdrawAmount.value = '';
        notice.value = { type: 'success', text: t('bridge.sentNotice') };
        pollNow();
    } catch (err) {
        notice.value = { type: 'error', text: err?.message === 'SWITCH_FAILED' ? t('bridge.switchFailed') : t(friendlyWalletError(err)) };
    } finally {
        busy.value = false;
        busyLabel.value = '';
        refreshTpixBalance();
    }
}

// ── ติดตามรายการ ─────────────────────────────────────────────────────────
const tracked = ref(loadTracked());
const statuses = ref({}); // key → ผลจาก API
const manualKind = ref('deposit');
const manualChainId = ref(null);
const manualHash = ref('');
let pollTimer = null;
let polling = false;

const trackKey = (e) => `${e.kind}:${e.chainId}:${e.txHash}`;

async function pollOnce() {
    if (polling) return;
    polling = true;
    try {
        for (const entry of tracked.value) {
            const key = trackKey(entry);
            if (statuses.value[key] && stateBadge(statuses.value[key].state).done) continue;
            try {
                const url = entry.kind === 'deposit'
                    ? `/api/v1/bridge/status/${entry.chainId}/${entry.txHash}`
                    : `/api/v1/bridge/withdrawals/${entry.txHash}`;
                const { data } = await axios.get(url);
                statuses.value = { ...statuses.value, [key]: data.data };
            } catch { /* ลองใหม่รอบหน้า */ }
        }
    } finally {
        polling = false;
    }
}

function pollNow() {
    pollOnce();
}

function addManual() {
    const hash = manualHash.value.trim();
    if (!/^0x[0-9a-fA-F]{64}$/.test(hash)) return;
    const chainId = manualKind.value === 'deposit' ? manualChainId.value : tpixChainId.value;
    if (!chainId) return;
    tracked.value = addTracked(tracked.value, { kind: manualKind.value, chainId, txHash: hash });
    saveTracked(tracked.value);
    manualHash.value = '';
    pollNow();
}

function removeTracked(entry) {
    tracked.value = tracked.value.filter((e) => trackKey(e) !== trackKey(entry));
    saveTracked(tracked.value);
}

function chainName(id) {
    if (id === tpixChainId.value) return 'TPIX Chain';
    return chains.value.find((c) => c.chain_id === id)?.name ?? `#${id}`;
}

function sourceExplorer(chainId) {
    if (chainId === tpixChainId.value) return info.value?.tpix?.explorer;
    return chains.value.find((c) => c.chain_id === chainId)?.explorer;
}

const toneClass = {
    success: 'bg-trading-green/15 text-trading-green border-trading-green/30',
    info: 'bg-primary-500/15 text-primary-300 border-primary-500/30',
    warning: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    danger: 'bg-trading-red/15 text-trading-red border-trading-red/30',
    muted: 'bg-white/5 text-dark-300 border-white/10',
};

// ── ประวัติของกระเป๋า ────────────────────────────────────────────────────
const history = ref({ deposits: [], withdrawals: [] });

async function fetchHistory() {
    if (!walletStore.address) {
        history.value = { deposits: [], withdrawals: [] };
        return;
    }
    try {
        const { data } = await axios.get(`/api/v1/bridge/history/${walletStore.address}`);
        history.value = data.data ?? { deposits: [], withdrawals: [] };
    } catch {
        /* ไม่โชว์ error ดิบ — ประวัติเป็นของเสริม */
    }
}

const short = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-6)}` : '—');
const fmt6 = (units) => (units === null || units === undefined ? '—' : formatUnitsPlain(units, USDT_TPIX_DECIMALS));

onMounted(async () => {
    await fetchInfo();
    manualChainId.value = enabledChains.value[0]?.chain_id ?? null;
    pollNow();
    fetchHistory();
    refreshTpixBalance();
    refreshSourceBalance();
    pollTimer = setInterval(() => {
        pollOnce();
    }, 10_000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
});

// สลับบัญชี/เชนในกระเป๋า — ยอดและประวัติต้องตามคนใหม่ ไม่ค้างของคนเก่า
watch(() => walletStore.address, () => {
    fetchHistory();
    refreshTpixBalance();
    refreshSourceBalance();
    if (!targetAddress.value || !isValidEvmAddress(targetAddress.value)) targetAddress.value = walletStore.address ?? '';
});
watch(() => walletStore.chainId, () => refreshSourceBalance());
watch(selectedChainId, () => {
    depositAmount.value = '';
    refreshSourceBalance();
});
</script>

<template>
    <Head :title="asset === 'tpix' ? t('bridge.wtpix.title') : t('bridge.title')" />
    <AppLayout>
        <div class="max-w-3xl mx-auto space-y-6 px-1">
            <div class="text-center">
                <h1 class="text-2xl md:text-3xl font-bold text-white mb-2">🌉 {{ asset === 'tpix' ? t('bridge.wtpix.title') : t('bridge.title') }}</h1>
                <p class="text-dark-400 text-sm">{{ asset === 'tpix' ? t('bridge.wtpix.subtitle') : t('bridge.subtitle') }}</p>
                <Link :href="asset === 'tpix' ? '/bridge/reserves?asset=tpix' : '/bridge/reserves'" class="inline-block mt-2 text-xs text-primary-400 hover:underline">🛡️ {{ t('bridge.reservesLink') }} →</Link>
            </div>

            <!-- เลือกสินทรัพย์ — สองสะพานแยกกันทุกอย่าง (สวิตช์ · คิว · กุญแจ) -->
            <div class="grid grid-cols-2 gap-2 p-1 rounded-xl bg-dark-800/60 border border-white/10" role="tablist">
                <button v-for="a in ['usdt', 'tpix']" :key="a" type="button" role="tab" :aria-selected="asset === a" @click="setAsset(a)" :data-test="`asset-${a}`"
                    :class="['py-2.5 rounded-lg text-sm font-semibold transition-all', asset === a ? 'bg-primary-500/25 text-white' : 'text-dark-400 hover:text-white']">
                    {{ a === 'usdt' ? t('bridge.wtpix.assetUsdt') : t('bridge.wtpix.assetTpix') }}
                </button>
            </div>

            <WtpixBridgePanel v-if="asset === 'tpix'" />

            <template v-else>
            <!-- ยังไม่เปิด / หยุดชั่วคราว — บอกตรง ๆ ดีกว่าโชว์ปุ่มที่กดแล้วเงินไปค้าง -->
            <div v-if="info && !isOpen" class="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex gap-3">
                <span class="text-lg shrink-0">🚧</span>
                <div>
                    <p class="text-sm font-semibold text-amber-300">{{ t('bridge.notReady') }}</p>
                    <p class="text-xs text-dark-300 leading-relaxed mt-1">{{ info.installed ? t('bridge.pausedBody') : t('bridge.notReadyBody') }}</p>
                </div>
            </div>

            <div v-if="notice" :class="['px-4 py-3 rounded-xl text-sm border', notice.type === 'success' ? 'bg-trading-green/10 text-trading-green border-trading-green/30' : 'bg-trading-red/10 text-trading-red border-trading-red/30']">
                {{ notice.text }}
            </div>

            <!-- แท็บ -->
            <div class="flex gap-2 p-1 rounded-xl bg-dark-800/60 border border-white/10">
                <button v-for="k in ['deposit', 'withdraw', 'track']" :key="k" type="button" @click="tab = k"
                    :class="['flex-1 py-2 rounded-lg text-sm font-medium transition-all', tab === k ? 'bg-primary-500/20 text-primary-300' : 'text-dark-400 hover:text-white']">
                    {{ t(k === 'deposit' ? 'bridge.tabDeposit' : k === 'withdraw' ? 'bridge.tabWithdraw' : 'bridge.tabTrack') }}
                </button>
            </div>

            <!-- ═══════════════ ฝาก ═══════════════ -->
            <div v-show="tab === 'deposit'" class="space-y-4">
                <!-- คำเตือนตัวใหญ่ — ความผิดพลาดแบบนี้เรียกเงินคืนไม่ได้ -->
                <div class="rounded-xl border-2 border-trading-red/40 bg-trading-red/[0.06] p-4">
                    <p class="text-sm font-bold text-trading-red mb-2">⚠️ {{ t('bridge.warnTitle') }}</p>
                    <ul class="space-y-1.5 text-xs text-dark-200 leading-relaxed list-disc pl-4">
                        <li><strong class="text-white">{{ t('bridge.warnNoExchange') }}</strong></li>
                        <li>{{ t('bridge.warnOwnWallet') }}</li>
                        <li>{{ t('bridge.warnSameAddress') }}</li>
                        <li>{{ t('bridge.warnUsdtOnly') }}</li>
                    </ul>
                </div>

                <div class="glass-card space-y-4">
                    <div>
                        <label class="text-xs text-dark-400 mb-2 block">{{ t('bridge.sourceChain') }}</label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <button v-for="c in chains" :key="c.chain_id" type="button" :disabled="!c.enabled"
                                @click="selectedChainId = c.chain_id"
                                :class="['px-3 py-2 rounded-xl border text-left transition-all disabled:opacity-40',
                                    selectedChainId === c.chain_id ? 'border-primary-500/60 bg-primary-500/10' : 'border-white/10 bg-dark-800/50 hover:border-white/20']">
                                <p class="text-sm font-semibold text-white">{{ c.name }}</p>
                                <p class="text-[11px] text-dark-400">{{ c.enabled ? c.usdt_symbol : t('bridge.chainDisabled') }}</p>
                            </button>
                        </div>
                    </div>

                    <div v-if="sourceChain && info" class="rounded-xl bg-dark-800/60 border border-white/10 p-3 space-y-2 text-xs">
                        <div>
                            <p class="text-dark-400">{{ t('bridge.vaultAddress') }} ({{ sourceChain.name }})</p>
                            <div class="flex items-center gap-2 mt-1">
                                <code class="font-mono text-white break-all">{{ info.vault_address }}</code>
                                <button type="button" class="shrink-0 text-primary-400 hover:underline" @click="copy(info.vault_address, 'vault')">
                                    {{ copiedKey === 'vault' ? t('bridge.copied') : t('bridge.copy') }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <p class="text-dark-400">{{ t('bridge.usdtContract') }} ({{ sourceChain.usdt_decimals }} decimals)</p>
                            <a :href="`${sourceChain.explorer}/token/${sourceChain.usdt_address}`" target="_blank" rel="noopener" class="font-mono text-primary-300 break-all hover:underline">{{ sourceChain.usdt_address }}</a>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-xs text-dark-400 mb-1">
                            <label>{{ t('bridge.amount') }}</label>
                            <span v-if="sourceBalance !== null && sourceChain">{{ t('bridge.balance') }}: {{ formatUnitsPlain(sourceBalance, sourceChain.usdt_decimals) }}</span>
                        </div>
                        <input v-model.trim="depositAmount" type="text" inputmode="decimal" placeholder="0.00" :disabled="busy"
                            class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white text-xl font-mono outline-none focus:border-primary-500/50" />
                        <p v-if="depositCheck.error" class="text-xs text-trading-red mt-1">{{ t(depositCheck.error, depositCheck.params) }}</p>
                        <p v-else-if="depositCheck.warning" class="text-xs text-amber-300 mt-1">{{ t(depositCheck.warning, depositCheck.warningParams) }}</p>
                        <p v-else-if="insufficientSource" class="text-xs text-trading-red mt-1">{{ t('bridge.errInsufficient') }}</p>
                    </div>

                    <div class="rounded-xl bg-dark-800/40 p-3 space-y-1 text-xs">
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.depositFee') }}</span><span class="text-white">{{ t('bridge.free') }}</span></div>
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.youReceive') }}</span><span class="text-trading-green font-semibold">{{ depositUnits6 !== null && !depositCheck.error ? fmt6(depositUnits6) : '—' }} {{ t('bridge.receiveOnTpix') }}</span></div>
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.eta') }}</span><span class="text-white">{{ t('bridge.etaMinutes', { minutes: sourceChain?.eta_minutes ?? '—' }) }}</span></div>
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.minDeposit') }} / {{ t('bridge.maxPerTx') }}</span><span class="text-white">{{ limits.min_deposit }} / {{ limits.max_deposit_per_tx }} USDT</span></div>
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.dailyPerAddress') }}</span><span class="text-white">{{ limits.max_deposit_per_address_daily }} USDT</span></div>
                    </div>

                    <label class="flex items-start gap-2 text-xs text-dark-200 cursor-pointer">
                        <input v-model="ackOwnWallet" type="checkbox" class="mt-0.5" data-test="ack-own-wallet" />
                        <span>{{ t('bridge.ackOwnWallet') }}</span>
                    </label>

                    <button v-if="!walletStore.isConnected" type="button" @click="walletStore.openConnectModal()"
                        class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500">
                        🔗 {{ t('bridge.connectWallet') }}
                    </button>
                    <button v-else-if="!onSourceChain && sourceChain" type="button" :disabled="busy || !isOpen" @click="switchToSource"
                        class="w-full py-3.5 rounded-xl font-bold text-white bg-primary-500/30 border border-primary-500/40 disabled:opacity-50">
                        {{ t('bridge.switchTo', { chain: sourceChain.name }) }}
                    </button>
                    <button v-else type="button" :disabled="!canDeposit" @click="submitDeposit"
                        class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500 disabled:opacity-50">
                        {{ busy ? (busyLabel || t('bridge.sending')) : t('bridge.sendUsdt') }}
                    </button>
                </div>

                <div class="glass-card">
                    <h3 class="text-sm font-semibold text-white mb-3">{{ t('bridge.howTitle') }}</h3>
                    <ol class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-dark-300">
                        <li v-for="(k, i) in ['howDeposit1', 'howDeposit2', 'howDeposit3', 'howDeposit4']" :key="k" class="flex gap-2">
                            <span class="w-5 h-5 shrink-0 rounded-full bg-primary-500/20 text-primary-300 flex items-center justify-center text-[10px] font-bold">{{ i + 1 }}</span>
                            <span>{{ t(`bridge.${k}`) }}</span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- ═══════════════ ถอน ═══════════════ -->
            <div v-show="tab === 'withdraw'" class="space-y-4">
                <div class="glass-card space-y-4">
                    <div>
                        <label class="text-xs text-dark-400 mb-2 block">{{ t('bridge.targetChain') }}</label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <button v-for="c in chains" :key="c.chain_id" type="button" :disabled="!c.enabled"
                                @click="targetChainId = c.chain_id"
                                :class="['px-3 py-2 rounded-xl border text-left transition-all disabled:opacity-40',
                                    targetChainId === c.chain_id ? 'border-primary-500/60 bg-primary-500/10' : 'border-white/10 bg-dark-800/50 hover:border-white/20']">
                                <p class="text-sm font-semibold text-white">{{ c.name }}</p>
                                <p class="text-[11px] text-dark-400">{{ t('bridge.withdrawFee') }}: {{ c.withdraw_fee }}</p>
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-xs text-dark-400 mb-1">
                            <label>{{ t('bridge.amount') }}</label>
                            <button v-if="tpixBalance !== null" type="button" class="hover:text-white" @click="withdrawAmount = fmt6(tpixBalance)">
                                {{ t('bridge.tpixBalance') }}: {{ fmt6(tpixBalance) }} · {{ t('bridge.max') }}
                            </button>
                        </div>
                        <input v-model.trim="withdrawAmount" type="text" inputmode="decimal" placeholder="0.00" :disabled="busy"
                            class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white text-xl font-mono outline-none focus:border-primary-500/50" />
                        <p v-if="withdrawError" class="text-xs text-trading-red mt-1">{{ t(withdrawError, { min: limits.min_withdraw }) }}</p>
                    </div>

                    <div>
                        <div class="flex justify-between text-xs text-dark-400 mb-1">
                            <label>{{ t('bridge.targetAddress') }}</label>
                            <button v-if="walletStore.address" type="button" class="text-primary-400 hover:underline" @click="useMyAddress">{{ t('bridge.useMyAddress') }}</button>
                        </div>
                        <input v-model.trim="targetAddress" type="text" placeholder="0x…" :disabled="busy" spellcheck="false" autocomplete="off"
                            class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white text-sm font-mono outline-none focus:border-primary-500/50" />
                        <p v-if="addressError" class="text-xs text-trading-red mt-1">{{ t(addressError) }}</p>
                    </div>

                    <div class="rounded-xl bg-dark-800/40 p-3 space-y-1 text-xs">
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.withdrawFee') }}</span><span class="text-white">{{ targetChain?.withdraw_fee ?? '—' }} USDT</span></div>
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.youReceive') }}</span><span class="text-trading-green font-semibold">{{ fmt6(receiveUnits) }} {{ targetChain?.usdt_symbol ?? 'USDT' }} · {{ targetChain?.name ?? '' }}</span></div>
                        <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.minDeposit') }} / {{ t('bridge.maxPerTx') }}</span><span class="text-white">{{ limits.min_withdraw }} / {{ limits.max_withdraw_per_tx }} USDT</span></div>
                    </div>

                    <button v-if="!walletStore.isConnected" type="button" @click="walletStore.openConnectModal()"
                        class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500">
                        🔗 {{ t('bridge.connectWallet') }}
                    </button>
                    <button v-else type="button" :disabled="!canWithdraw" @click="askWithdraw"
                        class="w-full py-3.5 rounded-xl font-bold text-white bg-gradient-to-r from-primary-500 to-accent-500 disabled:opacity-50">
                        {{ busy ? (busyLabel || t('bridge.sending')) : t('bridge.burnUsdt', { chain: targetChain?.name ?? '' }) }}
                    </button>
                </div>

                <div class="glass-card">
                    <h3 class="text-sm font-semibold text-white mb-3">{{ t('bridge.howTitle') }}</h3>
                    <ol class="space-y-2 text-xs text-dark-300">
                        <li v-for="(k, i) in ['howWithdraw1', 'howWithdraw2', 'howWithdraw3']" :key="k" class="flex gap-2">
                            <span class="w-5 h-5 shrink-0 rounded-full bg-primary-500/20 text-primary-300 flex items-center justify-center text-[10px] font-bold">{{ i + 1 }}</span>
                            <span>{{ t(`bridge.${k}`) }}</span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- ═══════════════ ติดตาม ═══════════════ -->
            <div v-show="tab === 'track'" class="glass-card space-y-3">
                <h3 class="text-sm font-semibold text-white">{{ t('bridge.trackTitle') }}</h3>
                <p class="text-xs text-dark-400">{{ t('bridge.trackHint') }}</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <select v-model="manualKind" class="bg-dark-800 border border-white/10 rounded-xl px-3 py-2 text-sm text-white">
                        <option value="deposit">{{ t('bridge.tabDeposit') }}</option>
                        <option value="withdraw">{{ t('bridge.trackWithdrawal') }}</option>
                    </select>
                    <select v-if="manualKind === 'deposit'" v-model="manualChainId" class="bg-dark-800 border border-white/10 rounded-xl px-3 py-2 text-sm text-white">
                        <option v-for="c in chains" :key="c.chain_id" :value="c.chain_id">{{ c.name }}</option>
                    </select>
                    <input v-model.trim="manualHash" type="text" :placeholder="t('bridge.trackTxHash')" spellcheck="false"
                        class="flex-1 min-w-0 bg-dark-800 border border-white/10 rounded-xl px-3 py-2 text-sm text-white font-mono" />
                    <button type="button" @click="addManual" :disabled="!/^0x[0-9a-fA-F]{64}$/.test(manualHash)"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-primary-500/20 text-primary-300 border border-primary-500/30 disabled:opacity-40">
                        {{ t('bridge.trackButton') }}
                    </button>
                </div>
            </div>

            <!-- รายการที่กำลังติดตาม (โชว์ทุกแท็บ) -->
            <div class="glass-card">
                <h3 class="text-sm font-semibold text-white mb-3">{{ t('bridge.tracked') }}</h3>
                <p v-if="!tracked.length" class="text-xs text-dark-500">{{ t('bridge.noTracked') }}</p>
                <div v-else class="space-y-2">
                    <div v-for="entry in tracked" :key="trackKey(entry)" class="rounded-xl bg-dark-800/50 border border-white/5 p-3 text-xs space-y-1.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-white font-medium">
                                    {{ entry.kind === 'deposit' ? `${chainName(entry.chainId)} → TPIX` : `TPIX → ${chainName(entry.targetChainId ?? statuses[trackKey(entry)]?.withdrawals?.[0]?.target_chain_id)}` }}
                                </p>
                                <a :href="explorerTx(sourceExplorer(entry.chainId), entry.txHash)" target="_blank" rel="noopener" class="font-mono text-dark-400 hover:text-primary-300">{{ short(entry.txHash) }}</a>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span v-if="statuses[trackKey(entry)]" :class="['px-2 py-0.5 rounded-md border', toneClass[stateBadge(statuses[trackKey(entry)].state).tone]]">
                                    {{ t(stateBadge(statuses[trackKey(entry)].state).labelKey) }}
                                </span>
                                <button type="button" class="text-dark-500 hover:text-trading-red" @click="removeTracked(entry)">{{ t('bridge.remove') }}</button>
                            </div>
                        </div>

                        <template v-if="statuses[trackKey(entry)]">
                            <p v-if="statuses[trackKey(entry)].state === 'confirming' && statuses[trackKey(entry)].confirmations !== undefined" class="text-dark-400">
                                {{ statuses[trackKey(entry)].final ? t('bridge.finalSoon') : t('bridge.confirmations', { n: statuses[trackKey(entry)].confirmations ?? 0 }) }}
                                · {{ statuses[trackKey(entry)].amount }} USDT
                            </p>
                            <div v-for="d in statuses[trackKey(entry)].deposits || []" :key="d.id" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-white">{{ d.mint_amount }} USDT</span>
                                <a v-if="d.mint_url" :href="d.mint_url" target="_blank" rel="noopener" class="text-primary-300 hover:underline">{{ t('bridge.viewMint') }}</a>
                                <Link v-if="d.status === 'minted'" :href="info?.trade_url || '/trade?pair=TPIX-USDT'"
                                    class="px-2.5 py-1 rounded-lg bg-trading-green/20 text-trading-green border border-trading-green/30 font-semibold">
                                    {{ t('bridge.buyTpix') }} →
                                </Link>
                            </div>
                            <div v-for="w in statuses[trackKey(entry)].withdrawals || []" :key="w.id" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-white">{{ w.payout_amount }} USDT → <span class="font-mono">{{ short(w.target_address) }}</span></span>
                                <a v-if="w.payout_url" :href="w.payout_url" target="_blank" rel="noopener" class="text-primary-300 hover:underline">{{ t('bridge.viewPayout') }}</a>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ประวัติของกระเป๋า -->
            <div v-if="walletStore.isConnected" class="glass-card">
                <h3 class="text-sm font-semibold text-white mb-3">{{ t('bridge.history') }}</h3>
                <p v-if="!history.deposits.length && !history.withdrawals.length" class="text-xs text-dark-500">{{ t('bridge.historyEmpty') }}</p>
                <div v-else class="space-y-1.5 text-xs">
                    <div v-for="d in history.deposits" :key="`d${d.id}`" class="flex justify-between gap-2 bg-dark-800/40 rounded-lg px-3 py-2">
                        <span class="text-white">{{ t('bridge.deposits') }} · {{ chainName(d.source_chain_id) }} · {{ d.amount }} USDT</span>
                        <span :class="['px-2 py-0.5 rounded-md border', toneClass[stateBadge(d.status === 'needs_review' ? 'review' : d.status === 'confirmed' ? 'queued' : d.status).tone]]">
                            {{ t(stateBadge(d.status === 'needs_review' ? 'review' : d.status === 'confirmed' ? 'queued' : d.status).labelKey) }}
                        </span>
                    </div>
                    <div v-for="w in history.withdrawals" :key="`w${w.id}`" class="flex justify-between gap-2 bg-dark-800/40 rounded-lg px-3 py-2">
                        <span class="text-white">{{ t('bridge.withdrawals') }} · {{ chainName(w.target_chain_id) }} · {{ w.amount }} USDT</span>
                        <span :class="['px-2 py-0.5 rounded-md border', toneClass[stateBadge(w.status === 'needs_review' ? 'review' : w.status === 'pending' ? 'queued' : w.status === 'ignored' ? 'below_fee' : w.status).tone]]">
                            {{ t(stateBadge(w.status === 'needs_review' ? 'review' : w.status === 'pending' ? 'queued' : w.status === 'ignored' ? 'below_fee' : w.status).labelKey) }}
                        </span>
                    </div>
                </div>
            </div>
            </template>
        </div>

        <!-- ยืนยันการถอน — เผาแล้วยกเลิกไม่ได้ -->
        <div v-if="confirmingWithdraw" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="confirmingWithdraw = false">
            <div class="w-full max-w-md glass-card space-y-4">
                <h3 class="text-lg font-bold text-white">{{ t('bridge.confirmWithdrawTitle') }}</h3>
                <p class="text-sm text-dark-200 break-words">{{ t('bridge.confirmWithdrawBody', { amount: withdrawAmount, receive: fmt6(receiveUnits), address: targetAddress, chain: targetChain?.name ?? '' }) }}</p>
                <div class="flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 rounded-xl text-sm bg-white/5 text-dark-200 border border-white/10" @click="confirmingWithdraw = false">{{ t('bridge.cancel') }}</button>
                    <button type="button" class="px-4 py-2 rounded-xl text-sm font-semibold bg-primary-500 text-white" data-test="confirm-withdraw" @click="confirmWithdraw">{{ t('bridge.confirm') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
