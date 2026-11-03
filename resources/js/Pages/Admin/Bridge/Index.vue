<script setup>
/**
 * TPIX TRADE — หลังบ้านสะพาน USDT หลายเชน → TPIX Chain
 *
 * ภาพรวม (สวิตช์ · ด่านความพร้อม · ยอดเงินหนุน) · รายการฝาก · รายการถอน · วงเงิน/ค่าธรรมเนียม
 * ปุ่มที่กระทบเงินทุกปุ่มมีกล่องยืนยัน และกันกดเบิ้ลทั้งฝั่งหน้าจอ (busy) และฝั่งเซิร์ฟเวอร์ (409)
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    tab: { type: String, default: 'overview' },
    filters: { type: Object, default: () => ({}) },
    state: { type: Object, default: () => ({}) },
    snapshot: { type: Object, default: null },
    deposits: { type: Object, default: () => ({ data: [] }) },
    withdrawals: { type: Object, default: () => ({ data: [] }) },
    counts: { type: Object, default: () => ({ deposits: {}, withdrawals: {} }) },
    settings: { type: Object, default: () => ({ limits: {}, chains: [] }) },
    explorers: { type: Object, default: () => ({}) },
});

const activeTab = ref(props.tab);
const notice = ref(null); // { type, text } หรือ { type, key, suffix } — เก็บคีย์ไว้ให้ข้อความเปลี่ยนภาษาตามได้
const noticeText = computed(() => (notice.value?.key ? t(notice.value.key) + (notice.value.suffix ?? '') : notice.value?.text ?? ''));
const busy = ref(false);

function goTab(tab, status = null) {
    activeTab.value = tab;
    router.get('/admin/bridge', { tab, ...(status ? { status } : {}) }, { preserveState: true, preserveScroll: true, replace: true });
}

const short = (v) => (v ? `${v.slice(0, 10)}…${v.slice(-6)}` : '—');
const txUrl = (chainId, hash) => (props.explorers?.[chainId] && hash ? `${props.explorers[chainId]}/tx/${hash}` : null);
const chainName = (id) => props.settings.chains.find((c) => c.chain_id === id)?.name ?? (id === 4289 ? 'TPIX' : `#${id ?? '?'}`);

const statusStyle = {
    confirmed: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    minting: 'bg-violet-500/20 text-violet-300 border-violet-500/30',
    minted: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    needs_review: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    rejected: 'bg-gray-600/30 text-gray-400 border-gray-500/30',
    ignored: 'bg-gray-700/30 text-gray-500 border-gray-600/30',
    pending: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    awaiting_liquidity: 'bg-orange-500/20 text-orange-300 border-orange-500/30',
    sending: 'bg-violet-500/20 text-violet-300 border-violet-500/30',
    paid: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
};

// เหตุผลรอตรวจ → คีย์แปล (admin.bridge.reason.*) — เหตุผลที่ไม่รู้จักโชว์ค่าดิบ
const reasonKeys = ['dust', 'below_minimum', 'internal_sender', 'sender_is_contract', 'sender_mismatch', 'rpc_disagreement',
    'source_unconfirmed', 'manual_refund', 'kyc_required', 'over_tx_limit', 'over_daily_address_limit', 'exceeds_onchain_max',
    'mint_reverted', 'mint_estimate_failed', 'nonce_consumed', 'minted_elsewhere', 'stuck_pending', 'unsupported_chain',
    'invalid_target_address', 'suspicious_target', 'payout_reverted', 'payout_estimate_failed'];
const reasonLabel = (r) => (reasonKeys.includes(r) ? t(`admin.bridge.reason.${r}`) : r);
const statusLabel = (st) => (statusStyle[st] ? t(`admin.bridge.status.${st}`) : st);

// ── สวิตช์ ──────────────────────────────────────────────────────────────
const confirmToggle = ref(false);
const toggleReason = ref('');

async function doToggle() {
    if (busy.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post('/admin/bridge/toggle', { enabled: !props.state.site_switch, reason: toggleReason.value || null });
        notice.value = { type: 'success', key: data.data.enabled ? 'admin.bridge.notice.enabled' : 'admin.bridge.notice.disabled', suffix: data.data.warnings?.length ? ` · ${data.data.warnings.join(' · ')}` : '' };
        confirmToggle.value = false;
        toggleReason.value = '';
        router.reload({ only: ['state'] });
    } catch (e) {
        notice.value = e.response?.data?.message ? { type: 'error', text: e.response.data.message } : { type: 'error', key: 'admin.bridge.notice.toggleFailed' };
    } finally {
        busy.value = false;
    }
}

// ── ยอดสด ───────────────────────────────────────────────────────────────
const live = ref(null);
const measuring = ref(false);

async function measure() {
    if (measuring.value) return;
    measuring.value = true;
    try {
        const { data } = await axios.get('/admin/bridge/measure');
        live.value = data.data;
    } catch (e) {
        notice.value = { type: 'error', key: e.response?.status === 429 ? 'admin.bridge.notice.rateLimited' : 'admin.bridge.notice.measureFailed' };
    } finally {
        measuring.value = false;
    }
}

const view = computed(() => live.value ?? props.snapshot);
const fmtRaw = (v, d = 6) => {
    if (v === null || v === undefined) return '—';
    const s = String(v).padStart(d + 1, '0');
    const whole = s.slice(0, -d);
    const frac = s.slice(-d).replace(/0+$/, '');
    return frac ? `${whole}.${frac}` : whole;
};
const fmtNative = (wei) => (wei === null || wei === undefined ? '—' : Number(fmtRaw(wei, 18)).toFixed(5));

// ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────
const pending = ref(null); // { kind, action, row }
const rejectReason = ref('');
// hash ของธุรกรรมที่ใช้ nonce ของการจ่ายเดิมไปแล้ว (ดูจาก explorer) — หลักฐานว่าการจ่ายเดิมไม่มีทางลงบล็อก
const nonceTxHash = ref('');
const nonceTxValid = computed(() => nonceTxHash.value.trim() === '' || /^0x[0-9a-fA-F]{64}$/.test(nonceTxHash.value.trim()));

function ask(kind, action, row) {
    pending.value = { kind, action, row };
    rejectReason.value = '';
    nonceTxHash.value = '';
}

/** รายการถอนที่เคยส่งธุรกรรมจ่ายแล้ว — อนุมัติ/ปฏิเสธต้องผ่านการพิสูจน์บนเชนว่าเงินไม่ได้ออก */
const needsPayoutProof = computed(() => pending.value?.kind === 'withdrawal' && !!pending.value?.row?.payout_tx_hash);

const confirmMessage = computed(() => {
    const p = pending.value;
    if (!p) return '';
    const row = p.row;
    if (p.kind === 'deposit') {
        if (p.action === 'approve') {
            return row.review_reason === 'minted_elsewhere'
                ? t('admin.bridge.confirm.depositMintedElsewhere', { address: row.from_address, amount: row.mint_amount })
                : t('admin.bridge.confirm.depositApprove', { amount: row.mint_amount, address: row.from_address, chain: chainName(row.source_chain_id) });
        }
        return t('admin.bridge.confirm.depositReject', { amount: row.amount, address: row.from_address })
            + (row.mint_tx_hash ? ` · ${t('admin.bridge.confirm.depositRejectMinted')}` : '');
    }
    const proof = row.payout_tx_hash
        ? ` · ${t('admin.bridge.confirm.payoutProof', { hash: short(row.payout_tx_hash), nonce: row.payout_nonce ?? '?' })}`
        : '';
    return (p.action === 'approve'
        ? t('admin.bridge.confirm.withdrawApprove', { amount: row.payout_amount, address: row.target_address, chain: chainName(row.target_chain_id) })
        : t('admin.bridge.confirm.withdrawReject', { amount: row.amount, address: row.from_address })) + proof;
});

async function runPending() {
    const p = pending.value;
    if (!p || busy.value) return;
    if (p.action === 'reject' && !rejectReason.value.trim()) return;
    if (!nonceTxValid.value) return;

    busy.value = true;
    try {
        const base = p.kind === 'deposit' ? '/admin/bridge/deposits' : '/admin/bridge/withdrawals';
        const body = p.action === 'reject' ? { reason: rejectReason.value.trim() } : {};
        if (needsPayoutProof.value && nonceTxHash.value.trim() !== '') body.nonce_tx_hash = nonceTxHash.value.trim();

        const { data } = await axios.post(`${base}/${p.row.id}/${p.action}`, body);
        notice.value = data.message ? { type: 'success', text: data.message } : { type: 'success', key: 'admin.bridge.notice.done' };
        pending.value = null;
        router.reload({ only: ['deposits', 'withdrawals', 'counts', 'state'] });
    } catch (e) {
        const errors = e.response?.data?.errors;
        notice.value = Array.isArray(errors) ? { type: 'error', text: errors.join(' · ') } : (e.response?.data?.message ? { type: 'error', text: e.response.data.message } : { type: 'error', key: 'admin.bridge.notice.actionFailed' });
        pending.value = null;
    } finally {
        busy.value = false;
    }
}

// ── ตั้งค่า ──────────────────────────────────────────────────────────────
// คีย์วงเงิน (ส่งไปเซิร์ฟเวอร์ตามนี้) — ป้ายแปลตอน render: admin.bridge.limit.*
const limitKeys = ['min_deposit', 'max_deposit_per_tx', 'max_deposit_per_address_daily', 'deposit_fee', 'min_withdraw', 'max_withdraw_per_tx', 'max_payout_daily'];
const limitsForm = ref({ ...props.settings.limits });
const feesForm = ref(Object.fromEntries(props.settings.chains.map((c) => [c.chain_id, c.withdraw_fee])));
const chainsForm = ref(Object.fromEntries(props.settings.chains.map((c) => [c.chain_id, c.enabled])));
const settingsErrors = ref([]);
const confirmSettings = ref(false);

async function saveSettings() {
    if (busy.value) return;
    busy.value = true;
    settingsErrors.value = [];
    try {
        await axios.put('/admin/bridge/settings', { limits: limitsForm.value, fees: feesForm.value, chains: chainsForm.value });
        notice.value = { type: 'success', key: 'admin.bridge.notice.settingsSaved' };
        confirmSettings.value = false;
        router.reload({ only: ['settings', 'state'] });
    } catch (e) {
        settingsErrors.value = e.response?.data?.errors
            ? (Array.isArray(e.response.data.errors) ? e.response.data.errors : Object.values(e.response.data.errors).flat())
            : [e.response?.data?.message ?? { key: 'admin.bridge.notice.saveFailed' }];
        confirmSettings.value = false;
    } finally {
        busy.value = false;
    }
}

const depositFilters = ['needs_review', 'confirmed', 'minting', 'minted', 'ignored', 'rejected'];
const withdrawFilters = ['needs_review', 'pending', 'awaiting_liquidity', 'sending', 'paid', 'rejected'];
const readinessKeys = ['installed', 'site_switch', 'usdt_tpix', 'minter', 'relayer_keystore', 'relayer_passphrase', 'minter_is_bridge',
    'relayer_role', 'minter_not_paused', 'token_not_paused'];
const readinessLabel = (k) => (readinessKeys.includes(k) ? t(`admin.bridge.readiness.${k}`) : k);

// แท็บ — computed เพื่อให้ป้ายเปลี่ยนตามภาษาทันที
const tabs = computed(() => {
    const dr = props.counts.deposits?.needs_review;
    const wr = props.counts.withdrawals?.needs_review;
    return [
        ['overview', t('admin.bridge.tabs.overview')],
        ['deposits', dr ? t('admin.bridge.tabs.depositsReview', { n: dr }) : t('admin.bridge.tabs.deposits')],
        ['withdrawals', wr ? t('admin.bridge.tabs.withdrawalsReview', { n: wr }) : t('admin.bridge.tabs.withdrawals')],
        ['settings', t('admin.bridge.tabs.settings')],
    ];
});
const fmtDate = (v) => new Date(v).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US');
</script>

<template>
    <Head :title="t('admin.bridge.pageTitle')" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.bridge.header.title') }}</h1>
                    <p class="text-sm text-gray-400 mt-1">{{ t('admin.bridge.header.subtitle') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="/admin/bridge/wtpix" class="px-4 py-2 text-sm rounded-xl bg-primary-500/10 text-primary-300 border border-primary-500/30 hover:bg-primary-500/20">{{ t('admin.bridge.header.wtpixLink') }}</a>
                    <a href="/bridge/reserves" target="_blank" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">{{ t('admin.bridge.header.reservesLink') }}</a>
                </div>
            </div>

            <div v-if="notice" :class="['rounded-xl border p-4 text-sm', notice.type === 'success' ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-300' : 'border-red-500/30 bg-red-500/5 text-red-300']">
                {{ noticeText }}
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="tb in tabs"
                    :key="tb[0]" @click="goTab(tb[0])"
                    :class="['px-4 py-2 text-sm rounded-xl border transition-all', activeTab === tb[0] ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10']">
                    {{ tb[1] }}
                </button>
            </div>

            <!-- ═══════════ ภาพรวม ═══════════ -->
            <div v-if="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="glass-card rounded-xl p-5 space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-white">{{ t('admin.bridge.switch.title') }}</h3>
                            <span :class="['px-2 py-1 text-xs rounded-md border', state.operational ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-red-500/20 text-red-300 border-red-500/30']">
                                {{ state.operational ? t('admin.bridge.switch.running') : t('admin.bridge.switch.stopped') }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-400">{{ t('admin.bridge.switch.installedLabel') }} <b :class="state.installed ? 'text-emerald-300' : 'text-red-300'">{{ state.installed ? t('common.yes') : t('admin.bridge.switch.notInstalled') }}</b> · {{ t('admin.bridge.switch.siteSwitchLabel') }} <b>{{ state.site_switch ? t('admin.bridge.switch.on') : t('admin.bridge.switch.off') }}</b></p>
                        <p v-if="state.switch_reason" class="text-xs text-amber-300">{{ state.switch_reason }}</p>
                        <button @click="confirmToggle = true" :disabled="busy"
                            :class="['w-full py-2.5 rounded-xl text-sm font-semibold border transition-all disabled:opacity-40', state.site_switch ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30']">
                            {{ state.site_switch ? t('admin.bridge.switch.stopButton') : t('admin.bridge.switch.startButton') }}
                        </button>
                        <p class="text-[11px] text-gray-500">{{ t('admin.bridge.switch.hint') }}</p>
                    </div>

                    <div class="glass-card rounded-xl p-5">
                        <h3 class="font-semibold text-white mb-3">{{ t('admin.bridge.readiness.title') }}</h3>
                        <ul class="space-y-1.5 text-xs">
                            <li v-for="c in state.readiness || []" :key="c.key" class="flex items-start gap-2">
                                <span :class="c.ok ? 'text-emerald-400' : 'text-red-400'">{{ c.ok ? '✓' : '✗' }}</span>
                                <span class="text-gray-300">{{ readinessLabel(c.key) }}</span>
                                <span v-if="!c.ok" class="text-gray-500 break-all">— {{ c.hint }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="glass-card rounded-xl p-5 text-xs space-y-2">
                        <h3 class="font-semibold text-white text-sm">{{ t('admin.bridge.signers.title') }}</h3>
                        <div v-for="(s, role) in state.signers || {}" :key="role" class="flex flex-wrap justify-between gap-2">
                            <span class="text-gray-400 w-20">{{ role }}</span>
                            <code class="font-mono text-white break-all flex-1">{{ s.address ?? t('admin.bridge.signers.noKeystore') }}</code>
                            <span :class="s.ready ? 'text-emerald-400' : 'text-amber-400'">{{ s.ready ? t('admin.bridge.signers.ready') : (s.passphrase_set ? t('admin.bridge.signers.missingFile') : t('admin.bridge.signers.missingPassphrase')) }}</span>
                        </div>
                        <div class="pt-2 border-t border-white/5 space-y-1">
                            <div class="flex justify-between"><span class="text-gray-400">vault</span><code class="font-mono text-white">{{ state.addresses?.vault }}</code></div>
                            <div class="flex justify-between"><span class="text-gray-400">USDT_TPIX</span><code class="font-mono text-white">{{ state.addresses?.usdt_tpix ?? '—' }}</code></div>
                            <div class="flex justify-between"><span class="text-gray-400">minter</span><code class="font-mono text-white">{{ state.addresses?.minter ?? t('admin.bridge.signers.notDeployed') }}</code></div>
                        </div>
                    </div>
                    <div class="glass-card rounded-xl p-5 text-xs space-y-1.5">
                        <h3 class="font-semibold text-white text-sm mb-1">{{ t('admin.bridge.onchain.title') }}</h3>
                        <div class="flex justify-between"><span class="text-gray-400">paused</span><span :class="state.minter?.paused ? 'text-red-300' : 'text-white'">{{ state.minter?.paused === null || state.minter?.paused === undefined ? '—' : (state.minter.paused ? t('common.yes') : t('common.no')) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">maxPerTx</span><span class="text-white font-mono">{{ state.minter?.max_per_tx ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">dailyCap</span><span class="text-white font-mono">{{ state.minter?.daily_cap ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ t('admin.bridge.onchain.remainingToday') }}</span><span class="text-white font-mono">{{ state.minter?.remaining_today ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ t('admin.bridge.onchain.totalMinted') }}</span><span class="text-white font-mono">{{ state.minter?.total_minted ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ t('admin.bridge.onchain.paidToday') }}</span><span class="text-white font-mono">{{ state.paid_today }}</span></div>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-white">{{ t('admin.bridge.reserves.title') }} {{ live ? t('admin.bridge.reserves.live') : t('admin.bridge.reserves.lastReconcile', { time: snapshot?.checked_at ? fmtDate(snapshot.checked_at) : '—' }) }}</h3>
                        <button @click="measure" :disabled="measuring" class="px-3 py-1.5 text-xs rounded-lg bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-40">
                            {{ measuring ? t('admin.bridge.reserves.measuring') : t('admin.bridge.reserves.measure') }}
                        </button>
                    </div>
                    <p v-if="!view" class="text-sm text-gray-500">{{ t('admin.bridge.reserves.empty') }}</p>
                    <template v-else>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                            <div><p class="text-gray-500">supply</p><p class="text-white font-mono text-base">{{ view.supply ?? '—' }}</p></div>
                            <div><p class="text-gray-500">vault</p><p class="text-white font-mono text-base">{{ view.vault }}</p></div>
                            <div><p class="text-gray-500">{{ t('admin.bridge.reserves.payoutWallets') }}</p><p class="text-white font-mono text-base">{{ view.payout_wallets }}</p></div>
                            <div><p class="text-gray-500">{{ t('admin.bridge.reserves.owed') }}</p><p class="text-white font-mono text-base">{{ view.owed }}</p></div>
                            <div><p class="text-gray-500">{{ t('admin.bridge.reserves.deficit') }}</p><p :class="['font-mono text-base', String(view.deficit).startsWith('-') || view.deficit === '0' ? 'text-emerald-300' : 'text-red-300']">{{ view.deficit }}</p></div>
                        </div>
                        <p v-if="view.unexplained_mint && view.unexplained_mint !== '0'" class="text-sm text-red-300">{{ t('admin.bridge.reserves.unexplainedMint', { amount: view.unexplained_mint }) }}</p>
                        <p v-if="view.note" class="text-xs text-amber-300">{{ view.note }}</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[640px]">
                                <thead class="text-gray-500"><tr>
                                    <th class="text-left py-1.5">{{ t('admin.bridge.reserves.chain') }}</th><th class="text-right">vault</th><th class="text-right">{{ t('admin.bridge.reserves.payoutUsdt') }}</th><th class="text-right">{{ t('admin.bridge.reserves.payoutGas') }}</th>
                                </tr></thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="c in view.chains" :key="c.chain_id">
                                        <td class="py-2 text-white">{{ c.name }} <span v-if="!c.enabled" class="text-gray-500">{{ t('admin.bridge.reserves.disabled') }}</span></td>
                                        <td class="text-right font-mono"><a v-if="c.vault_url" :href="c.vault_url" target="_blank" class="text-primary-300 hover:underline">{{ c.readable ? fmtRaw(c.vault_units) : t('admin.bridge.reserves.unreadable') }}</a></td>
                                        <td class="text-right font-mono"><a v-if="c.payout_url" :href="c.payout_url" target="_blank" class="text-primary-300 hover:underline">{{ c.readable ? fmtRaw(c.payout_units) : t('admin.bridge.reserves.unreadable') }}</a><span v-else class="text-gray-600">—</span></td>
                                        <td class="text-right font-mono text-gray-300">{{ fmtNative(c.payout_native_wei) }} {{ c.native }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ═══════════ ฝาก ═══════════ -->
            <div v-if="activeTab === 'deposits'" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button @click="goTab('deposits')" :class="['px-3 py-1.5 text-xs rounded-lg border', !filters.status ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">{{ t('common.all') }}</button>
                    <button v-for="s in depositFilters" :key="s" @click="goTab('deposits', s)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.status === s ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                        {{ statusLabel(s) }} ({{ counts.deposits?.[s] ?? 0 }})
                    </button>
                </div>
                <div class="glass-card rounded-xl overflow-x-auto">
                    <table class="w-full text-xs min-w-[860px]">
                        <thead class="bg-white/5 text-gray-500 uppercase tracking-wider"><tr>
                            <th class="text-left px-3 py-2">{{ t('admin.bridge.list.colChainTx') }}</th><th class="text-left px-3">{{ t('admin.bridge.list.colDepositor') }}</th><th class="text-right px-3">{{ t('admin.bridge.list.colAmount') }}</th>
                            <th class="text-left px-3">{{ t('admin.bridge.list.colStatus') }}</th><th class="text-left px-3">{{ t('admin.bridge.list.colMint') }}</th><th class="text-right px-3">{{ t('admin.bridge.list.colActions') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="d in deposits.data" :key="d.id" class="align-top">
                                <td class="px-3 py-2">
                                    <div class="text-white">{{ chainName(d.source_chain_id) }}</div>
                                    <a :href="txUrl(d.source_chain_id, d.tx_hash)" target="_blank" class="font-mono text-primary-300 hover:underline">{{ short(d.tx_hash) }}</a>
                                    <span class="text-gray-600"> #{{ d.log_index }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-gray-300">{{ short(d.from_address) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-white">{{ d.amount }}<div v-if="d.mint_amount !== d.amount" class="text-gray-500">{{ t('admin.bridge.list.mintAmount', { amount: d.mint_amount }) }}</div></td>
                                <td class="px-3 py-2">
                                    <span :class="['px-2 py-0.5 rounded-md border', statusStyle[d.status]]">{{ statusLabel(d.status) }}</span>
                                    <div v-if="d.review_reason" class="text-amber-300 mt-1">{{ reasonLabel(d.review_reason) }}</div>
                                    <div v-if="d.last_error" class="text-gray-500 mt-1 max-w-[240px] break-words">{{ d.last_error }}</div>
                                    <div v-if="d.limits_overridden" class="text-gray-500 mt-1">{{ t('admin.bridge.list.adminApproved') }}</div>
                                </td>
                                <td class="px-3 py-2"><a v-if="d.mint_tx_hash" :href="txUrl(4289, d.mint_tx_hash)" target="_blank" class="font-mono text-primary-300 hover:underline">{{ short(d.mint_tx_hash) }}</a></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <template v-if="d.status === 'needs_review' || (d.status === 'ignored' && d.review_reason === 'below_minimum')">
                                        <button @click="ask('deposit', 'approve', d)" :disabled="busy" class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">{{ t('admin.bridge.list.approve') }}</button>
                                    </template>
                                    <!-- needs_review ที่เคยส่ง mint แล้ว ปฏิเสธได้ — เซิร์ฟเวอร์พิสูจน์บนเชนก่อนว่าไม่มีการ mint -->
                                    <button v-if="d.status === 'needs_review' || (d.status === 'confirmed' && !d.mint_tx_hash)" @click="ask('deposit', 'reject', d)" :disabled="busy"
                                        class="ml-1 px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-white/10 disabled:opacity-40">{{ t('admin.bridge.list.reject') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!deposits.data.length"><td colspan="6" class="px-3 py-10 text-center text-gray-500">{{ t('admin.bridge.list.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 text-xs">
                    <button v-if="deposits.prev_page_url" @click="router.get(deposits.prev_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">{{ t('admin.bridge.list.prev') }}</button>
                    <button v-if="deposits.next_page_url" @click="router.get(deposits.next_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">{{ t('admin.bridge.list.next') }}</button>
                </div>
            </div>

            <!-- ═══════════ ถอน ═══════════ -->
            <div v-if="activeTab === 'withdrawals'" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button @click="goTab('withdrawals')" :class="['px-3 py-1.5 text-xs rounded-lg border', !filters.status ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">{{ t('common.all') }}</button>
                    <button v-for="s in withdrawFilters" :key="s" @click="goTab('withdrawals', s)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.status === s ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                        {{ statusLabel(s) }} ({{ counts.withdrawals?.[s] ?? 0 }})
                    </button>
                </div>
                <div class="glass-card rounded-xl overflow-x-auto">
                    <table class="w-full text-xs min-w-[900px]">
                        <thead class="bg-white/5 text-gray-500 uppercase tracking-wider"><tr>
                            <th class="text-left px-3 py-2">{{ t('admin.bridge.list.colBurnTx') }}</th><th class="text-left px-3">{{ t('admin.bridge.list.colWithdrawer') }}</th><th class="text-left px-3">{{ t('admin.bridge.list.colTarget') }}</th>
                            <th class="text-right px-3">{{ t('admin.bridge.list.colAmountPayout') }}</th><th class="text-left px-3">{{ t('admin.bridge.list.colStatus') }}</th><th class="text-right px-3">{{ t('admin.bridge.list.colActions') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="w in withdrawals.data" :key="w.id" class="align-top">
                                <td class="px-3 py-2"><a :href="txUrl(4289, w.tx_hash)" target="_blank" class="font-mono text-primary-300 hover:underline">{{ short(w.tx_hash) }}</a></td>
                                <td class="px-3 py-2 font-mono text-gray-300">{{ short(w.from_address) }}</td>
                                <td class="px-3 py-2">
                                    <div class="text-white">{{ w.target_chain_id ? chainName(w.target_chain_id) : t('admin.bridge.list.chainRaw', { raw: String(w.target_chain_raw).slice(0, 20) }) }}</div>
                                    <div class="font-mono text-gray-400 break-all max-w-[220px]">{{ w.target_address }}</div>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-white">{{ w.amount }}<div class="text-gray-500">{{ t('admin.bridge.list.payoutLine', { payout: w.payout_amount, fee: w.fee }) }}</div></td>
                                <td class="px-3 py-2">
                                    <span :class="['px-2 py-0.5 rounded-md border', statusStyle[w.status]]">{{ statusLabel(w.status) }}</span>
                                    <div v-if="w.review_reason" class="text-amber-300 mt-1">{{ reasonLabel(w.review_reason) }}</div>
                                    <div v-if="w.last_error" class="text-gray-500 mt-1 max-w-[240px] break-words">{{ w.last_error }}</div>
                                    <a v-if="w.payout_tx_hash" :href="txUrl(w.target_chain_id, w.payout_tx_hash)" target="_blank" class="block font-mono text-primary-300 hover:underline mt-1">{{ short(w.payout_tx_hash) }}</a>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button v-if="w.status === 'needs_review'" @click="ask('withdrawal', 'approve', w)" :disabled="busy" class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">{{ t('admin.bridge.list.approve') }}</button>
                                    <button v-if="['needs_review', 'pending', 'awaiting_liquidity'].includes(w.status)" @click="ask('withdrawal', 'reject', w)" :disabled="busy"
                                        class="ml-1 px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-white/10 disabled:opacity-40">{{ t('admin.bridge.list.reject') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!withdrawals.data.length"><td colspan="6" class="px-3 py-10 text-center text-gray-500">{{ t('admin.bridge.list.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 text-xs">
                    <button v-if="withdrawals.prev_page_url" @click="router.get(withdrawals.prev_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">{{ t('admin.bridge.list.prev') }}</button>
                    <button v-if="withdrawals.next_page_url" @click="router.get(withdrawals.next_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">{{ t('admin.bridge.list.next') }}</button>
                </div>
            </div>

            <!-- ═══════════ ตั้งค่า ═══════════ -->
            <div v-if="activeTab === 'settings'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="glass-card rounded-xl p-5 space-y-3">
                    <h3 class="font-semibold text-white">{{ t('admin.bridge.settings.limitsTitle') }}</h3>
                    <p class="text-xs text-gray-500">{{ t('admin.bridge.settings.limitsHint') }}</p>
                    <div v-for="key in limitKeys" :key="key" class="flex items-center justify-between gap-3">
                        <label class="text-xs text-gray-300">{{ t(`admin.bridge.limit.${key}`) }}</label>
                        <input v-model.trim="limitsForm[key]" type="text" inputmode="decimal" class="w-36 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                    </div>
                </div>
                <div class="glass-card rounded-xl p-5 space-y-3">
                    <h3 class="font-semibold text-white">{{ t('admin.bridge.settings.chainsTitle') }}</h3>
                    <div v-for="c in settings.chains" :key="c.chain_id" class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-xs">
                            <p class="text-white">{{ c.name }} <span class="text-gray-500">{{ t('admin.bridge.settings.tokenInfo', { symbol: c.usdt.symbol, decimals: c.usdt.decimals }) }}</span></p>
                            <p v-if="!c.configured" class="text-gray-500">{{ t('admin.bridge.settings.disabledInEnv') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model.trim="feesForm[c.chain_id]" type="text" inputmode="decimal" class="w-24 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                            <label class="flex items-center gap-1 text-xs text-gray-300"><input type="checkbox" v-model="chainsForm[c.chain_id]" :disabled="!c.configured" /> {{ t('admin.bridge.settings.enable') }}</label>
                        </div>
                    </div>
                </div>
                <div class="lg:col-span-2 space-y-2">
                    <div v-if="settingsErrors.length" class="rounded-xl border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-300">
                        <div v-for="(e, i) in settingsErrors" :key="i">{{ e?.key ? t(e.key) : e }}</div>
                    </div>
                    <button @click="confirmSettings = true" :disabled="busy" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-primary-500/20 text-primary-300 border border-primary-500/30 disabled:opacity-40">{{ t('common.save') }}</button>
                </div>
            </div>
        </div>

        <!-- กล่องยืนยัน -->
        <ConfirmDialog :show="confirmToggle" :danger="state.site_switch" :loading="busy"
            :title="state.site_switch ? t('admin.bridge.dialog.stopTitle') : t('admin.bridge.dialog.startTitle')"
            :message="state.site_switch ? t('admin.bridge.dialog.stopMessage') : t('admin.bridge.dialog.startMessage')"
            :confirm-text="state.site_switch ? t('admin.bridge.dialog.stopConfirm') : t('admin.bridge.dialog.startConfirm')" :cancel-text="t('common.cancel')"
            @confirm="doToggle" @cancel="confirmToggle = false" />

        <ConfirmDialog :show="!!pending && pending.action === 'approve' && !needsPayoutProof" :loading="busy" :title="t('admin.bridge.dialog.approveTitle')"
            :message="confirmMessage" :confirm-text="t('admin.bridge.list.approve')" :cancel-text="t('common.cancel')"
            @confirm="runPending" @cancel="pending = null" />

        <!-- อนุมัติจ่ายซ้ำรายการที่เคยส่งธุรกรรมแล้ว — เปิดช่องกรอกหลักฐาน nonce (ไม่บังคับ: ระบบหาเองจากรายการอื่นก่อน) -->
        <div v-if="pending && pending.action === 'approve' && needsPayoutProof" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="!busy && (pending = null)">
            <div class="w-full max-w-md glass-card rounded-2xl p-6 space-y-4">
                <h3 class="text-lg font-bold text-white">{{ t('admin.bridge.dialog.payoutTitle') }}</h3>
                <p class="text-sm text-gray-300 break-words">{{ confirmMessage }}</p>
                <div class="space-y-1">
                    <label class="text-xs text-gray-400">{{ t('admin.bridge.dialog.nonceLabelPayout', { nonce: pending.row.payout_nonce ?? '?' }) }}</label>
                    <input v-model.trim="nonceTxHash" type="text" placeholder="0x…" spellcheck="false" data-test="nonce-tx-hash"
                        class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-xs font-mono" />
                    <p v-if="!nonceTxValid" class="text-xs text-red-300">{{ t('admin.bridge.dialog.nonceInvalid') }}</p>
                    <p class="text-[11px] text-gray-500">{{ t('admin.bridge.dialog.nonceHint') }}</p>
                </div>
                <div class="flex justify-end gap-3">
                    <button @click="pending = null" :disabled="busy" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10">{{ t('common.cancel') }}</button>
                    <button @click="runPending" :disabled="busy || !nonceTxValid" class="px-4 py-2 text-sm rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">{{ t('admin.bridge.list.approve') }}</button>
                </div>
            </div>
        </div>

        <ConfirmDialog :show="confirmSettings" :loading="busy" :title="t('admin.bridge.dialog.settingsTitle')"
            :message="t('admin.bridge.dialog.settingsMessage')" :confirm-text="t('common.save')" :cancel-text="t('common.cancel')"
            @confirm="saveSettings" @cancel="confirmSettings = false" />

        <!-- ปฏิเสธ — ต้องมีเหตุผลเสมอ -->
        <div v-if="pending && pending.action === 'reject'" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="!busy && (pending = null)">
            <div class="w-full max-w-md glass-card rounded-2xl p-6 space-y-4">
                <h3 class="text-lg font-bold text-white">{{ t('admin.bridge.dialog.rejectTitle') }}</h3>
                <p class="text-sm text-gray-300">{{ confirmMessage }}</p>
                <textarea v-model="rejectReason" rows="3" maxlength="500" :placeholder="t('admin.bridge.dialog.rejectPlaceholder')"
                    class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm"></textarea>
                <div v-if="needsPayoutProof" class="space-y-1">
                    <label class="text-xs text-gray-400">{{ t('admin.bridge.dialog.nonceLabelReject', { nonce: pending.row.payout_nonce ?? '?' }) }}</label>
                    <input v-model.trim="nonceTxHash" type="text" placeholder="0x…" spellcheck="false"
                        class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-xs font-mono" />
                    <p v-if="!nonceTxValid" class="text-xs text-red-300">{{ t('admin.bridge.dialog.nonceInvalid') }}</p>
                </div>
                <div class="flex justify-end gap-3">
                    <button @click="pending = null" :disabled="busy" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10">{{ t('common.cancel') }}</button>
                    <button @click="runPending" :disabled="busy || !rejectReason.trim() || !nonceTxValid" class="px-4 py-2 text-sm rounded-xl bg-red-500/20 text-red-300 border border-red-500/30 disabled:opacity-40">{{ t('admin.bridge.list.reject') }}</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
