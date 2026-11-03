<script setup>
/**
 * TPIX TRADE - คิวจ่ายเงินจากกระเป๋าร้อน
 * สร้างรายการ -> อนุมัติ -> (ส่งขึ้นเชน: ยังปิดอยู่)
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
const k = (key) => `admin.treasuryPayouts.${key}`;

const props = defineProps({
    payouts: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    readiness: { type: Object, default: () => ({ ready: false, blocking: [] }) },
    hotWallet: { type: Object, default: () => ({}) },
    whitelist: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
    spentToday: { type: String, default: '0' },
    explorerUrl: { type: String, default: '' },
});

function fmtTpix(v) {
    if (v === null || v === undefined) return '—';
    const [i, f] = String(v).split('.');
    const withCommas = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (!f) return withCommas;
    const frac = f.replace(/0+$/, '').slice(0, 6);
    return frac ? `${withCommas}.${frac}` : withCommas;
}

const shortAddr = (a) => (a ? `${a.slice(0, 10)}…${a.slice(-8)}` : '—');
const shortHash = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-8)}` : '—');

// เก็บ key ไม่เก็บข้อความ — เรียก t() ตอน render เพื่อให้สลับภาษาได้ทันที
const statusStyle = {
    pending: { label: 'status.pending', cls: 'bg-amber-500/20 text-amber-400 border-amber-500/30' },
    approved: { label: 'status.approved', cls: 'bg-blue-500/20 text-blue-400 border-blue-500/30' },
    broadcasting: { label: 'status.broadcasting', cls: 'bg-violet-500/20 text-violet-400 border-violet-500/30' },
    confirmed: { label: 'status.confirmed', cls: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' },
    rejected: { label: 'status.rejected', cls: 'bg-gray-600/30 text-gray-400 border-gray-500/30' },
    failed: { label: 'status.failed', cls: 'bg-red-500/20 text-red-400 border-red-500/30' },
};
const st = (s) => statusStyle[s] ?? statusStyle.pending;

const statusFilters = [
    { key: null, label: 'filters.all' },
    { key: 'pending', label: 'status.pending' },
    { key: 'approved', label: 'status.approved' },
    { key: 'confirmed', label: 'status.confirmed' },
    { key: 'rejected', label: 'status.rejected' },
    { key: 'failed', label: 'status.failed' },
];

// ค่า purpose ที่ส่งไปเซิร์ฟเวอร์ — แสดงผลเป็นคำแปล ค่าที่ไม่รู้จักแสดงตามเดิม
const purposeKeys = ['masternode', 'token_sale', 'refund', 'other'];
const purposeLabel = (p) => (purposeKeys.includes(p) ? t(k(`purpose.${p}`)) : p);

/* ── ฟอร์มสร้างรายการ ─────────────────────────────────────────────── */
const showForm = ref(false);
const form = ref({ to_address: '', amount: '', purpose: 'other', memo: '' });
const formErrors = ref([]);
const isSubmitting = ref(false);

/* idempotency key สร้างครั้งเดียวตอนเปิดฟอร์ม แล้วใช้ยาวจนกว่าจะสำเร็จ
 * กดปุ่มซ้ำระหว่างรอ response จะได้ key เดิม เซิร์ฟเวอร์จึงคืนรายการเดิม
 * ไม่ใช่สร้างใหม่ */
const idempotencyKey = ref('');
function openForm() {
    idempotencyKey.value = `ui-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    formErrors.value = [];
    showForm.value = true;
}

async function submitPayout() {
    if (isSubmitting.value) return;      // กันดับเบิลคลิกฝั่งหน้าจอ
    isSubmitting.value = true;
    formErrors.value = [];
    try {
        const { data } = await axios.post('/admin/treasury/payouts', {
            ...form.value,
            idempotency_key: idempotencyKey.value,
        });
        if (data.success) {
            showForm.value = false;
            form.value = { to_address: '', amount: '', purpose: 'other', memo: '' };
            router.reload({ only: ['payouts', 'spentToday'] });
        }
    } catch (e) {
        formErrors.value = e.response?.data?.errors ?? [e.response?.data?.message ?? t(k('errors.createFailed'))];
    } finally {
        isSubmitting.value = false;
    }
}

/* ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────── */
const busyId = ref(null);
const actionError = ref('');
const actionNotice = ref('');

async function approve(payout) {
    if (busyId.value) return;
    if (!confirm(t(k('confirm.approve'), { amount: fmtTpix(payout.amount_tpix), address: payout.to_address }))) return;
    busyId.value = payout.id;
    actionError.value = '';
    try {
        await axios.post(`/admin/treasury/payouts/${payout.id}/approve`);
        router.reload({ only: ['payouts', 'spentToday'] });
    } catch (e) {
        actionError.value = (e.response?.data?.errors ?? [e.response?.data?.message]).join(' · ');
    } finally { busyId.value = null; }
}

async function reject(payout) {
    if (busyId.value) return;
    const reason = prompt(t(k('prompt.rejectReason')));
    if (!reason) return;
    busyId.value = payout.id;
    actionError.value = '';
    try {
        await axios.post(`/admin/treasury/payouts/${payout.id}/reject`, { reason });
        router.reload({ only: ['payouts'] });
    } catch (e) {
        actionError.value = e.response?.data?.message ?? t(k('errors.rejectFailed'));
    } finally { busyId.value = null; }
}

async function broadcast(payout) {
    if (busyId.value) return;
    if (!confirm(
        t(k('confirm.broadcast'), { amount: fmtTpix(payout.amount_tpix), address: payout.to_address })
    )) return;

    busyId.value = payout.id;
    actionError.value = '';
    try {
        const { data } = await axios.post(`/admin/treasury/payouts/${payout.id}/broadcast`);
        actionNotice.value = data.message ?? t(k('notice.queued'));
        router.reload({ only: ['payouts'] });
    } catch (e) {
        // 423 Locked = ยังไม่พร้อมใช้งาน ซึ่งเป็นพฤติกรรมที่ตั้งใจ
        actionError.value = e.response?.status === 423
            ? t(k('errors.notReady'))
            : ((e.response?.data?.errors ?? [e.response?.data?.message ?? t(k('errors.broadcastFailed'))]).join(' · '));
    } finally { busyId.value = null; }
}

// label จากเซิร์ฟเวอร์เป็นภาษาไทย — แปลตาม key ที่รู้จัก ค่าที่ไม่รู้จักแสดง label เดิม
const blockingKeys = ['flag', 'keystore', 'keystore_matches_wallet', 'passphrase', 'funded', 'rpc'];
const blockingLabels = computed(() => (props.readiness.blocking ?? []).map((b) => (
    blockingKeys.includes(b.key) ? t(k(`readiness.blocking.${b.key}`)) : b.label
)));

function filterStatus(status) {
    router.get('/admin/treasury/payouts', status ? { status } : {}, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="t(k('meta.title'))" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t(k('header.title')) }}</h1>
                    <p class="text-sm text-gray-400 mt-1">{{ t(k('header.subtitle')) }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/admin/treasury"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                        {{ t(k('header.back')) }}
                    </Link>
                    <button @click="openForm"
                            class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all">
                        + {{ t(k('header.create')) }}
                    </button>
                </div>
            </div>

            <!-- สถานะการเปิดใช้งาน -->
            <div v-if="!readiness.ready" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                <div class="flex items-start gap-3">
                    <span class="text-xl leading-none">&#9888;</span>
                    <div>
                        <div class="text-amber-300 font-bold text-sm">{{ t(k('readiness.title')) }}</div>
                        <p class="text-sm text-gray-400 mt-1">
                            {{ t(k('readiness.desc')) }}
                        </p>
                        <div class="mt-2 text-xs text-gray-500">
                            {{ t(k('readiness.missing')) }} <span class="text-amber-400">{{ blockingLabels.join(' · ') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- สรุปวงเงิน -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="glass-card rounded-xl p-4">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ t(k('summary.hotWallet')) }}</div>
                    <div class="text-lg font-black text-white">
                        {{ hotWallet.readable ? fmtTpix(hotWallet.balance_tpix) : '—' }}
                    </div>
                </div>
                <div class="glass-card rounded-xl p-4">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ t(k('summary.spentToday')) }}</div>
                    <div class="text-lg font-black text-primary-400">{{ fmtTpix(spentToday) }}</div>
                </div>
                <div class="glass-card rounded-xl p-4">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ t(k('summary.perTx')) }}</div>
                    <div class="text-lg font-black text-gray-300">{{ fmtTpix(limits.per_transaction) }}</div>
                </div>
                <div class="glass-card rounded-xl p-4">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ t(k('summary.perDay')) }}</div>
                    <div class="text-lg font-black text-gray-300">{{ fmtTpix(limits.per_day) }}</div>
                </div>
            </div>

            <div v-if="actionError" class="rounded-xl border border-red-500/30 bg-red-500/5 p-4 text-sm text-red-300">
                {{ actionError }}
            </div>
            <div v-if="actionNotice" class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm text-emerald-300">
                {{ actionNotice }}
            </div>

            <!-- ตัวกรอง -->
            <div class="flex flex-wrap gap-2">
                <button v-for="f in statusFilters" :key="f.label"
                        @click="filterStatus(f.key)"
                        :class="['px-3 py-1.5 text-xs font-medium rounded-lg border transition-all',
                                 filters.status === f.key || (!filters.status && !f.key)
                                    ? 'bg-primary-500/20 text-primary-400 border-primary-500/30'
                                    : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10']">
                    {{ t(k(f.label)) }}
                </button>
            </div>

            <!-- ตาราง -->
            <div class="glass-card rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-white/5 text-xs text-gray-500 uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-4 py-3">{{ t(k('table.destination')) }}</th>
                                <th class="text-right px-4 py-3">{{ t(k('table.amount')) }}</th>
                                <th class="text-left px-4 py-3">{{ t(k('table.purpose')) }}</th>
                                <th class="text-left px-4 py-3">{{ t(k('table.status')) }}</th>
                                <th class="text-left px-4 py-3">{{ t(k('table.requesterApprover')) }}</th>
                                <th class="text-right px-4 py-3">{{ t(k('table.actions')) }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="p in payouts.data" :key="p.id" class="hover:bg-white/[0.02]">
                                <td class="px-4 py-3">
                                    <div class="font-mono text-xs text-gray-300">{{ shortAddr(p.to_address) }}</div>
                                    <a v-if="p.tx_hash" :href="`${explorerUrl}/tx/${p.tx_hash}`" target="_blank" rel="noopener"
                                       class="font-mono text-xs text-primary-400 hover:underline">{{ shortHash(p.tx_hash) }}</a>
                                    <div v-if="p.memo" class="text-xs text-gray-600 mt-0.5">{{ p.memo }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-white">{{ fmtTpix(p.amount_tpix) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-400">{{ purposeLabel(p.purpose) }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['px-2 py-1 text-xs rounded-md border', st(p.status).cls]">
                                        {{ t(k(st(p.status).label)) }}
                                    </span>
                                    <div v-if="p.failure_reason" class="text-xs text-gray-600 mt-1 max-w-[220px]">
                                        {{ p.failure_reason }}
                                    </div>
                                    <!-- ยังไม่ล้มเหลว: ส่งไม่ยืนยันผล / ค้าง / nonce ถูกใช้ — เงินอาจออกไปแล้ว ห้ามสร้างรายการใหม่แทน -->
                                    <div v-if="p.status === 'broadcasting' && p.last_error" class="text-xs text-amber-400 mt-1 max-w-[220px]">
                                        {{ p.last_error }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    <div>{{ p.requester?.name ?? '—' }}</div>
                                    <div v-if="p.approver" class="text-gray-600">{{ t(k('table.approvedBy'), { name: p.approver.name }) }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <template v-if="p.status === 'pending'">
                                            <button @click="approve(p)" :disabled="busyId === p.id"
                                                    class="px-3 py-1.5 text-xs rounded-lg bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30 transition-all disabled:opacity-40">
                                                {{ t(k('actions.approve')) }}
                                            </button>
                                            <button @click="reject(p)" :disabled="busyId === p.id"
                                                    class="px-3 py-1.5 text-xs rounded-lg bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 transition-all disabled:opacity-40">
                                                {{ t(k('actions.reject')) }}
                                            </button>
                                        </template>
                                        <template v-else-if="p.status === 'approved'">
                                            <button @click="broadcast(p)"
                                                    :disabled="!readiness.ready || busyId === p.id"
                                                    :title="readiness.ready ? t(k('actions.broadcast')) : t(k('actions.missingTitle'), { items: blockingLabels.join(', ') })"
                                                    :class="['px-3 py-1.5 text-xs rounded-lg border transition-all',
                                                             readiness.ready
                                                                ? 'bg-primary-500/20 text-primary-400 border-primary-500/30 hover:bg-primary-500/30'
                                                                : 'bg-gray-600/20 text-gray-500 border-gray-600/30 cursor-not-allowed']">
                                                <template v-if="readiness.ready">{{ t(k('actions.broadcast')) }}</template>
                                                <template v-else>&#128274; {{ t(k('actions.awaitingEnable')) }}</template>
                                            </button>
                                        </template>
                                        <span v-else class="text-xs text-gray-600">—</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!payouts.data.length">
                                <td colspan="6" class="px-4 py-12 text-center">
                                    <div class="text-gray-500">{{ t(k('empty.title')) }}</div>
                                    <button @click="openForm" class="mt-3 text-sm text-primary-400 hover:underline">
                                        {{ t(k('empty.cta')) }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ฟอร์มสร้างรายการ -->
            <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
                 @click.self="showForm = false">
                <div class="w-full max-w-lg glass-card rounded-2xl p-6 space-y-4">
                    <h3 class="text-lg font-bold text-white">{{ t(k('form.title')) }}</h3>

                    <div v-if="formErrors.length" class="rounded-xl border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-300">
                        <div v-for="(e, i) in formErrors" :key="i">{{ e }}</div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ t(k('form.destination')) }}</label>
                        <select v-if="whitelist.length" v-model="form.to_address"
                                class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm focus:ring-2 focus:ring-primary-500/50 focus:outline-none">
                            <option value="">— {{ t(k('form.selectWhitelist')) }} —</option>
                            <option v-for="w in whitelist" :key="w.id" :value="w.address">
                                {{ w.label }} ({{ shortAddr(w.address) }})
                            </option>
                        </select>
                        <div v-else class="text-xs text-amber-400">
                            {{ t(k('form.noWhitelist')) }} —
                            <Link href="/admin/treasury/whitelist" class="underline">{{ t(k('form.addFirst')) }}</Link>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ t(k('form.amount')) }}</label>
                        <input v-model="form.amount" type="text" inputmode="decimal" placeholder="0.0"
                               class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm font-mono focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                        <div class="text-xs text-gray-600 mt-1">{{ t(k('form.maxPerTx'), { amount: fmtTpix(limits.per_transaction) }) }}</div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ t(k('form.purpose')) }}</label>
                        <select v-model="form.purpose"
                                class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm focus:ring-2 focus:ring-primary-500/50 focus:outline-none">
                            <option value="masternode">{{ t(k('purpose.masternode')) }}</option>
                            <option value="token_sale">{{ t(k('purpose.token_sale')) }}</option>
                            <option value="refund">{{ t(k('purpose.refund')) }}</option>
                            <option value="other">{{ t(k('purpose.other')) }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ t(k('form.memo')) }}</label>
                        <input v-model="form.memo" type="text" maxlength="500"
                               class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button @click="showForm = false"
                                class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                            {{ t('common.cancel') }}
                        </button>
                        <button @click="submitPayout" :disabled="isSubmitting || !form.to_address || !form.amount"
                                class="px-4 py-2 text-sm rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-40">
                            {{ isSubmitting ? t(k('form.saving')) : t(k('form.submit')) }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
