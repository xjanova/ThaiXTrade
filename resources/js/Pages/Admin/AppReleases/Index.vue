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
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    releases: { type: Array, default: () => [] },
    error: { type: String, default: null },
    hasToken: { type: Boolean, default: false },
    activeTag: { type: String, default: null },
    activeTags: { type: Object, default: () => ({}) },
    activeVersions: { type: Object, default: () => ({}) },
});

// เช็คว่า release นี้เป็น active ของ type ไหน
const isActiveRelease = (release) => {
    if (release.tag === props.activeTags?.trade && release.source === 'trade' && release.has_apk) return 'TPIX TRADE';
    if (release.tag === props.activeTags?.wallet && (release.has_wallet_apk || release.type === 'wallet')) return 'Wallet';
    if (release.tag === props.activeTags?.masternode && (release.has_exe || release.type === 'desktop')) return 'Master Node';
    return null;
};

// Filter & Pagination
const filter = ref('all');
const perPage = ref(15);
const currentPage = ref(1);

const filteredReleases = computed(() => {
    if (filter.value === 'all') return props.releases;
    if (filter.value === 'mobile') return props.releases.filter(r => r.source === 'trade' && r.has_apk);
    if (filter.value === 'wallet') return props.releases.filter(r => r.type === 'wallet' || r.has_wallet_apk);
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

const setActive = (tag, app) => {
    router.post('/admin/app-releases/set-active', { tag, app }, { preserveScroll: true });
};

// เลิกล็อกรุ่น กลับไปใช้ตัวล่าสุดที่ไฟล์ครบเสมอ
// เดิมหน้านี้ล็อกได้อย่างเดียว ปลดไม่ได้ ล็อกครั้งเดียวแล้วค้างตลอดไป
const setAuto = (app, label) => {
    if (!confirm(t('admin.appReleases.confirmAuto', { label }))) return;
    router.post('/admin/app-releases/set-active', { tag: 'auto', app }, { preserveScroll: true });
};

// หา asset types ที่ release มีสำหรับแสดง set-active buttons
const getAvailableApps = (release) => {
    const apps = [];
    if (release.source === 'trade' && release.has_apk) {
        apps.push({ key: 'trade', label: 'TPIX TRADE', color: 'bg-blue-500/20 text-blue-400 hover:bg-blue-500/30' });
    }
    if (release.has_wallet_apk) {
        apps.push({ key: 'wallet', label: 'Wallet', color: 'bg-purple-500/20 text-purple-400 hover:bg-purple-500/30' });
    }
    if (release.has_exe) {
        apps.push({ key: 'masternode', label: 'Master Node', color: 'bg-amber-500/20 text-amber-400 hover:bg-amber-500/30' });
    }
    return apps;
};

const formatSize = (mb) => (!mb ? '-' : `${mb} MB`);

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
};

// 5 releases ล่าสุดที่มี APK หรือ EXE (ทุก project รวมกัน)
const latestDownloadable = computed(() => {
    return props.releases
        .filter(r => r.has_apk || r.has_wallet_apk || r.has_exe)
        .slice(0, 5);
});

const filterCounts = computed(() => ({
    all: props.releases.length,
    mobile: props.releases.filter(r => r.source === 'trade' && r.has_apk).length,
    wallet: props.releases.filter(r => r.type === 'wallet' || r.has_wallet_apk).length,
    desktop: props.releases.filter(r => r.type === 'desktop' || r.has_exe).length,
    web: props.releases.filter(r => r.source === 'trade').length,
    chain: props.releases.filter(r => r.source === 'chain').length,
    apk: props.releases.filter(r => r.has_apk || r.has_wallet_apk).length,
}));

const typeBadge = (release) => {
    const badges = {
        wallet: { label: 'admin.appReleases.type.wallet', color: 'bg-purple-500/20 text-purple-400' },
        mobile: { label: 'admin.appReleases.type.mobile', color: 'bg-blue-500/20 text-blue-400' },
        desktop: { label: 'admin.appReleases.type.desktop', color: 'bg-amber-500/20 text-amber-400' },
        chain: { label: 'admin.appReleases.type.chain', color: 'bg-cyan-500/20 text-cyan-400' },
        web: { label: 'admin.appReleases.type.web', color: 'bg-green-500/20 text-green-400' },
    };
    return badges[release.type] || badges.web;
};

// แท็บกรอง — computed เพื่อให้ป้ายเปลี่ยนตามภาษาทันที
const filterTabs = computed(() => [
    { key: 'all', label: t('common.all') },
    { key: 'mobile', label: 'TPIX TRADE' },
    { key: 'wallet', label: 'TPIX Wallet' },
    { key: 'desktop', label: 'Master Node' },
    { key: 'chain', label: t('admin.appReleases.type.chain') },
    { key: 'apk', label: t('admin.appReleases.withApk') },
]);
</script>

<template>
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.appReleases.title') }}</h1>
                    <p class="text-dark-400 text-sm mt-1">{{ t('admin.appReleases.subtitle') }}</p>
                </div>
                <button @click="refresh" class="btn-primary flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ t('admin.appReleases.refresh') }}
                </button>
            </div>

            <!-- Error Alert -->
            <div v-if="error" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <p class="text-red-400 text-sm font-medium">{{ t('admin.appReleases.apiError') }}</p>
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
                        <p class="text-yellow-400 text-sm font-medium">{{ t('admin.appReleases.tokenMissing.title') }}</p>
                        <p class="text-yellow-400/80 text-sm mt-1">{{ t('admin.appReleases.tokenMissing.add') }} <code class="bg-yellow-500/20 px-1 rounded">GITHUB_TOKEN=ghp_xxx</code> {{ t('admin.appReleases.tokenMissing.in') }} <code class="bg-yellow-500/20 px-1 rounded">.env</code> {{ t('admin.appReleases.tokenMissing.onServer') }}</p>
                    </div>
                </div>
            </div>

            <!-- Active Versions Banner -->
            <div class="p-4 rounded-xl bg-trading-green/10 border border-trading-green/20">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-trading-green flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-trading-green text-sm font-semibold">{{ t('admin.appReleases.activeVersions') }}</span>
                </div>
                <div class="flex flex-wrap gap-3 ml-7">
                    <div class="flex items-center gap-1.5">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-500/20 text-blue-400">TPIX TRADE</span>
                        <span class="text-white text-xs font-mono">{{ activeTags?.trade || '—' }}</span>
                        <span v-if="activeVersions?.trade" class="text-dark-500 text-xs">(v{{ activeVersions.trade }})</span>
                        <span v-if="!activeTags?.trade" class="text-trading-green text-[10px]">{{ t('admin.appReleases.auto') }}</span>
                        <button v-else type="button" @click="setAuto('trade', 'TPIX TRADE')"
                                class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-dark-300 hover:bg-white/10 hover:text-white"
                                :title="t('admin.appReleases.unlockTitle')">{{ t('admin.appReleases.unlock') }}</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-500/20 text-purple-400">Wallet</span>
                        <span class="text-white text-xs font-mono">{{ activeTags?.wallet || '—' }}</span>
                        <span v-if="activeVersions?.wallet" class="text-dark-500 text-xs">(v{{ activeVersions.wallet }})</span>
                        <span v-if="!activeTags?.wallet" class="text-trading-green text-[10px]">{{ t('admin.appReleases.auto') }}</span>
                        <button v-else type="button" @click="setAuto('wallet', 'Wallet')"
                                class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-dark-300 hover:bg-white/10 hover:text-white"
                                :title="t('admin.appReleases.unlockTitle')">{{ t('admin.appReleases.unlock') }}</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-400">Master Node</span>
                        <span class="text-white text-xs font-mono">{{ activeTags?.masternode || '—' }}</span>
                        <span v-if="activeVersions?.masternode" class="text-dark-500 text-xs">(v{{ activeVersions.masternode }})</span>
                        <span v-if="!activeTags?.masternode" class="text-trading-green text-[10px]">{{ t('admin.appReleases.auto') }}</span>
                        <button v-else type="button" @click="setAuto('masternode', 'Master Node')"
                                class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-dark-300 hover:bg-white/10 hover:text-white"
                                :title="t('admin.appReleases.unlockTitle')">{{ t('admin.appReleases.unlock') }}</button>
                    </div>
                </div>
            </div>

            <!-- Latest APK/EXE Releases (5 ล่าสุด) -->
            <div v-if="latestDownloadable.length" class="glass-card p-5 border border-primary-500/10">
                <h3 class="text-sm font-semibold text-primary-400 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ t('admin.appReleases.latestDownloadable') }}
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div v-for="r in latestDownloadable" :key="r.id"
                        :class="['p-3 rounded-xl border transition-all', isActiveRelease(r) ? 'bg-trading-green/10 border-trading-green/30' : 'bg-white/5 border-white/5 hover:border-white/20']">
                        <div class="flex items-center gap-2 mb-2">
                            <span :class="['px-1.5 py-0.5 rounded text-[10px] font-bold', typeBadge(r).color]">{{ t(typeBadge(r).label) }}</span>
                            <span v-if="isActiveRelease(r)" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-trading-green/20 text-trading-green">{{ t('admin.appReleases.activeFor', { app: isActiveRelease(r) }) }}</span>
                        </div>
                        <p class="text-white font-semibold text-sm">{{ r.name }}</p>
                        <p class="text-dark-500 text-xs font-mono">{{ r.tag }}</p>
                        <div class="mt-2 space-y-0.5">
                            <p v-if="r.apk_name" class="text-trading-green text-xs truncate">{{ r.apk_name }} ({{ formatSize(r.apk_size) }})</p>
                            <p v-if="r.wallet_apk_name && r.wallet_apk_name !== r.apk_name" class="text-purple-400 text-xs truncate">{{ r.wallet_apk_name }}</p>
                            <p v-if="r.exe_name" class="text-amber-400 text-xs truncate">{{ r.exe_name }}</p>
                        </div>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-dark-500 text-[10px]">{{ r.repo }}</span>
                            <div class="flex gap-1">
                                <button v-for="app in getAvailableApps(r).filter(a => {
                                    if (a.key === 'trade') return r.tag !== activeTags?.trade;
                                    if (a.key === 'wallet') return r.tag !== activeTags?.wallet;
                                    if (a.key === 'masternode') return r.tag !== activeTags?.masternode;
                                    return true;
                                })" :key="app.key"
                                    @click="setActive(r.tag, app.key)"
                                    :class="['px-1.5 py-0.5 rounded text-[9px] font-medium transition', app.color]"
                                    :title="t('admin.appReleases.setActiveTitle', { app: app.label })">
                                    {{ app.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">{{ t('admin.appReleases.stats.total') }}</p>
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
                    <p class="text-dark-400 text-sm">{{ t('admin.appReleases.withApk') }}</p>
                    <p class="text-2xl font-bold text-trading-green mt-1">{{ filterCounts.apk }}</p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4">
                    <p class="text-dark-400 text-sm">{{ t('admin.appReleases.stats.downloads') }}</p>
                    <p class="text-2xl font-bold text-accent-400 mt-1">{{ releases.reduce((sum, r) => sum + (r.apk_downloads || 0), 0).toLocaleString(locale === 'th' ? 'th-TH' : 'en-US') }}</p>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex items-center gap-2 flex-wrap">
                <button
                    v-for="f in filterTabs"
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
                    <p class="text-dark-400">{{ t('admin.appReleases.empty.title') }}</p>
                    <p class="text-dark-500 text-sm mt-1">{{ t('admin.appReleases.empty.hint') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">{{ t('admin.appReleases.table.release') }}</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase hidden sm:table-cell">{{ t('admin.appReleases.table.type') }}</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase hidden lg:table-cell">APK</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase hidden md:table-cell">{{ t('admin.appReleases.stats.downloads') }}</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase hidden md:table-cell">{{ t('admin.appReleases.table.published') }}</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-dark-400 uppercase">{{ t('common.status') }}</th>
                            <th class="text-center px-6 py-3 text-xs font-medium text-dark-400 uppercase">{{ t('admin.appReleases.table.action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="release in paginatedReleases" :key="release.id"
                            :class="['hover:bg-white/2 transition-colors', isActiveRelease(release) ? 'bg-trading-green/5 border-l-2 border-trading-green' : '']">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div>
                                        <p class="text-white font-medium">{{ release.name }}</p>
                                        <p class="text-dark-500 text-xs font-mono mt-0.5">{{ release.tag }}</p>
                                    </div>
                                    <span v-if="isActiveRelease(release)" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-trading-green/20 text-trading-green">
                                        {{ t('admin.appReleases.active') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 hidden sm:table-cell">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', typeBadge(release).color]">
                                        {{ t(typeBadge(release).label) }}
                                    </span>
                                    <span class="text-[10px] text-dark-500 font-mono">{{ release.repo }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 hidden lg:table-cell">
                                <div v-if="release.has_apk || release.has_wallet_apk || release.has_exe" class="text-sm space-y-0.5">
                                    <p v-if="release.apk_name" class="text-trading-green">{{ release.apk_name }} <span class="text-dark-500 text-xs">{{ formatSize(release.apk_size) }}</span></p>
                                    <p v-if="release.wallet_apk_name && release.wallet_apk_name !== release.apk_name" class="text-purple-400">{{ release.wallet_apk_name }}</p>
                                    <p v-if="release.exe_name" class="text-amber-400">{{ release.exe_name }}</p>
                                </div>
                                <span v-else class="text-dark-500 text-sm">-</span>
                            </td>
                            <td class="px-6 py-4 hidden md:table-cell">
                                <span class="text-white text-sm">{{ release.apk_downloads?.toLocaleString(locale === 'th' ? 'th-TH' : 'en-US') || '-' }}</span>
                            </td>
                            <td class="px-6 py-4 hidden md:table-cell">
                                <span class="text-dark-300 text-sm">{{ formatDate(release.published_at) }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span v-if="release.is_draft" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/20 text-yellow-400">{{ t('admin.appReleases.status.draft') }}</span>
                                <span v-else-if="release.is_prerelease" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-orange-500/20 text-orange-400">{{ t('admin.appReleases.status.prerelease') }}</span>
                                <span v-else class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-500/20 text-green-400">{{ t('admin.appReleases.status.published') }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-1 flex-wrap">
                                    <template v-if="getAvailableApps(release).length > 0">
                                        <button v-for="app in getAvailableApps(release)" :key="app.key"
                                            @click="setActive(release.tag, app.key)"
                                            :disabled="(app.key === 'trade' && release.tag === activeTags?.trade) || (app.key === 'wallet' && release.tag === activeTags?.wallet) || (app.key === 'masternode' && release.tag === activeTags?.masternode)"
                                            :class="[
                                                'px-2 py-1 rounded-lg text-[10px] font-medium transition-colors',
                                                (app.key === 'trade' && release.tag === activeTags?.trade) || (app.key === 'wallet' && release.tag === activeTags?.wallet) || (app.key === 'masternode' && release.tag === activeTags?.masternode)
                                                    ? 'bg-trading-green/20 text-trading-green cursor-default'
                                                    : app.color
                                            ]">
                                            {{ (app.key === 'trade' && release.tag === activeTags?.trade) || (app.key === 'wallet' && release.tag === activeTags?.wallet) || (app.key === 'masternode' && release.tag === activeTags?.masternode) ? '✓ ' : '' }}{{ app.label }}
                                        </button>
                                    </template>
                                    <span v-else class="text-dark-600 text-xs">{{ t('admin.appReleases.noAssets') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="flex items-center justify-between px-6 py-4 border-t border-white/5">
                    <p class="text-dark-500 text-sm">
                        {{ t('admin.appReleases.pagination', { from: (currentPage - 1) * perPage + 1, to: Math.min(currentPage * perPage, filteredReleases.length), total: filteredReleases.length }) }}
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
                        <p>{{ t('admin.appReleases.info.p1a') }} <strong class="text-white">{{ t('admin.appReleases.info.setActive') }}</strong> {{ t('admin.appReleases.info.p1b') }} <strong class="text-white">{{ t('admin.appReleases.info.downloadPage') }}</strong> {{ t('admin.appReleases.info.p1c') }} <strong class="text-white">{{ t('admin.appReleases.info.inAppUpdates') }}</strong></p>
                        <p class="mt-1">{{ t('admin.appReleases.info.p2') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
