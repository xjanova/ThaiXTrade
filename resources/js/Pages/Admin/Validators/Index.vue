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
    awaiting_vote:  { label: 'รอโหวตเข้า',             color: 'text-yellow-300', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    sealing:        { label: 'ปิดบล็อกอยู่',             color: 'text-emerald-300', bg: 'bg-emerald-500/15 border-emerald-500/30' },
    drop_needed:    { label: 'ต้องโหวตถอด (stake หมดแล้ว)', color: 'text-red-300', bg: 'bg-red-500/15 border-red-500/30' },
    stake_inactive: { label: 'stake ไม่ active',          color: 'text-gray-400', bg: 'bg-white/5 border-white/10' },
    unknown:        { label: 'อ่านชุด validator ไม่ได้',   color: 'text-gray-400', bg: 'bg-white/5 border-white/10' },
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
    pending:  { label: 'Pending',  color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30' },
    approved: { label: 'Approved', color: 'text-emerald-400', bg: 'bg-emerald-500/15 border-emerald-500/30' },
    rejected: { label: 'Rejected', color: 'text-red-400', bg: 'bg-red-500/15 border-red-500/30' },
    active:   { label: 'Active',   color: 'text-cyan-400', bg: 'bg-cyan-500/15 border-cyan-500/30' },
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
        alert(e.response?.data?.error?.message || 'Approval failed');
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
        alert(e.response?.data?.error?.message || 'Rejection failed');
    }
}

async function proposeVote() {
    if (!/^0x[a-fA-F0-9]{40}$/.test(voteAddress.value.trim())) {
        voteResult.value = { error: 'ที่อยู่ validator ต้องเป็น 0x + 40 ตัวอักษร' };
        return;
    }
    if (voteAction.value === 'add' && !/^0x[a-fA-F0-9]{96}$/.test(voteBls.value.trim())) {
        voteResult.value = { error: 'เพิ่ม validator ต้องมี BLS public key (0x + 96 ตัวอักษร) — genesis ของเชนนี้เป็นแบบ bls' };
        return;
    }
    try {
        const { data } = await axios.post('/admin/validators/propose-vote', {
            address: voteAddress.value.trim(),
            action: voteAction.value,
            bls: voteAction.value === 'add' ? voteBls.value.trim() : null,
            node_id: voteNodeId.value.trim() || null,
        });
        voteResult.value = data.success ? data.data : { error: data.error?.message || 'ไม่สำเร็จ' };
    } catch (e) {
        const errors = e.response?.data?.errors;
        voteResult.value = { error: errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || 'ไม่สำเร็จ') };
    }
}

useAdminPolling(refreshAll, 30000);
</script>

<template>
    <Head title="Validator Management" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Validator Management</h1>
                    <p class="text-sm text-gray-400 mt-1">ชุด validator จริง · การปิดบล็อกรายตัว · ผูกกุญแจ/โหวต ·
                        <Link href="/admin/validators/kyc" class="text-primary-300 hover:underline">ตรวจ KYC ผู้สมัคร →</Link>
                    </p>
                </div>
                <button @click="refreshAll" :disabled="isRefreshing"
                    class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                    {{ isRefreshing ? 'Refreshing...' : 'Refresh' }}
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div v-for="card in [
                    { label: 'Validators ในชุด', value: fmt(stats.active_validators || validators.length), color: 'text-yellow-400' },
                    { label: 'ทนดับได้ / ต้องเห็นพ้อง', value: `${stats.fault_tolerance ?? '—'} / ${stats.quorum ?? '—'}`, color: 'text-cyan-400' },
                    { label: 'เงียบ (ไม่เซ็น)', value: fmt(stats.silent_validators || 0), color: stats.silent_validators ? 'text-red-400' : 'text-emerald-400' },
                    { label: 'รอโหวต / ต้องถอด', value: `${stats.awaiting_vote || 0} / ${stats.drop_needed || 0}`, color: 'text-orange-400' },
                    { label: 'Block Height', value: fmt(stats.block_height || 0), color: 'text-white' },
                ]" :key="card.label"
                   class="glass-card rounded-xl p-4 text-center">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">{{ card.label }}</div>
                    <div :class="['text-xl font-black', card.color]">{{ card.value }}</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-dark-800/50 rounded-xl p-1 w-fit">
                <button v-for="tab in [
                    { id: 'applications', label: 'Applications', badge: pendingCount },
                    { id: 'validators', label: 'Active Validators' },
                    { id: 'onboarding', label: 'ผูกกุญแจ / รอโหวต', badge: onboardingBadge },
                    { id: 'vote', label: 'Propose Vote' },
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
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Wallet</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden sm:table-cell">Tier</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">Location</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden lg:table-cell">Contact</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">Date</th>
                                    <th class="px-4 py-3 text-right text-[10px] text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="app in applications" :key="app.id"
                                    class="border-b border-white/[0.03] hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border',
                                            statusConfig[app.status]?.bg || '']">
                                            <span :class="statusConfig[app.status]?.color || 'text-gray-400'">
                                                {{ statusConfig[app.status]?.label || app.status }}
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
                                        {{ new Date(app.created_at).toLocaleDateString() }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div v-if="app.status === 'pending'" class="flex gap-2 justify-end">
                                            <button @click="reviewingId = app.id"
                                                class="px-3 py-1.5 text-[10px] font-bold rounded-lg bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30 transition-all">
                                                Approve
                                            </button>
                                            <button @click="rejectingId = app.id; showRejectModal = true"
                                                class="px-3 py-1.5 text-[10px] font-bold rounded-lg bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30 transition-all">
                                                Reject
                                            </button>
                                        </div>
                                        <span v-else-if="app.admin_notes" class="text-[10px] text-gray-500 italic">
                                            {{ app.admin_notes }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="applications.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                                        No applications yet
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Approve confirmation -->
                <div v-if="reviewingId" class="glass-card rounded-xl p-5 space-y-4">
                    <h3 class="text-sm font-bold text-emerald-400">Approve Application #{{ reviewingId }}</h3>
                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Admin Notes (optional)</label>
                        <textarea v-model="adminNotes" rows="2"
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2 focus:border-emerald-500/50 focus:outline-none resize-none"
                            placeholder="Notes for the applicant..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button @click="approveApplication(reviewingId)"
                            class="px-5 py-2 text-sm font-bold rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30 transition-all">
                            Confirm Approve
                        </button>
                        <button @click="reviewingId = null; adminNotes = ''"
                            class="px-5 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 transition-all">
                            Cancel
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
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">สถานะ</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Validator</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">เสนอล่าสุด</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">เซ็นล่าสุด</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">เซ็น % (10 นาที)</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">เสนอ</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase hidden md:table-cell">พลาดคิว</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in validators" :key="v.address"
                                    class="border-b border-white/[0.03] hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <span :class="['inline-block w-2.5 h-2.5 rounded-full',
                                            v.silent ? 'bg-red-500' : (v.window_blocks ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-gray-500')]"
                                            :title="v.silent ? 'ไม่เซ็นรับรองบล็อกเกินเกณฑ์' : (v.window_blocks ? 'ปกติ' : 'ยังไม่มีข้อมูลจากตัวเฝ้าดู')"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a :href="'https://explorer.tpix.online/address/' + v.address" target="_blank" rel="noopener"
                                           class="font-mono text-xs text-cyan-400 hover:text-cyan-300">{{ v.address }}</a>
                                        <div v-if="v.staking_wallet" class="text-[10px] text-gray-500">stake: {{ shortAddr(v.staking_wallet) }}</div>
                                        <div v-else class="text-[10px] text-gray-600">validator หลัก</div>
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
                                        อ่านชุด validator จากเชนไม่ได้ตอนนี้
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-4 text-xs text-gray-500 space-y-1">
                    <p>ชุด validator ถอดจาก <code class="text-cyan-400">extraData</code> ของบล็อกล่าสุด · ตัวเลขรายตัวมาจาก <code class="text-cyan-400">validators:seal-monitor</code> (ทุกนาที)
                        <span v-if="stats.monitor_block"> — อ่านถึงบล็อก #{{ fmt(stats.monitor_block) }}</span>
                        <span v-else class="text-yellow-400"> — ยังไม่เคยรัน</span></p>
                    <p>เซ็น % ต่ำกว่า 100 เป็นปกติ: แต่ละบล็อกต้องการแค่ครบ quorum · ตัวที่ดับจะเป็น 0% และขึ้นคาดแดงเมื่อเงียบเกิน 5 นาที</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Onboarding (ผูกกุญแจ / รอโหวต / ต้องถอด)                -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'onboarding'" class="space-y-4">
                <div v-for="o in onboarding" :key="o.validator_address" class="glass-card rounded-xl p-5 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs text-gray-400">stake: <span class="font-mono text-cyan-400">{{ o.staking_wallet }}</span></div>
                        <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border', stageConfig[o.stage]?.bg]">
                            <span :class="stageConfig[o.stage]?.color">{{ stageConfig[o.stage]?.label || o.stage }}</span>
                        </span>
                    </div>
                    <div class="grid gap-1 text-[11px] text-gray-400 font-mono break-all">
                        <div><span class="text-gray-500 font-sans">ที่อยู่ validator:</span> {{ o.validator_address }}</div>
                        <div><span class="text-gray-500 font-sans">BLS:</span> {{ o.bls_public_key }}</div>
                        <div><span class="text-gray-500 font-sans">node ID:</span> {{ o.node_id }}</div>
                    </div>
                    <div v-if="o.commands" class="space-y-2">
                        <div class="text-[11px] text-gray-500">รันบนเครื่องเชน (ซ้อมก่อน แล้วเติม --yes เมื่อผลตรวจผ่าน):</div>
                        <div class="flex gap-2">
                            <code class="flex-1 block bg-dark-900 rounded-lg p-3 text-cyan-400 font-mono text-[11px] break-all">{{ o.commands.script }}</code>
                            <button @click="copyText(o.commands.script)" class="px-3 text-[10px] rounded-lg bg-white/5 border border-white/10 text-gray-300">
                                {{ copied === o.commands.script ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="onboarding.length === 0" class="glass-card rounded-xl p-10 text-center text-gray-500 text-sm">
                    ยังไม่มีผู้ผูกกุญแจ validator — ผู้ stake ชั้น Validator ผูกจากโปรแกรม TPIX Master Node (แท็บ Validator)
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Propose Vote                                            -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'vote'" class="max-w-2xl space-y-6">
                <div class="glass-card rounded-xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">Propose Validator Vote</h3>
                    <p class="text-xs text-gray-400">
                        ต้องได้เสียงเกินครึ่งของชุดปัจจุบัน (ตอนนี้ {{ stats.votes_needed ?? '—' }} จาก {{ stats.active_validators ?? '—' }}) ·
                        หน้านี้บันทึกความตั้งใจและสร้างคำสั่งที่ถูกต้อง — การโหวตจริงต้องรันบนเครื่องเชน (gRPC เปิดเฉพาะในเครื่องนั้น)
                    </p>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Candidate Address</label>
                        <input v-model="voteAddress" type="text" placeholder="0x..."
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                    </div>

                    <div v-if="voteAction === 'add'">
                        <label class="text-xs text-gray-400 mb-1 block">BLS Public Key (ต้องมีตอนเพิ่ม)</label>
                        <input v-model="voteBls" type="text" placeholder="0x... (96 ตัวอักษร)"
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        <label class="text-xs text-gray-400 mb-1 mt-3 block">Node ID (ไม่บังคับ — ใส่แล้วสคริปต์ตรวจว่าเป็นเพียร์ก่อนโหวต)</label>
                        <input v-model="voteNodeId" type="text" placeholder="16Uiu2HA..."
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                    </div>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Action</label>
                        <div class="flex gap-3">
                            <button @click="voteAction = 'add'"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium border transition-all',
                                    voteAction === 'add'
                                        ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                        : 'bg-white/5 text-gray-400 border-white/10']">
                                Add Validator
                            </button>
                            <button @click="voteAction = 'remove'"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium border transition-all',
                                    voteAction === 'remove'
                                        ? 'bg-red-500/20 text-red-400 border-red-500/30'
                                        : 'bg-white/5 text-gray-400 border-white/10']">
                                Remove Validator
                            </button>
                        </div>
                    </div>

                    <button @click="proposeVote"
                        class="px-6 py-3 rounded-xl text-sm font-bold bg-gradient-to-r from-yellow-500/80 to-amber-500/80 text-black hover:from-yellow-500 hover:to-amber-500 transition-all">
                        Propose Vote
                    </button>

                    <!-- Result -->
                    <div v-if="voteResult" class="space-y-3">
                        <div v-if="voteResult.error" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                            {{ voteResult.error }}
                        </div>
                        <div v-else class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 space-y-3">
                            <div class="text-emerald-400 font-bold text-sm">บันทึกแล้ว — รันบนเครื่องเชน</div>
                            <pre class="bg-dark-900 rounded-lg p-3 text-cyan-400 font-mono text-[11px] whitespace-pre-wrap break-all">{{ (voteResult.cli_instructions || []).join('\n') }}</pre>
                            <button @click="copyText(voteResult.cli_command)" class="px-3 py-1.5 text-[10px] rounded-lg bg-white/5 border border-white/10 text-gray-300">
                                {{ copied === voteResult.cli_command ? 'คัดลอกแล้ว' : 'คัดลอกคำสั่งสคริปต์' }}
                            </button>
                            <ul v-if="voteResult.warnings?.length" class="text-[11px] text-yellow-200 list-disc list-inside space-y-1">
                                <li v-for="w in voteResult.warnings" :key="w">{{ w }}</li>
                            </ul>
                            <div class="text-[10px] text-gray-500">
                                ต้องได้ {{ voteResult.votes_needed || 'เกินครึ่ง' }} เสียงจาก {{ voteResult.total_validators || '?' }} validator
                            </div>
                        </div>
                    </div>
                </div>

                <!-- IBFT2 Info -->
                <div class="glass-card rounded-xl p-6 space-y-3">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">IBFT2 Validator Voting</h3>
                    <div class="text-xs text-gray-400 space-y-2">
                        <p><span class="text-white font-medium">How it works:</span> IBFT2 uses a permissioned validator set. New validators are added (or removed) by a majority vote of the existing validator set.</p>
                        <p><span class="text-white font-medium">Steps:</span></p>
                        <ol class="list-decimal list-inside space-y-1 ml-2">
                            <li>ผู้สมัครผ่าน KYC (หน้า "ตรวจ KYC ผู้สมัคร") แล้ว stake ชั้น Validator</li>
                            <li>ผู้สมัครรันโหนด ซิงก์ถึงบล็อกล่าสุด และผูกกุญแจ validator จากโปรแกรม (แท็บ "ผูกกุญแจ / รอโหวต")</li>
                            <li>เจ้าของรัน validator-vote.sh บนเครื่องเชน — โหวตบน validator ทุกตัวในเครื่อง</li>
                            <li>ครบเสียงเมื่อ validator เดิมแต่ละตัวได้คิวเสนอบล็อก (ไม่กี่วินาที) แล้วตัวใหม่เริ่มปิดบล็อกทันที</li>
                            <li>ถอน stake / ถูก slash / ดับนาน → โหวตถอดด้วยสคริปต์เดียวกัน (drop) — ไม่มีอะไรถอดให้เอง</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Reject Modal -->
            <Teleport to="body">
                <div v-if="showRejectModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div class="glass-card rounded-2xl p-6 max-w-md w-full space-y-4">
                        <h3 class="text-lg font-bold text-red-400">Reject Application</h3>
                        <div>
                            <label class="text-xs text-gray-400 mb-1 block">Reason (required)</label>
                            <textarea v-model="rejectReason" rows="3"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2 focus:border-red-500/50 focus:outline-none resize-none"
                                placeholder="Explain why the application is rejected..."></textarea>
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button @click="showRejectModal = false; rejectingId = null; rejectReason = ''"
                                class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 transition-all">
                                Cancel
                            </button>
                            <button @click="rejectApplication" :disabled="!rejectReason.trim()"
                                class="px-4 py-2 text-sm font-bold rounded-xl bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30 transition-all disabled:opacity-50">
                                Confirm Reject
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AdminLayout>
</template>
