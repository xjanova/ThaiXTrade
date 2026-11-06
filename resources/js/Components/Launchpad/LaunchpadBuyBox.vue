<script setup>
/**
 * TPIX TRADE — กล่องซื้อของหน้าขาย Launchpad (จ่าย USDT บน BSC → ได้เหรียญบน TPIX Chain)
 *
 * ขั้นตอน: จำนวน → prepare (เซิร์ฟเวอร์ตรวจทุกด่าน) → อ่านคำเตือน + ติ๊กยอมรับ 2 ข้อ → เซ็นข้อความ (ไม่มีธุรกรรม)
 *         → POST คำสั่ง → โอน USDT ยอดเป๊ะเข้า vault ของสะพาน (ERC-20 transfer ธรรมดา) → ถามสถานะจนจบ
 *
 * กฎเงินที่ห้ามพัง:
 *  - ยอดที่โอน = order.amount_units (จากเซิร์ฟเวอร์) ขยายเป็นทศนิยมของเชนต้นทาง — ไม่ใช้ช่องที่ผู้ใช้พิมพ์
 *  - ปลายทาง/สัญญา USDT มาจากคำสั่ง และต้องตรงกับรายการเชนของหน้า (สองแหล่งไม่ตรง = ไม่ส่ง)
 *  - สลับเชนแล้วยืนยันว่าสลับจริงก่อนส่ง · บัญชีในกระเป๋าต้องเป็นคนเดียวกับผู้ซื้อในคำสั่ง
 *  - ส่งแล้วจำ tx ไว้ (localStorage) — รีเฟรชแล้วปุ่มส่งไม่กลับมาให้ส่งซ้ำ
 *  - ลายเซ็นที่กลับมาหลังสลับบัญชี = ทิ้ง ไม่ส่งไปสร้างคำสั่ง
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Contract } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import {
    ORDER_STATUS,
    ERC20_TRANSFER_ABI,
    apiError,
    walletErrorKey,
    normalizeAmount,
    sourceUnits,
    saleState,
    timeLeft,
    formatDecimal,
    unitsToDecimal,
    estimateTokens,
    explorerUrl,
    shortAddr,
    loadPayments,
    rememberPayment,
} from '@/utils/launchpad';

const { t } = useTranslation();
const wallet = useWalletStore();

const props = defineProps({
    sale: { type: Object, required: true },
    launchpad: { type: Object, required: true },
});
const emit = defineEmits(['ordered']);

const OPEN = ['awaiting_payment', 'paid', 'buying', 'refunding', 'needs_review'];

// ── สถานะของกล่อง ────────────────────────────────────────────────────────
const step = ref('amount'); // amount | review | order
const amount = ref('');
const chainId = ref(props.launchpad.chains.find((c) => c.enabled)?.chain_id ?? props.launchpad.chains[0]?.chain_id ?? null);
const prepared = ref(null);
const acceptRisk = ref(false);
const acceptOwnWallet = ref(false);
const order = ref(null);
const myOrders = ref([]);
const busy = ref(false);
const busyLabel = ref('');
const notice = ref(null); // { key, params, tone }
const payments = ref(loadPayments());
const balance = ref(null); // BigInt | null
const confirmingCancel = ref(false);
const copied = ref('');
const now = ref(Date.now());

const chain = computed(() => props.launchpad.chains.find((c) => c.chain_id === chainId.value) ?? null);
const orderChain = computed(() => props.launchpad.chains.find((c) => c.chain_id === order.value?.source_chain_id) ?? null);
const state = computed(() => saleState(props.sale, now.value));
const canOrder = computed(() => props.launchpad.enabled && state.value === 'live' && chain.value?.enabled === true);
const normalized = computed(() => normalizeAmount(amount.value));
const estimate = computed(() => estimateTokens(amount.value, props.sale.tokens_per_usdt));
const amountError = computed(() => (amount.value !== '' && normalized.value === null ? 'launchpad.buy.amountInvalid' : null));

const status = computed(() => ORDER_STATUS[order.value?.status] ?? { tone: 'muted', done: true });
const sentTx = computed(() => (order.value ? payments.value[order.value.id]?.txHash ?? null : null));
const expiresLeft = computed(() => timeLeft(order.value?.expires_at, now.value));
const expired = computed(() => {
    const at = Date.parse(order.value?.expires_at ?? '');
    return Number.isNaN(at) || now.value >= at;
});
const isBuyerWallet = computed(() => !!order.value && String(wallet.address ?? '').toLowerCase() === order.value.buyer_address);
const onOrderChain = computed(() => wallet.chainId === order.value?.source_chain_id);
const sendUnits = computed(() => {
    try {
        return order.value?.payment ? sourceUnits(order.value.amount_units, order.value.payment.usdt_decimals) : null;
    } catch {
        return null;
    }
});
const insufficient = computed(() => balance.value !== null && sendUnits.value !== null && balance.value < sendUnits.value);
const canPay = computed(() => order.value?.status === 'awaiting_payment' && !!order.value.payment && !sentTx.value && !expired.value
    && isBuyerWallet.value && !busy.value && sendUnits.value !== null && !insufficient.value);
const canCancel = computed(() => order.value?.status === 'awaiting_payment' && !sentTx.value && isBuyerWallet.value && !busy.value);

function setNotice(key, tone = 'error', params = {}) {
    notice.value = { key, tone, params };
}

function countdown({ d, h, m, s }) {
    if (d > 0) return t('launchpad.time.dhm', { d, h, m });
    if (h > 0) return t('launchpad.time.hms', { h, m, s });
    return t('launchpad.time.ms', { m, s });
}

async function copy(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1500);
    } catch { /* เลือกข้อความเองได้ */ }
}

/** สลับเชนแล้วยืนยันว่าสลับจริง — ไม่งั้นธุรกรรมไปเกิดบนเชนผิด */
async function ensureChain(id) {
    if (wallet.chainId === id) return;
    await wallet.switchChain(id);
    if (wallet.chainId !== id) throw new Error('SWITCH_FAILED');
}

// ── 1. ตรวจคำสั่ง (prepare) ──────────────────────────────────────────────
async function review() {
    if (!wallet.isConnected) {
        wallet.openConnectModal();
        return;
    }
    if (busy.value || !canOrder.value || normalized.value === null) return;

    busy.value = true;
    busyLabel.value = t('launchpad.buy.reviewing');
    notice.value = null;
    const buyer = String(wallet.address).toLowerCase();
    try {
        const { data } = await axios.post('/api/v1/launchpad/orders/prepare', {
            sale_id: props.sale.id,
            buyer,
            amount: normalized.value,
            source_chain_id: chainId.value,
        });
        prepared.value = { ...data.data, buyer, amount: normalized.value, chainId: chainId.value };
        acceptRisk.value = false;
        acceptOwnWallet.value = false;
        step.value = 'review';
    } catch (err) {
        await handleOrderError(err);
    } finally {
        busy.value = false;
        busyLabel.value = '';
    }
}

// ── 2. เซ็น + สร้างคำสั่ง ─────────────────────────────────────────────────
async function sign() {
    const p = prepared.value;
    if (!p || busy.value || !acceptRisk.value || !acceptOwnWallet.value) return;

    busy.value = true;
    busyLabel.value = t('launchpad.buy.waitingWallet');
    notice.value = null;
    try {
        if (String(wallet.address ?? '').toLowerCase() !== p.buyer || !wallet.signer) throw new Error('WALLET_CHANGED');
        const signature = await wallet.signer.signMessage(p.message);
        // ลายเซ็นมาถึงหลังผู้ใช้สลับบัญชี (WalletConnect รอได้หลายนาที) — ทิ้ง
        if (String(wallet.address ?? '').toLowerCase() !== p.buyer) throw new Error('WALLET_CHANGED');

        const { data } = await axios.post('/api/v1/launchpad/orders', {
            sale_id: props.sale.id,
            buyer: p.buyer,
            amount: p.amount,
            source_chain_id: p.chainId,
            issued_at: p.issued_at,
            nonce: p.nonce,
            signature,
            risk_accepted: true,
        });
        order.value = data.data;
        prepared.value = null;
        step.value = 'order';
        emit('ordered', order.value);
        refreshBalance();
        loadMyOrders();
    } catch (err) {
        if (err?.response) {
            await handleOrderError(err);
        } else {
            setNotice(walletErrorKey(err));
            if (err?.message === 'WALLET_CHANGED') resetToAmount();
        }
    } finally {
        busy.value = false;
        busyLabel.value = '';
    }
}

async function handleOrderError(err) {
    const e = apiError(err);
    if (e.code === 'OPEN_ORDER_EXISTS' && e.details?.order_id) {
        await openOrder(e.details.order_id);
        return;
    }
    setNotice(e.key, 'error', e.params);
    // ลายเซ็น/ข้อความหมดอายุ → กลับไปตรวจใหม่ (ข้อความใหม่มี nonce ใหม่)
    if (['SIGNATURE_EXPIRED', 'INVALID_NONCE', 'INVALID_ISSUED_AT', 'SIGNATURE_USED'].includes(e.code)) resetToAmount(false);
}

function resetToAmount(clearNotice = false) {
    step.value = 'amount';
    prepared.value = null;
    acceptRisk.value = false;
    acceptOwnWallet.value = false;
    if (clearNotice) notice.value = null;
}

/** เปิดคำสั่งที่มีอยู่ — ถ้าเป็นของหน้าขายอื่น บอกให้ไปจบที่นั่นก่อน */
async function openOrder(id) {
    try {
        const { data } = await axios.get(`/api/v1/launchpad/orders/${id}`);
        if (data.data.sale_id !== props.sale.id) {
            setNotice('launchpad.buy.openElsewhere', 'warning');
            resetToAmount();
            return;
        }
        order.value = data.data;
        step.value = 'order';
        setNotice('launchpad.buy.resumeNotice', 'info');
        refreshBalance();
    } catch (err) {
        setNotice(apiError(err).key);
    }
}

// ── 3. โอน USDT ─────────────────────────────────────────────────────────
async function pay() {
    if (!canPay.value) return;
    const o = order.value;
    const target = o.payment;
    const listed = orderChain.value;

    // ปลายทางสองแหล่งต้องตรงกัน — ไม่ตรง = ข้อมูลผิดปกติ ไม่ส่งเงิน
    if (!listed || listed.usdt_address !== target.usdt_contract || listed.usdt_decimals !== target.usdt_decimals) {
        setNotice('launchpad.errors.SERVER_ERROR');
        return;
    }

    busy.value = true;
    busyLabel.value = t('launchpad.buy.waitingWallet');
    notice.value = null;
    try {
        await ensureChain(o.source_chain_id);
        if (String(wallet.address ?? '').toLowerCase() !== o.buyer_address) throw new Error('WALLET_CHANGED');

        const usdt = new Contract(target.usdt_contract, ERC20_TRANSFER_ABI, wallet.signer);
        const tx = await usdt.transfer(target.vault, sendUnits.value);

        // จำ hash ทันทีที่กระเป๋าคืนมา — ปิดหน้าตอนนี้ก็ไม่เห็นปุ่มส่งซ้ำ
        payments.value = rememberPayment(o.id, tx.hash);
        setNotice('launchpad.buy.sentNotice', 'success');
        poll();
    } catch (err) {
        setNotice(walletErrorKey(err));
    } finally {
        busy.value = false;
        busyLabel.value = '';
        refreshBalance();
    }
}

async function refreshBalance() {
    balance.value = null;
    const o = order.value;
    if (!o?.payment || !wallet.isConnected || !wallet.provider || wallet.chainId !== o.source_chain_id) return;
    try {
        const usdt = new Contract(o.payment.usdt_contract, ERC20_TRANSFER_ABI, wallet.provider);
        balance.value = await usdt.balanceOf(wallet.address);
    } catch {
        balance.value = null; // อ่านไม่ได้ ≠ ศูนย์ — ให้กระเป๋าตัดสิน
    }
}

async function switchToOrderChain() {
    notice.value = null;
    try {
        await ensureChain(order.value.source_chain_id);
        await refreshBalance();
    } catch {
        setNotice('launchpad.buy.switchFailed');
    }
}

// ── ยกเลิก ──────────────────────────────────────────────────────────────
async function cancel() {
    confirmingCancel.value = false;
    if (!canCancel.value) return;
    const o = order.value;

    busy.value = true;
    busyLabel.value = t('launchpad.buy.cancelling');
    notice.value = null;
    try {
        const { data: msg } = await axios.get(`/api/v1/launchpad/orders/${o.id}/cancel-message`);
        busyLabel.value = t('launchpad.buy.waitingWallet');
        const signature = await wallet.signer.signMessage(msg.data.message);
        if (String(wallet.address ?? '').toLowerCase() !== o.buyer_address) throw new Error('WALLET_CHANGED');
        const { data } = await axios.post(`/api/v1/launchpad/orders/${o.id}/cancel`, { signature });
        order.value = data.data;
        setNotice('launchpad.buy.cancelled', 'info');
        loadMyOrders();
    } catch (err) {
        setNotice(err?.response ? apiError(err).key : walletErrorKey(err), 'error', err?.response ? apiError(err).params : {});
    } finally {
        busy.value = false;
        busyLabel.value = '';
    }
}

function newOrder() {
    order.value = null;
    amount.value = '';
    resetToAmount(true);
}

// ── ถามสถานะ / ประวัติ ─────────────────────────────────────────────────────
let pollTimer = null;
let tick = null;
let polling = false;

async function poll() {
    if (polling || !order.value || status.value.done) return;
    polling = true;
    const id = order.value.id;
    try {
        const { data } = await axios.get(`/api/v1/launchpad/orders/${id}`);
        if (order.value?.id === id) order.value = data.data; // ผู้ใช้ไม่ได้เปลี่ยนคำสั่งระหว่างรอ
    } catch { /* ลองใหม่รอบหน้า */ } finally {
        polling = false;
    }
}

async function loadMyOrders() {
    const address = wallet.address;
    if (!address) {
        myOrders.value = [];
        return;
    }
    try {
        const { data } = await axios.get(`/api/v1/launchpad/wallet/${String(address).toLowerCase()}/orders`);
        if (wallet.address !== address) return; // สลับกระเป๋าระหว่างรอ
        myOrders.value = (data.data ?? []).filter((o) => o.sale_id === props.sale.id);
        // มีคำสั่งค้างของหน้านี้ และยังไม่ได้เปิดอะไรอยู่ → เปิดให้ทำต่อ
        const openOne = myOrders.value.find((o) => OPEN.includes(o.status));
        if (openOne && !order.value && step.value === 'amount') {
            order.value = openOne;
            step.value = 'order';
            setNotice('launchpad.buy.resumeNotice', 'info');
            refreshBalance();
        }
    } catch { /* ประวัติเป็นของเสริม */ }
}

function showOrder(o) {
    order.value = o;
    step.value = 'order';
    notice.value = null;
    refreshBalance();
    poll();
}

onMounted(() => {
    loadMyOrders();
    pollTimer = setInterval(poll, 10_000);
    tick = setInterval(() => { now.value = Date.now(); }, 1000);
});
onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (tick) clearInterval(tick);
});

// สลับบัญชี: ทุกอย่างที่ผูกกับคนเก่าต้องหาย (คำสั่ง/ข้อความที่จะเซ็น/ยอดเงิน)
watch(() => wallet.address, (next, prev) => {
    if (String(next ?? '').toLowerCase() === String(prev ?? '').toLowerCase()) return;
    order.value = null;
    myOrders.value = [];
    balance.value = null;
    confirmingCancel.value = false;
    resetToAmount(true);
    loadMyOrders();
});
watch(() => wallet.chainId, () => refreshBalance());

const toneClass = {
    success: 'bg-trading-green/10 text-trading-green border-trading-green/30',
    info: 'bg-primary-500/10 text-primary-300 border-primary-500/30',
    warning: 'bg-amber-500/10 text-amber-300 border-amber-500/30',
    error: 'bg-trading-red/10 text-trading-red border-trading-red/30',
    muted: 'bg-white/5 text-dark-300 border-white/10',
};

const balanceText = computed(() => (balance.value === null || !order.value?.payment ? null
    : formatDecimal(unitsToDecimal(balance.value, order.value.payment.usdt_decimals), 6)));
const tpixTx = (hash) => explorerUrl(props.launchpad.tpix_explorer, 'tx', hash);
const sourceTx = (hash) => explorerUrl(orderChain.value?.explorer, 'tx', hash);
const dateText = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
</script>

<template>
    <section class="glass-dark rounded-2xl p-5 border border-white/10 space-y-4" data-test="buy-box">
        <h2 class="text-base font-semibold text-white">💳 {{ t('launchpad.buy.title') }}</h2>

        <div v-if="notice" :class="['px-4 py-3 rounded-xl text-sm border', toneClass[notice.tone] ?? toneClass.error]" data-test="notice">
            {{ t(notice.key, notice.params) }}
        </div>

        <!-- ── ขั้น 1: จำนวน ── -->
        <template v-if="step === 'amount'">
            <p v-if="!launchpad.enabled" class="text-sm text-amber-300" data-test="buy-closed">{{ t('launchpad.notOpenBody') }}</p>
            <p v-else-if="state !== 'live'" class="text-sm text-amber-300" data-test="buy-not-live">{{ t('launchpad.buy.notLive') }}</p>

            <template v-else>
                <label v-if="launchpad.chains.length > 1" class="block">
                    <span class="text-xs text-dark-400">{{ t('launchpad.buy.chainLabel') }}</span>
                    <select v-model="chainId" class="trading-input w-full mt-1">
                        <option v-for="c in launchpad.chains" :key="c.chain_id" :value="c.chain_id" :disabled="!c.enabled">{{ c.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-dark-400">{{ t('launchpad.buy.amountLabel') }}</span>
                    <input v-model="amount" type="text" inputmode="decimal" autocomplete="off" class="trading-input w-full mt-1 font-mono"
                        :placeholder="t('launchpad.buy.amountPlaceholder')" data-test="amount" @keyup.enter="review" />
                </label>
                <p v-if="amountError" class="text-xs text-trading-red">{{ t(amountError) }}</p>
                <p v-else-if="estimate" class="text-xs text-dark-300" data-test="estimate">
                    {{ t('launchpad.buy.estimate', { tokens: formatDecimal(estimate, 2), symbol: sale.token.symbol }) }}
                </p>

                <button v-if="!wallet.isConnected" type="button" class="btn-primary w-full py-3" data-test="connect" @click="wallet.openConnectModal()">
                    {{ t('launchpad.buy.connect') }}
                </button>
                <button v-else type="button" class="btn-primary w-full py-3" :disabled="busy || !canOrder || normalized === null" data-test="review" @click="review">
                    {{ busy ? busyLabel : t('launchpad.buy.review') }}
                </button>
            </template>
        </template>

        <!-- ── ขั้น 2: ตรวจ + คำเตือน + ติ๊กยอมรับ + เซ็น ── -->
        <template v-else-if="step === 'review' && prepared">
            <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4 space-y-2 text-sm" data-test="quote">
                <p class="text-xs font-semibold text-dark-300 uppercase tracking-wide">{{ t('launchpad.buy.quoteTitle') }}</p>
                <div class="flex justify-between gap-2"><span class="text-dark-400">{{ t('launchpad.buy.youSend') }}</span><span class="text-white font-mono">{{ prepared.quote.amount }} USDT</span></div>
                <div class="flex justify-between gap-2"><span class="text-dark-400">{{ t('launchpad.buy.fee') }}</span><span class="text-white font-mono">{{ prepared.quote.fee }} USDT</span></div>
                <div class="flex justify-between gap-2"><span class="text-dark-400">{{ t('launchpad.buy.youGet') }}</span><span class="text-trading-green font-mono" data-test="quote-tokens">{{ formatDecimal(prepared.quote.tokens, 4) }} {{ sale.token.symbol }}</span></div>
            </div>

            <div class="rounded-xl border border-trading-red/30 bg-trading-red/[0.06] p-4" data-test="risk-warning">
                <p class="text-sm font-semibold text-trading-red mb-2">⚠️ {{ t('launchpad.buy.riskTitle') }}</p>
                <ul class="list-disc pl-5 space-y-1 text-xs text-dark-200 leading-relaxed">
                    <li>{{ t('launchpad.buy.risk1') }}</li>
                    <li>{{ t('launchpad.buy.risk2') }}</li>
                    <li>{{ t('launchpad.buy.risk3') }}</li>
                    <li>{{ t('launchpad.buy.risk4', { amount: prepared.quote.amount, minutes: launchpad.order_ttl_minutes }) }}</li>
                    <li>{{ t('launchpad.buy.risk5') }}</li>
                </ul>
            </div>

            <label class="flex gap-2 items-start text-sm text-dark-200">
                <input v-model="acceptRisk" type="checkbox" class="mt-1" data-test="accept-risk" />
                <span>{{ t('launchpad.buy.acceptRisk') }}</span>
            </label>
            <label class="flex gap-2 items-start text-sm text-dark-200">
                <input v-model="acceptOwnWallet" type="checkbox" class="mt-1" data-test="accept-wallet" />
                <span>{{ t('launchpad.buy.acceptOwnWallet', { wallet: shortAddr(prepared.buyer) }) }}</span>
            </label>

            <button type="button" class="btn-primary w-full py-3" :disabled="busy || !acceptRisk || !acceptOwnWallet" data-test="sign" @click="sign">
                {{ busy ? busyLabel : t('launchpad.buy.sign') }}
            </button>
            <p class="text-[11px] text-dark-500 text-center">{{ t('launchpad.buy.signHint') }}</p>
            <button type="button" class="w-full text-xs text-dark-400 hover:text-white" :disabled="busy" @click="resetToAmount(true)">← {{ t('launchpad.buy.back') }}</button>
        </template>

        <!-- ── ขั้น 3: คำสั่งซื้อ / จ่ายเงิน / สถานะ ── -->
        <template v-else-if="step === 'order' && order">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs text-dark-400 font-mono">{{ t('launchpad.buy.orderTitle', { id: order.id.slice(0, 8) }) }}</p>
                <span :class="['px-2 py-0.5 rounded-full text-[11px] border', toneClass[status.tone]]" data-test="order-status">{{ t(`launchpad.status.${order.status}`) }}</span>
            </div>
            <p class="text-sm text-dark-300" data-test="status-body">
                {{ t(`launchpad.statusBody.${order.status}`, { tokens: formatDecimal(order.tokens_out ?? order.expected_tokens, 4), symbol: sale.token.symbol }) }}
            </p>

            <!-- รอเงิน: ปลายทาง + ปุ่มส่ง -->
            <template v-if="order.status === 'awaiting_payment' && order.payment">
                <div class="rounded-xl border border-primary-500/30 bg-primary-500/[0.06] p-4 space-y-3 text-sm">
                    <p class="font-semibold text-white" data-test="send-exact">{{ t('launchpad.buy.sendExact', { amount: order.amount }) }}</p>
                    <div>
                        <p class="text-xs text-dark-400">{{ t('launchpad.buy.payTo') }}</p>
                        <div class="flex items-center gap-2">
                            <code class="text-xs text-white break-all" data-test="vault">{{ order.payment.vault }}</code>
                            <button type="button" class="text-[11px] text-primary-400 shrink-0" @click="copy(order.payment.vault, 'vault')">{{ copied === 'vault' ? t('launchpad.buy.copied') : t('launchpad.buy.copy') }}</button>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-dark-400">{{ t('launchpad.buy.usdtContract') }} — {{ orderChain?.name }}</p>
                        <code class="text-xs text-dark-200 break-all">{{ order.payment.usdt_contract }}</code>
                    </div>
                    <p :class="['text-xs', expired ? 'text-trading-red' : 'text-amber-300']" data-test="expires">
                        {{ expired ? t('launchpad.buy.expired') : t('launchpad.buy.expiresIn', { time: countdown(expiresLeft) }) }}
                    </p>
                </div>

                <p v-if="sentTx" class="text-sm text-trading-green" data-test="already-sent">
                    ✅ {{ t('launchpad.buy.alreadySent') }}
                    <a v-if="sourceTx(sentTx)" :href="sourceTx(sentTx)" target="_blank" rel="noopener noreferrer" class="underline ml-1">{{ t('launchpad.buy.viewTx') }}</a>
                </p>

                <template v-else-if="!expired && isBuyerWallet">
                    <p v-if="balanceText !== null" :class="['text-xs', insufficient ? 'text-trading-red' : 'text-dark-400']">
                        {{ insufficient ? t('launchpad.buy.insufficientBalance') : t('launchpad.buy.balance', { chain: orderChain?.name, amount: balanceText }) }}
                    </p>
                    <button v-if="!onOrderChain" type="button" class="btn-secondary w-full py-2.5" :disabled="busy" data-test="switch" @click="switchToOrderChain">
                        {{ t('launchpad.buy.switchTo', { chain: orderChain?.name }) }}
                    </button>
                    <button type="button" class="btn-success w-full py-3" :disabled="!canPay" data-test="pay" @click="pay">
                        {{ busy ? busyLabel : t('launchpad.buy.sendNow', { amount: order.amount }) }}
                    </button>
                    <p class="text-[11px] text-dark-500 text-center">{{ t('launchpad.buy.gasNote') }}</p>
                </template>

                <template v-if="canCancel">
                    <button v-if="!confirmingCancel" type="button" class="w-full text-xs text-dark-400 hover:text-trading-red" data-test="cancel" @click="confirmingCancel = true">{{ t('launchpad.buy.cancel') }}</button>
                    <div v-else class="rounded-xl border border-trading-red/30 p-3 space-y-2">
                        <p class="text-xs text-dark-200">{{ t('launchpad.buy.cancelConfirm') }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="btn-danger flex-1 py-2 text-sm" data-test="cancel-confirm" @click="cancel">{{ t('launchpad.buy.cancel') }}</button>
                            <button type="button" class="flex-1 py-2 text-sm text-dark-300" @click="confirmingCancel = false">{{ t('launchpad.buy.back') }}</button>
                        </div>
                    </div>
                </template>
            </template>

            <p v-if="order.delivery_tx_hash" class="text-sm">
                <a :href="tpixTx(order.delivery_tx_hash)" target="_blank" rel="noopener noreferrer" class="text-primary-400 underline" data-test="delivery-tx">{{ t('launchpad.buy.viewTx') }}</a>
            </p>
            <p v-if="['refunding', 'refunded', 'expired', 'cancelled'].includes(order.status)" class="text-sm">
                <a v-if="order.refund_tx_hash" :href="tpixTx(order.refund_tx_hash)" target="_blank" rel="noopener noreferrer" class="text-primary-400 underline mr-3">{{ t('launchpad.buy.viewTx') }}</a>
                <Link href="/bridge" class="text-primary-400 underline" data-test="to-bridge">{{ t('launchpad.buy.toBridge') }} →</Link>
            </p>

            <button v-if="status.done" type="button" class="btn-primary w-full py-2.5" data-test="new-order" @click="newOrder">{{ t('launchpad.buy.newOrder') }}</button>
        </template>

        <!-- ประวัติของกระเป๋าในหน้าขายนี้ -->
        <div v-if="wallet.isConnected" class="pt-2 border-t border-white/5">
            <p class="text-xs font-semibold text-dark-300 mb-2">{{ t('launchpad.orders.title') }}</p>
            <p v-if="!myOrders.length" class="text-xs text-dark-500">{{ t('launchpad.orders.empty') }}</p>
            <ul v-else class="space-y-1.5" data-test="my-orders">
                <li v-for="o in myOrders" :key="o.id" class="flex items-center justify-between gap-2 text-xs">
                    <span class="text-dark-400">{{ dateText(o.created_at) }}</span>
                    <span class="text-white font-mono">{{ o.amount }} USDT</span>
                    <span class="text-dark-300">{{ t(`launchpad.status.${o.status}`) }}</span>
                    <button type="button" class="text-primary-400" @click="showOrder(o)">{{ t('launchpad.orders.open') }}</button>
                </li>
            </ul>
        </div>
    </section>
</template>
