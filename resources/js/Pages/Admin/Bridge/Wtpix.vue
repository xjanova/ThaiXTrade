<script setup>
/**
 * TPIX TRADE — หลังบ้านสะพาน TPIX (เชน 4289) ⇄ wTPIX (BSC)
 *
 * ภาพรวม (สวิตช์ · ด่านความพร้อม · บทบาท/แก๊ส BNB · เพดานบนสัญญา · เงินหนุน) · รายการสองทิศ · วงเงิน
 * ปุ่มที่กระทบเงินทุกปุ่มมีกล่องยืนยัน และกันกดเบิ้ลทั้งฝั่งหน้าจอ (busy) และฝั่งเซิร์ฟเวอร์ (409)
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const NS = 'admin.bridgeWtpix';
const tt = (key, params) => t(`${NS}.${key}`, params);

const props = defineProps({
    tab: { type: String, default: 'overview' },
    filters: { type: Object, default: () => ({}) },
    state: { type: Object, default: () => ({}) },
    snapshot: { type: Object, default: null },
    transfers: { type: Object, default: () => ({ data: [] }) },
    counts: { type: Object, default: () => ({}) },
    settings: { type: Object, default: () => ({ limits: {} }) },
    explorers: { type: Object, default: () => ({}) },
    chains: { type: Object, default: () => ({ tpix: { chain_id: 4289, name: 'TPIX Chain' }, bsc: { chain_id: 56, name: 'BNB Smart Chain' } }) },
});

const activeTab = ref(props.tab);
// notice เก็บคีย์ไว้ ไม่ใช่ข้อความที่แปลแล้ว — สลับภาษาระหว่างที่แถบยังค้างอยู่ก็เปลี่ยนตาม
// { type, key?, params?, text?, suffix? } — text = ข้อความจากเซิร์ฟเวอร์ (แสดงตามที่ได้มา)
const notice = ref(null);
const noticeText = computed(() => {
    const n = notice.value;
    if (!n) return '';
    return (n.key ? tt(n.key, n.params) : n.text ?? '') + (n.suffix ?? '');
});
const busy = ref(false);

function go(params) {
    router.get('/admin/bridge/wtpix', params, { preserveState: true, preserveScroll: true, replace: true });
}
function goTab(tab) {
    activeTab.value = tab;
    go({ tab });
}
function filter(status, direction = props.filters.direction) {
    go({ tab: 'transfers', ...(status ? { status } : {}), ...(direction ? { direction } : {}) });
}

const short = (v) => (v ? `${v.slice(0, 10)}…${v.slice(-6)}` : '—');
const txUrl = (chainId, hash) => (props.explorers?.[chainId] && hash ? `${props.explorers[chainId]}/tx/${hash}` : null);
const chainName = (id) => (id === props.chains.tpix?.chain_id ? 'TPIX' : id === props.chains.bsc?.chain_id ? props.chains.bsc.name : `#${id ?? '?'}`);
const directionLabel = { to_bsc: 'TPIX → wTPIX (BSC)', to_tpix: 'wTPIX (BSC) → TPIX' };

// แปลรหัสจากเซิร์ฟเวอร์ — ไม่มีคำแปลก็โชว์รหัสดิบ (ดีกว่าโชว์ชื่อคีย์)
function codeLabel(group, code) {
    if (code === null || code === undefined || code === '') return code;
    const key = `${NS}.${group}.${code}`;
    const v = t(key);
    return v === key ? code : v;
}

const displayLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));
const formatDateTime = (v) => (v ? new Date(v).toLocaleString(displayLocale.value) : '—');

const tabs = computed(() => [
    ['overview', tt('tabs.overview')],
    ['transfers', props.counts?.needs_review ? tt('tabs.transfersPending', { n: props.counts.needs_review }) : tt('tabs.transfers')],
    ['settings', tt('tabs.settings')],
]);

const statusStyle = {
    confirmed: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    awaiting_liquidity: 'bg-orange-500/20 text-orange-300 border-orange-500/30',
    sending: 'bg-violet-500/20 text-violet-300 border-violet-500/30',
    completed: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    needs_review: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    rejected: 'bg-gray-600/30 text-gray-400 border-gray-500/30',
    ignored: 'bg-gray-700/30 text-gray-500 border-gray-600/30',
};

// ── สวิตช์ ──────────────────────────────────────────────────────────────
const confirmToggle = ref(false);
const toggleReason = ref('');

async function doToggle() {
    if (busy.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post('/admin/bridge/wtpix/toggle', { enabled: !props.state.site_switch, reason: toggleReason.value || null });
        notice.value = {
            type: 'success',
            key: data.data.enabled ? 'notices.bridgeEnabled' : 'notices.bridgeDisabled',
            suffix: data.data.warnings?.length ? ` · ${data.data.warnings.join(' · ')}` : '',
        };
        confirmToggle.value = false;
        toggleReason.value = '';
        router.reload({ only: ['state'] });
    } catch (e) {
        const msg = e.response?.data?.message;
        notice.value = msg ? { type: 'error', text: msg } : { type: 'error', key: 'notices.toggleFailed' };
    } finally {
        busy.value = false;
    }
}

// ── โหมดทดสอบ ───────────────────────────────────────────────────────────
const testWalletsText = ref((props.settings.test_wallets ?? []).join('\n'));
const testWalletsErrors = ref([]);
const confirmTestWallets = ref(false);
const testWalletsDraft = computed(() => [...new Set(testWalletsText.value.split(/[\s,]+/).map((a) => a.trim().toLowerCase()).filter(Boolean))]);
const testWalletsMessage = computed(() => (testWalletsDraft.value.length
    ? tt('testMode.confirmSome', { n: testWalletsDraft.value.length })
    : tt('testMode.confirmEmpty')));

async function saveTestWallets() {
    if (busy.value) return;
    busy.value = true;
    testWalletsErrors.value = [];
    try {
        const { data } = await axios.put('/admin/bridge/wtpix/test-wallets', { wallets: testWalletsDraft.value });
        testWalletsText.value = (data.data.test_wallets ?? []).join('\n');
        notice.value = { type: 'success', key: data.data.test_wallets?.length ? 'notices.testModeOn' : 'notices.testModeOff' };
        router.reload({ only: ['settings', 'state'] });
    } catch (e) {
        testWalletsErrors.value = e.response?.data?.errors
            ? [...new Set(Object.values(e.response.data.errors).flat())]
            : [e.response?.data?.message ?? tt('notices.saveFailed')];
    } finally {
        confirmTestWallets.value = false;
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
        const { data } = await axios.get('/admin/bridge/wtpix/measure');
        live.value = data.data;
    } catch (e) {
        notice.value = { type: 'error', key: e.response?.status === 429 ? 'notices.tooFrequent' : 'notices.readFailed' };
    } finally {
        measuring.value = false;
    }
}

const view = computed(() => live.value ?? props.snapshot);
const isNonZero = (v) => v !== null && v !== undefined && v !== '0';

// ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────
const pending = ref(null); // { action, row }
const rejectReason = ref('');

function ask(action, row) {
    pending.value = { action, row };
    rejectReason.value = '';
}

const confirmMessage = computed(() => {
    const p = pending.value;
    if (!p) return '';
    const r = p.row;
    const toBsc = r.direction === 'to_bsc';
    if (p.action === 'approve') {
        return toBsc
            ? tt('confirm.approveMint', { amount: r.receive_amount, chain: props.chains.bsc?.name ?? 'BSC', recipient: r.recipient })
            : tt('confirm.approveRelease', { amount: r.receive_amount, recipient: r.recipient });
    }
    return toBsc
        ? tt('confirm.rejectLock', { amount: r.amount, from: r.from_address })
        : tt('confirm.rejectBurn', { amount: r.amount, from: r.from_address });
});

async function runPending() {
    const p = pending.value;
    if (!p || busy.value) return;
    if (p.action === 'reject' && !rejectReason.value.trim()) return;

    busy.value = true;
    try {
        const { data } = await axios.post(`/admin/bridge/wtpix/transfers/${p.row.id}/${p.action}`, p.action === 'reject' ? { reason: rejectReason.value.trim() } : {});
        notice.value = data.message ? { type: 'success', text: data.message } : { type: 'success', key: 'notices.actionDone' };
        pending.value = null;
        router.reload({ only: ['transfers', 'counts', 'state'] });
    } catch (e) {
        const errors = e.response?.data?.errors;
        const msg = Array.isArray(errors) ? errors.join(' · ') : e.response?.data?.message;
        notice.value = msg ? { type: 'error', text: msg } : { type: 'error', key: 'notices.actionFailed' };
        pending.value = null;
        router.reload({ only: ['transfers', 'counts'] });
    } finally {
        busy.value = false;
    }
}

// ── วงเงิน ──────────────────────────────────────────────────────────────
// คีย์ของฟอร์ม (ตรงกับชื่อฟิลด์ที่ส่งเซิร์ฟเวอร์) — ป้ายแปลใน template ผ่าน limits.<key>
const limitKeys = [
    'min_lock', 'max_lock_per_tx', 'max_lock_per_address_daily', 'lock_fee', 'lock_fee_max',
    'min_burn', 'max_burn_per_tx', 'max_burn_per_address_daily', 'burn_fee',
];
const limitsForm = ref({ ...props.settings.limits });
const markupForm = ref(props.settings.fee_markup_pct ?? '10');
const feeQuote = computed(() => props.settings.fee_quote ?? null);
const feeLedger = computed(() => props.settings.fee_ledger ?? null);
const settingsErrors = ref([]);
const confirmSettings = ref(false);

async function saveSettings() {
    if (busy.value) return;
    busy.value = true;
    settingsErrors.value = [];
    try {
        await axios.put('/admin/bridge/wtpix/settings', { limits: limitsForm.value, fee_markup_pct: String(markupForm.value ?? '').trim() });
        notice.value = { type: 'success', key: 'notices.limitsSaved' };
        confirmSettings.value = false;
        router.reload({ only: ['settings', 'state'] });
    } catch (e) {
        settingsErrors.value = e.response?.data?.errors
            ? (Array.isArray(e.response.data.errors) ? e.response.data.errors : Object.values(e.response.data.errors).flat())
            : [e.response?.data?.message ?? tt('notices.saveFailed')];
        confirmSettings.value = false;
    } finally {
        busy.value = false;
    }
}

const statusFilters = ['needs_review', 'confirmed', 'awaiting_liquidity', 'sending', 'completed', 'ignored', 'rejected'];
const blockingFailed = computed(() => (props.state.checks || []).filter((c) => !c.ok && !c.optional).length);
</script>

<template>
    <Head :title="tt('pageTitle')" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ tt('header.title', { chain: chains.bsc?.name ?? 'BSC' }) }}</h1>
                    <p class="text-sm text-gray-400 mt-1">{{ tt('header.subtitle') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/bridge" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">{{ tt('header.usdtBridge') }}</Link>
                    <a href="/bridge/reserves?asset=tpix" target="_blank" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">{{ tt('header.proofOfReserves') }}</a>
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
                            <h3 class="font-semibold text-white">{{ tt('switch.title') }}</h3>
                            <span :class="['px-2 py-1 text-xs rounded-md border', state.operational ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-red-500/20 text-red-300 border-red-500/30']">
                                {{ state.operational ? tt('switch.operational') : tt('switch.stopped') }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-400">
                            {{ tt('switch.installed') }} <b :class="state.installed ? 'text-emerald-300' : 'text-red-300'">{{ state.installed ? t('common.yes') : tt('switch.notInstalled') }}</b>
                            · {{ tt('switch.switchLabel') }} <b>{{ state.site_switch ? tt('switch.on') : tt('switch.off') }}</b>
                            · mint: <b :class="state.mint_ready ? 'text-emerald-300' : 'text-amber-300'">{{ state.mint_ready ? tt('ready') : tt('notReady') }}</b>
                            · {{ tt('switch.releaseLabel') }} <b :class="state.release_ready ? 'text-emerald-300' : 'text-amber-300'">{{ state.release_ready ? tt('ready') : tt('notReady') }}</b>
                        </p>
                        <p v-if="state.switch_reason" class="text-xs text-amber-300 break-words">{{ state.switch_reason }}</p>
                        <p v-if="state.shared_key_with_usdt" class="text-xs text-amber-300">{{ tt('switch.sharedKeyWarning') }}</p>
                        <button @click="confirmToggle = true" :disabled="busy"
                            :class="['w-full py-2.5 rounded-xl text-sm font-semibold border transition-all disabled:opacity-40', state.site_switch ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30']">
                            {{ state.site_switch ? tt('switch.stopButton') : tt('switch.startButton') }}
                        </button>
                        <p class="text-[11px] text-gray-500">{{ tt('switch.note') }}</p>

                        <div class="pt-3 border-t border-white/10 space-y-2" data-test="test-wallets">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-white">{{ tt('testMode.title') }}</h4>
                                <span :class="['px-2 py-1 text-xs rounded-md border', state.test_mode ? 'bg-amber-500/20 text-amber-300 border-amber-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                                    {{ state.test_mode ? tt('testMode.onBadge', { n: settings.test_wallets?.length ?? 0 }) : tt('testMode.offBadge') }}
                                </span>
                            </div>
                            <p class="text-[11px] text-gray-500">{{ tt('testMode.help') }}</p>
                            <textarea v-model="testWalletsText" rows="3" :placeholder="tt('testMode.placeholder')"
                                class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-xs font-mono"></textarea>
                            <div v-if="testWalletsErrors.length" class="text-xs text-red-300">
                                <div v-for="(e, i) in testWalletsErrors" :key="i">{{ e }}</div>
                            </div>
                            <button @click="confirmTestWallets = true" :disabled="busy"
                                class="w-full py-2 rounded-xl text-sm font-semibold border bg-amber-500/10 text-amber-300 border-amber-500/30 disabled:opacity-40">
                                {{ tt('testMode.saveButton') }}
                            </button>
                        </div>
                    </div>

                    <div class="glass-card rounded-xl p-5">
                        <h3 class="font-semibold text-white mb-1">{{ tt('readiness.title') }}</h3>
                        <p class="text-[11px] text-gray-500 mb-3">{{ blockingFailed ? tt('readiness.failed', { n: blockingFailed }) : tt('readiness.allPassed') }} · {{ tt('readiness.note') }}</p>
                        <ul class="space-y-1.5 text-xs" data-test="readiness">
                            <li v-for="c in state.checks || []" :key="c.key" class="flex items-start gap-2">
                                <span :class="c.ok ? 'text-emerald-400' : (c.optional ? 'text-amber-400' : 'text-red-400')">{{ c.ok ? '✓' : (c.optional ? '!' : '✗') }}</span>
                                <span class="text-gray-300">{{ codeLabel('checks', c.key) }}<span v-if="c.optional" class="text-gray-500"> {{ tt('readiness.recommended') }}</span></span>
                                <span v-if="!c.ok" class="text-gray-500 break-all">— {{ c.hint }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="glass-card rounded-xl p-5 text-xs space-y-2">
                        <h3 class="font-semibold text-white text-sm">{{ tt('signers.title') }}</h3>
                        <div v-for="(s, role) in state.signers || {}" :key="role" class="space-y-0.5">
                            <div class="flex justify-between gap-2"><span class="text-gray-400">{{ role }}</span>
                                <span :class="s.ready ? 'text-emerald-400' : 'text-amber-400'">{{ s.ready ? tt('ready') : (s.passphrase_set ? tt('signers.missingFile') : tt('signers.missingPassphrase')) }}</span></div>
                            <code class="font-mono text-white break-all block">{{ s.address ?? tt('signers.noKeystore') }}</code>
                            <div class="text-gray-500">BNB: <span class="font-mono text-gray-300">{{ s.bnb ?? '—' }}</span></div>
                        </div>
                        <div class="pt-2 border-t border-white/5 space-y-1">
                            <div><span class="text-gray-400">vault (4289)</span> <code class="font-mono text-white break-all">{{ state.addresses?.vault ?? '—' }}</code></div>
                            <div v-if="state.addresses?.vault_registry && state.addresses.vault_registry !== state.addresses.vault" class="text-red-300">{{ tt('signers.registry', { address: state.addresses.vault_registry }) }}</div>
                            <div><span class="text-gray-400">wTPIX</span> <code class="font-mono text-white break-all">{{ state.addresses?.wtpix ?? '—' }}</code></div>
                            <div><span class="text-gray-400">minter</span> <code class="font-mono text-white break-all">{{ state.addresses?.minter ?? '—' }}</code></div>
                        </div>
                    </div>
                    <div class="glass-card rounded-xl p-5 text-xs space-y-1.5">
                        <h3 class="font-semibold text-white text-sm mb-1">{{ tt('vault.title') }}</h3>
                        <div class="flex justify-between"><span class="text-gray-400">paused</span><span :class="state.vault?.paused ? 'text-red-300' : 'text-white'">{{ state.vault?.paused == null ? '—' : (state.vault.paused ? t('common.yes') : t('common.no')) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ tt('vault.balance') }}</span><span class="text-white font-mono">{{ state.vault?.balance ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ tt('vault.lockRange') }}</span><span class="text-white font-mono">{{ state.vault?.min_lock ?? '—' }} – {{ state.vault?.max_lock ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ tt('vault.releaseCaps') }}</span><span class="text-white font-mono">{{ state.vault?.max_per_tx ?? '—' }} / {{ state.vault?.daily_cap ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ tt('remainingToday') }}</span><span class="text-white font-mono">{{ state.vault?.remaining_today ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">totalLocked / totalReleased</span><span class="text-white font-mono">{{ state.vault?.total_locked ?? '—' }} / {{ state.vault?.total_released ?? '—' }}</span></div>
                    </div>
                    <div class="glass-card rounded-xl p-5 text-xs space-y-1.5">
                        <h3 class="font-semibold text-white text-sm mb-1">WTPIXBridgeMinter + wTPIX ({{ chains.bsc?.name }})</h3>
                        <div class="flex justify-between"><span class="text-gray-400">minter paused</span><span :class="state.minter?.paused ? 'text-red-300' : 'text-white'">{{ state.minter?.paused == null ? '—' : (state.minter.paused ? t('common.yes') : t('common.no')) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">mint maxPerTx / dailyCap</span><span class="text-white font-mono">{{ state.minter?.max_per_tx ?? '—' }} / {{ state.minter?.daily_cap ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">{{ tt('remainingToday') }}</span><span class="text-white font-mono">{{ state.minter?.remaining_today ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">totalMinted</span><span class="text-white font-mono">{{ state.minter?.total_minted ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">wTPIX supply</span><span class="text-white font-mono">{{ state.token?.total_supply ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">wTPIX paused</span><span :class="state.token?.paused ? 'text-red-300' : 'text-white'">{{ state.token?.paused == null ? '—' : (state.token.paused ? t('common.yes') : t('common.no')) }}</span></div>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-white">{{ tt('backing.title') }} {{ live ? tt('backing.live') : tt('backing.lastReconcile', { time: formatDateTime(snapshot?.checked_at) }) }}</h3>
                        <button @click="measure" :disabled="measuring" class="px-3 py-1.5 text-xs rounded-lg bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-40">
                            {{ measuring ? tt('backing.reading') : tt('backing.readLive') }}
                        </button>
                    </div>
                    <p v-if="!view" class="text-sm text-gray-500">{{ tt('backing.empty') }}</p>
                    <template v-else>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                            <div><p class="text-gray-500">wTPIX supply</p><p class="text-white font-mono text-base">{{ view.supply ?? '—' }}</p></div>
                            <div><p class="text-gray-500">{{ tt('vault.balance') }}</p><p class="text-white font-mono text-base">{{ view.vault ?? '—' }}</p></div>
                            <div><p class="text-gray-500">{{ tt('backing.owed') }}</p><p class="text-white font-mono text-base">{{ view.owed }}</p></div>
                            <div><p class="text-gray-500">{{ tt('backing.net') }}</p><p class="text-white font-mono text-base">{{ view.backing }}</p></div>
                            <div><p class="text-gray-500">{{ tt('backing.deficit') }}</p><p :class="['font-mono text-base', String(view.deficit).startsWith('-') || view.deficit === '0' ? 'text-emerald-300' : 'text-red-300']">{{ view.deficit }}</p></div>
                        </div>
                        <p v-if="isNonZero(view.unexplained_mint)" class="text-sm text-red-300">{{ tt('backing.unexplainedMint', { amount: view.unexplained_mint }) }}</p>
                        <p v-if="isNonZero(view.unexplained_release)" class="text-sm text-red-300">{{ tt('backing.unexplainedRelease', { amount: view.unexplained_release }) }}</p>
                        <p v-if="isNonZero(view.foreign_supply)" class="text-sm text-red-300">{{ tt('backing.foreignSupply', { amount: view.foreign_supply }) }}</p>
                        <p v-if="view.note" class="text-xs text-amber-300">{{ view.note }}</p>
                        <p v-if="view.action" class="text-xs text-gray-500">{{ tt('backing.action', { action: view.action }) }}</p>
                    </template>
                </div>
            </div>

            <!-- ═══════════ รายการ ═══════════ -->
            <div v-if="activeTab === 'transfers'" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button @click="filter(null, null)" :class="['px-3 py-1.5 text-xs rounded-lg border', !filters.status && !filters.direction ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">{{ t('common.all') }}</button>
                    <button v-for="d in ['to_bsc', 'to_tpix']" :key="d" @click="filter(filters.status, filters.direction === d ? null : d)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.direction === d ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">{{ directionLabel[d] }}</button>
                    <span class="w-px bg-white/10"></span>
                    <button v-for="s in statusFilters" :key="s" @click="filter(filters.status === s ? null : s)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.status === s ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                        {{ codeLabel('statuses', s) }} ({{ counts?.[s] ?? 0 }})
                    </button>
                </div>
                <div class="glass-card rounded-xl overflow-x-auto">
                    <table class="w-full text-xs min-w-[900px]">
                        <thead class="bg-white/5 text-gray-500 uppercase tracking-wider"><tr>
                            <th class="text-left px-3 py-2">{{ tt('table.direction') }}</th><th class="text-left px-3">{{ tt('table.recipient') }}</th><th class="text-right px-3">{{ tt('table.amount') }}</th>
                            <th class="text-left px-3">{{ t('common.status') }}</th><th class="text-left px-3">{{ tt('table.destTx') }}</th><th class="text-right px-3">{{ tt('table.actions') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="tr in transfers.data" :key="tr.id" class="align-top">
                                <td class="px-3 py-2">
                                    <div class="text-white">{{ directionLabel[tr.direction] }}</div>
                                    <a :href="txUrl(tr.source_chain_id, tr.tx_hash)" target="_blank" rel="noopener" class="font-mono text-primary-300 hover:underline">{{ short(tr.tx_hash) }}</a>
                                    <span class="text-gray-600"> #{{ tr.log_index }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-gray-300">{{ short(tr.recipient) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-white">{{ tr.amount }}<div class="text-gray-500">{{ tt('table.received', { amount: tr.receive_amount, fee: tr.fee }) }}</div></td>
                                <td class="px-3 py-2">
                                    <span :class="['px-2 py-0.5 rounded-md border', statusStyle[tr.status]]">{{ codeLabel('statuses', tr.status) }}</span>
                                    <div v-if="tr.review_reason" class="text-amber-300 mt-1">{{ codeLabel('reasons', tr.review_reason) }}</div>
                                    <div v-if="tr.last_error" class="text-gray-500 mt-1 max-w-[260px] break-words">{{ tr.last_error }}</div>
                                    <div v-if="tr.limits_overridden" class="text-gray-500 mt-1">{{ tt('table.adminApproved') }}</div>
                                </td>
                                <td class="px-3 py-2"><a v-if="tr.dest_tx_hash" :href="txUrl(tr.dest_chain_id, tr.dest_tx_hash)" target="_blank" rel="noopener" class="font-mono text-primary-300 hover:underline">{{ short(tr.dest_tx_hash) }}</a></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button v-if="tr.status === 'needs_review' || (tr.status === 'ignored' && tr.review_reason === 'below_minimum')" @click="ask('approve', tr)" :disabled="busy"
                                        class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">{{ tt('approve') }}</button>
                                    <button v-if="['needs_review', 'confirmed', 'awaiting_liquidity'].includes(tr.status)" @click="ask('reject', tr)" :disabled="busy"
                                        class="ml-1 px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-white/10 disabled:opacity-40">{{ tt('reject') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!transfers.data.length"><td colspan="6" class="px-3 py-10 text-center text-gray-500">{{ tt('table.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 text-xs">
                    <button v-if="transfers.prev_page_url" @click="router.get(transfers.prev_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">{{ tt('table.prev') }}</button>
                    <button v-if="transfers.next_page_url" @click="router.get(transfers.next_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">{{ tt('table.next') }}</button>
                </div>
            </div>

            <!-- ═══════════ วงเงิน ═══════════ -->
            <div v-if="activeTab === 'settings'" class="glass-card rounded-xl p-5 space-y-3 max-w-2xl">
                <h3 class="font-semibold text-white">{{ tt('settings.title') }}</h3>
                <p class="text-xs text-gray-500">{{ tt('settings.help') }}</p>
                <div v-for="key in limitKeys" :key="key" class="flex items-center justify-between gap-3">
                    <label class="text-xs text-gray-300">{{ tt(`limits.${key}`) }}</label>
                    <input v-model.trim="limitsForm[key]" type="text" inputmode="decimal" class="w-40 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                </div>
                <div class="flex items-center justify-between gap-3">
                    <label class="text-xs text-gray-300">{{ tt('settings.markup') }}</label>
                    <input v-model.trim="markupForm" type="text" inputmode="decimal" class="w-40 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                </div>

                <div class="rounded-xl border border-white/10 bg-white/5 p-3 space-y-1 text-xs text-gray-300">
                    <div class="font-semibold text-white">{{ tt('settings.feeQuote', { fee: feeQuote?.fee ?? '—' }) }}
                        <span v-if="feeQuote && !feeQuote.fresh" class="text-amber-300">{{ tt('settings.feeStale') }}</span>
                    </div>
                    <template v-if="feeQuote?.quote">
                        <div>{{ tt('settings.source', { source: codeLabel('feeSources', feeQuote.quote.source) }) }}<span v-if="feeQuote.quote.note"> · {{ feeQuote.quote.note }}</span></div>
                        <div>{{ tt('settings.rawFee', { fee: feeQuote.quote.raw_fee ?? '—' }) }}
                            · {{ tt('settings.gas', { units: feeQuote.quote.gas_units, gwei: feeQuote.quote.gas_gwei ?? '—' }) }}
                            · BNB ${{ feeQuote.quote.bnb_usd ?? '—' }} · TPIX ${{ feeQuote.quote.tpix_usd ?? '—' }} {{ tt('settings.twap') }}</div>
                        <div class="text-gray-500">{{ tt('settings.updatedAt', { time: formatDateTime(feeQuote.quote.updated_at) }) }}</div>
                    </template>
                    <div v-if="feeLedger" class="pt-1 border-t border-white/5">
                        {{ tt('settings.ledger', { fees: feeLedger.fees_tpix, mints: feeLedger.mints, gas: feeLedger.gas_bnb }) }}
                        <span v-if="feeLedger.unmetered" class="text-gray-500">{{ tt('settings.unmetered', { n: feeLedger.unmetered }) }}</span>
                    </div>
                </div>

                <div v-if="settingsErrors.length" class="rounded-xl border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-300">
                    <div v-for="(e, i) in settingsErrors" :key="i">{{ e }}</div>
                </div>
                <button @click="confirmSettings = true" :disabled="busy" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-primary-500/20 text-primary-300 border border-primary-500/30 disabled:opacity-40">{{ t('common.save') }}</button>
            </div>
        </div>

        <ConfirmDialog :show="confirmToggle" :danger="state.site_switch" :loading="busy"
            :title="state.site_switch ? tt('dialogs.stopTitle') : tt('dialogs.startTitle')"
            :message="state.site_switch ? tt('dialogs.stopMessage') : tt('dialogs.startMessage')"
            :confirm-text="state.site_switch ? tt('dialogs.stop') : tt('dialogs.start')" :cancel-text="t('common.cancel')"
            @confirm="doToggle" @cancel="confirmToggle = false" />

        <ConfirmDialog :show="!!pending && pending.action === 'approve'" :loading="busy" :title="tt('dialogs.approveTitle')"
            :message="confirmMessage" :confirm-text="tt('approve')" :cancel-text="t('common.cancel')"
            @confirm="runPending" @cancel="pending = null" />

        <ConfirmDialog :show="confirmTestWallets" :loading="busy" :title="tt('dialogs.testWalletsTitle')"
            :message="testWalletsMessage" :confirm-text="t('common.save')" :cancel-text="t('common.cancel')"
            @confirm="saveTestWallets" @cancel="confirmTestWallets = false" />

        <ConfirmDialog :show="confirmSettings" :loading="busy" :title="tt('dialogs.settingsTitle')"
            :message="tt('dialogs.settingsMessage')" :confirm-text="t('common.save')" :cancel-text="t('common.cancel')"
            @confirm="saveSettings" @cancel="confirmSettings = false" />

        <!-- ปฏิเสธ — ต้องมีเหตุผลเสมอ -->
        <div v-if="pending && pending.action === 'reject'" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="!busy && (pending = null)">
            <div class="w-full max-w-md glass-card rounded-2xl p-6 space-y-4">
                <h3 class="text-lg font-bold text-white">{{ tt('dialogs.rejectTitle') }}</h3>
                <p class="text-sm text-gray-300 break-words">{{ confirmMessage }}</p>
                <textarea v-model="rejectReason" rows="3" maxlength="500" :placeholder="tt('dialogs.reasonPlaceholder')"
                    class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm"></textarea>
                <div class="flex justify-end gap-3">
                    <button @click="pending = null" :disabled="busy" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10">{{ t('common.cancel') }}</button>
                    <button @click="runPending" :disabled="busy || !rejectReason.trim()" class="px-4 py-2 text-sm rounded-xl bg-red-500/20 text-red-300 border border-red-500/30 disabled:opacity-40">{{ tt('reject') }}</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
