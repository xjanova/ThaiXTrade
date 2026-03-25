<script setup>
/**
 * TPIX TRADE - Admin App Releases Page
 * แสดงรายการ releases จาก GitHub + เลือก active release
 * พร้อม filter + pagination
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    releases: { type: Array, default: () => [] },
    error: { type: String, default: null },
    hasToken: { type: Boolean, default: false },
    activeTag: { type: String, default: null },
});

// Filter & Pagination
const filter = ref('all');
const perPage = ref(15);
const currentPage = ref(1);

const filteredReleases = computed(() => {
    if (filter.value === 'all') return props.releases;
    if (filter.value === 'mobile') return props.releases.filter(r => r.type === 'mobile');
    if (filter.value === 'wallet') return props.releases.filter(r => r.type === 'wallet');
    if (filter.value === 'desktop') return props.releases.filter(r => r.type === 'desktop' || r.has_exe);
    if (filter.value === 'web') return props.releases.filter(r => r.source === 'trade');
    if (filter.value === 'chain') return props.releases.filter(r => r.source === 'chain');
    if (filter.value === 'apk') return props.releases.filter(r => r.has_apk || r.has_wallet_apk);
    return props.releases;
});

const totalPages = computed(() => Math.ceil(filteredReleases.value.length / perPage.value));

const paginatedReleases = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredReleases.value.slice(start, start + perPage.value);
});

const setFilter = (f) => {
    filter.value = f;
    currentPage.value = 1;
};

const refresh = () => {
    router.post('/admin/app-releases/refresh', {}, { preserveScroll: true });
};

const setActive = (tag) => {
    router.post('/admin/app-releases/set-active', { tag }, { preserveScroll: true });
};

const formatSize = (mb) => (!mb ? '-' : `${mb} MB`);

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('th-TH', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
};

const filterCounts = computed(() => ({
    all: props.releases.length,
    mobile: props.releases.filter(r => r.type === 'mobile').length,
    wallet: props.releases.filter(r => r.type === 'wallet').length,
    desktop: props.releases.filter(r => r.type === 'desktop' || r.has_exe).length,
    web: props.releases.filter(r => r.source === 'trade').length,
    chain: props.releases.filter(r => r.source === 'chain').length,
    apk: props.releases.filter(r => r.has_apk || r.has_wallet_apk).length,
}));

const typeBadge = (release) => {
    const badges = {
        wallet: { label: 'Wallet', color: 'bg-purple-500/20 text-purple-400' },
        mobile: { label: 'Mobile', color: 'bg-blue-500/20 text-blue-400' },
        desktop: { label: 'Desktop', color: 'bg-amber-500/20 text-amber-400' },
        chain: { label: 'Chain', color: 'bg-cyan-500/20 text-cyan-400' },
        web: { label: 'Web', color: 'bg-green-500/20 text-green-400' },
    };
    return badges[release.type] || badges.web;
};
</script>

<template>
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">App Releases</h1>
                    <p class="text-dark-400 text-sm mt-1">Manage mobile app releases from GitHub</p>
                </div>
                <button @click="refresh" class="btn-primary flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Refresh from GitHub
                </button>
            </div>

            <!-- Error Alert -->
            <div v-if="error" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <p class="text-red-400 text-sm font-medium">GitHub API Error</p>
                        <p class="text-red-400/80 text-sm mt-1">{{ error }}</p>
                    </div>
                </div>
            </div>

            <!-- Token Warning -->
            <div v-if="!hasToken && !error" class="p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/20">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-yellow-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <p class="text-yellow-400 text-sm font-medium">GITHUB_TOKEN ไม่ได้ตั้งค่า</p>
                        <p class="text-yellow-400/80 text-sm mt-1">เพิ่ม <code class="bg-yellow-500/20 px-1 rounded">GITHUB_TOKEN=ghp_xxx</code> ใน <code class="bg-yellow-500/20 px-1 rounded">.env</code> บน server</p>
                    </div>
                </div>
            </div>

            <!-- Active Release Banner -->
            <div v-if="activeTag" class="p-4 rounded-xl bg-trading-green/10 border border-trading-green/20">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-trading-green flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <p class="text-trading-green text-sm">Active release for download &amp; in-app update: <strong class="text-white">{{ activeTag }}</strong></p>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">Total</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ releases.length }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-blue-500/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">TPIX TRADE</p>
                    <p class="text-2xl font-bold text-blue-400 mt-1">{{ filterCounts.mobile }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-purple-500/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">TPIX Wallet</p>
                    <p class="text-2xl font-bold text-purple-400 mt-1">{{ filterCounts.wallet }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-amber-500/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">Master Node</p>
                    <p class="text-2xl font-bold text-amber-400 mt-1">{{ filterCounts.desktop }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-green-500/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">With APK</p>
                    <p class="text-2xl font-bold text-trading-green mt-1">{{ filterCounts.apk }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">Downloads</p>
                    <p class="text-2xl font-bold text-accent-400 mt-1">{{ releases.reduce((sum, r) => sum + (r.apk_downloads || 0), 0).toLocaleString() }}</p>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex items-center gap-2 flex-wrap">
                <button
                    v-for="f in [
                        { key: 'all', label: 'All' },
                        { key: 'mobile', label: 'TPIX TRADE' },
                        { key: 'wallet', label: 'TPIX Wallet' },
                        { key: 'desktop', label: 'Master Node' },
                        { key: 'chain', label: 'Chain' },
                        { key: 'apk', label: 'With APK' },
                    ]"
                    :key="f.key"
                    @click="setFilter(f.key)"
                    :class="[
                        'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                        filter === f.key
                            ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                            : 'bg-white/5 text-dark-400 border border-white/5 hover:bg-white/10 hover:text-white'
                    ]"
                >
                    {{ f.label }}
                    <span class="ml-1.5 text-xs opacity-60">({{ filterCounts[f.key] }})</span>
                </button>
            </div>

            <!-- Releases Table -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                <div v-if="filteredReleases.length === 0" class="p-12 text-center">
                    <svg class="w-16 h-16 text-dark-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    <p class="text-dark-400">No releases found</p>
                    <p class="text-dark-500 text-sm mt-1">Check GitHub token configuration or click Refresh</p>
                </div>

                <table v-else class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">Release</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">Type</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">APK</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">Downloads</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">Published</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">Status</th>
                            <th class="text-center px-6 py-3 text-xs font-medium text-dark-400 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="release in paginatedReleases" :key="release.id"
                            :class="['hover:bg-white/2 transition-colors', release.tag === activeTag ? 'bg-trading-green/5 border-l-2 border-trading-green' : '']">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div>
                                        <p class="text-white font-medium">{{ release.name }}</p>
                                        <p class="text-dark-500 text-xs font-mono mt-0.5">{{ release.tag }}</p>
                                    </div>
                                    <span v-if="release.tag === activeTag" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-trading-green/20 text-trading-green">
                                        Active
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', typeBadge(release).color]">
                                        {{ typeBadge(release).label }}
                                    </span>
                                    <span class="text-[10px] text-dark-500 font-mono">{{ release.repo }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div v-if="release.has_apk || release.has_wallet_apk || release.has_exe" class="text-sm space-y-0.5">
                                    <p v-if="release.apk_name" class="text-trading-green">{{ release.apk_name }} <span class="text-dark-500 text-xs">{{ formatSize(release.apk_size) }}</span></p>
                                    <p v-if="release.wallet_apk_name && release.wallet_apk_name !== release.apk_name" class="text-purple-400">{{ release.wallet_apk_name }}</p>
                                    <p v-if="release.exe_name" class="text-amber-400">{{ release.exe_name }}</p>
                                </div>
                                <span v-else class="text-dark-500 text-sm">-</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-white text-sm">{{ release.apk_downloads?.toLocaleString() || '-' }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-dark-300 text-sm">{{ formatDate(release.published_at) }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span v-if="release.is_draft" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/20 text-yellow-400">Draft</span>
                                <span v-else-if="release.is_prerelease" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-orange-500/20 text-orange-400">Pre-release</span>
                                <span v-else class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-500/20 text-green-400">Published</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button
                                    v-if="(release.has_apk || release.has_wallet_apk || release.has_exe) && release.tag !== activeTag"
                                    @click="setActive(release.tag)"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-primary-500/20 text-primary-400 hover:bg-primary-500/30 transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Set Active
                                </button>
                                <span v-else-if="release.tag === activeTag" class="text-trading-green text-xs font-medium">Active</span>
                                <span v-else class="text-dark-600 text-xs">No APK</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="flex items-center justify-between px-6 py-4 border-t border-white/5">
                    <p class="text-dark-500 text-sm">
                        Showing {{ (currentPage - 1) * perPage + 1 }}–{{ Math.min(currentPage * perPage, filteredReleases.length) }}
                        of {{ filteredReleases.length }} releases
                    </p>
                    <div class="flex items-center gap-1">
                        <button
                            @click="currentPage = Math.max(1, currentPage - 1)"
                            :disabled="currentPage === 1"
                            :class="['px-3 py-1.5 rounded-lg text-sm transition-colors', currentPage === 1 ? 'text-dark-600 cursor-not-allowed' : 'text-dark-300 hover:bg-white/10 hover:text-white']"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                        </button>
                        <button
                            v-for="page in totalPages"
                            :key="page"
                            @click="currentPage = page"
                            :class="[
                                'w-8 h-8 rounded-lg text-sm font-medium transition-colors',
                                currentPage === page
                                    ? 'bg-primary-500/20 text-primary-400'
                                    : 'text-dark-400 hover:bg-white/10 hover:text-white'
                            ]"
                        >
                            {{ page }}
                        </button>
                        <button
                            @click="currentPage = Math.min(totalPages, currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            :class="['px-3 py-1.5 rounded-lg text-sm transition-colors', currentPage === totalPages ? 'text-dark-600 cursor-not-allowed' : 'text-dark-300 hover:bg-white/10 hover:text-white']"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info Note -->
            <div class="bg-primary-500/5 border border-primary-500/20 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm text-dark-300">
                        <p>Click <strong class="text-white">Set Active</strong> to choose which release appears on the <strong class="text-white">Download page</strong> and is used for <strong class="text-white">in-app updates</strong>.</p>
                        <p class="mt-1">If no release is set as active, the latest mobile release with APK is used automatically.</p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
