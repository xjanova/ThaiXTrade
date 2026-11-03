<script setup>
/**
 * TPIX TRADE — Admin Validator Management Dashboard
 * Review applications, monitor active validators, trigger votes
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useAdminPolling } from '@/Composables/useAdminPolling';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    validators: { type: Array, default: () => [] },
    onboarding: { type: Array, default: () => [] },
    applications: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});

const activeTab = ref('applications'); // applications | validators | onboarding | vote
const validators = ref(props.validators || []);
const onboarding = ref(props.onboarding || []);
const applications = ref(props.applications || []);
const stats = ref(props.stats || {});
const isRefreshing = ref(false);

// Application review
const reviewingId = ref(null);
const adminNotes = ref('');
const rejectReason = ref('');
const showRejectModal = ref(false);
const rejectingId = ref(null);

// Vote
const voteAddress = ref('');
const voteBls = ref('');
const voteNodeId = ref('');
const voteAction = ref('add'); // add | remove
const voteResult = ref(null);
const copied = ref('');

const fmt = (n) => Number(n || 0).toLocaleString();
const fmtDate = (d) => new Date(d).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US');

function flag(code) {
    if (!code || code.length !== 2) return '🌐';
    return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
}

function shortAddr(addr) {
    if (!addr) return '—';
    return addr.slice(0, 10) + '...' + addr.slice(-8);
}

const pendingCount = computed(() => applications.value.filter(a => a.status === 'pending').length);
const onboardingBadge = computed(() => onboarding.value.filter(o => ['awaiting_vote', 'drop_needed'].includes(o.stage)).length);

/** ขั้นของกุญแจที่ผูกไว้ — ตรงกับ ValidatorKeyBindingService::stage() */
const stageConfig = {
    awaiting_vote:  { labelKey: 'admin.validators.stage.awaitingVote', color: 'text-yellow-300', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    sealing:        { labelKey: 'admin.validators.stage.sealing', color: 'text-emerald-300', bg: 'bg-emerald-500/15 border-emerald-500/30' },
    drop_needed:    { labelKey: 'admin.validators.stage.dropNeeded', color: 'text-red-300', bg: 'bg-red-500/15 border-red-500/30' },
    stake_inactive: { labelKey: 'admin.validators.stage.stakeInactive', color: 'text-gray-400', bg: 'bg-white/5 border-white/10' },
    unknown:        { labelKey: 'admin.validators.stage.unknown', color: 'text-gray-400', bg: 'bg-white/5 border-white/10' },
};

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = text;
        setTimeout(() => { if (copied.value === text) copied.value = ''; }, 2000);
    } catch { /* เบราว์เซอร์ไม่ให้สิทธิ์คลิปบอร์ด — ผู้ใช้คัดลอกเองจากกล่องได้ */ }
}
const approvedCount = computed(() => applications.value.filter(a => a.status === 'approved').length);

const tierConfig = {
    validator: { label: 'Validator', color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    sentinel:  { label: 'Sentinel',  color: 'text-purple-400', bg: 'bg-purple-500/15 border-purple-500/30' },
    light:     { label: 'Light',     color: 'text-cyan-400',   bg: 'bg-cyan-500/15 border-cyan-500/30' },
};

const statusConfig = {
    pending:  { labelKey: 'admin.validators.status.pending', color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    approved: { labelKey: 'admin.validators.status.approved', color: 'text-emerald-400', bg: 'bg-emerald-500/15 border-emerald-500/30' },
    rejected: { labelKey: 'admin.validators.status.rejected', color: 'text-red-400', bg: 'bg-red-500/15 border-red-500/30' },
    active:   { labelKey: 'admin.validators.status.active', color: 'text-cyan-400', bg: 'bg-cyan-500/15 border-cyan-500/30' },
};

// ============================================================
//  API
// ============================================================
async function refreshAll() {
    isRefreshing.value = true;
    try {
        const { data } = await axios.get('/admin/validators/stats');
        if (data.success) {
            stats.value = data.data?.stats || {};
            validators.value = data.data?.validators || [];
            onboarding.value = data.data?.onboarding || [];
            applications.value = data.data?.applications || [];
        }
    } catch {} finally { isRefreshing.value = false; }
}

async function approveApplication(id) {
    try {
        const { data } = await axios.post(`/admin/validators/applications/${id}/approve`, {
            admin_notes: adminNotes.value,
        });
        if (data.success) {
            const app = applications.value.find(a => a.id === id);
            if (app) app.status = 'approved';
            reviewingId.value = null;
            adminNotes.value = '';
        }
    } catch (e) {
        alert(e.response?.data?.error?.message || t('admin.validators.errors.approvalFailed'));
    }
}

async function rejectApplication() {
    if (!rejectingId.value) return;
    try {
        const { data } = await axios.post(`/admin/validators/applications/${rejectingId.value}/reject`, {
            admin_notes: rejectReason.value,
        });
        if (data.success) {
            const app = applications.value.find(a => a.id === rejectingId.value);
            if (app) {
                app.status = 'rejected';
                app.admin_notes = rejectReason.value;
            }
            showRejectModal.value = false;
            rejectingId.value = null;
            rejectReason.value = '';
        }
    } catch (e) {
        alert(e.response?.data?.error?.message || t('admin.validators.errors.rejectionFailed'));
    }
}

async function proposeVote() {
    if (!/^0x[a-fA-F0-9]{40}$/.test(voteAddress.value.trim())) {
        voteResult.value = { errorKey: 'admin.validators.errors.invalidAddress' };
        return;
    }
    if (voteAction.value === 'add' && !/^0x[a-fA-F0-9]{96}$/.test(voteBls.value.trim())) {
        voteResult.value = { errorKey: 'admin.validators.errors.blsRequired' };
        return;
    }
    try {
        const { data } = await axios.post('/admin/validators/propose-vote', {
            address: voteAddress.value.trim(),
            action: voteAction.value,
            bls: voteAction.value === 'add' ? voteBls.value.trim() : null,
            node_id: voteNodeId.value.trim() || null,
        });
        voteResult.value = data.success ? data.data : (data.error?.message ? { error: data.error.message } : { errorKey: 'admin.validators.errors.failed' });
    } catch (e) {
        const errors = e.response?.data?.errors;
        const msg = errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message;
        // เก็บคีย์แทนข้อความของเราเอง — ผลค้างบนจอ สลับภาษาแล้วต้องเปลี่ยนตาม
        voteResult.value = msg ? { error: msg } : { errorKey: 'admin.validators.errors.failed' };
    }
}

useAdminPolling(refreshAll, 30000);
</script>

<template>
    <Head :title="t('admin.validators.pageTitle')" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.validators.header.title') }}</h1>
                    <p class="text-sm text-gray-400 mt-1">{{ t('admin.validators.header.subtitle') }} ·
                        <Link href="/admin/validators/kyc" class="text-primary-300 hover:underline">{{ t('admin.validators.header.kycLink') }}</Link>
                    </p>
                </div>
                <button @click="refreshAll" :disabled="isRefreshing"
                    class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                    {{ isRefreshing ? t('admin.validators.header.refreshing') : t('admin.validators.header.refresh') }}
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div v-for="card in [
                    { id: 'set', label: t('admin.validators.stats.inSet'), value: fmt(stats.active_validators || validators.length), color: 'text-yellow-400' },
                    { id: 'ft', label: t('admin.validators.stats.faultQuorum'), value: `${stats.fault_tolerance ?? '—'} / ${stats.quorum ?? '—'}`, color: 'text-cyan-400' },
                    { id: 'silent', label: t('admin.validators.stats.silent'), value: fmt(stats.silent_validators || 0), color: stats.silent_validators ? 'text-red-400' : 'text-emerald-400' },
                    { id: 'vote', label: t('admin.validators.stats.awaitingDrop'), value: `${stats.awaiting_vote || 0} / ${stats.drop_needed || 0}`, color: 'text-orange-400' },
                    { id: 'height', label: t('admin.validators.stats.blockHeight'), value: fmt(stats.block_height || 0), color: 'text-white' },
                ]" :key="card.id"
                   class="glass-card rounded-xl p-4 text-center">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ card.label }}</div>
                    <div :class="['text-xl font-black', card.color]">{{ card.value }}</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-dark-800/50 rounded-xl p-1 w-fit">
                <button v-for="tab in [
                    { id: 'applications', label: t('admin.validators.tabs.applications'), badge: pendingCount },
                    { id: 'validators', label: t('admin.validators.tabs.validators') },
                    { id: 'onboarding', label: t('admin.validators.tabs.onboarding'), badge: onboardingBadge },
                    { id: 'vote', label: t('admin.validators.tabs.vote') },
                ]" :key="tab.id" @click="activeTab = tab.id"
                   :class="['px-5 py-2.5 rounded-lg text-sm font-medium transition-all relative',
                       activeTab === tab.id ? 'bg-white/10 text-white' : 'text-gray-500 hover:text-gray-300']">
                    {{ tab.label }}
                    <span v-if="tab.badge"
                        class="absolute -top-1 -right-1 w-5 h-5 bg-orange-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
                        {{ tab.badge }}
                    </span>
                </button>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Applications                                            -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'applications'" class="space-y-4">
                <div class="glass-card rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/5">
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.apps.status') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.apps.wallet') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden sm:table-cell">{{ t('admin.validators.apps.tier') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">{{ t('admin.validators.apps.location') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden lg:table-cell">{{ t('admin.validators.apps.contact') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">{{ t('admin.validators.apps.date') }}</th>
                                    <th class="px-4 py-3 text-right text-[10px] text-gray-500 uppercase">{{ t('admin.validators.apps.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="app in applications" :key="app.id"
                                    class="border-b border-white/[0.03] hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border',
                                            statusConfig[app.status]?.bg || '']">
                                            <span :class="statusConfig[app.status]?.color || 'text-gray-400'">
                                                {{ statusConfig[app.status] ? t(statusConfig[app.status].labelKey) : app.status }}
                                            </span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-mono text-xs text-cyan-400">{{ shortAddr(app.wallet_address) }}</span>
                                    </td>
                                    <td class="px-4 py-3 hidden sm:table-cell">
                                        <span :class="['text-xs font-medium', tierConfig[app.tier]?.color || 'text-gray-400']">
                                            {{ tierConfig[app.tier]?.label || app.tier }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 hidden md:table-cell">
                                        <span class="text-xs text-gray-300">
                                            {{ flag(app.country_code) }} {{ app.country_name || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 hidden lg:table-cell">
                                        <div class="text-xs text-gray-400">
                                            <div v-if="app.contact_email">{{ app.contact_email }}</div>
                                            <div v-if="app.contact_telegram" class="text-gray-500">{{ app.contact_telegram }}</div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 hidden md:table-cell">
                                        {{ fmtDate(app.created_at) }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div v-if="app.status === 'pending'" class="flex gap-2 justify-end">
                                            <button @click="reviewingId = app.id"
                                                class="px-3 py-1.5 text-[10px] font-bold rounded-lg bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30 transition-all">
                                                {{ t('admin.validators.apps.approve') }}
                                            </button>
                                            <button @click="rejectingId = app.id; showRejectModal = true"
                                                class="px-3 py-1.5 text-[10px] font-bold rounded-lg bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30 transition-all">
                                                {{ t('admin.validators.apps.reject') }}
                                            </button>
                                        </div>
                                        <span v-else-if="app.admin_notes" class="text-[10px] text-gray-500 italic">
                                            {{ app.admin_notes }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="applications.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                                        {{ t('admin.validators.apps.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Approve confirmation -->
                <div v-if="reviewingId" class="glass-card rounded-xl p-5 space-y-4">
                    <h3 class="text-sm font-bold text-emerald-400">{{ t('admin.validators.approve.title', { id: reviewingId }) }}</h3>
                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">{{ t('admin.validators.approve.notesLabel') }}</label>
                        <textarea v-model="adminNotes" rows="2"
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2 focus:border-emerald-500/50 focus:outline-none resize-none"
                            :placeholder="t('admin.validators.approve.notesPlaceholder')"></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button @click="approveApplication(reviewingId)"
                            class="px-5 py-2 text-sm font-bold rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30 transition-all">
                            {{ t('admin.validators.approve.confirm') }}
                        </button>
                        <button @click="reviewingId = null; adminNotes = ''"
                            class="px-5 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 transition-all">
                            {{ t('admin.validators.approve.cancel') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Active Validators                                       -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'validators'" class="space-y-4">
                <div class="glass-card rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/5">
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.table.status') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.table.validator') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.table.lastSealed') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.table.lastSigned') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">{{ t('admin.validators.table.signRate') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">{{ t('admin.validators.table.sealed') }}</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">{{ t('admin.validators.table.missed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in validators" :key="v.address"
                                    class="border-b border-white/[0.03] hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <span :class="['inline-block w-2.5 h-2.5 rounded-full',
                                            v.silent ? 'bg-red-500' : (v.window_blocks ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-gray-500')]"
                                            :title="v.silent ? t('admin.validators.table.tipSilent') : (v.window_blocks ? t('admin.validators.table.tipNormal') : t('admin.validators.table.tipNoData'))"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a :href="'https://explorer.tpix.online/address/' + v.address" target="_blank" rel="noopener"
                                           class="font-mono text-xs text-cyan-400 hover:text-cyan-300">{{ v.address }}</a>
                                        <div v-if="v.staking_wallet" class="text-[10px] text-gray-500">{{ t('admin.validators.table.stake', { addr: shortAddr(v.staking_wallet) }) }}</div>
                                        <div v-else class="text-[10px] text-gray-600">{{ t('admin.validators.table.primary') }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-400">{{ v.last_sealed_block ? `#${fmt(v.last_sealed_block)}` : '—' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-400">{{ v.last_signed_block ? `#${fmt(v.last_signed_block)}` : '—' }}</td>
                                    <td class="px-4 py-3 text-xs font-medium" :class="v.sign_rate === null ? 'text-gray-500' : (v.sign_rate < 25 ? 'text-red-400' : 'text-white')">
                                        {{ v.sign_rate === null ? '—' : `${v.sign_rate}%` }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-white hidden md:table-cell">{{ fmt(v.blocks_sealed || 0) }}</td>
                                    <td class="px-4 py-3 text-xs hidden md:table-cell" :class="v.missed_turns ? 'text-orange-400' : 'text-gray-500'">{{ v.missed_turns || 0 }}</td>
                                </tr>
                                <tr v-if="validators.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                                        {{ t('admin.validators.table.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-4 text-xs text-gray-500 space-y-1">
                    <p>{{ t('admin.validators.notes.decodedFrom') }} <code class="text-cyan-400">extraData</code> {{ t('admin.validators.notes.perValidatorFrom') }} <code class="text-cyan-400">validators:seal-monitor</code> {{ t('admin.validators.notes.everyMinute') }}
                        <span v-if="stats.monitor_block"> — {{ t('admin.validators.notes.readUpTo', { block: fmt(stats.monitor_block) }) }}</span>
                        <span v-else class="text-yellow-400"> — {{ t('admin.validators.notes.neverRun') }}</span></p>
                    <p>{{ t('admin.validators.notes.signRateNote') }}</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Onboarding (ผูกกุญแจ / รอโหวต / ต้องถอด)                -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'onboarding'" class="space-y-4">
                <div v-for="o in onboarding" :key="o.validator_address" class="glass-card rounded-xl p-5 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs text-gray-400">{{ t('admin.validators.onboarding.stake') }} <span class="font-mono text-cyan-400">{{ o.staking_wallet }}</span></div>
                        <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border', stageConfig[o.stage]?.bg]">
                            <span :class="stageConfig[o.stage]?.color">{{ stageConfig[o.stage] ? t(stageConfig[o.stage].labelKey) : o.stage }}</span>
                        </span>
                    </div>
                    <div class="grid gap-1 text-[11px] text-gray-400 font-mono break-all">
                        <div><span class="text-gray-500 font-sans">{{ t('admin.validators.onboarding.validatorAddress') }}</span> {{ o.validator_address }}</div>
                        <div><span class="text-gray-500 font-sans">BLS:</span> {{ o.bls_public_key }}</div>
                        <div><span class="text-gray-500 font-sans">{{ t('admin.validators.onboarding.nodeId') }}</span> {{ o.node_id }}</div>
                    </div>
                    <div v-if="o.commands" class="space-y-2">
                        <div class="text-[11px] text-gray-500">{{ t('admin.validators.onboarding.runOnChain') }}</div>
                        <div class="flex gap-2">
                            <code class="flex-1 block bg-dark-900 rounded-lg p-3 text-cyan-400 font-mono text-[11px] break-all">{{ o.commands.script }}</code>
                            <button @click="copyText(o.commands.script)" class="px-3 text-[10px] rounded-lg bg-white/5 border border-white/10 text-gray-300">
                                {{ copied === o.commands.script ? t('admin.validators.copied') : t('admin.validators.copy') }}
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="onboarding.length === 0" class="glass-card rounded-xl p-10 text-center text-gray-500 text-sm">
                    {{ t('admin.validators.onboarding.empty') }}
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Propose Vote                                            -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'vote'" class="max-w-2xl space-y-6">
                <div class="glass-card rounded-xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">{{ t('admin.validators.vote.title') }}</h3>
                    <p class="text-xs text-gray-400">
                        {{ t('admin.validators.vote.desc', { needed: stats.votes_needed ?? '—', total: stats.active_validators ?? '—' }) }}
                    </p>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">{{ t('admin.validators.vote.candidate') }}</label>
                        <input v-model="voteAddress" type="text" placeholder="0x..."
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                    </div>

                    <div v-if="voteAction === 'add'">
                        <label class="text-xs text-gray-400 mb-1 block">{{ t('admin.validators.vote.blsLabel') }}</label>
                        <input v-model="voteBls" type="text" :placeholder="t('admin.validators.vote.blsPlaceholder')"
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        <label class="text-xs text-gray-400 mb-1 mt-3 block">{{ t('admin.validators.vote.nodeIdLabel') }}</label>
                        <input v-model="voteNodeId" type="text" placeholder="16Uiu2HA..."
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                    </div>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">{{ t('admin.validators.vote.action') }}</label>
                        <div class="flex gap-3">
                            <button @click="voteAction = 'add'"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium border transition-all',
                                    voteAction === 'add'
                                        ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                        : 'bg-white/5 text-gray-400 border-white/10']">
                                {{ t('admin.validators.vote.add') }}
                            </button>
                            <button @click="voteAction = 'remove'"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium border transition-all',
                                    voteAction === 'remove'
                                        ? 'bg-red-500/20 text-red-400 border-red-500/30'
                                        : 'bg-white/5 text-gray-400 border-white/10']">
                                {{ t('admin.validators.vote.remove') }}
                            </button>
                        </div>
                    </div>

                    <button @click="proposeVote"
                        class="px-6 py-3 rounded-xl text-sm font-bold bg-gradient-to-r from-yellow-500/80 to-amber-500/80 text-black hover:from-yellow-500 hover:to-amber-500 transition-all">
                        {{ t('admin.validators.vote.propose') }}
                    </button>

                    <!-- Result -->
                    <div v-if="voteResult" class="space-y-3">
                        <div v-if="voteResult.error || voteResult.errorKey" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                            {{ voteResult.errorKey ? t(voteResult.errorKey) : voteResult.error }}
                        </div>
                        <div v-else class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 space-y-3">
                            <div class="text-emerald-400 font-bold text-sm">{{ t('admin.validators.vote.saved') }}</div>
                            <pre class="bg-dark-900 rounded-lg p-3 text-cyan-400 font-mono text-[11px] whitespace-pre-wrap break-all">{{ (voteResult.cli_instructions || []).join('\n') }}</pre>
                            <button @click="copyText(voteResult.cli_command)" class="px-3 py-1.5 text-[10px] rounded-lg bg-white/5 border border-white/10 text-gray-300">
                                {{ copied === voteResult.cli_command ? t('admin.validators.copied') : t('admin.validators.vote.copyScript') }}
                            </button>
                            <ul v-if="voteResult.warnings?.length" class="text-[11px] text-yellow-200 list-disc list-inside space-y-1">
                                <li v-for="w in voteResult.warnings" :key="w">{{ w }}</li>
                            </ul>
                            <div class="text-[10px] text-gray-500">
                                {{ voteResult.votes_needed
                                    ? t('admin.validators.vote.votesNeeded', { needed: voteResult.votes_needed, total: voteResult.total_validators || '?' })
                                    : t('admin.validators.vote.votesNeededMajority', { total: voteResult.total_validators || '?' }) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- IBFT2 Info -->
                <div class="glass-card rounded-xl p-6 space-y-3">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">{{ t('admin.validators.ibft.title') }}</h3>
                    <div class="text-xs text-gray-400 space-y-2">
                        <p><span class="text-white font-medium">{{ t('admin.validators.ibft.howLabel') }}</span> {{ t('admin.validators.ibft.howText') }}</p>
                        <p><span class="text-white font-medium">{{ t('admin.validators.ibft.stepsLabel') }}</span></p>
                        <ol class="list-decimal list-inside space-y-1 ml-2">
                            <li>{{ t('admin.validators.ibft.steps.s1') }}</li>
                            <li>{{ t('admin.validators.ibft.steps.s2') }}</li>
                            <li>{{ t('admin.validators.ibft.steps.s3') }}</li>
                            <li>{{ t('admin.validators.ibft.steps.s4') }}</li>
                            <li>{{ t('admin.validators.ibft.steps.s5') }}</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Reject Modal -->
            <Teleport to="body">
                <div v-if="showRejectModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div class="glass-card rounded-2xl p-6 max-w-md w-full space-y-4">
                        <h3 class="text-lg font-bold text-red-400">{{ t('admin.validators.reject.title') }}</h3>
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">{{ t('admin.validators.reject.reason') }}</label>
                            <textarea v-model="rejectReason" rows="3"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2 focus:border-red-500/50 focus:outline-none resize-none"
                                :placeholder="t('admin.validators.reject.placeholder')"></textarea>
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button @click="showRejectModal = false; rejectingId = null; rejectReason = ''"
                                class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 transition-all">
                                {{ t('admin.validators.reject.cancel') }}
                            </button>
                            <button @click="rejectApplication" :disabled="!rejectReason.trim()"
                                class="px-4 py-2 text-sm font-bold rounded-xl bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30 transition-all disabled:opacity-50">
                                {{ t('admin.validators.reject.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AdminLayout>
</template>
