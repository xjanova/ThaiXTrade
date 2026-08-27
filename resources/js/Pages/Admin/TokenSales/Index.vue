<script setup>
/**
 * TPIX TRADE - Admin Token Sales + Token Control
 * จัดการรอบขายเหรียญ TPIX + ดู master wallet + transactions
 * รองรับ: Stripe + USDT payment | ไม่มีการถอนเป็นเงินสด
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SaleLaunchPanel from '@/Components/Admin/SaleLaunchPanel.vue';

const props = defineProps({
    sales: { type: Array, default: () => [] },
    transactions: { type: Object, default: () => ({ data: [] }) },
    walletInfo: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    // ความพร้อม + ตารางที่จะได้ถ้ากดเปิดรอบขายวันนี้
    launch: { type: Object, default: () => ({}) },
});

// Tab ปัจจุบัน
const activeTab = ref('overview');
const tabs = [
    { id: 'overview', label: '📊 Overview' },
    { id: 'sales', label: '🏷️ Sales & Phases' },
    { id: 'transactions', label: '📋 Transactions' },
    { id: 'wallet', label: '💰 Master Wallet' },
];

// Modal states
const showSaleModal = ref(false);
const showPhaseModal = ref(false);
const editingSale = ref(null);

// ฟอร์ม Token Sale
const saleForm = useForm({
    id: null,
    name: '',
    description: '',
    total_supply_for_sale: 700000000,
    accept_currencies: ['USDT', 'STRIPE'],
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
    saleForm.accept_currencies = ['USDT', 'STRIPE'];
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
        accept_currencies: sale.accept_currencies || ['USDT', 'STRIPE'],
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

/**
 * ยืนยันว่าเงินเข้าบัญชีจริง
 *
 * ⚠️ จุดเดียวที่ทำให้เหรียญออกจากคำสั่งซื้อทางโอนเงิน
 *    ต้องเปิดดูรายการเดินบัญชีจริงก่อนกดเสมอ ระบบตรวจแทนไม่ได้
 */
function confirmBank(tx) {
    if (confirmingId.value) return;

    const ref = bankReference(tx) || tx.uuid;
    if (!window.confirm(`ยืนยันว่าได้รับเงินโอนของรายการ ${ref} แล้วจริงหรือไม่?\n\nกดยืนยันแล้วระบบจะนับเป็นยอดขายและเข้าคิวจ่ายเหรียญทันที`)) {
        return;
    }

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/bank/${tx.id}/confirm`, {}, {
        preserveScroll: true,
        onFinish: () => { confirmingId.value = null; },
    });
}

/** ปฏิเสธรายการที่ไม่มีเงินเข้า */
function rejectBank(tx) {
    if (confirmingId.value) return;

    const reason = window.prompt('เหตุผลที่ปฏิเสธ (บันทึกไว้ในระบบ)', 'ไม่พบเงินเข้าตามรหัสอ้างอิง');
    if (!reason) return;

    confirmingId.value = tx.id;
    router.post(`/admin/token-sales/bank/${tx.id}/reject`, { reason }, {
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
</script>

<template>
    <Head title="Token Sales & Control" />

    <AdminLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-white">🪙 Token Sales & Control</h1>
                    <p class="text-gray-400 text-sm">จัดการรอบขาย TPIX + Master Wallet | USDT exchange only — ไม่มีการถอนเป็นเงินสด</p>
                </div>
            </div>

            <!-- ⚠️ No Cash Withdrawal Warning -->
            <div class="mb-6 p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/30">
                <p class="text-sm text-yellow-400 font-medium">
                    ⚠️ <strong>สำคัญ:</strong> TPIX เป็น utility token แลกเปลี่ยนได้กับ USDT เท่านั้น ไม่สามารถถอนเป็นเงินสดได้ ข้อความนี้แสดงให้ผู้ซื้อทราบทุกจุด
                </p>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 mb-6 p-1 rounded-xl bg-white/5">
                <button
                    v-for="tab in tabs" :key="tab.id"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                    :class="activeTab === tab.id ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white'"
                    @click="activeTab = tab.id"
                >{{ tab.label }}</button>
            </div>

            <!-- ==================== TAB: Overview ==================== -->
            <div v-if="activeTab === 'overview'">
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-white">{{ fmt(stats.total_supply) }}</p>
                        <p class="text-sm text-gray-400 mt-1">Total Supply</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-trading-green">{{ fmt(stats.total_sold) }}</p>
                        <p class="text-sm text-gray-400 mt-1">Total Sold</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-primary-400">${{ fmt(stats.total_raised_usd) }}</p>
                        <p class="text-sm text-gray-400 mt-1">Total Raised</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-accent-400">{{ stats.total_buyers || 0 }}</p>
                        <p class="text-sm text-gray-400 mt-1">Unique Buyers</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-white">{{ stats.total_transactions || 0 }}</p>
                        <p class="text-sm text-gray-400 mt-1">Total Transactions</p>
                    </div>
                    <div class="glass-dark rounded-xl border border-white/10 p-5 text-center">
                        <p class="text-3xl font-bold text-yellow-400">{{ stats.pending_transactions || 0 }}</p>
                        <p class="text-sm text-gray-400 mt-1">Pending</p>
                    </div>
                </div>

                <!-- Master Wallet Quick View -->
                <div class="glass-dark rounded-xl border border-white/10 p-5">
                    <h3 class="text-lg font-bold text-white mb-3">💰 Master Wallet</h3>
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Address</p>
                            <p class="text-sm font-mono text-primary-400 break-all">{{ walletInfo.address || 'Not set' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Balance</p>
                            <p class="text-lg font-bold text-white">{{ walletInfo.balance_formatted || '0 TPIX' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Status</p>
                            <span class="px-2 py-1 text-xs rounded-full" :class="walletInfo.status === 'connected' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'">
                                {{ walletInfo.status || 'unknown' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: Sales & Phases ==================== -->
            <div v-if="activeTab === 'sales'">
                <!-- เปิดรอบขาย — เฟสแรกเริ่มนับจากวันที่กด ไม่ใช่วันที่ตั้งไว้ล่วงหน้า -->
                <SaleLaunchPanel :launch="launch" />

                <div class="flex justify-end mb-4">
                    <button class="btn-primary px-4 py-2 text-sm font-semibold" @click="openCreateSale">+ New Sale</button>
                </div>

                <div class="space-y-4">
                    <div v-for="sale in sales" :key="sale.id" class="glass-dark rounded-xl border border-white/10 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-white">{{ sale.name }}</h3>
                                <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(sale.status)">{{ sale.status }}</span>
                            </div>
                            <div class="flex gap-2">
                                <button class="btn-secondary px-3 py-1 text-xs" @click="openAddPhase(sale)">+ Phase</button>
                                <button class="btn-secondary px-3 py-1 text-xs" @click="openEditSale(sale)">Edit</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4 text-sm">
                            <div><span class="text-gray-500">Supply</span><p class="text-white font-medium">{{ fmt(sale.total_supply_for_sale) }}</p></div>
                            <div><span class="text-gray-500">Sold</span><p class="text-trading-green font-medium">{{ fmt(sale.total_sold) }}</p></div>
                            <div><span class="text-gray-500">Raised</span><p class="text-primary-400 font-medium">${{ fmt(sale.total_raised_usd) }}</p></div>
                            <div><span class="text-gray-500">Payment</span><p class="text-white">Stripe + USDT</p></div>
                        </div>

                        <!-- Phases Table -->
                        <div v-if="sale.phases?.length" class="border-t border-white/5 pt-3 overflow-x-auto">
                            <table class="w-full text-sm min-w-[600px]">
                                <thead><tr class="text-gray-500 text-xs">
                                    <th class="text-left py-1 px-2">Phase</th>
                                    <th class="text-right py-1 px-2">Price</th>
                                    <th class="text-right py-1 px-2">Allocation</th>
                                    <th class="text-right py-1 px-2">Sold</th>
                                    <th class="text-right py-1 px-2">%</th>
                                    <th class="text-center py-1 px-2">TGE</th>
                                    <th class="text-center py-1 px-2">Vesting</th>
                                    <th class="text-center py-1 px-2">Status</th>
                                    <th class="text-center py-1 px-2">ช่วงเวลา</th>
                                    <th class="text-right py-1 px-2">จัดการ</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="p in sale.phases" :key="p.id" class="border-t border-white/5">
                                        <td class="py-1.5 px-2 text-white">{{ p.name }}</td>
                                        <td class="py-1.5 px-2 text-right text-primary-400 font-medium">${{ p.price_usd }}</td>
                                        <td class="py-1.5 px-2 text-right text-gray-300">{{ fmt(p.allocation) }}</td>
                                        <td class="py-1.5 px-2 text-right text-trading-green">{{ fmt(p.sold) }}</td>
                                        <td class="py-1.5 px-2 text-right text-gray-400">{{ p.allocation > 0 ? ((p.sold / p.allocation) * 100).toFixed(1) : 0 }}%</td>
                                        <td class="py-1.5 px-2 text-center text-gray-300">{{ p.vesting_tge_percent }}%</td>
                                        <td class="py-1.5 px-2 text-center text-gray-400 text-xs">{{ p.vesting_cliff_days }}d cliff / {{ p.vesting_duration_days }}d</td>
                                        <td class="py-1.5 px-2 text-center">
                                            <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(p.status)">{{ p.status }}</span>
                                            <!--
                                                ป้าย status อย่างเดียวหลอกตาได้ — เฟสที่ค้าง active ไว้แต่เลยวันปิด
                                                จะถูกปฏิเสธทุกการซื้อ ต้องเห็นตรงนี้ทันทีว่าต้องเข้าไปแก้
                                            -->
                                            <span
                                                v-if="isPhaseExpired(p)"
                                                class="ml-1 px-2 py-0.5 text-xs rounded-full bg-trading-red/20 text-trading-red border border-trading-red/30"
                                                title="สถานะเป็น active แต่เลยวันปิดแล้ว — ระบบจะปฏิเสธทุกการซื้อ"
                                            >หมดอายุ</span>
                                        </td>
                                        <td class="py-1.5 px-2 text-center text-xs text-gray-400 whitespace-nowrap">
                                            {{ p.starts_at ? new Date(p.starts_at).toLocaleDateString('th-TH') : '—' }}
                                            &rarr;
                                            {{ p.ends_at ? new Date(p.ends_at).toLocaleDateString('th-TH') : '—' }}
                                        </td>
                                        <td class="py-1.5 px-2 text-right">
                                            <button
                                                type="button"
                                                class="px-2 py-1 text-xs rounded-lg bg-primary-500/20 text-primary-300 hover:bg-primary-500/30 transition"
                                                @click="openEditPhase(sale, p)"
                                            >แก้ไข</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="!sales.length" class="text-center py-16 text-gray-500">
                        <p class="text-lg mb-2">No token sales yet</p>
                        <button class="btn-primary px-6 py-2 mt-2" @click="openCreateSale">Create First Sale</button>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: Transactions ==================== -->
            <div v-if="activeTab === 'transactions'">
                <div class="glass-dark rounded-xl border border-white/10 overflow-hidden overflow-x-auto">
                    <table class="w-full text-sm min-w-[600px]">
                        <thead><tr class="border-b border-white/10 text-gray-500 text-xs">
                            <th class="text-left py-3 px-4">ID</th>
                            <th class="text-left py-3 px-4">Wallet</th>
                            <th class="text-left py-3 px-4 hidden sm:table-cell">Payment</th>
                            <th class="text-right py-3 px-4">USD</th>
                            <th class="text-right py-3 px-4">TPIX</th>
                            <th class="text-center py-3 px-4">Status</th>
                            <th class="text-center py-3 px-4">รหัสอ้างอิง</th>
                            <th class="text-right py-3 px-4 hidden md:table-cell">Date</th>
                            <th class="text-right py-3 px-4">จัดการ</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="tx in transactions.data" :key="tx.id" class="border-t border-white/5 hover:bg-white/5">
                                <td class="py-2 px-4 text-gray-400 font-mono text-xs">{{ tx.uuid?.slice(0, 8) }}...</td>
                                <td class="py-2 px-4 text-primary-400 font-mono text-xs">{{ tx.wallet_address?.slice(0, 10) }}...</td>
                                <td class="py-2 px-4 text-gray-300 hidden sm:table-cell">{{ tx.payment_currency }}</td>
                                <td class="py-2 px-4 text-right text-white">${{ Number(tx.payment_usd_value).toFixed(2) }}</td>
                                <td class="py-2 px-4 text-right text-trading-green font-medium">{{ fmt(tx.tpix_amount) }}</td>
                                <td class="py-2 px-4 text-center"><span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadge(tx.status)">{{ tx.status }}</span></td>
                                <td class="py-2 px-4 text-center">
                                    <code v-if="bankReference(tx)" class="text-xs text-primary-300">{{ bankReference(tx) }}</code>
                                    <span v-else class="text-gray-600 text-xs">—</span>
                                </td>
                                <td class="py-2 px-4 text-right text-gray-500 text-xs hidden md:table-cell">{{ new Date(tx.created_at).toLocaleDateString() }}</td>
                                <td class="py-2 px-4 text-right whitespace-nowrap">
                                    <!--
                                        ปุ่มโผล่เฉพาะรายการโอนเงินที่ยังรอยืนยัน
                                        รายการทางบัตรยืนยันเองอัตโนมัติผ่าน webhook ของ Stripe
                                    -->
                                    <template v-if="isPendingBankTransfer(tx)">
                                        <button
                                            class="px-2 py-1 text-xs rounded-lg bg-trading-green/20 text-trading-green hover:bg-trading-green/30 transition disabled:opacity-40"
                                            :disabled="confirmingId === tx.id"
                                            @click="confirmBank(tx)"
                                        >ได้รับเงินแล้ว</button>
                                        <button
                                            class="ml-1 px-2 py-1 text-xs rounded-lg bg-white/5 text-gray-400 hover:text-trading-red hover:bg-trading-red/10 transition disabled:opacity-40"
                                            :disabled="confirmingId === tx.id"
                                            @click="rejectBank(tx)"
                                        >ปฏิเสธ</button>
                                    </template>
                                    <span v-else class="text-gray-600 text-xs">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="!transactions.data?.length" class="text-center py-12 text-gray-500">
                        <p>No transactions yet</p>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: Master Wallet ==================== -->
            <div v-if="activeTab === 'wallet'">
                <div class="space-y-4">
                    <div class="glass-dark rounded-xl border border-white/10 p-6">
                        <h3 class="text-lg font-bold text-white mb-4">💰 Master Wallet — TPIX Chain</h3>
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Wallet Address</p>
                                <p class="text-sm font-mono text-primary-400 bg-white/5 p-3 rounded-lg break-all">{{ walletInfo.address || 'Not configured' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Balance</p>
                                <p class="text-3xl font-bold text-white">{{ walletInfo.balance_formatted || '0 TPIX' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Chain</p>
                                <p class="text-sm text-gray-300">TPIX Chain (ID: {{ walletInfo.chain_id || 7000 }})</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">RPC URL</p>
                                <p class="text-sm font-mono text-gray-400">{{ walletInfo.rpc_url || 'https://rpc.tpix.online' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass-dark rounded-xl border border-white/10 p-6">
                        <h3 class="text-lg font-bold text-white mb-3">🔐 Validator Keys</h3>
                        <p class="text-sm text-gray-400 mb-3">Private key / mnemonic ของ validator อยู่ที่เซิร์ฟเวอร์:</p>
                        <div class="bg-white/5 p-4 rounded-lg font-mono text-sm text-gray-400 space-y-1">
                            <p>📁 ~/tpix-infrastructure/tpix-chain/data/consensus/validator.key</p>
                            <p>📁 ~/tpix-infrastructure/tpix-chain/data/consensus/validator-bls.key</p>
                        </div>
                        <p class="text-xs text-yellow-400 mt-3">⚠️ อย่าเปิดเผย private key กับใคร! ใช้ SSH เข้าเซิร์ฟเวอร์เพื่อดู</p>
                    </div>

                    <div class="glass-dark rounded-xl border border-white/10 p-6">
                        <h3 class="text-lg font-bold text-white mb-3">🔗 Quick Links</h3>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <a href="https://explorer.tpix.online" target="_blank" class="flex items-center gap-2 p-3 rounded-lg bg-white/5 hover:bg-white/10 text-primary-400 text-sm">
                                🔍 TPIX Explorer
                            </a>
                            <a href="https://rpc.tpix.online" target="_blank" class="flex items-center gap-2 p-3 rounded-lg bg-white/5 hover:bg-white/10 text-primary-400 text-sm">
                                🌐 RPC Endpoint
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Sale Modal ===== -->
            <Teleport to="body">
                <div v-if="showSaleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showSaleModal = false">
                    <div class="glass-dark rounded-xl border border-white/10 p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 class="text-xl font-bold text-white mb-4">{{ editingSale ? 'Edit Sale' : 'Create Token Sale' }}</h3>
                        <form @submit.prevent="submitSale" class="space-y-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Name</label>
                                <input v-model="saleForm.name" class="trading-input w-full" placeholder="TPIX ICO Round 1" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Description</label>
                                <textarea v-model="saleForm.description" class="trading-input w-full" rows="2" />
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Supply for Sale</label>
                                    <input v-model.number="saleForm.total_supply_for_sale" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Status</label>
                                    <select v-model="saleForm.status" class="trading-input w-full">
                                        <option value="draft">Draft</option>
                                        <option value="upcoming">Upcoming</option>
                                        <option value="active">Active</option>
                                        <option value="paused">Paused</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Sale Wallet Address</label>
                                <input v-model="saleForm.sale_wallet_address" class="trading-input w-full font-mono text-sm" placeholder="0x..." />
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Starts At</label>
                                    <input v-model="saleForm.starts_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Ends At</label>
                                    <input v-model="saleForm.ends_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary px-6 py-2 font-semibold" :disabled="saleForm.processing">{{ editingSale ? 'Update' : 'Create' }}</button>
                                <button type="button" class="btn-secondary px-6 py-2" @click="showSaleModal = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- ===== Phase Modal ===== -->
            <Teleport to="body">
                <div v-if="showPhaseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showPhaseModal = false">
                    <div class="glass-dark rounded-xl border border-white/10 p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                        <h3 class="text-xl font-bold text-white mb-4">{{ phaseForm.id ? 'แก้ไขเฟสรอบขาย' : 'Add Sale Phase' }}</h3>
                        <form @submit.prevent="submitPhase" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Phase Name</label>
                                    <input v-model="phaseForm.name" class="trading-input w-full" placeholder="Private Sale" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Order</label>
                                    <input v-model.number="phaseForm.phase_order" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">ความยาวเฟส (วัน)</label>
                                    <input v-model.number="phaseForm.duration_days" type="number" min="1" class="trading-input w-full" />
                                    <p class="text-xs text-gray-500 mt-1">ใช้คำนวณวันจริงตอนกด "เปิดรอบขาย"</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Price (USD)</label>
                                    <input v-model.number="phaseForm.price_usd" type="number" step="0.001" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Allocation (TPIX)</label>
                                    <input v-model.number="phaseForm.allocation" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Min Purchase</label>
                                    <input v-model.number="phaseForm.min_purchase" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Max Purchase</label>
                                    <input v-model.number="phaseForm.max_purchase" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <h4 class="text-sm font-semibold text-white pt-2">Vesting</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">TGE %</label>
                                    <input v-model.number="phaseForm.vesting_tge_percent" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Cliff (days)</label>
                                    <input v-model.number="phaseForm.vesting_cliff_days" type="number" class="trading-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Duration (days)</label>
                                    <input v-model.number="phaseForm.vesting_duration_days" type="number" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Status</label>
                                    <select v-model="phaseForm.status" class="trading-input w-full">
                                        <option value="upcoming">Upcoming</option>
                                        <option value="active">Active</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-400 mb-1">Starts At</label>
                                    <input v-model="phaseForm.starts_at" type="datetime-local" class="trading-input w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <!--
                                        ช่องนี้เคยไม่มีในฟอร์มเลย ทั้งที่เป็นค่าที่ตัดสินว่าเฟสยังขายได้ไหม
                                        เฟสที่เลย ends_at จะถูกปฏิเสธทุกการซื้อ แต่แอดมินแก้ไม่ได้
                                    -->
                                    <label class="block text-sm text-gray-400 mb-1">Ends At</label>
                                    <input v-model="phaseForm.ends_at" type="datetime-local" class="trading-input w-full" />
                                    <p class="mt-1 text-xs text-gray-500">ว่างไว้ = ไม่จำกัดวันปิด</p>
                                </div>
                            </div>
                            <!-- แสดง error จากเซิร์ฟเวอร์ ไม่งั้นกดบันทึกแล้วเงียบ ไม่รู้ว่าติดอะไร -->
                            <div v-if="Object.keys(phaseForm.errors).length" class="rounded-lg border border-trading-red/30 bg-trading-red/10 px-3 py-2">
                                <p v-for="(msg, field) in phaseForm.errors" :key="field" class="text-xs text-trading-red">{{ msg }}</p>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="btn-primary px-6 py-2 font-semibold" :disabled="phaseForm.processing">{{ phaseForm.processing ? 'กำลังบันทึก...' : (phaseForm.id ? 'บันทึกการแก้ไข' : 'Add Phase') }}</button>
                                <button type="button" class="btn-secondary px-6 py-2" @click="showPhaseModal = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>
        </div>
    </AdminLayout>
</template>
