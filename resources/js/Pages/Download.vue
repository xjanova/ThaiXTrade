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
                        <img src="/logo.webp" class="w-16 h-16" alt="TPIX TRADE" />
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

                        <!-- Android Badge -->
                        <div class="flex items-center gap-2 mb-4">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M17.523 2.132a.5.5 0 00-.87.49l1.14 2.03A7.47 7.47 0 0012 2.47a7.47 7.47 0 00-5.793 2.182l1.14-2.03a.5.5 0 10-.87-.49L5.063 4.79A7.97 7.97 0 004 9h16a7.97 7.97 0 00-1.063-4.21l-1.414-2.658zM8.5 7a1 1 0 110-2 1 1 0 010 2zm7 0a1 1 0 110-2 1 1 0 010 2zM5 10v8a2 2 0 002 2h10a2 2 0 002-2v-8H5z"/>
                                </svg>
                                Android
                            </span>
                        </div>

                        <!-- Download Button -->
                        <a
                            v-if="release.apkUrl"
                            :href="release.apkUrl"
                            class="w-full flex items-center justify-center gap-3 py-4 px-6 bg-trading-green/90 hover:bg-trading-green text-white rounded-xl font-semibold text-lg transition-all shadow-glow-sm hover:shadow-glow"
                        >
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M17.523 2.132a.5.5 0 00-.87.49l1.14 2.03A7.47 7.47 0 0012 2.47a7.47 7.47 0 00-5.793 2.182l1.14-2.03a.5.5 0 10-.87-.49L5.063 4.79A7.97 7.97 0 004 9h16a7.97 7.97 0 00-1.063-4.21l-1.414-2.658zM8.5 7a1 1 0 110-2 1 1 0 010 2zm7 0a1 1 0 110-2 1 1 0 010 2zM5 10v8a2 2 0 002 2h10a2 2 0 002-2v-8H5z"/>
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

                <!-- ══════════ MASTER NODE PC DOWNLOAD ══════════ -->
                <div class="mt-16 pt-12 border-t border-white/5">
                    <div class="text-center mb-8">
                        <div class="w-20 h-20 mx-auto rounded-3xl bg-gradient-to-br from-cyan-500/20 to-purple-500/20 flex items-center justify-center mb-4 shadow-glow">
                            <img src="/logo.webp" class="w-12 h-12" alt="TPIX Master Node" />
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-2">TPIX Master Node</h2>
                        <p class="text-dark-400 text-sm">
                            {{ locale === 'th'
                                ? 'โปรแกรมตั้ง Master Node สำหรับ Windows — รัน Validator ได้ง่ายๆ รับรางวัล TPIX'
                                : 'Master Node program for Windows — Run a Validator node easily and earn TPIX rewards' }}
                        </p>
                    </div>

                    <!-- PC Download Card -->
                    <div class="glass-card p-6 border border-cyan-500/20 mb-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-xl bg-cyan-500/20 flex items-center justify-center">
                                <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-bold text-white">TPIX Master Node for PC</h3>
                                <p class="text-dark-400 text-sm">v1.0.0 &middot; Windows 10/11 (x64)</p>
                            </div>
                        </div>

                        <!-- Windows Badge -->
                        <div class="flex items-center gap-2 mb-4">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-cyan-500/20 text-cyan-400">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M3 12V6.75l8-1.25V12H3zm0 .5h8v6.5l-8-1.25V12.5zM11.5 12V5.35l9.5-1.6V12h-9.5zm0 .5h9.5v8.25l-9.5-1.6V12.5z"/>
                                </svg>
                                Windows
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-purple-500/20 text-purple-400">
                                Portable
                            </span>
                        </div>

                        <!-- Download Buttons -->
                        <div class="space-y-3">
                            <a href="https://github.com/xjanova/TPIX-Coin/releases/latest"
                                target="_blank"
                                class="w-full flex items-center justify-center gap-3 py-4 px-6 bg-cyan-500/90 hover:bg-cyan-500 text-white rounded-xl font-semibold text-lg transition-all shadow-glow-sm hover:shadow-glow">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                {{ locale === 'th' ? 'ดาวน์โหลด Master Node (.exe)' : 'Download Master Node (.exe)' }}
                            </a>
                        </div>

                        <p class="text-xs text-dark-500 mt-3 text-center">
                            {{ locale === 'th'
                                ? 'ดาวน์โหลดจาก GitHub Releases — อัปเดตอัตโนมัติในตัว'
                                : 'Download from GitHub Releases — auto-update built-in' }}
                        </p>
                    </div>

                    <!-- Node Tiers Quick Info -->
                    <div class="glass-card p-6 mb-6">
                        <h3 class="text-white font-semibold mb-4">
                            {{ locale === 'th' ? 'ระดับโหนด & รางวัล' : 'Node Tiers & Rewards' }}
                        </h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                                <div class="flex items-center gap-3">
                                    <span class="text-lg">💎</span>
                                    <div>
                                        <div class="text-sm font-semibold text-white">Light Node</div>
                                        <div class="text-xs text-dark-500">10,000 TPIX</div>
                                    </div>
                                </div>
                                <span class="text-trading-green font-semibold text-sm">4-6% APY</span>
                            </div>
                            <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                                <div class="flex items-center gap-3">
                                    <span class="text-lg">🛡️</span>
                                    <div>
                                        <div class="text-sm font-semibold text-white">Sentinel Node</div>
                                        <div class="text-xs text-dark-500">100,000 TPIX</div>
                                    </div>
                                </div>
                                <span class="text-trading-green font-semibold text-sm">7-10% APY</span>
                            </div>
                            <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                                <div class="flex items-center gap-3">
                                    <span class="text-lg">⚡</span>
                                    <div>
                                        <div class="text-sm font-semibold text-white">Validator Node</div>
                                        <div class="text-xs text-dark-500">1,000,000 TPIX</div>
                                    </div>
                                </div>
                                <span class="text-trading-green font-semibold text-sm">12-15% APY</span>
                            </div>
                        </div>
                        <p class="text-xs text-dark-500 mt-3">
                            {{ locale === 'th'
                                ? 'พูลรางวัลรวม 1,400,000,000 TPIX แจกตลอด 5 ปี'
                                : 'Total reward pool: 1.4 Billion TPIX over 5 years' }}
                        </p>
                    </div>

                    <!-- PC Features -->
                    <div class="glass-card p-6 mb-6">
                        <h3 class="text-white font-semibold mb-4">
                            {{ locale === 'th' ? 'ฟีเจอร์โปรแกรม' : 'App Features' }}
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">🔐</span>
                                <span class="text-sm text-dark-300">{{ locale === 'th' ? 'กระเป๋าในตัว' : 'Built-in Wallet' }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">📊</span>
                                <span class="text-sm text-dark-300">{{ locale === 'th' ? 'แดชบอร์ดแบบเรียลไทม์' : 'Real-time Dashboard' }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">🔄</span>
                                <span class="text-sm text-dark-300">{{ locale === 'th' ? 'อัปเดตอัตโนมัติ' : 'Auto Update' }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span class="text-lg">🌐</span>
                                <span class="text-sm text-dark-300">{{ locale === 'th' ? 'ไทย / English' : 'Thai / English' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Guide Link -->
                    <div class="text-center">
                        <a href="/masternode/guide"
                            class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                            📖 {{ locale === 'th' ? 'คู่มือการตั้งค่า Master Node' : 'Master Node Setup Guide' }}
                        </a>
                    </div>
                </div>

                <!-- ══════════ TPIX WALLET (MOBILE) ══════════ -->
                <div class="mt-16 pt-12 border-t border-white/5">
                    <div class="text-center mb-8">
                        <div class="w-20 h-20 mx-auto rounded-3xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 flex items-center justify-center mb-4 shadow-glow">
                            <img src="/tpixlogo.webp" class="w-12 h-12 object-contain" alt="TPIX Wallet" />
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-2">TPIX Wallet</h2>
                        <p class="text-dark-400 text-sm">
                            {{ locale === 'th'
                                ? 'กระเป๋าเงินอย่างเป็นทางการสำหรับ TPIX Chain — ปลอดภัย ไม่มีค่าแก๊ส อนิเมชั่น 3D'
                                : 'Official wallet for TPIX Chain — Secure, zero gas, beautiful 3D animations' }}
                        </p>
                    </div>

                    <!-- Wallet Download Card -->
                    <div class="glass-card p-6 border border-purple-500/20 mb-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-xl bg-purple-500/20 flex items-center justify-center">
                                <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-bold text-white">TPIX Wallet for Mobile</h3>
                                <p class="text-dark-400 text-sm">v1.0.0 &middot; Android</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mb-4">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                                🤖 Android
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-purple-500/20 text-purple-400">
                                🔐 Encrypted
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-amber-500/20 text-amber-400">
                                ⚡ Zero Gas
                            </span>
                        </div>

                        <a href="https://github.com/xjanova/TPIX-Coin/releases/latest"
                            target="_blank"
                            class="w-full flex items-center justify-center gap-3 py-4 px-6 bg-purple-500/90 hover:bg-purple-500 text-white rounded-xl font-semibold text-lg transition-all shadow-lg hover:shadow-purple-500/20">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            {{ locale === 'th' ? 'ดาวน์โหลด TPIX Wallet (.apk)' : 'Download TPIX Wallet (.apk)' }}
                        </a>

                        <p class="text-xs text-dark-500 mt-3 text-center">
                            {{ locale === 'th'
                                ? 'ดาวน์โหลดจาก GitHub Releases'
                                : 'Download from GitHub Releases' }}
                        </p>
                    </div>

                    <!-- Wallet Features -->
                    <div class="glass-card p-6 mb-6">
                        <h3 class="text-white font-semibold mb-4">
                            {{ locale === 'th' ? 'ฟีเจอร์กระเป๋า' : 'Wallet Features' }}
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span>🔐</span>
                                <span class="text-sm text-white">{{ locale === 'th' ? 'เข้ารหัส PIN' : 'PIN Encrypted' }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span>⚡</span>
                                <span class="text-sm text-white">{{ locale === 'th' ? 'ส่งฟรี 0 แก๊ส' : 'Zero Gas Fee' }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span>📱</span>
                                <span class="text-sm text-white">{{ locale === 'th' ? 'QR รับเงิน' : 'QR Receive' }}</span>
                            </div>
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-white/5">
                                <span>🎨</span>
                                <span class="text-sm text-white">{{ locale === 'th' ? 'อนิเมชั่น 3D' : '3D Animations' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
