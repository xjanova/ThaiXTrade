<script setup>
/**
 * TPIX TRADE - Admin Token Factory Management
 * จัดการ Token ที่สร้างจาก Factory — อนุมัติ, ปฏิเสธ, verify, list/delist
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    tokens: Object,
    stats: Object,
    currentStatus: String,
    currentSearch: String,
    factoryReady: Object,
    factoryConfig: Object,
});

const flash = computed(() => usePage().props.flash || {});
const showRejectModal = ref(false);
const rejectTokenId = ref(null);
const rejectReason = ref('');
const searchQuery = ref(props.currentSearch || '');

const statusFilters = [
    { value: null, label: 'All', count: props.stats?.total },
    { value: 'pending', label: 'Pending', count: props.stats?.pending },
    { value: 'deploying', label: 'Deploying', count: props.stats?.deploying },
    { value: 'deployed', label: 'Deployed', count: props.stats?.deployed },
    { value: 'failed', label: 'Failed', count: props.stats?.failed },
    { value: 'rejected', label: 'Rejected', count: props.stats?.rejected },
];

function filterByStatus(status) {
    const params = {};
    if (status) params.status = status;
    if (searchQuery.value) params.search = searchQuery.value;
    router.get('/admin/token-factory', params, { preserveState: true });
}

function handleSearch() {
    const params = {};
    if (props.currentStatus) params.status = props.currentStatus;
    if (searchQuery.value) params.search = searchQuery.value;
    router.get('/admin/token-factory', params, { preserveState: true });
}

function approveToken(id) {
    if (!confirm('Approve this token for deployment?')) return;
    router.post(`/admin/token-factory/${id}/approve`);
}

function openRejectModal(id) {
    rejectTokenId.value = id;
    rejectReason.value = '';
    showRejectModal.value = true;
}

function submitReject() {
    if (!rejectReason.value) return;
    router.post(`/admin/token-factory/${rejectTokenId.value}/reject`, {
        reason: rejectReason.value,
    });
    showRejectModal.value = false;
}

function retryDeploy(id) {
    if (!confirm('Retry deployment for this token?')) return;
    router.post(`/admin/token-factory/${id}/retry`);
}

function toggleVerified(id) {
    router.patch(`/admin/token-factory/${id}/verify`);
}

function toggleListed(id) {
    router.patch(`/admin/token-factory/${id}/list`);
}

function getStatusBadge(status) {
    const map = {
        pending: 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/20',
        deploying: 'bg-blue-500/10 text-blue-400 border border-blue-500/20',
        deployed: 'bg-green-500/10 text-green-400 border border-green-500/20',
        failed: 'bg-red-500/10 text-red-400 border border-red-500/20',
        rejected: 'bg-red-500/10 text-red-400 border border-red-500/20',
    };
    return map[status] || 'bg-gray-500/10 text-gray-400';
}

function formatDate(d) {
    return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function shortAddr(addr) {
    if (!addr) return '-';
    return addr.slice(0, 8) + '...' + addr.slice(-4);
}

// Pagination
const pagination = computed(() => props.tokens || {});
function goToPage(page) {
    const params = { page };
    if (props.currentStatus) params.status = props.currentStatus;
    if (props.currentSearch) params.search = props.currentSearch;
    router.get('/admin/token-factory', params, { preserveState: true });
}
</script>

<template>
    <AdminLayout title="Token Factory">
        <!-- Flash Messages -->
        <div v-if="flash.success" class="mb-4 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-sm">
            {{ flash.success }}
        </div>
        <div v-if="flash.error" class="mb-4 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
            {{ flash.error }}
        </div>

        <!-- Factory Readiness Warning -->
        <div v-if="factoryReady && !factoryReady.ready" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 flex items-start gap-3">
            <svg class="w-6 h-6 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <h3 class="text-sm font-semibold text-red-400">Token Factory Not Ready</h3>
                <p class="text-xs text-red-400/70 mt-1">
                    <span v-for="issue in factoryReady.issues" :key="issue">{{ issue }}. </span>
                    Go to <Link href="/admin/settings" class="underline hover:text-red-300">Settings</Link> to configure.
                </p>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-white">{{ stats?.total || 0 }}</p>
                <p class="text-xs text-gray-400">Total</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-yellow-400">{{ stats?.pending || 0 }}</p>
                <p class="text-xs text-gray-400">Pending</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-blue-400">{{ stats?.deploying || 0 }}</p>
                <p class="text-xs text-gray-400">Deploying</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-green-400">{{ stats?.deployed || 0 }}</p>
                <p class="text-xs text-gray-400">Deployed</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-red-400">{{ stats?.failed || 0 }}</p>
                <p class="text-xs text-gray-400">Failed</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-red-300">{{ stats?.rejected || 0 }}</p>
                <p class="text-xs text-gray-400">Rejected</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-blue-300">{{ stats?.verified || 0 }}</p>
                <p class="text-xs text-gray-400">Verified</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-purple-400">{{ stats?.unique_creators || 0 }}</p>
                <p class="text-xs text-gray-400">Creators</p>
            </div>
        </div>

        <!-- Filters + Search -->
        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex gap-2 flex-wrap">
                <button
                    v-for="f in statusFilters"
                    :key="f.value"
                    @click="filterByStatus(f.value)"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all flex items-center gap-1.5"
                    :class="currentStatus === f.value ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
                >
                    {{ f.label }}
                    <span v-if="f.count" class="bg-white/10 px-1.5 py-0.5 rounded text-[10px]">{{ f.count }}</span>
                </button>
            </div>
            <div class="flex-1 max-w-xs ml-auto">
                <form @submit.prevent="handleSearch" class="flex gap-2">
                    <input v-model="searchQuery" type="text" class="trading-input flex-1 text-sm" placeholder="Search name, symbol, address..." />
                    <button type="submit" class="px-3 py-2 rounded-lg bg-primary-500/10 text-primary-400 hover:bg-primary-500/20 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </button>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">Token</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Creator</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Supply</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Type</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Status</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Listed</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Date</th>
                            <th class="text-right p-4 text-gray-400 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="token in tokens?.data || []"
                            :key="token.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="p-4">
                                <div class="flex items-center gap-2">
                                    <div v-if="token.logo_url" class="w-8 h-8 rounded-full overflow-hidden bg-dark-800">
                                        <img :src="token.logo_url" :alt="token.symbol" class="w-full h-full object-cover" />
                                    </div>
                                    <div v-else class="w-8 h-8 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                        <span class="text-xs font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white flex items-center gap-1">
                                            {{ token.name }}
                                            <svg v-if="token.is_verified" class="w-3.5 h-3.5 text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        </p>
                                        <p class="text-xs text-gray-400">{{ token.symbol }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4">
                                <span class="text-xs text-gray-400 font-mono">{{ shortAddr(token.creator_address) }}</span>
                            </td>
                            <td class="p-4 text-white font-mono text-xs">{{ Number(token.total_supply).toLocaleString() }}</td>
                            <td class="p-4">
                                <span class="text-xs text-gray-300 capitalize">{{ token.token_type?.replace('_', ' ') }}</span>
                                <span class="block text-[10px] text-gray-500">{{ token.token_category }}</span>
                            </td>
                            <td class="p-4">
                                <span :class="['text-xs px-2.5 py-1 rounded-full font-medium inline-flex items-center gap-1', getStatusBadge(token.status)]">
                                    <span v-if="token.status === 'deploying'" class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                                    {{ token.status }}
                                </span>
                                <p v-if="token.reject_reason" class="text-[10px] text-red-400 mt-1 max-w-[150px] truncate" :title="token.reject_reason">{{ token.reject_reason }}</p>
                                <p v-if="token.status === 'failed' && token.metadata?.error" class="text-[10px] text-red-400 mt-1 max-w-[150px] truncate" :title="token.metadata.error">{{ token.metadata.error }}</p>
                            </td>
                            <td class="p-4">
                                <span v-if="token.status === 'deployed'" :class="['text-xs', token.is_listed ? 'text-green-400' : 'text-gray-500']">
                                    {{ token.is_listed ? 'Yes' : 'No' }}
                                </span>
                                <span v-else class="text-xs text-gray-600">-</span>
                            </td>
                            <td class="p-4 text-gray-400 text-xs">{{ formatDate(token.created_at) }}</td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    <!-- Pending actions -->
                                    <button
                                        v-if="token.status === 'pending'"
                                        @click="approveToken(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-green-500/10 text-green-400 text-xs font-medium hover:bg-green-500/20 transition-colors"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        v-if="token.status === 'pending'"
                                        @click="openRejectModal(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        Reject
                                    </button>
                                    <!-- Failed actions -->
                                    <button
                                        v-if="token.status === 'failed'"
                                        @click="retryDeploy(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-yellow-500/10 text-yellow-400 text-xs font-medium hover:bg-yellow-500/20 transition-colors"
                                    >
                                        Retry
                                    </button>
                                    <button
                                        v-if="token.status === 'failed'"
                                        @click="openRejectModal(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        Reject
                                    </button>
                                    <!-- Deployed actions -->
                                    <button
                                        v-if="token.status === 'deployed'"
                                        @click="toggleVerified(token.id)"
                                        class="px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                                        :class="token.is_verified ? 'bg-blue-500/10 text-blue-400 hover:bg-blue-500/20' : 'bg-white/5 text-gray-400 hover:bg-white/10'"
                                    >
                                        {{ token.is_verified ? 'Unverify' : 'Verify' }}
                                    </button>
                                    <button
                                        v-if="token.status === 'deployed'"
                                        @click="toggleListed(token.id)"
                                        class="px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                                        :class="token.is_listed ? 'bg-green-500/10 text-green-400 hover:bg-green-500/20' : 'bg-white/5 text-gray-400 hover:bg-white/10'"
                                    >
                                        {{ token.is_listed ? 'Delist' : 'List' }}
                                    </button>
                                    <!-- Contract link -->
                                    <a
                                        v-if="token.contract_address"
                                        :href="`https://explorer.tpix.online/address/${token.contract_address}`"
                                        target="_blank"
                                        class="px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 text-xs font-medium hover:bg-white/10 transition-colors"
                                    >
                                        Explorer
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!tokens?.data?.length">
                            <td colspan="8" class="p-8 text-center text-gray-400">
                                {{ currentSearch ? 'No tokens match your search.' : 'No tokens found.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="pagination.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-white/5">
                <p class="text-xs text-gray-400">
                    Showing {{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}
                </p>
                <div class="flex gap-1">
                    <button
                        v-for="page in pagination.last_page"
                        :key="page"
                        @click="goToPage(page)"
                        class="w-8 h-8 rounded-lg text-xs font-medium transition-colors"
                        :class="page === pagination.current_page ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
                    >
                        {{ page }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showRejectModal = false"></div>
                    <div class="relative glass-dark p-6 rounded-2xl border border-white/10 max-w-md w-full">
                        <h3 class="text-lg font-bold text-white mb-4">Reject Token</h3>
                        <div class="mb-4">
                            <label class="block text-sm text-gray-300 mb-1.5">Reason <span class="text-red-400">*</span></label>
                            <textarea v-model="rejectReason" rows="3" class="trading-input w-full resize-none" placeholder="Explain why this token is rejected..."></textarea>
                        </div>
                        <div class="flex gap-3">
                            <button @click="showRejectModal = false" class="flex-1 btn-secondary py-2">Cancel</button>
                            <button @click="submitReject" :disabled="!rejectReason.trim()" class="flex-1 py-2 rounded-lg bg-red-500/20 text-red-400 font-medium hover:bg-red-500/30 disabled:opacity-50 transition-colors">Reject</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>
