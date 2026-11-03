<script setup>
/**
 * TPIX TRADE - Admin Token Sales + Token Control
 * จัดการรอบขายเหรียญ TPIX + ดู master wallet + transactions
 * รองรับ: Stripe + โอนเงิน + USDT (ตรวจเงินเข้าเองจากเชน) | ไม่มีการถอนเป็นเงินสด
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SaleLaunchPanel from '@/Components/Admin/SaleLaunchPanel.vue';
import SaleCryptoPanel from '@/Components/Admin/SaleCryptoPanel.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    sales: { type: Array, default: () => [] },
    transactions: { type: Object, default: () => ({ data: [] }) },
    walletInfo: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    // ความพร้อม + ตารางที่จะได้ถ้ากดเปิดรอบขายวันนี้
    launch: { type: Object, default: () => ({}) },
    // ทาง USDT — สถานะการเปิดรับ + เงินเข้าที่รอทีมงานตัดสิน
    crypto: { type: Object, default: () => ({}) },
    // ทุกคำสั่งของหน้านี้ขยับเงินจริง — ใช้ได้เฉพาะ super_admin ระดับอื่นเปิดดูได้อย่างเดียว
    canManageMoney: { type: Boolean, default: false },
    // ออเดอร์บัตรที่ร้านยังถือเงินอยู่หลัง chargeback — เซิร์ฟเวอร์ส่งให้เฉพาะ super_admin
    disputesAwaitingRestore: { type: Array, default: () => [] },
});

// Tab ปัจจุบัน
const activeTab = ref('overview');
// เก็บคีย์ไว้ แล้วเรียก t() ใน template — ป้ายแท็บเปลี่ยนภาษาได้ทันที
const tabs = [
    { id: 'overview', icon: '📊', labelKey: 'admin.tokenSales.tabs.overview' },
    { id: 'sales', icon: '🏷️', labelKey: 'admin.tokenSales.tabs.sales' },
    { id: 'transactions', icon: '📋', labelKey: 'admin.tokenSales.tabs.transactions' },
    { id: 'wallet', icon: '💰', labelKey: 'admin.tokenSales.tabs.wallet' },
];

// Modal states
const showSaleModal = ref(false);
const showPhaseModal = ref(false);
const editingSale = ref(null);

// ช่องทางรับเงินที่เลือกเพิ่มจากบัตรได้ — ต้องตรงกับ TokenSale::PAYMENT_CHANNELS ที่เซิร์ฟเวอร์ตรวจซ้ำตอนบันทึก
const OPTIONAL_CHANNELS = [
    { id: 'BANK', keyBase: 'admin.tokenSales.channels.bank' },
    { id: 'CRYPTO', keyBase: 'admin.tokenSales.channels.crypto' },
];

/** บัตรเปิดเสมอ และตัดค่าที่เลิกรับแล้ว (USDT, BNB, STRIPE) ทิ้ง ฟอร์มจะได้ไม่ส่งค่าที่เซิร์ฟเวอร์ปฏิเสธ */
function saleChannels(saved) {
    return ['CARD', ...OPTIONAL_CHANNELS.map((channel) => channel.id).filter((id) => saved?.includes(id))];
}

/** ป้ายบนการ์ดรอบขาย ตามช่องทางที่รอบนั้นรับจริง */
function saleChannelsLabel(saved) {
    return ['Stripe', ...OPTIONAL_CHANNELS.filter((channel) => saved?.includes(channel.id)).map((channel) => t(`${channel.keyBase}.short`))].join(' + ');
}

// ฟอร์ม Token Sale
const saleForm = useForm({
    id: null,
    name: '',
    description: '',
    total_supply_for_sale: 700000000,
    accept_currencies: saleChannels(),
    sale_wallet_address: '',
    starts_at: '',
    ends_at: '',
    status: 'draft',
});

// ฟอร์ม Phase
const phaseForm = useForm({
    id: null,
    token_sale_id: null,
    name: '',
    phase_order: 1,
    // ความยาวเฟสเป็นวัน — ตอนกด "เปิดรอบขาย" วันจริงถูกคำนวณจากค่านี้
    duration_days: 60,
    price_usd: 0.05,
    allocation: 100000000,
    min_purchase: 100,
    max_purchase: 1000000,
    vesting_tge_percent: 10,
    vesting_cliff_days: 30,
    vesting_duration_days: 180,
    status: 'upcoming',
    starts_at: '',
    ends_at: '',
});

// ===== Sale CRUD =====
function openCreateSale() {
    editingSale.value = null;
    saleForm.reset();
    saleForm.accept_currencies = saleChannels();
    saleForm.total_supply_for_sale = 700000000;
    showSaleModal.value = true;
}

function openEditSale(sale) {
    editingSale.value = sale;
    Object.assign(saleForm, {
        id: sale.id,
        name: sale.name,
        description: sale.description || '',
        total_supply_for_sale: sale.total_supply_for_sale,
        accept_currencies: saleChannels(sale.accept_currencies),
        sale_wallet_address: sale.sale_wallet_address || '',
        starts_at: sale.starts_at?.slice(0, 16) || '',
        ends_at: sale.ends_at?.slice(0, 16) || '',
        status: sale.status,
    });
    showSaleModal.value = true;
}

function submitSale() {
    saleForm.post('/admin/token-sales', {
        onSuccess: () => { showSaleModal.value = false; },
    });
}

// ===== Phase CRUD =====

/**
 * แปลงวันที่จาก API (ISO-8601 พร้อม timezone) ให้ <input type="datetime-local"> อ่านได้
 *
 * input ชนิดนี้รับได้เฉพาะรูปแบบ 'YYYY-MM-DDTHH:mm' เท่านั้น ถ้าโยน ISO เต็มเข้าไป
 * ช่องจะขึ้นว่างเปล่า — แอดมินเห็นเป็นช่องว่างแล้วเผลอกดบันทึกทับวันเดิมเป็น null
 */
function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** เฟสนี้ค้างสถานะ active ไว้แต่เลยวันปิดไปแล้วหรือยัง (= ขายไม่ได้จริง) */
function isPhaseExpired(phase) {
    return phase.status === 'active' && !!phase.ends_at && new Date(phase.ends_at) < new Date();
}

function openAddPhase(sale) {
    phaseForm.reset();
    phaseForm.token_sale_id = sale.id;
    phaseForm.phase_order = (sale.phases?.length || 0) + 1;
    showPhaseModal.value = true;
}

/**
 * เปิดฟอร์มแก้ไขเฟสที่มีอยู่
 *
 * ★ เดิมตารางเฟสเป็น "ดูอย่างเดียว" ไม่มีปุ่มแก้ไขเลย ทั้งที่หลังบ้านรองรับอยู่แล้ว
 *   (updatePhase() แยกสาขาด้วย id มาตั้งแต่ต้น) ผลคือเมื่อเฟสหมดอายุ เจ้าของ
 *   แก้วันปิดเองไม่ได้ ต้องรันคำสั่งบนเซิร์ฟเวอร์เท่านั้น = ระบบขายหยุดขาย
 *   โดยที่คนดูแลกู้เองไม่ได้
 */
function openEditPhase(sale, phase) {
    phaseForm.reset();
    phaseForm.id = phase.id;
    phaseForm.token_sale_id = sale.id;
    phaseForm.name = phase.name;
    phaseForm.phase_order = phase.phase_order;
    phaseForm.duration_days = Number(phase.duration_days) || 60;
    phaseForm.price_usd = Number(phase.price_usd);
    phaseForm.allocation = Number(phase.allocation);
    phaseForm.min_purchase = Number(phase.min_purchase);
    phaseForm.max_purchase = Number(phase.max_purchase);
    phaseForm.vesting_tge_percent = Number(phase.vesting_tge_percent);
    phaseForm.vesting_cliff_days = Number(phase.vesting_cliff_days);
    phaseForm.vesting_duration_days = Number(phase.vesting_duration_days);
    phaseForm.status = phase.status;
    phaseForm.starts_at = toLocalInput(phase.starts_at);
    phaseForm.ends_at = toLocalInput(phase.ends_at);
    showPhaseModal.value = true;
}

function submitPhase() {
    phaseForm.post('/admin/token-sales/phase', {
        onSuccess: () => { showPhaseModal.value = false; },
    });
}

// ===== การโอนเงินเข้าบัญชี =====

/** รายการนี้เป็นการโอนเงินที่รอทีมงานยืนยันหรือเปล่า */
function isPendingBankTransfer(tx) {
    return tx.status === 'pending' && tx.metadata?.method === 'bank_transfer';
}

/** รหัสอ้างอิงที่ผู้ซื้อใส่ตอนโอน — ใช้จับคู่กับรายการเดินบัญชี */
function bankReference(tx) {
    return tx.metadata?.reference || null;
}

const confirmingId = ref(null);

/** ตรงกับ BankTransferSaleService::ORDER_TTL_HOURS */
const BANK_ORDER_TTL_MS = 48 * 60 * 60 * 1000;

/** ออเดอร์เก่าไม่มี expires_at — คิดจากวันที่สร้างแบบเดียวกับหลังบ้าน ไม่งั้นส่ง honor_expired ไม่ได้ทั้งที่หมดอายุแล้ว */
function isBankOrderExpired(tx) {
    const expiresAt = tx.metadata?.expires_at
        ? new Date(tx.metadata.expires_at).getTime()
        : new Date(tx.created_at).getTime() + BANK_ORDER_TTL_MS;

    return expiresAt < Date.now();
}

/**
 * ยืนยันว่าเงินเข้าบัญชีจริง
 *
 * ⚠️ จุดเดียวที่ทำให้เหรียญออกจากคำสั่งซื้อทางโอนเงิน
 *    ต้องเปิดดูรายการเดินบัญชีจริงก่อนกดเสมอ ระบบตรวจแทนไม่ได้
 */
function confirmBank(tx) {
    if (confirmingId.value) return;

    const ref = bankReference(tx) || tx.uuid;
    const expired = isBankOrderExpired(tx);
    const warning = expired ? t('admin.tokenSales.confirm.bankExpiredWarning') : '';
    if (!window.confirm(t('admin.tokenSales.confirm.bank', { ref, warning }))) {
        return;
    }

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/bank/${tx.id}/confirm`, { honor_expired: expired }, {
        preserveScroll: true,
        onFinish: () => { confirmingId.value = null; },
    });
}

/** ปฏิเสธรายการที่ไม่มีเงินเข้า */
function rejectBank(tx) {
    if (confirmingId.value) return;

    const reason = window.prompt(t('admin.tokenSales.confirm.rejectPrompt'), t('admin.tokenSales.confirm.rejectDefault'));
    if (!reason) return;

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/bank/${tx.id}/reject`, { reason }, {
        preserveScroll: true,
        onFinish: () => { confirmingId.value = null; },
    });
}

/**
 * เข้าคิวจ่ายใหม่ — เซิร์ฟเวอร์ส่งยอดมาเฉพาะออเดอร์ที่ไม่มีรายการจ่ายที่อาจจ่ายออกอยู่
 * เช่น รายการเดิมถูกปฏิเสธหรือ revert หรือคิวล้มตอนกดยืนยัน
 */
function requeuePayout(tx) {
    if (confirmingId.value) return;

    const reference = bankReference(tx) || tx.uuid;
    if (!window.confirm(t('admin.tokenSales.confirm.requeuePayout', { amount: tx.requeue_payout_tpix, ref: reference }))) {
        return;
    }

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/bank/${tx.id}/requeue`, {}, {
        preserveScroll: true,
        onFinish: () => { confirmingId.value = null; },
    });
}

/**
 * กู้ออเดอร์หลัง chargeback — หน้านี้ไม่รู้โควตาล่าสุด เซิร์ฟเวอร์ตรวจ Stripe โควตา และเพดานกระเป๋าใหม่ตอนกดจริง
 * ถ้าถูกปฏิเสธ เหตุผลขึ้นที่แถบแจ้งเตือนของ layout และออเดอร์ยังอยู่ในกล่องเดิม
 */
function restoreDispute(tx) {
    if (confirmingId.value) return;

    const amount = Number(tx.tpix_amount).toLocaleString('en-US');
    if (!window.confirm(t('admin.tokenSales.confirm.restoreDispute', { uuid: tx.uuid, amount }))) {
        return;
    }

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/dispute/${tx.id}/restore`, {}, {
        preserveScroll: true,
        onFinish: () => { confirmingId.value = null; },
    });
}

/**
 * กู้ยอด claim — เซิร์ฟเวอร์ส่งยอดมาเฉพาะแถวที่พิสูจน์ได้ว่าเงินไม่ออกและยังไม่เคยกู้
 * ส่วนที่เกินวงเงินต่อครั้งของปลายทางจะคืนเป็นสิทธิ์ claim แอดมินต้องเห็นก่อนกด
 */
function requeueClaim(tx) {
    if (confirmingId.value) return;

    const reference = bankReference(tx) || tx.uuid;
    const returned = tx.requeue_claim_returned_tpix
        ? t('admin.tokenSales.confirm.claimReturned', { amount: tx.requeue_claim_returned_tpix })
        : '';
    if (!window.confirm(t('admin.tokenSales.confirm.requeueClaim', { amount: tx.requeue_claim_tpix, ref: reference, returned }))) {
        return;
    }

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/claims/${tx.id}/requeue`, {}, {
        preserveScroll: true,
        onFinish: () => { confirmingId.value = null; },
    });
}

// ===== Helpers =====
function fmt(n) {
    if (!n) return '0';
    if (n >= 1e9) return (n / 1e9).toFixed(2) + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
    return Number(n).toLocaleString();
}

function statusBadge(s) {
    const m = {
        draft: 'bg-gray-500/20 text-gray-400',
        upcoming: 'bg-blue-500/20 text-blue-400',
        active: 'bg-green-500/20 text-green-400',
        paused: 'bg-yellow-500/20 text-yellow-400',
        completed: 'bg-purple-500/20 text-purple-400',
        confirmed: 'bg-green-500/20 text-green-400',
        pending: 'bg-yellow-500/20 text-yellow-400',
        failed: 'bg-red-500/20 text-red-400',
        cancelled: 'bg-red-500/20 text-red-400',
    };
    return m[s] || 'bg-gray-500/20 text-gray-400';
}

/** ป้ายสถานะตามภาษา — สถานะที่ไม่มีคำแปลแสดงค่าจากเซิร์ฟเวอร์ตรงๆ */
function statusLabel(s) {
    if (!s) return '';
    const key = `admin.tokenSales.status.${s}`;
    const label = t(key);
    return label === key ? s : label;
}

function walletStatusLabel(s) {
    const key = `admin.tokenSales.walletStatus.${s || 'unknown'}`;
    const label = t(key);
    return label === key ? s : label;
}

/**
 * ที่อยู่ master wallet — เซิร์ฟเวอร์ส่ง 'Not configured' เป็นข้อความอังกฤษตายตัวเมื่อยังไม่ตั้งค่า
 * ตัดสินจาก status แล้วแสดงคำแปลแทน
 */
const walletAddressLabel = computed(() => (
    props.walletInfo.status === 'not_configured' || !props.walletInfo.address
        ? t('admin.tokenSales.wallet.notConfigured')
        : props.walletInfo.address
));

/** ยอดคงเหลือ — ตอน RPC ล้ม เซิร์ฟเวอร์ส่ง 'RPC Error' มาแทนตัวเลข */
const walletBalanceLabel = computed(() => (
    props.walletInfo.status === 'rpc_error'
        ? t('admin.tokenSales.walletStatus.rpc_error')
        : (props.walletInfo.balance_formatted || '0 TPIX')
));

/** วันที่สำหรับแสดงผล ตามภาษาที่เลือก */
function displayDate(d) {
    return new Date(d).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US');
}
</script>

<template>
    <Head :title="t('admin.tokenSales.header.pageTitle')" />

    <AdminLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-white">🪙 {{ t('admin.tokenSales.header.title') }}</h1>
                    <p class="text-gray-400 text-sm">{{ t('admin.tokenSales.header.subtitle') }}</p>
                </div>
            </div>

            <!-- ⚠️ No Cash Withdrawal Warning -->
            <div class="mb-6 p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/30">
                <p class="text-sm text-yellow-400 font-medium">
                    ⚠️ <strong>{{ t('admin.tokenSales.warning.label') }}</strong> {{ t('admin.tokenSales.warning.text') }}
                </p>
            </div>

            <!-- ร้านยังถือเงินอยู่หลัง chargeback แต่ผู้ซื้อไม่มีเหรียญ — อยู่นอกแท็บเพราะต้องมีคนตัดสินทุกแถว -->
            <div v-if="disputesAwaitingRestore.length" class="mb-6 glass-dark rounded-xl border border-trading-red/30 p-4">
                <h2 class="text-sm font-semibold text-trading-red">{{ t('admin.tokenSales.disputes.title') }}</h2>
                <p class="text-xs text-gray-400 mt-1 mb-3">{{ t('admin.tokenSales.disputes.desc') }}</p>
                <div
                    v-for="tx in disputesAwaitingRestore" :key="tx.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2 border-t border-white/5 text-xs"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="text-gray-400 font-mono">{{ tx.uuid?.slice(0, 8) }}...</span>
                        <span class="text-primary-400 font-mono">{{ tx.wallet_address?.slice(0, 10) }}...</span>
                        <span class="text-trading-green font-medium">{{ fmt(tx.tpix_amount) }} TPIX</span>
                        <span class="text-white">${{ Number(tx.payment_usd_value).toFixed(2) }}</span>
                    </div>
                    <span v-if="tx.restore_refusal" class="text-gray-500">{{ tx.restore_refusal }}</span>
                    <button
                        v-else
                        class="px-2 py-1 rounded-lg bg-trading-green/20 text-trading-green hover:bg-trading-green/30 transition disabled:opacity-40"
                        :disabled="confirmingId === tx.id"
                        @click="restoreDispute(tx)"
                    >{{ t('admin.tokenSales.disputes.restore') }}</button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 mb-6 p-1 rounded-xl bg-white/5">
                <button
                    v-for="tab in tabs" :key="tab.id"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                    :class="activeTab === tab.id ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white'"
                    @click="activeTab = tab.id"
                >{{ tab.icon }} {{ t(tab.labelKey) }}</button>
            </div>

            <!-- ==================== TAB: Overview ==================== -->
            <div v-if="activeTab === 'overview'">
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-white">{{ fmt(stats.total_supply) }}</p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.tokenSales.overview.totalSupply') }}</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-trading-green">{{ fmt(stats.total_sold) }}</p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.tokenSales.overview.totalSold') }}</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-primary-400">${{ fmt(stats.total_raised_usd) }}</p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.tokenSales.overview.totalRaised') }}</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-accent-400">{{ stats.total_buyers || 0 }}</p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.tokenSales.overview.uniqueBuyers') }}</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-white">{{ stats.total_transactions || 0 }}</p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.tokenSales.overview.totalTransactions') }}</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-yellow-400">{{ stats.pending_transactions || 0 }}</p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.tokenSales.overview.pending') }}</p>
                    </div>
                </div>

                <!-- Master Wallet Quick View -->
                <div class="glass-dark rounded-xl border border-white/10 p-5">
                    <h3 class="text-lg font-bold text-white mb-3">💰 {{ t('admin.tokenSales.tabs.wallet') }}</h3>
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">{{ t('admin.tokenSales.overview.address') }}</p>
                            <p class="text-sm font-mono text-primary-400 break-all">{{ walletAddressLabel }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">{{ t('admin.tokenSales.overview.balance') }}</p>
                            <p class="text-lg font-bold text-white">{{ walletBalanceLabel }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">{{ t('admin.tokenSales.overview.status') }}</p>
                            <span class="px-2 py-1 text-xs rounded-full" :class="walletInfo.status === 'connected' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'">
                                {{ walletStatusLabel(walletInfo.status) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: Sales & Phases ==================== -->
            <div v-if="activeTab === 'sales'">
                <!-- เปิดรอบขาย — เฟสแรกเริ่มนับจากวันที่กด ไม่ใช่วันที่ตั้งไว้ล่วงหน้า -->
                <SaleLaunchPanel :launch="launch" :can-manage="canManageMoney" />

                <SaleCryptoPanel :crypto="crypto" :can-manage="canManageMoney" />

                <div class="flex justify-end mb-4">
                    <button v-if="canManageMoney" class="btn-primary px-4 py-2 text-sm font-semibold" @click="openCreateSale">{{ t('admin.tokenSales.sales.newSale') }}</button>
                </div>

                <div class="space-y-4">
                    <div v-for="sale in sales" :key="sale.id" class="glass-dark rounded-xl border border-white/10 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-white">{{ sale.name }}</h3>
                                <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(sale.status)">{{ statusLabel(sale.status) }}</span>
                            </div>
                            <div class="flex gap-2">
                                <button v-if="canManageMoney" class="btn-secondary px-3 py-1 text-xs" @click="openAddPhase(sale)">{{ t('admin.tokenSales.sales.addPhase') }}</button>
                                <button class="btn-secondary px-3 py-1 text-xs" @click="openEditSale(sale)">{{ t('admin.tokenSales.sales.edit') }}</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4 text-sm">
                            <div><span class="text-gray-500">{{ t('admin.tokenSales.sales.supply') }}</span><p class="text-white font-medium">{{ fmt(sale.total_supply_for_sale) }}</p></div>
                            <div><span class="text-gray-500">{{ t('admin.tokenSales.sales.sold') }}</span><p class="text-trading-green font-medium">{{ fmt(sale.total_sold) }}</p></div>
                            <div><span class="text-gray-500">{{ t('admin.tokenSales.sales.raised') }}</span><p class="text-primary-400 font-medium">${{ fmt(sale.total_raised_usd) }}</p></div>
                            <div><span class="text-gray-500">{{ t('admin.tokenSales.sales.payment') }}</span><p class="text-white">{{ saleChannelsLabel(sale.accept_currencies) }}</p></div>
                        </div>

                        <!-- Phases Table -->
                        <div v-if="sale.phases?.length" class="border-t border-white/5 pt-3 overflow-x-auto">
                            <table class="w-full text-sm min-w-[600px]">
                                <thead><tr class="text-gray-500 text-xs">
                                    <th class="text-left py-1 px-2">{{ t('admin.tokenSales.phases.phase') }}</th>
                                    <th class="text-right py-1 px-2">{{ t('admin.tokenSales.phases.price') }}</th>
                                    <th class="text-right py-1 px-2">{{ t('admin.tokenSales.phases.allocation') }}</th>
                                    <th class="text-right py-1 px-2">{{ t('admin.tokenSales.phases.sold') }}</th>
                                    <th class="text-right py-1 px-2">%</th>
                                    <th class="text-center py-1 px-2">{{ t('admin.tokenSales.phases.tge') }}</th>
                                    <th class="text-center py-1 px-2">{{ t('admin.tokenSales.phases.vesting') }}</th>
                                    <th class="text-center py-1 px-2">{{ t('admin.tokenSales.phases.status') }}</th>
                                    <th class="text-center py-1 px-2">{{ t('admin.tokenSales.phases.period') }}</th>
                                    <th class="text-right py-1 px-2">{{ t('admin.tokenSales.phases.actions') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="p in sale.phases" :key="p.id" class="border-t border-white/5">
                                        <td class="py-1.5 px-2 text-white">{{ p.name }}</td>
                                        <td class="py-1.5 px-2 text-right text-primary-400 font-medium">${{ p.price_usd }}</td>
                                        <td class="py-1.5 px-2 text-right text-gray-300">{{ fmt(p.allocation) }}</td>
                                        <td class="py-1.5 px-2 text-right text-trading-green">{{ fmt(p.sold) }}</td>
                                        <td class="py-1.5 px-2 text-right text-gray-400">{{ p.allocation > 0 ? ((p.sold / p.allocation) * 100).toFixed(1) : 0 }}%</td>
                                        <td class="py-1.5 px-2 text-center text-gray-300">{{ p.vesting_tge_percent }}%</td>
                                        <td class="py-1.5 px-2 text-center text-gray-400 text-xs">{{ t('admin.tokenSales.phases.vestingSchedule', { cliff: p.vesting_cliff_days, duration: p.vesting_duration_days }) }}</td>
                                        <td class="py-1.5 px-2 text-center">
                                            <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(p.status)">{{ statusLabel(p.status) }}</span>
                                            <!--
                                                ป้าย status อย่างเดียวหลอกตาได้ — เฟสที่ค้าง active ไว้แต่เลยวันปิด
                                                จะถูกปฏิเสธทุกการซื้อ ต้องเห็นตรงนี้ทันทีว่าต้องเข้าไปแก้
                                            -->
                                            <span
                                                v-if="isPhaseExpired(p)"
                                                class="ml-1 px-2 py-0.5 text-xs rounded-full bg-trading-red/20 text-trading-red border border-trading-red/30"
                                                :title="t('admin.tokenSales.phases.expiredTitle')"
                                            >{{ t('admin.tokenSales.phases.expired') }}</span>
                                        </td>
                                        <td class="py-1.5 px-2 text-center text-xs text-gray-400 whitespace-nowrap">
                                            {{ p.starts_at ? displayDate(p.starts_at) : '—' }}
                                            &rarr;
                                            {{ p.ends_at ? displayDate(p.ends_at) : '—' }}
                                        </td>
                                        <td class="py-1.5 px-2 text-right">
                                            <button
                                                v-if="canManageMoney"
                                                type="button"
                                                class="px-2 py-1 text-xs rounded-lg bg-primary-500/20 text-primary-300 hover:bg-primary-500/30 transition"
                                                @click="openEditPhase(sale, p)"
                                            >{{ t('admin.tokenSales.phases.edit') }}</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="!sales.length" class="text-center py-16 text-gray-500">
                        <p class="text-lg mb-2">{{ t('admin.tokenSales.sales.empty') }}</p>
                        <button v-if="canManageMoney" class="btn-primary px-6 py-2 mt-2" @click="openCreateSale">{{ t('admin.tokenSales.sales.createFirst') }}</button>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: Transactions ==================== -->
            <div v-if="activeTab === 'transactions'">
                <div class="glass-dark rounded-xl border border-white/10 overflow-hidden overflow-x-auto">
                    <table class="w-full text-sm min-w-[600px]">
                        <thead><tr class="border-b border-white/10 text-gray-500 text-xs">
                            <th class="text-left py-3 px-4">{{ t('admin.tokenSales.tx.id') }}</th>
                            <th class="text-left py-3 px-4">{{ t('admin.tokenSales.tx.wallet') }}</th>
                            <th class="text-left py-3 px-4 hidden sm:table-cell">{{ t('admin.tokenSales.tx.payment') }}</th>
                            <th class="text-right py-3 px-4">{{ t('admin.tokenSales.tx.usd') }}</th>
                            <th class="text-right py-3 px-4">{{ t('admin.tokenSales.tx.tpix') }}</th>
                            <th class="text-center py-3 px-4">{{ t('admin.tokenSales.tx.status') }}</th>
                            <th class="text-center py-3 px-4">{{ t('admin.tokenSales.tx.reference') }}</th>
                            <th class="text-right py-3 px-4 hidden md:table-cell">{{ t('admin.tokenSales.tx.date') }}</th>
                            <th class="text-right py-3 px-4">{{ t('admin.tokenSales.tx.actions') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="tx in transactions.data" :key="tx.id" class="border-t border-white/5 hover:bg-white/5">
                                <td class="py-2 px-4 text-gray-400 font-mono text-xs">{{ tx.uuid?.slice(0, 8) }}...</td>
                                <td class="py-2 px-4 text-primary-400 font-mono text-xs">{{ tx.wallet_address?.slice(0, 10) }}...</td>
                                <td class="py-2 px-4 text-gray-300 hidden sm:table-cell">{{ tx.payment_currency }}</td>
                                <td class="py-2 px-4 text-right text-white">${{ Number(tx.payment_usd_value).toFixed(2) }}</td>
                                <td class="py-2 px-4 text-right text-trading-green font-medium">{{ fmt(tx.tpix_amount) }}</td>
                                <td class="py-2 px-4 text-center"><span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(tx.status)">{{ statusLabel(tx.status) }}</span></td>
                                <td class="py-2 px-4 text-center">
                                    <code v-if="bankReference(tx)" class="text-xs text-primary-300">{{ bankReference(tx) }}</code>
                                    <span v-else class="text-gray-600 text-xs">—</span>
                                </td>
                                <td class="py-2 px-4 text-right text-gray-500 text-xs hidden md:table-cell">{{ displayDate(tx.created_at) }}</td>
                                <td class="py-2 px-4 text-right whitespace-nowrap">
                                    <!--
                                        ปุ่มโผล่เฉพาะรายการโอนเงินที่ยังรอยืนยัน
                                        รายการทางบัตรยืนยันเองอัตโนมัติผ่าน webhook ของ Stripe
                                    -->
                                    <template v-if="canManageMoney && isPendingBankTransfer(tx)">
                                        <button
                                            class="px-2 py-1 text-xs rounded-lg bg-trading-green/20 text-trading-green hover:bg-trading-green/30 transition disabled:opacity-40"
                                            :disabled="confirmingId === tx.id"
                                            @click="confirmBank(tx)"
                                        >{{ t('admin.tokenSales.tx.received') }}</button>
                                        <button
                                            class="ml-1 px-2 py-1 text-xs rounded-lg bg-white/5 text-gray-400 hover:text-trading-red hover:bg-trading-red/10 transition disabled:opacity-40"
                                            :disabled="confirmingId === tx.id"
                                            @click="rejectBank(tx)"
                                        >{{ t('admin.tokenSales.tx.reject') }}</button>
                                    </template>
                                    <template v-else-if="tx.requeue_payout_tpix || tx.requeue_claim_tpix">
                                        <button
                                            v-if="tx.requeue_payout_tpix"
                                            class="px-2 py-1 text-xs rounded-lg bg-primary-500/20 text-primary-400 hover:bg-primary-500/30 transition disabled:opacity-40"
                                            :disabled="confirmingId === tx.id"
                                            @click="requeuePayout(tx)"
                                        >{{ t('admin.tokenSales.tx.requeuePayout') }}</button>
                                        <button
                                            v-if="tx.requeue_claim_tpix"
                                            class="ml-1 px-2 py-1 text-xs rounded-lg bg-primary-500/20 text-primary-400 hover:bg-primary-500/30 transition disabled:opacity-40"
                                            :disabled="confirmingId === tx.id"
                                            @click="requeueClaim(tx)"
                                        >{{ t('admin.tokenSales.tx.requeueClaim') }}</button>
                                    </template>
                                    <span v-else class="text-gray-600 text-xs">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="!transactions.data?.length" class="text-center py-12 text-gray-500">
                        <p>{{ t('admin.tokenSales.tx.empty') }}</p>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: Master Wallet ==================== -->
            <div v-if="activeTab === 'wallet'">
                <div class="space-y-4">
                    <div class="glass-dark rounded-xl border border-white/10 p-6">
                        <h3 class="text-lg font-bold text-white mb-4">💰 {{ t('admin.tokenSales.wallet.title') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <p class="text-xs text-gray-500 mb-1">{{ t('admin.tokenSales.wallet.address') }}</p>
                                <p class="text-sm font-mono text-primary-400 bg-white/5 p-3 rounded-lg break-all">{{ walletAddressLabel }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">{{ t('admin.tokenSales.wallet.balance') }}</p>
                                <p class="text-3xl font-bold text-white">{{ walletBalanceLabel }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">{{ t('admin.tokenSales.wallet.chain') }}</p>
                                <p class="text-sm text-gray-300">{{ t('admin.tokenSales.wallet.chainValue', { id: walletInfo.chain_id || 7000 }) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">{{ t('admin.tokenSales.wallet.rpcUrl') }}</p>
                                <p class="text-sm font-mono text-gray-400">{{ walletInfo.rpc_url || 'https://rpc1.tpix.online' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass-dark rounded-xl border border-white/10 p-6">
                        <h3 class="text-lg font-bold text-white mb-3">🔐 {{ t('admin.tokenSales.wallet.validatorKeys') }}</h3>
                        <p class="text-sm text-gray-400 mb-3">{{ t('admin.tokenSales.wallet.validatorDesc') }}</p>
                        <div class="bg-white/5 p-4 rounded-lg font-mono text-sm text-gray-400 space-y-1">
                            <p>📁 ~/tpix-infrastructure/tpix-chain/data/consensus/validator.key</p>
                            <p>📁 ~/tpix-infrastructure/tpix-chain/data/consensus/validator-bls.key</p>
                        </div>
                        <p class="text-xs text-yellow-400 mt-3">⚠️ {{ t('admin.tokenSales.wallet.keyWarning') }}</p>
                    </div>

                    <div class="glass-dark rounded-xl border border-white/10 p-6">
                        <h3 class="text-lg font-bold text-white mb-3">🔗 {{ t('admin.tokenSales.wallet.quickLinks') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <a href="https://explorer.tpix.online" target="_blank" class="flex items-center gap-2 p-3 rounded-lg bg-white/5 hover:bg-white/10 text-primary-400 text-sm">
                                🔍 {{ t('admin.tokenSales.wallet.explorer') }}
                            </a>
                            <a href="https://rpc1.tpix.online" target="_blank" class="flex items-center gap-2 p-3 rounded-lg bg-white/5 hover:bg-white/10 text-primary-400 text-sm">
                                🌐 {{ t('admin.tokenSales.wallet.rpcEndpoint') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Sale Modal ===== -->
            <Teleport to="body">
                <div v-if="showSaleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showSaleModal = false">
                    <div class="glass-dark rounded-xl border border-white/10 p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 class="text-xl font-bold text-white mb-4">{{ editingSale ? t('admin.tokenSales.saleModal.editTitle') : t('admin.tokenSales.saleModal.createTitle') }}</h3>
                        <form @submit.prevent="submitSale" class="space-y-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.name') }}</label>
                                <input v-model="saleForm.name" class="trading-input w-full" :placeholder="t('admin.tokenSales.saleModal.namePlaceholder')" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.description') }}</label>
                                <textarea v-model="saleForm.description" class="trading-input w-full" rows="2" />
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.supply') }}</label>
                                    <input v-model.number="saleForm.total_supply_for_sale" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.status') }}</label>
                                    <select v-model="saleForm.status" class="trading-input w-full">
                                        <option value="draft">{{ t('admin.tokenSales.status.draft') }}</option>
                                        <option value="upcoming">{{ t('admin.tokenSales.status.upcoming') }}</option>
                                        <option value="active">{{ t('admin.tokenSales.status.active') }}</option>
                                        <option value="paused">{{ t('admin.tokenSales.status.paused') }}</option>
                                        <option value="completed">{{ t('admin.tokenSales.status.completed') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.wallet') }}</label>
                                <input v-model="saleForm.sale_wallet_address" class="trading-input w-full font-mono text-sm" placeholder="0x..." />
                                <p class="mt-1 text-xs text-gray-500">{{ t('admin.tokenSales.saleModal.walletHint') }}</p>
                                <p v-if="saleForm.errors.sale_wallet_address" class="mt-1 text-sm text-red-400">{{ saleForm.errors.sale_wallet_address }}</p>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.channels') }}</label>
                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm text-gray-300">
                                        <input type="checkbox" checked disabled class="w-4 h-4 rounded bg-dark-800 border-white/20" />
                                        <span>{{ t('admin.tokenSales.saleModal.card') }}</span>
                                        <span class="text-xs text-gray-500">{{ t('admin.tokenSales.saleModal.alwaysOn') }}</span>
                                    </label>
                                    <label v-for="channel in OPTIONAL_CHANNELS" :key="channel.id" class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                        <input v-model="saleForm.accept_currencies" type="checkbox" :value="channel.id" class="w-4 h-4 rounded bg-dark-800 border-white/20" />
                                        <span>{{ t(`${channel.keyBase}.label`) }}</span>
                                        <span class="text-xs text-gray-500">{{ t(`${channel.keyBase}.hint`) }}</span>
                                    </label>
                                </div>
                                <p v-if="saleForm.errors.accept_currencies" class="mt-1 text-sm text-red-400">{{ saleForm.errors.accept_currencies }}</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.startsAt') }}</label>
                                    <input v-model="saleForm.starts_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.saleModal.endsAt') }}</label>
                                    <input v-model="saleForm.ends_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button v-if="canManageMoney" type="submit" class="btn-primary px-6 py-2 font-semibold" :disabled="saleForm.processing">{{ editingSale ? t('admin.tokenSales.saleModal.update') : t('admin.tokenSales.saleModal.create') }}</button>
                                <p v-else class="text-xs text-amber-300 self-center">{{ t('admin.tokenSales.saleModal.superAdminOnly') }}</p>
                                <button type="button" class="btn-secondary px-6 py-2" @click="showSaleModal = false">{{ t('common.cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- ===== Phase Modal ===== -->
            <Teleport to="body">
                <div v-if="showPhaseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showPhaseModal = false">
                    <div class="glass-dark rounded-xl border border-white/10 p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 class="text-xl font-bold text-white mb-4">{{ phaseForm.id ? t('admin.tokenSales.phaseModal.editTitle') : t('admin.tokenSales.phaseModal.addTitle') }}</h3>
                        <form @submit.prevent="submitPhase" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.name') }}</label>
                                    <input v-model="phaseForm.name" class="trading-input w-full" :placeholder="t('admin.tokenSales.phaseModal.namePlaceholder')" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.order') }}</label>
                                    <input v-model.number="phaseForm.phase_order" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.duration') }}</label>
                                    <input v-model.number="phaseForm.duration_days" type="number" min="1" class="trading-input w-full" />
                                    <p class="text-xs text-gray-500 mt-1">{{ t('admin.tokenSales.phaseModal.durationHint') }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.price') }}</label>
                                    <input v-model.number="phaseForm.price_usd" type="number" step="0.001" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.allocation') }}</label>
                                    <input v-model.number="phaseForm.allocation" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.minPurchase') }}</label>
                                    <input v-model.number="phaseForm.min_purchase" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.maxPurchase') }}</label>
                                    <input v-model.number="phaseForm.max_purchase" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <h4 class="text-sm font-semibold text-white pt-2">{{ t('admin.tokenSales.phaseModal.vesting') }}</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.tge') }}</label>
                                    <input v-model.number="phaseForm.vesting_tge_percent" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.cliff') }}</label>
                                    <input v-model.number="phaseForm.vesting_cliff_days" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.vestingDuration') }}</label>
                                    <input v-model.number="phaseForm.vesting_duration_days" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.status') }}</label>
                                    <select v-model="phaseForm.status" class="trading-input w-full">
                                        <option value="upcoming">{{ t('admin.tokenSales.status.upcoming') }}</option>
                                        <option value="active">{{ t('admin.tokenSales.status.active') }}</option>
                                        <option value="completed">{{ t('admin.tokenSales.status.completed') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.startsAt') }}</label>
                                    <input v-model="phaseForm.starts_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <!--
                                        ช่องนี้เคยไม่มีในฟอร์มเลย ทั้งที่เป็นค่าที่ตัดสินว่าเฟสยังขายได้ไหม
                                        เฟสที่เลย ends_at จะถูกปฏิเสธทุกการซื้อ แต่แอดมินแก้ไม่ได้
                                    -->
                                    <label class="block text-sm text-gray-400 mb-1">{{ t('admin.tokenSales.phaseModal.endsAt') }}</label>
                                    <input v-model="phaseForm.ends_at" type="datetime-local" class="trading-input w-full" />
                                    <p class="mt-1 text-xs text-gray-500">{{ t('admin.tokenSales.phaseModal.endsAtHint') }}</p>
                                </div>
                            </div>
                            <!-- แสดง error จากเซิร์ฟเวอร์ ไม่งั้นกดบันทึกแล้วเงียบ ไม่รู้ว่าติดอะไร -->
                            <div v-if="Object.keys(phaseForm.errors).length" class="rounded-lg border border-trading-red/30 bg-trading-red/10 px-3 py-2">
                                <p v-for="(msg, field) in phaseForm.errors" :key="field" class="text-xs text-trading-red">{{ msg }}</p>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary px-6 py-2 font-semibold" :disabled="phaseForm.processing">{{ phaseForm.processing ? t('admin.tokenSales.phaseModal.saving') : (phaseForm.id ? t('admin.tokenSales.phaseModal.saveChanges') : t('admin.tokenSales.phaseModal.add')) }}</button>
                                <button type="button" class="btn-secondary px-6 py-2" @click="showPhaseModal = false">{{ t('common.cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>
        </div>
    </AdminLayout>
</template>
