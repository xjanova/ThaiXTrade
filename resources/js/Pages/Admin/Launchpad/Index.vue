<script setup>
/**
 * TPIX TRADE — หลังบ้าน Launchpad
 *
 * หน้าขาย (ลงทะเบียนจากเชน · อนุมัติ/ซ่อน/ปฏิเสธ) · คำสั่งซื้อที่ต้องให้คนดู · ธุรกรรมของตัวแทน · รายงานจากผู้ใช้
 * ไม่มีปุ่มเคลื่อนเงิน — การแก้คำสั่ง/ธุรกรรมที่ค้างทำด้วย CLI บนเซิร์ฟเวอร์
 * ทุกปุ่มที่เปลี่ยนสถานะมีกล่องยืนยัน และกันกดเบิ้ล (busy + 409 จากเซิร์ฟเวอร์)
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { explorerUrl, shortAddr } from '@/utils/launchpad';

const { t } = useTranslation();

const props = defineProps({
    tab: { type: String, default: 'sales' },
    filters: { type: Object, default: () => ({ status: 'attention' }) },
    state: { type: Object, default: () => ({}) },
    sales: { type: Array, default: () => [] },
    orders: { type: Object, default: () => ({ data: [] }) },
    txs: { type: Array, default: () => [] },
    reports: { type: Object, default: () => ({ data: [] }) },
    counts: { type: Object, default: () => ({}) },
    explorers: { type: Object, default: () => ({}) },
});

const activeTab = ref(props.tab);
const busy = ref(false);
const notice = ref(null); // { tone, key, params, detail }
const saleAddress = ref('');
const pending = ref(null); // { sale, action }
const note = ref('');

const tpixExplorer = computed(() => props.explorers?.[4289] ?? Object.values(props.explorers ?? {})[0] ?? null);
const orderStatuses = ['attention', 'all', 'awaiting_payment', 'paid', 'buying', 'delivered', 'refunding', 'refunded', 'expired', 'cancelled', 'needs_review'];

function goTab(tab, extra = {}) {
    activeTab.value = tab;
    router.get('/admin/launchpad', { tab, ...extra }, { preserveState: true, preserveScroll: true, replace: true });
}

function fail(err) {
    const code = err?.response?.data?.error?.code;
    const key = code && t(`launchpad.admin.errors.${code}`) !== `launchpad.admin.errors.${code}` ? `launchpad.admin.errors.${code}` : 'launchpad.admin.errors.SERVER_ERROR';
    // ข้อความจากเซิร์ฟเวอร์ (ไทย) ไว้ให้แอดมินเห็นรายละเอียด เช่นชิ้นส่วนไหนไม่ตรง — ไม่มีข้อมูลลับ
    notice.value = { tone: 'error', key, params: {}, detail: err?.response?.data?.error?.message ?? null };
}

async function registerSale() {
    const address = saleAddress.value.trim();
    if (busy.value || !/^0x[0-9a-fA-F]{40}$/.test(address)) {
        if (!busy.value) notice.value = { tone: 'error', key: 'launchpad.admin.errors.INVALID_ADDRESS', params: {} };
        return;
    }
    busy.value = true;
    notice.value = null;
    try {
        const { data } = await axios.post('/admin/launchpad/register', { sale_address: address });
        notice.value = { tone: 'success', key: 'launchpad.admin.register.done', params: { id: data.data.id, symbol: data.data.symbol, status: t(`launchpad.admin.saleStatus.${data.data.status}`) } };
        saleAddress.value = '';
        router.reload({ only: ['sales', 'counts'] });
    } catch (err) {
        fail(err);
    } finally {
        busy.value = false;
    }
}

function ask(sale, action) {
    note.value = '';
    pending.value = { sale, action };
}

const confirmKey = { approve: 'confirmApprove', hide: 'confirmHide', reject: 'confirmReject', pending: 'confirmPending' };
const confirmMessage = computed(() => (pending.value ? t(`launchpad.admin.${confirmKey[pending.value.action]}`, { symbol: pending.value.sale.token.symbol }) : ''));

async function runPending() {
    const p = pending.value;
    if (!p || busy.value) return;
    busy.value = true;
    notice.value = null;
    try {
        await axios.post(`/admin/launchpad/sales/${p.sale.id}/status`, { action: p.action, note: note.value.trim() || null });
        notice.value = { tone: 'success', key: 'launchpad.admin.updated', params: {} };
        router.reload({ only: ['sales', 'counts'] });
    } catch (err) {
        fail(err);
    } finally {
        busy.value = false;
        pending.value = null;
    }
}

async function setReport(report, status) {
    if (busy.value) return;
    busy.value = true;
    notice.value = null;
    try {
        await axios.post(`/admin/launchpad/reports/${report.id}`, { status });
        router.reload({ only: ['reports', 'sales', 'counts'] });
    } catch (err) {
        fail(err);
    } finally {
        busy.value = false;
    }
}

const statusStyle = {
    pending: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    approved: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    rejected: 'bg-gray-600/30 text-gray-400 border-gray-500/30',
    hidden: 'bg-gray-700/30 text-gray-400 border-gray-600/30',
    open: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    reviewed: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    dismissed: 'bg-gray-700/30 text-gray-400 border-gray-600/30',
    needs_review: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    delivered: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    stuck: 'bg-red-500/20 text-red-300 border-red-500/30',
    failed: 'bg-red-500/20 text-red-300 border-red-500/30',
    confirmed: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
};
const pill = (s) => ['px-2 py-0.5 rounded-full text-[11px] border whitespace-nowrap', statusStyle[s] ?? 'bg-blue-500/20 text-blue-300 border-blue-500/30'];
const dateText = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const tx = (hash) => explorerUrl(tpixExplorer.value, 'tx', hash);
const tabCount = (tab) => ({ sales: props.counts.pending_sales, orders: props.counts.needs_review, txs: props.counts.unresolved_txs, reports: props.counts.open_reports }[tab] ?? 0);
const noticeClass = (tone) => (tone === 'success' ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-red-500/10 text-red-300 border-red-500/30');
</script>

<template>
    <Head :title="t('launchpad.admin.title')" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-white">🚀 {{ t('launchpad.admin.title') }}</h1>
                <p class="text-sm text-gray-400 mt-1">{{ t('launchpad.admin.subtitle') }}</p>
            </div>

            <div class="grid md:grid-cols-2 gap-3">
                <div class="glass-card rounded-xl p-4 border border-white/10">
                    <p :class="['text-sm font-semibold', state.enabled ? 'text-emerald-300' : 'text-amber-300']" data-test="enabled">
                        {{ state.enabled ? t('launchpad.admin.enabled') : t('launchpad.admin.disabled') }}
                    </p>
                    <p class="text-xs text-gray-400 mt-1">{{ t('launchpad.admin.agent') }}:
                        <span v-if="state.agent_address" class="font-mono text-gray-200">{{ state.agent_address }}</span>
                        <span v-else class="text-amber-300">{{ t('launchpad.admin.agentMissing') }}</span>
                    </p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-white/10 text-xs text-gray-400">{{ t('launchpad.admin.cliOnly') }}</div>
            </div>

            <div v-if="notice" :class="['px-4 py-3 rounded-xl text-sm border', noticeClass(notice.tone)]" data-test="notice">
                {{ t(notice.key, notice.params) }}
                <span v-if="notice.detail" class="block text-xs opacity-80 mt-1">{{ notice.detail }}</span>
            </div>

            <div class="flex flex-wrap gap-2 border-b border-white/10">
                <button v-for="tb in ['sales', 'orders', 'txs', 'reports']" :key="tb" type="button" :data-test="`tab-${tb}`"
                    :class="['px-4 py-2 text-sm -mb-px border-b-2', activeTab === tb ? 'border-primary-500 text-white' : 'border-transparent text-gray-400 hover:text-white']"
                    @click="goTab(tb)">
                    {{ t(`launchpad.admin.tabs.${tb}`) }}
                    <span v-if="tabCount(tb)" class="ml-1 px-1.5 rounded-full bg-amber-500/30 text-amber-200 text-[10px]">{{ tabCount(tb) }}</span>
                </button>
            </div>

            <!-- หน้าขาย -->
            <template v-if="activeTab === 'sales'">
                <div class="glass-card rounded-xl p-4 border border-white/10 space-y-2">
                    <h2 class="text-sm font-semibold text-white">{{ t('launchpad.admin.register.title') }}</h2>
                    <p class="text-xs text-gray-400">{{ t('launchpad.admin.register.body') }}</p>
                    <div class="flex gap-2">
                        <input v-model="saleAddress" type="text" class="trading-input flex-1 font-mono text-sm" :placeholder="t('launchpad.admin.register.placeholder')" data-test="sale-address" />
                        <button type="button" class="btn-primary px-4 text-sm" :disabled="busy" data-test="register" @click="registerSale">{{ t('launchpad.admin.register.submit') }}</button>
                    </div>
                </div>

                <div class="overflow-x-auto glass-card rounded-xl border border-white/10">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-400">
                            <tr class="border-b border-white/10">
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.token') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.status') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.window') }}</th>
                                <th class="text-right p-3">{{ t('launchpad.admin.cols.orders') }}</th>
                                <th class="text-right p-3">{{ t('launchpad.admin.cols.reports') }}</th>
                                <th class="text-right p-3">{{ t('launchpad.admin.cols.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!sales.length"><td colspan="6" class="p-4 text-center text-gray-500">{{ t('launchpad.admin.noRows') }}</td></tr>
                            <tr v-for="s in sales" :key="s.id" class="border-b border-white/5" :data-test="`sale-row-${s.id}`">
                                <td class="p-3">
                                    <p class="text-white font-semibold">#{{ s.id }} {{ s.token.symbol }}</p>
                                    <p class="text-xs text-gray-400">{{ s.token.name }}</p>
                                    <a :href="explorerUrl(tpixExplorer, 'address', s.sale_address)" target="_blank" rel="noopener noreferrer" class="text-[11px] font-mono text-primary-400">{{ shortAddr(s.sale_address) }}</a>
                                </td>
                                <td class="p-3">
                                    <span :class="pill(s.status)">{{ t(`launchpad.admin.saleStatus.${s.status}`) }}</span>
                                    <p v-if="s.status_note" class="text-[11px] text-gray-500 mt-1 max-w-[16rem] break-words">{{ s.status_note }}</p>
                                </td>
                                <td class="p-3 text-xs text-gray-300">{{ dateText(s.starts_at) }}<br />{{ dateText(s.ends_at) }}</td>
                                <td class="p-3 text-right text-gray-200">{{ s.orders_count }}<span v-if="s.open_orders_count" class="text-amber-300"> ({{ s.open_orders_count }})</span></td>
                                <td class="p-3 text-right" :class="s.open_reports ? 'text-amber-300 font-semibold' : 'text-gray-500'">{{ s.open_reports }}</td>
                                <td class="p-3 text-right space-x-2 whitespace-nowrap">
                                    <button v-if="s.status !== 'approved'" type="button" class="text-emerald-300 text-xs" :disabled="busy" :data-test="`approve-${s.id}`" @click="ask(s, 'approve')">{{ t('launchpad.admin.approve') }}</button>
                                    <button v-if="s.status === 'approved'" type="button" class="text-amber-300 text-xs" :disabled="busy" :data-test="`hide-${s.id}`" @click="ask(s, 'hide')">{{ t('launchpad.admin.hide') }}</button>
                                    <button v-if="s.status === 'pending'" type="button" class="text-red-300 text-xs" :disabled="busy" @click="ask(s, 'reject')">{{ t('launchpad.admin.reject') }}</button>
                                    <button v-if="['hidden', 'rejected'].includes(s.status)" type="button" class="text-gray-300 text-xs" :disabled="busy" @click="ask(s, 'pending')">{{ t('launchpad.admin.toPending') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- คำสั่งซื้อ -->
            <template v-else-if="activeTab === 'orders'">
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-gray-400">{{ t('launchpad.admin.orderStatusFilter') }}</span>
                    <select :value="filters.status" class="trading-input text-sm" @change="goTab('orders', { status: $event.target.value })">
                        <option v-for="s in orderStatuses" :key="s" :value="s">
                            {{ s === 'attention' ? t('launchpad.admin.attention') : s === 'all' ? t('launchpad.admin.all') : t(`launchpad.status.${s}`) }}
                        </option>
                    </select>
                </div>
                <div class="overflow-x-auto glass-card rounded-xl border border-white/10">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-400">
                            <tr class="border-b border-white/10">
                                <th class="text-left p-3">#</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.sale') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.buyer') }}</th>
                                <th class="text-right p-3">{{ t('launchpad.admin.cols.amount') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.status') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.error') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!orders.data.length"><td colspan="7" class="p-4 text-center text-gray-500">{{ t('launchpad.admin.noRows') }}</td></tr>
                            <tr v-for="o in orders.data" :key="o.id" class="border-b border-white/5">
                                <td class="p-3 text-gray-400 font-mono text-xs">{{ o.id }}<br />{{ o.public_id.slice(0, 8) }}</td>
                                <td class="p-3 text-white">{{ o.symbol }}</td>
                                <td class="p-3 font-mono text-xs text-gray-300">{{ shortAddr(o.buyer) }}</td>
                                <td class="p-3 text-right font-mono text-gray-200">{{ o.amount }}</td>
                                <td class="p-3"><span :class="pill(o.status)">{{ t(`launchpad.status.${o.status}`) }}</span><span v-if="o.attempts" class="text-[11px] text-gray-500 ml-1">×{{ o.attempts }}</span></td>
                                <td class="p-3 text-[11px] text-gray-400 max-w-[18rem] break-words">
                                    {{ o.last_error ?? '—' }}
                                    <a v-if="o.delivery_tx_hash" :href="tx(o.delivery_tx_hash)" target="_blank" rel="noopener noreferrer" class="block text-primary-400 font-mono">{{ shortAddr(o.delivery_tx_hash) }}</a>
                                    <a v-if="o.refund_tx_hash" :href="tx(o.refund_tx_hash)" target="_blank" rel="noopener noreferrer" class="block text-amber-300 font-mono">{{ shortAddr(o.refund_tx_hash) }}</a>
                                </td>
                                <td class="p-3 text-xs text-gray-400">{{ dateText(o.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ธุรกรรมของตัวแทน -->
            <template v-else-if="activeTab === 'txs'">
                <div class="overflow-x-auto glass-card rounded-xl border border-white/10">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-400">
                            <tr class="border-b border-white/10">
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.kind') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.nonce') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.tx') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.status') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.error') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!txs.length"><td colspan="6" class="p-4 text-center text-gray-500">{{ t('launchpad.admin.noRows') }}</td></tr>
                            <tr v-for="x in txs" :key="x.id" class="border-b border-white/5">
                                <td class="p-3 text-white">{{ x.kind }}<span v-if="x.order_id" class="text-[11px] text-gray-500"> · order {{ x.order_id }}</span></td>
                                <td class="p-3 font-mono text-gray-300">{{ x.nonce }}</td>
                                <td class="p-3"><a :href="tx(x.tx_hash)" target="_blank" rel="noopener noreferrer" class="font-mono text-xs text-primary-400">{{ shortAddr(x.tx_hash) }}</a></td>
                                <td class="p-3"><span :class="pill(x.status)">{{ x.status }}</span></td>
                                <td class="p-3 text-[11px] text-gray-400 max-w-[18rem] break-words">{{ x.last_error ?? '—' }}</td>
                                <td class="p-3 text-xs text-gray-400">{{ dateText(x.broadcast_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- รายงาน -->
            <template v-else>
                <div class="overflow-x-auto glass-card rounded-xl border border-white/10">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-400">
                            <tr class="border-b border-white/10">
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.sale') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.reason') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.details') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.status') }}</th>
                                <th class="text-left p-3">{{ t('launchpad.admin.cols.created') }}</th>
                                <th class="text-right p-3">{{ t('launchpad.admin.cols.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!reports.data.length"><td colspan="6" class="p-4 text-center text-gray-500">{{ t('launchpad.admin.noRows') }}</td></tr>
                            <tr v-for="r in reports.data" :key="r.id" class="border-b border-white/5" :data-test="`report-row-${r.id}`">
                                <td class="p-3 text-white">#{{ r.sale_id }} {{ r.symbol }}</td>
                                <td class="p-3 text-gray-200">{{ t(`launchpad.report.reasons.${r.reason}`) }}</td>
                                <td class="p-3 text-xs text-gray-300 max-w-[24rem] whitespace-pre-wrap break-words">{{ r.details ?? '—' }}<span v-if="r.reporter_wallet" class="block font-mono text-gray-500 mt-1">{{ shortAddr(r.reporter_wallet) }}</span></td>
                                <td class="p-3"><span :class="pill(r.status)">{{ r.status }}</span></td>
                                <td class="p-3 text-xs text-gray-400">{{ dateText(r.created_at) }}</td>
                                <td class="p-3 text-right space-x-2 whitespace-nowrap">
                                    <template v-if="r.status === 'open'">
                                        <button type="button" class="text-emerald-300 text-xs" :disabled="busy" @click="setReport(r, 'reviewed')">{{ t('launchpad.admin.markReviewed') }}</button>
                                        <button type="button" class="text-gray-300 text-xs" :disabled="busy" @click="setReport(r, 'dismissed')">{{ t('launchpad.admin.dismiss') }}</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <!-- ยืนยันเปลี่ยนสถานะหน้าขาย + หมายเหตุ -->
        <Teleport to="body">
        <div v-if="pending" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" role="dialog" aria-modal="true" @click.self="!busy && (pending = null)">
            <div class="w-full max-w-md glass-card rounded-2xl p-6 space-y-4" data-test="status-dialog">
                <p class="text-sm text-gray-200 break-words">{{ confirmMessage }}</p>
                <input v-model="note" type="text" maxlength="300" class="trading-input w-full text-sm" :placeholder="t('launchpad.admin.note')" data-test="status-note" />
                <div class="flex gap-3">
                    <button type="button" class="flex-1 px-4 py-2.5 rounded-xl text-sm text-gray-300 bg-dark-800 border border-white/10" :disabled="busy" @click="pending = null">{{ t('common.cancel') }}</button>
                    <button type="button" :class="['flex-1 px-4 py-2.5 rounded-xl text-sm text-white', pending.action === 'approve' ? 'bg-primary-500 hover:bg-primary-600' : 'bg-red-500 hover:bg-red-600']"
                        :disabled="busy" data-test="status-confirm" @click="runPending">
                        {{ t(`launchpad.admin.${pending.action === 'pending' ? 'toPending' : pending.action}`) }}
                    </button>
                </div>
            </div>
        </div>
        </Teleport>
    </AdminLayout>
</template>
