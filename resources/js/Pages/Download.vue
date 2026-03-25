<script setup>
/**
 * TPIX TRADE - Download Mobile App Page
 * ดาวน์โหลด APK — แอดมินเลือก release ที่ใช้งานได้
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    latestRelease: { type: Object, default: null },
});

const release = ref(props.latestRelease);
const chainData = ref(null);
const isLoading = ref(true);
const error = ref('');

onMounted(async () => {
    try {
        // Fetch both repos in parallel
        const [tradeRes, chainRes] = await Promise.all([
            release.value ? Promise.resolve(null) : fetch('/api/v1/app/latest'),
            fetch('/api/v1/app/chain-latest'),
        ]);

        if (tradeRes && tradeRes.ok) {
            const json = await tradeRes.json();
            if (json.success && json.data) {
                release.value = {
                    version: `v${json.data.version}`,
                    name: json.data.name,
                    publishedAt: json.data.published_at,
                    apkUrl: json.data.download_url,
                    apkSize: json.data.file_size ? Math.round(json.data.file_size / 1024 / 1024) : null,
                    apkName: json.data.file_name,
                };
            }
        }

        if (chainRes.ok) {
            const json = await chainRes.json();
            if (json.success && json.data) {
                chainData.value = json.data;
            }
        }
    } catch {
        error.value = t('download.noRelease');
    } finally {
        isLoading.value = false;
    }
});

const walletSize = computed(() => {
    if (!chainData.value?.wallet?.file_size) return null;
    return Math.round(chainData.value.wallet.file_size / 1024 / 1024);
});

const masternodeSize = computed(() => {
    if (!chainData.value?.masternode?.file_size) return null;
    return Math.round(chainData.value.masternode.file_size / 1024 / 1024);
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
            <div class="max-w-5xl mx-auto">

                <!-- Header -->
                <div class="text-center mb-10">
                    <h1 class="text-3xl font-bold text-white mb-2">{{ t('download.title') }}</h1>
                    <p class="text-dark-400">{{ locale === 'th' ? 'ดาวน์โหลดแอปจากระบบของเราโดยตรง — ปลอดภัย เร็ว' : 'Download apps directly from our server — safe and fast' }}</p>
                </div>

                <!-- Loading -->
                <div v-if="isLoading" class="text-center py-12">
                    <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                    <p class="text-dark-400 text-sm">{{ t('download.loading') }}</p>
                </div>

                <!-- ═══════ 2-COLUMN GRID ═══════ -->
                <div v-else class="grid md:grid-cols-2 gap-6">

                    <!-- ─── COL 1: TPIX TRADE (Mobile) ─── -->
                    <div class="space-y-4">
                        <div class="glass-card p-6 border border-primary-500/20">
                            <div class="flex items-center gap-3 mb-4">
                                <img src="/images/logotpixdextrade.webp" class="w-14 h-14 object-contain" alt="TPIX TRADE" />
                                <div>
                                    <h2 class="text-lg font-bold text-white">TPIX TRADE</h2>
                                    <p class="text-dark-400 text-xs" v-if="release">{{ release.version }} &middot; {{ formatDate(release.publishedAt) }}</p>
                                    <p class="text-dark-500 text-xs" v-else>{{ locale === 'th' ? 'ยังไม่มี release' : 'No release yet' }}</p>
                                </div>
                            </div>
                            <p class="text-dark-400 text-sm mb-4">{{ locale === 'th' ? 'แอปเทรดคริปโต DEX — เทรดจากกระเป๋าของคุณโดยตรง' : 'DEX crypto trading app — trade directly from your wallet' }}</p>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-500/20 text-green-400">Android</span>
                            </div>
                            <a v-if="release?.apkUrl" :href="release.apkUrl"
                                class="w-full flex items-center justify-center gap-2 py-3 bg-trading-green/90 hover:bg-trading-green text-white rounded-xl font-semibold transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                {{ t('download.downloadApk') }}
                                <span v-if="release?.apkSize" class="text-sm opacity-80">({{ release.apkSize }} MB)</span>
                            </a>
                            <div v-else class="w-full text-center py-3 bg-white/5 rounded-xl text-dark-500 text-sm">
                                {{ locale === 'th' ? 'ยังไม่พร้อมดาวน์โหลด' : 'Not available yet' }}
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-4">
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>⚡</span> {{ t('download.zeroGas') }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>📊</span> {{ t('download.charts') }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🔐</span> {{ t('download.wallet') }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🌐</span> {{ t('download.multiChain') }}</div>
                            </div>
                        </div>

                        <!-- TPIX Wallet -->
                        <div class="glass-card p-6 border border-purple-500/20">
                            <div class="flex items-center gap-3 mb-4">
                                <img src="/images/logowallet.webp" class="w-14 h-14 object-contain" alt="TPIX Wallet" />
                                <div>
                                    <h2 class="text-lg font-bold text-white">TPIX Wallet</h2>
                                    <p class="text-dark-400 text-xs" v-if="chainData?.wallet">v{{ chainData.version }} &middot; {{ formatDate(chainData.published_at) }}</p>
                                    <p class="text-dark-500 text-xs" v-else>{{ locale === 'th' ? 'กำลังโหลด...' : 'Loading...' }}</p>
                                </div>
                            </div>
                            <p class="text-dark-400 text-sm mb-4">{{ locale === 'th' ? 'กระเป๋าเงิน TPIX Chain ปลอดภัย — PIN, สแกนลายนิ้วมือ, QR Code' : 'Secure TPIX Chain wallet — PIN, biometric, QR Code' }}</p>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="px-2 py-0.5 rounded-full text-xs bg-purple-500/20 text-purple-400">Android</span>
                                <span class="px-2 py-0.5 rounded-full text-xs bg-white/10 text-dark-400">3D UI</span>
                            </div>
                            <a v-if="chainData?.wallet" href="/api/v1/app/chain-download?type=wallet"
                                class="w-full flex items-center justify-center gap-2 py-3 bg-purple-500/90 hover:bg-purple-500 text-white rounded-xl font-semibold transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                {{ locale === 'th' ? 'ดาวน์โหลด TPIX Wallet' : 'Download TPIX Wallet' }}
                                <span v-if="walletSize" class="text-sm opacity-80">({{ walletSize }} MB)</span>
                            </a>
                            <div v-else class="w-full text-center py-3 bg-white/5 rounded-xl text-dark-500 text-sm">
                                {{ locale === 'th' ? 'ยังไม่พร้อมดาวน์โหลด' : 'Not available yet' }}
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-4">
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🔒</span> {{ locale === 'th' ? 'PIN เข้ารหัส' : 'PIN encrypted' }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>⚡</span> {{ locale === 'th' ? 'ไม่มีค่าแก๊ส' : 'Zero gas fee' }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>📱</span> {{ locale === 'th' ? 'QR รับเงิน' : 'QR receive' }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🎨</span> {{ locale === 'th' ? '3D อนิเมชัน' : '3D animation' }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- ─── COL 2: MASTER NODE (PC) ─── -->
                    <div class="space-y-4">
                        <div class="glass-card p-6 border border-cyan-500/20">
                            <div class="flex items-center gap-3 mb-4">
                                <img src="/images/logomasternode.webp" class="w-14 h-14 object-contain" alt="TPIX Master Node" />
                                <div>
                                    <h2 class="text-lg font-bold text-white">TPIX Master Node</h2>
                                    <p class="text-dark-400 text-xs" v-if="chainData?.masternode">v{{ chainData.version }} &middot; Windows 10/11</p>
                                    <p class="text-dark-500 text-xs" v-else>{{ locale === 'th' ? 'กำลังโหลด...' : 'Loading...' }}</p>
                                </div>
                            </div>
                            <p class="text-dark-400 text-sm mb-4">{{ locale === 'th' ? 'ตั้ง Validator Node บน Windows — รับรางวัลสูงสุด 15% APY' : 'Run a Validator Node on Windows — earn up to 15% APY rewards' }}</p>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="px-2 py-0.5 rounded-full text-xs bg-cyan-500/20 text-cyan-400">Windows</span>
                                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-500/20 text-amber-400">Portable</span>
                            </div>
                            <a v-if="chainData?.masternode" href="/api/v1/app/chain-download?type=masternode"
                                class="w-full flex items-center justify-center gap-2 py-3 bg-cyan-500/90 hover:bg-cyan-500 text-white rounded-xl font-semibold transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                {{ locale === 'th' ? 'ดาวน์โหลด Master Node' : 'Download Master Node' }}
                                <span v-if="masternodeSize" class="text-sm opacity-80">({{ masternodeSize }} MB)</span>
                            </a>
                            <div v-else class="w-full text-center py-3 bg-white/5 rounded-xl text-dark-500 text-sm">
                                {{ locale === 'th' ? 'ยังไม่พร้อมดาวน์โหลด' : 'Not available yet' }}
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-4">
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🖥️</span> {{ locale === 'th' ? 'ตั้งค่า 1 คลิก' : 'One-click setup' }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>📊</span> {{ locale === 'th' ? 'แดชบอร์ดเรียลไทม์' : 'Realtime dashboard' }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🔄</span> {{ locale === 'th' ? 'อัปเดตอัตโนมัติ' : 'Auto-update' }}</div>
                                <div class="flex items-center gap-2 p-2 rounded-lg bg-white/5 text-xs text-dark-300"><span>🌐</span> {{ locale === 'th' ? 'ไทย/อังกฤษ' : 'Thai/English' }}</div>
                            </div>
                        </div>

                        <!-- Node Tiers -->
                        <div class="glass-card p-6">
                            <h3 class="text-white font-semibold mb-4">{{ locale === 'th' ? 'ระดับโหนด & รางวัล' : 'Node Tiers & Rewards' }}</h3>
                            <div class="space-y-3">
                                <div v-for="tier in [
                                    { name: 'Light Node', stake: '10,000 TPIX', apy: '4-6%', icon: '💎', color: 'text-cyan-400' },
                                    { name: 'Sentinel Node', stake: '100,000 TPIX', apy: '7-10%', icon: '🔷', color: 'text-purple-400' },
                                    { name: 'Validator Node', stake: '1,000,000 TPIX', apy: '12-15%', icon: '⚡', color: 'text-amber-400' },
                                ]" :key="tier.name" class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                                    <div class="flex items-center gap-3">
                                        <span class="text-lg">{{ tier.icon }}</span>
                                        <div>
                                            <div class="text-sm font-semibold text-white">{{ tier.name }}</div>
                                            <div class="text-xs text-dark-500">{{ tier.stake }}</div>
                                        </div>
                                    </div>
                                    <span class="text-trading-green font-semibold text-sm">{{ tier.apy }} APY</span>
                                </div>
                            </div>
                            <p class="text-dark-500 text-xs mt-3 text-center">
                                {{ locale === 'th' ? 'รวมรางวัล: 1.4 พันล้าน TPIX ระยะ 5 ปี' : 'Total reward pool: 1.4 Billion TPIX over 5 years' }}
                            </p>
                        </div>

                        <!-- Setup Guide Link -->
                        <a href="/masternode/guide" class="block glass-card p-4 border border-cyan-500/10 hover:border-cyan-500/30 transition-all text-center">
                            <span class="text-cyan-400 font-semibold text-sm">📖 {{ locale === 'th' ? 'อ่านคู่มือการตั้งค่า Master Node' : 'Read Master Node Setup Guide' }}</span>
                        </a>
                    </div>
                </div>

                <!-- Install Instructions -->
                <div class="mt-8 glass-card p-6">
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

                <!-- Footer -->
                <div class="text-center mt-8">
                    <p class="text-dark-500 text-sm">by Xman Studio</p>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
