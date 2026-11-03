<script setup>
/**
 * TPIX TRADE — หลังบ้านกองทุนพยุงราคา (TPIX Stability Reserve)
 *
 * ทำได้: เปิด/ปิดสวิตช์ · ปรับพารามิเตอร์ภายในกรอบแข็ง · บันทึกเงินฝากจาก tx hash ·
 * ดูตัวอย่างการตัดสินใจ — ทุกปุ่มที่เปลี่ยนสถานะต้องผ่านหน้าต่างยืนยันก่อน
 * หน้าเว็บเซ็นธุรกรรมไม่ได้ (การเซ็นอยู่ใน scheduler ฝั่ง CLI เท่านั้น)
 * Developed by Xman Studio
 */
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    state: { type: Object, default: null },
    readiness: { type: Object, default: () => ({ ready: false, checks: [] }) },
    params: { type: Object, default: () => ({}) },
    bounds: { type: Object, default: () => ({}) },
    keystore: { type: Object, default: () => ({}) },
    decisionsPage: { type: Object, default: () => ({ data: [], links: [] }) },
});

// ชื่อพารามิเตอร์ — ดึงจาก t() ตอน render (สลับภาษาได้) ถ้าไม่มีคำแปลแสดงชื่อดิบ
const paramLabel = (name) => {
    const key = `admin.stabilizer.params.labels.${name}`;
    const text = t(key);
    return text === key ? name : text;
};

const form = reactive({ ...props.params });
const confirmText = ref('');
const deposit = reactive({ token: 'usdt', tx_hash: '', note: '' });
const busy = ref(false);
const dialog = ref(null); // { kind, danger } — หัวข้อ/ข้อความดึงจาก t() ตอน render เพื่อให้สลับภาษาได้
const preview = ref(null);
const previewError = ref(null); // { message } — message ว่าง = ใช้ข้อความ fallback ตามภาษาปัจจุบัน
const previewLoading = ref(false);

const st = computed(() => props.state?.status ?? {});
const switchOn = computed(() => !!st.value.switch_on);
const envOn = computed(() => !!st.value.env_enabled);

const reasonText = (code) => {
    const key = `stability.reasons.${code}`;
    const text = t(key);
    return text === key ? code : text;
};
// แปลค่าที่มาจากเซิร์ฟเวอร์ (action/side/status/pause reason/readiness key) ตอน render — ไม่มีคำแปลแสดงค่าดิบ
const labelOr = (key, raw) => {
    const text = t(key);
    return text === key ? raw : text;
};
const actionText = (a) => labelOr(`stability.decisions.actions.${a}`, a);
const txTypeText = (tx) => (tx.purpose === 'approve'
    ? labelOr('stability.decisions.actions.approve', 'approve')
    : labelOr(`stability.decisions.actions.${tx.side}`, tx.side));
const txStatusText = (s) => labelOr(`stability.trades.statuses.${s}`, s);
const pauseReasonText = (r) => labelOr(`stability.pauseReasons.${r}`, r);
const checkText = (c) => labelOr(`admin.stabilizer.readiness.checks.${c.key}`, c.label);
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const fmtPrice = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(6));
const short = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-6)}` : '—');

function ask(kind) {
    const danger = { enable: true, pause: false, params: true, deposit: false };
    dialog.value = { kind, danger: danger[kind] };
}

function confirmDialog() {
    const kind = dialog.value?.kind;
    busy.value = true;
    const done = { preserveScroll: true, onFinish: () => { busy.value = false; dialog.value = null; } };

    if (kind === 'enable') {
        router.post('/admin/stabilizer/switch', { enabled: true, confirm: confirmText.value }, done);
    } else if (kind === 'pause') {
        router.post('/admin/stabilizer/switch', { enabled: false, reason: 'manual_pause' }, done);
    } else if (kind === 'params') {
        router.put('/admin/stabilizer/params', { ...form }, done);
    } else if (kind === 'deposit') {
        router.post('/admin/stabilizer/deposits', { ...deposit }, {
            ...done,
            onSuccess: () => { deposit.tx_hash = ''; deposit.note = ''; },
        });
    } else {
        busy.value = false;
        dialog.value = null;
    }
}

async function runPreview() {
    previewLoading.value = true;
    previewError.value = null;
    try {
        const { data } = await axios.get('/admin/stabilizer/preview');
        preview.value = data.data;
    } catch (e) {
        previewError.value = { message: e.response?.data?.error?.message || '' };
    } finally {
        previewLoading.value = false;
    }
}
</script>

<template>
    <Head :title="t('admin.stabilizer.headTitle')" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.stabilizer.header.title') }}</h1>
                    <p class="text-sm text-dark-400">{{ t('admin.stabilizer.header.subtitle') }} <a href="/stability" target="_blank" class="text-sky-300 hover:underline">/stability</a></p>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold border"
                    :class="st.active ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-amber-500/15 text-amber-300 border-amber-500/30'">
                    {{ st.active ? t('admin.stabilizer.status.active') : (envOn ? t('admin.stabilizer.status.paused') : t('admin.stabilizer.status.envOff')) }}
                </span>
            </div>

            <div v-if="!state" class="glass-dark rounded-xl p-4 text-amber-300 text-sm">{{ t('admin.stabilizer.noState') }}</div>

            <!-- สวิตช์ + ความพร้อม -->
            <section class="grid lg:grid-cols-2 gap-4">
                <div class="glass-dark rounded-xl p-5 space-y-3">
                    <h2 class="font-semibold text-white">{{ t('admin.stabilizer.switch.title') }}</h2>
                    <p class="text-sm text-dark-300">{{ t('admin.stabilizer.switch.envLabel') }} <b :class="envOn ? 'text-emerald-300' : 'text-rose-300'">{{ envOn ? t('admin.stabilizer.switch.on') : t('admin.stabilizer.switch.off') }}</b> · {{ t('admin.stabilizer.switch.adminLabel') }} <b :class="switchOn ? 'text-emerald-300' : 'text-amber-300'">{{ switchOn ? t('admin.stabilizer.switch.on') : t('admin.stabilizer.switch.off') }}</b></p>
                    <p v-if="st.pause_reason" class="text-sm text-amber-300">{{ t('admin.stabilizer.switch.pausedBecause', { reason: pauseReasonText(st.pause_reason) }) }} <span class="text-dark-500">({{ fmtTime(st.paused_at) }})</span></p>

                    <div v-if="switchOn">
                        <button type="button" class="btn-danger px-4 py-2" :disabled="busy" @click="ask('pause')">{{ t('admin.stabilizer.switch.pauseNow') }}</button>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <input v-model="confirmText" type="text" class="trading-input w-40" :placeholder="t('admin.stabilizer.switch.confirmPlaceholder')" autocomplete="off" />
                        <button type="button" class="btn-success px-4 py-2" :disabled="busy || confirmText !== 'ENABLE' || !readiness.ready" @click="ask('enable')">{{ t('admin.stabilizer.switch.enable') }}</button>
                    </div>
                </div>

                <div class="glass-dark rounded-xl p-5">
                    <h2 class="font-semibold text-white mb-2">{{ t('admin.stabilizer.readiness.title') }}</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="c in readiness.checks" :key="c.key" class="flex items-start gap-2">
                            <span :class="c.ok ? 'text-emerald-400' : 'text-rose-400'">{{ c.ok ? '✓' : '✗' }}</span>
                            <span class="text-dark-200">{{ checkText(c) }}</span>
                        </li>
                    </ul>
                    <p class="text-xs text-dark-500 mt-2">{{ t('admin.stabilizer.readiness.keystore', { status: keystore.configured ? t('admin.stabilizer.readiness.fileFound') : t('admin.stabilizer.readiness.fileNotFound') }) }} · {{ t('admin.stabilizer.readiness.addressInFile') }} <span class="font-mono">{{ keystore.address || '—' }}</span> · {{ t('admin.stabilizer.readiness.passphrase', { status: keystore.passphrase_set ? t('admin.stabilizer.readiness.passSet') : t('admin.stabilizer.readiness.passNotSet') }) }}</p>
                </div>
            </section>

            <!-- กระเป๋า / ตลาด / งบ -->
            <section v-if="state" class="grid lg:grid-cols-3 gap-4">
                <div class="glass-dark rounded-xl p-5 text-sm space-y-1">
                    <h2 class="font-semibold text-white mb-2">{{ t('admin.stabilizer.wallet.title') }}</h2>
                    <p class="font-mono break-all text-white">{{ state.wallet.address || t('admin.stabilizer.wallet.notSet') }}</p>
                    <p v-if="state.wallet.balances" class="text-dark-300">TPIX: <span class="font-mono text-white">{{ state.wallet.balances.tpix ?? '—' }}</span> · USDT: <span class="font-mono text-white">{{ state.wallet.balances.usdt ?? '—' }}</span></p>
                    <p class="text-dark-300">{{ t('admin.stabilizer.wallet.usdtMode') }} <b class="text-white">{{ state.usdt_backing.mode === 'bridge_proof' ? t('admin.stabilizer.wallet.modeBridgeProof') : t('admin.stabilizer.wallet.modeDepositsOnly') }}</b></p>
                    <p class="text-dark-300">{{ t('admin.stabilizer.wallet.depositsSummary', { deposited: state.usdt_backing.deposits_total, spent: state.usdt_backing.spent_total, remaining: state.usdt_backing.deposits_remaining }) }}</p>
                </div>
                <div class="glass-dark rounded-xl p-5 text-sm space-y-1">
                    <h2 class="font-semibold text-white mb-2">{{ t('admin.stabilizer.market.title') }}</h2>
                    <template v-if="state.market">
                        <p class="text-dark-300">{{ t('admin.stabilizer.market.poolPrice') }} <span class="font-mono text-white">{{ fmtPrice(state.market.pool_price) }}</span></p>
                        <p class="text-dark-300">{{ t('admin.stabilizer.market.reference') }} <span class="font-mono text-white">{{ fmtPrice(state.market.reference_price) }}</span> · {{ t('admin.stabilizer.market.band') }} <span class="font-mono text-white">{{ fmtPrice(state.market.band_low) }}–{{ fmtPrice(state.market.band_high) }}</span></p>
                        <p class="text-dark-300">{{ t('admin.stabilizer.market.poolSummary', { tpix: state.market.pool_tpix, usdt: state.market.pool_usdt, coverage: state.market.history_coverage_pct }) }}</p>
                    </template>
                    <p v-else class="text-dark-400">{{ t('admin.stabilizer.market.noPool') }}</p>
                    <button type="button" class="btn-secondary px-3 py-1.5 mt-2 text-xs" :disabled="previewLoading" @click="runPreview">{{ previewLoading ? t('admin.stabilizer.market.previewLoading') : t('admin.stabilizer.market.previewButton') }}</button>
                    <p v-if="previewError" class="text-rose-300 text-xs">{{ previewError.message || t('admin.stabilizer.market.previewError') }}</p>
                    <div v-if="preview" class="mt-2 rounded-lg bg-white/5 p-2 text-xs">
                        <p class="text-white">{{ actionText(preview.action) }} · {{ reasonText(preview.reason_code) }}</p>
                        <pre class="text-dark-300 whitespace-pre-wrap break-all mt-1">{{ JSON.stringify(preview.details, null, 2) }}</pre>
                    </div>
                </div>
                <div class="glass-dark rounded-xl p-5 text-sm space-y-1">
                    <h2 class="font-semibold text-white mb-2">{{ t('admin.stabilizer.budget.title') }}</h2>
                    <p class="text-dark-300">{{ t('admin.stabilizer.budget.bought') }} <span class="font-mono text-white">{{ state.budget_today.usdt_spent }} / {{ state.budget_today.usdt_limit }}</span> USDT</p>
                    <p class="text-dark-300">{{ t('admin.stabilizer.budget.sold') }} <span class="font-mono text-white">{{ state.budget_today.tpix_sold }} / {{ state.budget_today.tpix_limit }}</span> TPIX</p>
                </div>
            </section>

            <!-- พารามิเตอร์ -->
            <section class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-1">{{ t('admin.stabilizer.params.title') }}</h2>
                <p class="text-xs text-dark-400 mb-4">{{ t('admin.stabilizer.params.hint') }}</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <label v-for="(b, name) in bounds" :key="name" class="text-sm">
                        <span class="block text-dark-300 mb-1">{{ paramLabel(name) }}</span>
                        <input v-model.number="form[name]" type="number" step="any" :min="b.min" :max="b.max" class="trading-input w-full" />
                        <span class="block text-xs text-dark-500 mt-0.5">{{ t('admin.stabilizer.params.range', { min: b.min, max: b.max, def: b.default }) }}</span>
                    </label>
                </div>
                <button type="button" class="btn-primary px-4 py-2 mt-4" :disabled="busy" @click="ask('params')">{{ t('admin.stabilizer.params.save') }}</button>
            </section>

            <!-- เงินฝาก -->
            <section class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-1">{{ t('admin.stabilizer.deposit.title') }}</h2>
                <p class="text-xs text-dark-400 mb-3">{{ t('admin.stabilizer.deposit.hint') }}</p>
                <div class="flex flex-wrap gap-2 items-end">
                    <select v-model="deposit.token" class="trading-input w-28">
                        <option value="usdt">USDT</option>
                        <option value="tpix">TPIX</option>
                    </select>
                    <input v-model="deposit.tx_hash" type="text" class="trading-input flex-1 min-w-0 font-mono" :placeholder="t('admin.stabilizer.deposit.txPlaceholder')" />
                    <input v-model="deposit.note" type="text" class="trading-input w-full sm:w-56" :placeholder="t('admin.stabilizer.deposit.notePlaceholder')" maxlength="255" />
                    <button type="button" class="btn-primary px-4 py-2" :disabled="busy || !/^0x[0-9a-fA-F]{64}$/.test(deposit.tx_hash)" @click="ask('deposit')">{{ t('admin.stabilizer.deposit.submit') }}</button>
                </div>
                <ul v-if="state?.deposits?.length" class="mt-3 text-sm space-y-1">
                    <li v-for="d in state.deposits" :key="d.tx_hash" class="flex flex-wrap gap-3">
                        <span class="font-mono text-white">{{ d.amount }} {{ d.token.toUpperCase() }}</span>
                        <a :href="d.tx_url" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(d.tx_hash) }}</a>
                        <span class="text-dark-500">{{ fmtTime(d.at) }}</span>
                    </li>
                </ul>
            </section>

            <!-- ธุรกรรม -->
            <section v-if="state" class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-3">{{ t('admin.stabilizer.tx.title') }}</h2>
                <p v-if="!state.transactions.length" class="text-sm text-dark-400">{{ t('admin.stabilizer.tx.empty') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-dark-400 border-b border-white/5"><th class="py-2 pr-3">{{ t('admin.stabilizer.tx.colTime') }}</th><th class="pr-3">{{ t('admin.stabilizer.tx.colType') }}</th><th class="pr-3">{{ t('admin.stabilizer.tx.colIn') }}</th><th class="pr-3">{{ t('admin.stabilizer.tx.colOut') }}</th><th class="pr-3">{{ t('admin.stabilizer.tx.colStatus') }}</th><th>{{ t('stability.trades.tx') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="tx in state.transactions" :key="tx.tx_hash" class="border-b border-white/5">
                                <td class="py-2 pr-3 text-dark-300">{{ fmtTime(tx.at) }}</td>
                                <td class="pr-3 text-white">{{ txTypeText(tx) }}</td>
                                <td class="pr-3 font-mono">{{ tx.amount_in }} {{ tx.token_in?.toUpperCase() }}</td>
                                <td class="pr-3 font-mono">{{ tx.actual_out ?? tx.expected_out ?? '—' }}</td>
                                <td class="pr-3">{{ txStatusText(tx.status) }}</td>
                                <td><a :href="tx.tx_url" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(tx.tx_hash) }}</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- การตัดสินใจทั้งหมด -->
            <section class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-3">{{ t('admin.stabilizer.decisions.title') }}</h2>
                <p v-if="!decisionsPage.data.length" class="text-sm text-dark-400">{{ t('admin.stabilizer.decisions.empty') }}</p>
                <ul v-else class="divide-y divide-white/5 text-sm">
                    <li v-for="d in decisionsPage.data" :key="d.id" class="py-2">
                        <div class="flex flex-wrap gap-3">
                            <span class="text-dark-400 w-36">{{ fmtTime(d.at) }}</span>
                            <span class="font-semibold text-white w-16">{{ actionText(d.action) }}</span>
                            <span class="text-dark-200 flex-1">{{ reasonText(d.reason_code) }} <span class="text-dark-500">({{ d.reason_code }})</span></span>
                            <span class="font-mono text-xs text-dark-400">{{ fmtPrice(d.pool_price) }} · {{ fmtPrice(d.band_low) }}–{{ fmtPrice(d.band_high) }}</span>
                        </div>
                    </li>
                </ul>
                <div v-if="decisionsPage.links?.length > 3" class="flex flex-wrap gap-1 mt-3">
                    <template v-for="(l, i) in decisionsPage.links" :key="i">
                        <Link v-if="l.url" :href="l.url" preserve-scroll class="px-2 py-1 rounded text-xs" :class="l.active ? 'bg-primary-500 text-white' : 'bg-white/5 text-dark-300'" v-html="l.label" />
                    </template>
                </div>
            </section>
        </div>

        <ConfirmDialog
            :show="!!dialog"
            :title="dialog ? t(`admin.stabilizer.dialog.${dialog.kind}.title`) : ''"
            :message="dialog ? t(`admin.stabilizer.dialog.${dialog.kind}.message`) : ''"
            :danger="dialog?.danger"
            :loading="busy"
            :confirm-text="busy ? t('admin.stabilizer.dialog.processing') : t('common.confirm')"
            :cancel-text="t('common.cancel')"
            @confirm="confirmDialog"
            @cancel="dialog = null"
        />
    </AdminLayout>
</template>
