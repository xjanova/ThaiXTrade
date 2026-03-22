<script setup>
/**
 * TPIX TRADE - Admin App Releases Page
 * แสดงรายการ releases จาก GitHub + สถานะ APK
 * Developed by Xman Studio
 */

import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    releases: {
        type: Array,
        default: () => [],
    },
    error: {
        type: String,
        default: null,
    },
    hasToken: {
        type: Boolean,
        default: false,
    },
});

const refresh = () => {
    router.post('/admin/app-releases/refresh', {}, { preserveScroll: true });
};

const formatSize = (mb) => {
    if (!mb) return '-';
    return `${mb} MB`;
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
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
                <button
                    @click="refresh"
                    class="btn-primary flex items-center gap-2"
                >
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
                        <p class="text-yellow-400/80 text-sm mt-1">เพิ่ม <code class="bg-yellow-500/20 px-1 rounded">GITHUB_TOKEN=ghp_xxx</code> ใน <code class="bg-yellow-500/20 px-1 rounded">.env</code> บน server เพื่อเข้าถึง private repo</p>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">Total Releases</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ releases.length }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">Mobile Releases</p>
                    <p class="text-2xl font-bold text-primary-400 mt-1">{{ releases.filter(r => r.is_mobile).length }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">With APK</p>
                    <p class="text-2xl font-bold text-trading-green mt-1">{{ releases.filter(r => r.has_apk).length }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">Total Downloads</p>
                    <p class="text-2xl font-bold text-accent-400 mt-1">{{ releases.reduce((sum, r) => sum + (r.apk_downloads || 0), 0).toLocaleString() }}</p>
                </div>
            </div>

            <!-- Releases Table -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                <div v-if="releases.length === 0" class="p-12 text-center">
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
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="release in releases" :key="release.id" class="hover:bg-white/2 transition-colors">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-white font-medium">{{ release.name }}</p>
                                    <p class="text-dark-500 text-xs font-mono mt-0.5">{{ release.tag }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span v-if="release.is_mobile" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-primary-500/20 text-primary-400">
                                    Mobile
                                </span>
                                <span v-else class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-dark-600 text-dark-300">
                                    Web
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div v-if="release.has_apk" class="text-sm">
                                    <p class="text-trading-green">{{ release.apk_name }}</p>
                                    <p class="text-dark-500 text-xs">{{ formatSize(release.apk_size) }}</p>
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
                                <span v-if="release.is_draft" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/20 text-yellow-400">
                                    Draft
                                </span>
                                <span v-else-if="release.is_prerelease" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-orange-500/20 text-orange-400">
                                    Pre-release
                                </span>
                                <span v-else class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                                    Published
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Info Note -->
            <div class="bg-primary-500/5 border border-primary-500/20 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm text-dark-300">
                        <p>Releases are synced from <strong class="text-white">GitHub Releases</strong>. Mobile releases must be tagged with <code class="text-primary-400">mobile-v*</code> (e.g., <code class="text-primary-400">mobile-v1.0.145</code>) and include an APK asset.</p>
                        <p class="mt-1">Data is cached for 5 minutes. Click <strong class="text-white">Refresh</strong> to get the latest.</p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
