<script setup>
/**
 * TPIX TRADE - Admin Token Factory Management
 * จัดการ Token ที่สร้างจาก Factory — อนุมัติ, ปฏิเสธ, verify
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    tokens: Object,
    stats: Object,
    currentStatus: String,
});

const showRejectModal = ref(false);
const rejectTokenId = ref(null);
const rejectReason = ref('');

const statusFilters = [
    { value: null, label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'deploying', label: 'Deploying' },
    { value: 'deployed', label: 'Deployed' },
    { value: 'failed', label: 'Failed' },
    { value: 'rejected', label: 'Rejected' },
];

function filterByStatus(status) {
    router.get('/admin/token-factory', status ? { status } : {}, { preserveState: true });
}

function approveToken(id) {
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
    router.post(`/admin/token-factory/${id}/retry`);
}

function toggleVerified(id) {
    router.patch(`/admin/token-factory/${id}/verify`);
}

function getStatusBadge(status) {
    const map = {
        pending: 'badge-warning',
        deploying: 'badge-info',
        deployed: 'badge-success',
        failed: 'badge-danger',
        rejected: 'badge-danger',
    };
    return map[status] || 'badge-info';
}

function formatDate(d) {
    return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>

<template>
    <AdminLayout title="Token Factory">
        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div v-for="(val, key) in stats" :key="key" class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-white">{{ val }}</p>
                <p class="text-xs text-gray-400 capitalize">{{ key.replace(/_/g, ' ') }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex gap-2 mb-6">
            <button
                v-for="f in statusFilters"
                :key="f.value"
                @click="filterByStatus(f.value)"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
                :class="currentStatus === f.value ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
            >
                {{ f.label }}
            </button>
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
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                        <span class="text-xs font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">{{ token.name }}</p>
                                        <p class="text-xs text-gray-400">{{ token.symbol }}</p>
                                    </div>
                                    <svg v-if="token.is_verified" class="w-4 h-4 text-blue-400 ml-1" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </div>
                            </td>
                            <td class="p-4">
                                <span class="text-xs text-gray-400 font-mono">{{ token.creator_address?.slice(0, 8) }}...{{ token.creator_address?.slice(-4) }}</span>
                            </td>
                            <td class="p-4 text-white font-mono">{{ Number(token.total_supply).toLocaleString() }}</td>
                            <td class="p-4 text-gray-300">{{ token.token_type }}</td>
                            <td class="p-4">
                                <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', getStatusBadge(token.status)]">
                                    {{ token.status }}
                                </span>
                            </td>
                            <td class="p-4 text-gray-400 text-xs">{{ formatDate(token.created_at) }}</td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        v-if="token.status === 'pending'"
                                        @click="approveToken(token.id)"
                                        class="px-3 py-1.5 rounded-lg bg-green-500/10 text-green-400 text-xs font-medium hover:bg-green-500/20 transition-colors"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        v-if="token.status === 'pending'"
                                        @click="openRejectModal(token.id)"
                                        class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        Reject
                                    </button>
                                    <button
                                        v-if="token.status === 'failed'"
                                        @click="retryDeploy(token.id)"
                                        class="px-3 py-1.5 rounded-lg bg-yellow-500/10 text-yellow-400 text-xs font-medium hover:bg-yellow-500/20 transition-colors"
                                    >
                                        Retry
                                    </button>
                                    <button
                                        v-if="token.status === 'deployed'"
                                        @click="toggleVerified(token.id)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                                        :class="token.is_verified ? 'bg-blue-500/10 text-blue-400 hover:bg-blue-500/20' : 'bg-white/5 text-gray-400 hover:bg-white/10'"
                                    >
                                        {{ token.is_verified ? 'Unverify' : 'Verify' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!tokens?.data?.length">
                            <td colspan="7" class="p-8 text-center text-gray-400">No tokens found.</td>
                        </tr>
                    </tbody>
                </table>
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
                            <label class="block text-sm text-gray-300 mb-1.5">Reason</label>
                            <textarea v-model="rejectReason" rows="3" class="trading-input w-full resize-none" placeholder="Enter rejection reason..."></textarea>
                        </div>
                        <div class="flex gap-3">
                            <button @click="showRejectModal = false" class="flex-1 btn-secondary py-2">Cancel</button>
                            <button @click="submitReject" :disabled="!rejectReason" class="flex-1 py-2 rounded-lg bg-red-500/20 text-red-400 font-medium hover:bg-red-500/30 disabled:opacity-50">Reject</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>
