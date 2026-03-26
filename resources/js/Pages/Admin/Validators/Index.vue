<script setup>
/**
 * TPIX TRADE — Admin Validator Management Dashboard
 * Review applications, monitor active validators, trigger votes
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';

const props = defineProps({
    validators: { type: Array, default: () => [] },
    applications: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});

const activeTab = ref('applications'); // applications | validators | vote
const validators = ref(props.validators || []);
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
const voteAction = ref('add'); // add | remove
const voteResult = ref(null);

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
    if (!/^0x[a-fA-F0-9]{40}$/.test(voteAddress.value)) {
        voteResult.value = { error: 'Invalid address format' };
        return;
    }
    try {
        const { data } = await axios.post('/admin/validators/propose-vote', {
            address: voteAddress.value,
            action: voteAction.value,
        });
        voteResult.value = data.success ? data.data : { error: data.error?.message || 'Failed' };
    } catch (e) {
        voteResult.value = { error: e.response?.data?.error?.message || 'Vote proposal failed' };
    }
}

let pollInterval;
onMounted(() => { pollInterval = setInterval(refreshAll, 30000); });
onUnmounted(() => clearInterval(pollInterval));
</script>

<template>
    <Head title="Validator Management" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Validator Management</h1>
                    <p class="text-sm text-gray-400 mt-1">Review applications, monitor validators, propose votes</p>
                </div>
                <button @click="refreshAll" :disabled="isRefreshing"
                    class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                    {{ isRefreshing ? 'Refreshing...' : 'Refresh' }}
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div v-for="card in [
                    { label: 'Active Validators', value: fmt(stats.active_validators || validators.length), color: 'text-yellow-400' },
                    { label: 'Pending Applications', value: pendingCount, color: 'text-orange-400' },
                    { label: 'Approved', value: approvedCount, color: 'text-emerald-400' },
                    { label: 'Total Nodes', value: fmt(stats.total_nodes || 0), color: 'text-cyan-400' },
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
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Tier</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Location</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Contact</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Date</th>
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
                                    <td class="px-4 py-3">
                                        <span :class="['text-xs font-medium', tierConfig[app.tier]?.color || 'text-gray-400']">
                                            {{ tierConfig[app.tier]?.label || app.tier }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-xs text-gray-300">
                                            {{ flag(app.country_code) }} {{ app.country_name || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-xs text-gray-400">
                                            <div v-if="app.contact_email">{{ app.contact_email }}</div>
                                            <div v-if="app.contact_telegram" class="text-gray-500">{{ app.contact_telegram }}</div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">
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
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Address</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Last Sealed</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase">Blocks Sealed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in validators" :key="v.address"
                                    class="border-b border-white/[0.03] hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <span :class="['inline-block w-2.5 h-2.5 rounded-full',
                                            v.active ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)] animate-pulse' : 'bg-red-500']"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a :href="'https://explorer.tpix.online/address/' + v.address" target="_blank"
                                           class="font-mono text-xs text-cyan-400 hover:text-cyan-300">
                                            {{ v.address }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-400">
                                        {{ v.last_sealed_block ? `Block #${fmt(v.last_sealed_block)}` : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-white font-medium">
                                        {{ fmt(v.blocks_sealed || 0) }}
                                    </td>
                                </tr>
                                <tr v-if="validators.length === 0">
                                    <td colspan="4" class="px-4 py-12 text-center text-gray-500">
                                        No active validators detected from chain
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-4 text-xs text-gray-500">
                    Validators are extracted from the IBFT2 <code class="text-cyan-400">extraData</code> field in block headers.
                    New validators must be voted in by majority of the existing validator set.
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Propose Vote                                            -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'vote'" class="max-w-2xl space-y-6">
                <div class="glass-card rounded-xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">Propose Validator Vote</h3>
                    <p class="text-xs text-gray-400">
                        IBFT2 requires >50% of existing validators to vote before a new validator can join.
                        This will record the vote intent — you'll need to execute the actual vote on each validator node.
                    </p>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Candidate Address</label>
                        <input v-model="voteAddress" type="text" placeholder="0x..."
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
                            <div class="text-emerald-400 font-bold text-sm">Vote Proposed Successfully</div>
                            <div class="text-xs text-gray-400">
                                <p class="mb-2">Run this command on each validator node to cast the vote:</p>
                                <code class="block bg-dark-900 rounded-lg p-3 text-cyan-400 font-mono text-[11px] break-all">
                                    {{ voteResult.cli_command || `polygon-edge ibft propose --addr ${voteAddress} --vote ${voteAction === 'add' ? 'auth' : 'drop'} --grpc-address localhost:10000` }}
                                </code>
                            </div>
                            <div class="text-[10px] text-gray-500">
                                Need {{ voteResult.votes_needed || 'majority' }} of {{ voteResult.total_validators || '?' }} validators to vote.
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
                            <li>Candidate runs a full node and syncs the chain</li>
                            <li>Admin proposes the vote through this dashboard</li>
                            <li>Each existing validator casts their vote via CLI</li>
                            <li>When >50% vote in favor, the candidate becomes a validator</li>
                            <li>The new validator starts sealing blocks in the next epoch</li>
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
