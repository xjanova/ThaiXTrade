<script setup>
/**
 * TPIX TRADE - Download Mobile App Page
 * ดาวน์โหลด APK — แอดมินเลือก release ที่ใช้งานได้
 * Developed by Xman Studio
 */

import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    latestRelease: { type: Object, default: null },
});

const release = ref(props.latestRelease);
const isLoading = ref(!props.latestRelease);
const error = ref('');

onMounted(async () => {
    if (release.value) return;

    try {
        const res = await fetch('/api/v1/app/latest');
        if (!res.ok) throw new Error('No releases found');
        const json = await res.json();

        if (!json.success || !json.data) throw new Error('No releases found');

        const data = json.data;
        release.value = {
            version: `v${data.version}`,
            name: data.name,
            publishedAt: data.published_at,
            body: data.notes,
            apkUrl: data.download_url,
            apkSize: data.file_size ? Math.round(data.file_size / 1024 / 1024) : null,
            apkName: data.file_name,
        };
    } catch {
        error.value = t('download.noRelease');
    } finally {
        isLoading.value = false;
    }
});

function formatDate(iso) {
    if (!iso) return '';
    const loc = locale.value === 'th' ? 'th-TH' : 'en-US';
    return new Date(iso).toLocaleDateString(loc, {
        year: 'numeric', month: 'long', day: 'numeric',
    });
}
</script>

<template>
    <Head :title="t('download.downloadApk')" />
    <AppLayout>
        <div class="min-h-screen py-12 px-4">
            <div class="max-w-2xl mx-auto">

                <!-- Header -->
                <div class="text-center mb-10">
                    <div class="w-24 h-24 mx-auto rounded-3xl bg-gradient-to-br from-primary-500/20 to-accent-500/20 flex items-center justify-center mb-6 shadow-glow">
                        <img src="/logo.png" class="w-16 h-16 rounded-2xl" alt="TPIX TRADE" />
                    </div>
                    <h1 class="text-3xl font-bold text-white mb-2">{{ t('download.title') }}</h1>
                    <p class="text-dark-400">{{ t('download.subtitle') }}</p>
                </div>

                <!-- Loading -->
                <div v-if="isLoading" class="text-center py-12">
                    <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                    <p class="text-dark-400 text-sm">{{ t('download.loading') }}</p>
                </div>

                <!-- No Release -->
                <div v-else-if="error" class="glass-card p-8 text-center">
                    <svg class="w-12 h-12 text-dark-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-dark-400 mb-4">{{ error }}</p>
                </div>

                <!-- Release Card -->
                <div v-else-if="release" class="space-y-6">

                    <!-- APK Download Card -->
                    <div class="glass-card p-6 border border-primary-500/20">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-xl bg-trading-green/20 flex items-center justify-center">
                                <svg class="w-6 h-6 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h2 class="text-lg font-bold text-white">{{ release.name || 'TPIX TRADE' }}</h2>
                                <p class="text-dark-400 text-sm">{{ release.version }} &middot; {{ formatDate(release.publishedAt) }}</p>
                            </div>
                        </div>

                        <!-- Download Button -->
                        <a
                            v-if="release.apkUrl"
                            :href="release.apkUrl"
                            class="w-full flex items-center justify-center gap-3 py-4 px-6 bg-trading-green/90 hover:bg-trading-green text-white rounded-xl font-semibold text-lg transition-all shadow-glow-sm hover:shadow-glow"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            {{ t('download.downloadApk') }}
                            <span v-if="release.apkSize" class="text-sm opacity-80">({{ release.apkSize }} MB)</span>
                        </a>

                        <!-- Info -->
                        <div class="flex items-center justify-between mt-4 text-xs text-dark-500">
                            <span v-if="release.apkName">{{ release.apkName }}</span>
                        </div>
                    </div>

                    <!-- Install Instructions -->
                    <div class="glass-card p-6">
                        <h3 class="text-white font-semibold mb-4">{{ t('download.installTitle') }}</h3>
                        <ol class="space-y-3 text-sm text-dark-300">
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary-500/20 text-primary-400 flex items-center justify-center flex-shrink-0 text-xs font-bold">1</span>
                                <span>{{ t('download.step1') }}</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary-500/20 text-primary-400 flex items-center justify-center flex-shrink-0 text-xs font-bold">2</span>
                                <span>{{ t('download.step2Open') }} <strong class="text-white">{{ t('download.step2Settings') }}</strong> > <strong class="text-white">{{ t('download.step2Install') }}</strong> > {{ t('download.step2Allow') }}</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary-500/20 text-primary-400 flex items-center justify-center flex-shrink-0 text-xs font-bold">3</span>
                                <span>{{ t('download.step3') }} <strong class="text-white">{{ t('download.step3Install') }}</strong></span>
                            </li>
                        </ol>
                    </div>

                    <!-- Features -->
                    <div class="glass-card p-6">
                        <h3 class="text-white font-semibold mb-4">{{ t('download.featuresTitle') }}</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">⚡</span>
                                <span class="text-sm text-dark-300">{{ t('download.zeroGas') }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">🔐</span>
                                <span class="text-sm text-dark-300">{{ t('download.wallet') }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">📊</span>
                                <span class="text-sm text-dark-300">{{ t('download.charts') }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">🌐</span>
                                <span class="text-sm text-dark-300">{{ t('download.multiChain') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="text-center">
                        <p class="text-dark-500 text-sm">by Xman Studio</p>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
