<script setup>
/**
 * TPIX TRADE - Home Page
 * Landing page with real market data from Binance API
 * Developed by Xman Studio
 */

import { ref, onMounted, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import { useMarketData } from '@/Composables/useMarketData';
import BannerAd from '@/Components/BannerAd.vue';
import versionData from '../../../version.json';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const { topGainers, topVolume, isLoading, fetchTickers, startAutoRefresh } = useMarketData();

// Hero video slideshow: 1.mp4 → 2.mp4 → bg1.webp (stop, no loop)
const heroMedia = ref('video1'); // 'video1', 'video2', 'image'
const heroFading = ref(false);

function onVideo1Ended() {
    heroFading.value = true;
    setTimeout(() => { heroMedia.value = 'video2'; heroFading.value = false; }, 600);
}
function onVideo2Ended() {
    heroFading.value = true;
    setTimeout(() => { heroMedia.value = 'image'; heroFading.value = false; }, 600);
}

const features = computed(() => [
    {
        icon: 'shield',
        title: t('home.feature3'),
        description: t('home.feature3Desc'),
    },
    {
        icon: 'globe',
        title: t('home.feature4'),
        description: t('home.feature4Desc'),
    },
    {
        icon: 'lightning',
        title: t('home.feature2'),
        description: t('home.feature2Desc'),
    },
    {
        icon: 'robot',
        title: t('home.feature1'),
        description: t('home.feature1Desc'),
    },
]);

const stats = computed(() => [
    { label: t('home.supportedChains'), value: '9' },
    { label: t('home.tradingPairs'), value: '100+' },
    { label: t('home.dexProtocol'), value: 'PancakeSwap' },
    { label: t('home.network'), value: 'TPIX Chain + BSC' },
]);

onMounted(async () => {
    await fetchTickers();
    startAutoRefresh();
});
</script>

<template>
    <Head title="Decentralized Trading Platform" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero Section with Video Slideshow -->
        <section class="relative py-20 overflow-hidden min-h-[600px]">
            <!-- Video/Image Background Slideshow -->
            <div class="absolute inset-0 pointer-events-none">
                <!-- Video 1 -->
                <video v-if="heroMedia === 'video1'"
                    autoplay muted playsinline
                    @ended="onVideo1Ended"
                    class="absolute inset-0 w-full h-full object-cover transition-opacity duration-700"
                    :class="heroFading ? 'opacity-0' : 'opacity-40'"
                >
                    <source src="/videos/1.mp4" type="video/mp4" />
                </video>
                <!-- Video 2 -->
                <video v-if="heroMedia === 'video2'"
                    autoplay muted playsinline
                    @ended="onVideo2Ended"
                    class="absolute inset-0 w-full h-full object-cover transition-opacity duration-700"
                    :class="heroFading ? 'opacity-0' : 'opacity-40'"
                >
                    <source src="/videos/2.mp4" type="video/mp4" />
                </video>
                <!-- Final image (stays, no loop) -->
                <div v-if="heroMedia === 'image'"
                    class="absolute inset-0 bg-cover bg-center transition-opacity duration-1000 opacity-30"
                    style="background-image: url('/images/bg1.webp')"
                />
                <!-- Bottom fade gradient (blend into dark bg) -->
                <div class="absolute bottom-0 left-0 right-0 h-48 bg-gradient-to-t from-dark-950 via-dark-950/80 to-transparent" />
                <!-- Top subtle fade -->
                <div class="absolute top-0 left-0 right-0 h-24 bg-gradient-to-b from-dark-950/60 to-transparent" />
                <!-- Side fades -->
                <div class="absolute inset-y-0 left-0 w-32 bg-gradient-to-r from-dark-950/60 to-transparent" />
                <div class="absolute inset-y-0 right-0 w-32 bg-gradient-to-l from-dark-950/60 to-transparent" />
            </div>

            <div class="relative max-w-6xl mx-auto text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-sm text-sm mb-8">
                    <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                    <span class="text-dark-300">{{ t('home.liveOnBSC') }}</span>
                </div>

                <h1 class="text-5xl md:text-7xl font-bold text-white mb-6">
                    {{ t('home.heroTitle1') }} <span class="text-gradient">{{ t('home.heroTitle2') }}</span>
                    <br />
                    {{ t('home.heroTitle1') }} <span class="text-gradient-gold">{{ t('home.heroTitle3') }}</span>
                </h1>

                <p class="text-xl text-dark-400 max-w-2xl mx-auto mb-10">
                    {{ t('home.heroDesc') }}
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link href="/trade" class="btn-primary px-8 py-4 text-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                        {{ t('home.startTrading') }}
                    </Link>
                    <button class="btn-secondary px-8 py-4 text-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ t('home.watchDemo') }}
                    </button>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mt-20">
                    <div v-for="stat in stats" :key="stat.label" class="glass-card text-center">
                        <p class="text-3xl font-bold text-white mb-1">{{ stat.value }}</p>
                        <p class="text-dark-400">{{ stat.label }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Master Node Promo Banner -->
        <section class="py-10">
            <div class="max-w-6xl mx-auto">
                <div class="relative overflow-hidden rounded-3xl border border-cyan-500/20">
                    <!-- Animated gradient bg -->
                    <div class="absolute inset-0 bg-gradient-to-br from-cyan-900/40 via-dark-900/80 to-purple-900/40" />
                    <div class="absolute top-0 right-0 w-80 h-80 bg-cyan-500/10 rounded-full blur-3xl" />
                    <div class="absolute bottom-0 left-0 w-60 h-60 bg-purple-500/10 rounded-full blur-3xl" />

                    <div class="relative flex flex-col md:flex-row items-center gap-8 p-8 md:p-12">
                        <!-- Left: Info -->
                        <div class="flex-1 text-center md:text-left">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-xs text-cyan-400 font-semibold mb-4">
                                <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse" />
                                {{ t('home.nodeNew') }}
                            </div>
                            <h2 class="text-3xl md:text-4xl font-black text-white mb-3">
                                {{ t('home.nodeTitle') }}
                            </h2>
                            <p class="text-gray-400 mb-6 max-w-lg">
                                {{ t('home.nodeDesc') }}
                            </p>

                            <!-- Reward tiers mini -->
                            <div class="grid grid-cols-3 gap-3 mb-6 max-w-md">
                                <div class="bg-white/5 rounded-xl p-3 text-center border border-white/5">
                                    <div class="text-xs text-gray-500">Light</div>
                                    <div class="text-lg font-black text-cyan-400">10K</div>
                                    <div class="text-xs text-trading-green">4-6% APY</div>
                                </div>
                                <div class="bg-white/5 rounded-xl p-3 text-center border border-purple-500/20">
                                    <div class="text-xs text-gray-500">Sentinel</div>
                                    <div class="text-lg font-black text-purple-400">100K</div>
                                    <div class="text-xs text-trading-green">7-10% APY</div>
                                </div>
                                <div class="bg-white/5 rounded-xl p-3 text-center border border-red-500/20">
                                    <div class="text-xs text-gray-500">Validator</div>
                                    <div class="text-lg font-black text-red-400">10M</div>
                                    <div class="text-xs text-trading-green">15-20% APY</div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <Link href="/masternode" class="btn-primary px-6 py-3 text-sm font-bold">
                                    ⚡ {{ t('home.nodeStake') }}
                                </Link>
                                <Link href="/masternode/guide" class="px-6 py-3 text-sm font-semibold border border-cyan-500/30 text-cyan-400 rounded-xl hover:bg-cyan-500/10 transition">
                                    📖 {{ t('home.nodeGuide') }}
                                </Link>
                                <a href="https://github.com/xjanova/TPIX-Coin/releases/latest" target="_blank"
                                    class="px-6 py-3 text-sm font-semibold border border-white/10 text-gray-400 rounded-xl hover:bg-white/5 transition">
                                    📥 {{ t('home.nodeDownload') }}
                                </a>
                            </div>
                        </div>

                        <!-- Right: TPIX Hero Image with Glow -->
                        <div class="relative w-72 h-72 shrink-0 hidden md:block">
                            <!-- Ambient glow rings -->
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="absolute w-64 h-64 border border-cyan-500/10 rounded-full animate-spin" style="animation-duration: 25s" />
                                <div class="absolute w-52 h-52 border border-purple-500/10 rounded-full animate-spin" style="animation-duration: 18s; animation-direction: reverse" />
                            </div>
                            <!-- Glow behind image -->
                            <div class="absolute inset-8 bg-gradient-to-br from-cyan-500/20 via-purple-500/10 to-amber-500/15 rounded-full blur-2xl animate-pulse" style="animation-duration: 3s" />
                            <!-- TPIX image -->
                            <img src="/tpix1.webp" alt="TPIX Master Node"
                                class="relative w-full h-full object-contain drop-shadow-2xl"
                                style="filter: drop-shadow(0 0 30px rgba(6,182,212,0.3)) drop-shadow(0 0 60px rgba(139,92,246,0.15))" />
                            <!-- Orbiting dots -->
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="absolute w-64 h-64 animate-spin" style="animation-duration: 8s">
                                    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-2.5 h-2.5 bg-cyan-400 rounded-full shadow-lg shadow-cyan-400/50" />
                                </div>
                                <div class="absolute w-52 h-52 animate-spin" style="animation-duration: 6s; animation-direction: reverse">
                                    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-2 h-2 bg-purple-400 rounded-full shadow-lg shadow-purple-400/50" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TPIX Price Chart 3D -->
        <section class="py-10">
            <div class="max-w-6xl mx-auto">
                <div class="glass-dark rounded-2xl p-6 border border-white/5">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-semibold text-white">TPIX {{ t('home.priceChart') }}</h3>
                            <p class="text-xs text-gray-500">{{ t('home.priceChartDesc') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-black text-white">$0.18</span>
                            <span class="text-sm text-trading-green">+0.00%</span>
                        </div>
                    </div>
                    <!-- 3D-style chart with CSS perspective -->
                    <div class="relative h-64 overflow-hidden rounded-xl" style="perspective: 800px">
                        <div class="absolute inset-0 bg-gradient-to-t from-cyan-500/5 to-transparent" style="transform: rotateX(15deg); transform-origin: bottom" />
                        <!-- SVG chart bars -->
                        <svg class="w-full h-full" viewBox="0 0 800 250" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="barGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#00BCD4" stop-opacity="0.8"/>
                                    <stop offset="100%" stop-color="#00BCD4" stop-opacity="0.1"/>
                                </linearGradient>
                                <linearGradient id="lineGrad" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0%" stop-color="#06B6D4"/>
                                    <stop offset="50%" stop-color="#8B5CF6"/>
                                    <stop offset="100%" stop-color="#F59E0B"/>
                                </linearGradient>
                            </defs>
                            <!-- Grid lines -->
                            <line v-for="i in 5" :key="'g'+i" x1="0" :y1="i*50" x2="800" :y2="i*50" stroke="white" stroke-opacity="0.03" />
                            <!-- 3D-effect bars -->
                            <g v-for="(h, i) in [120,140,110,160,130,170,150,180,145,165,155,175,140,190,160,185,170,195,180,200,175,210,190,205,195,215,200,220,185,210]" :key="'b'+i">
                                <rect :x="i * 26.6 + 4" :y="250 - h" width="20" :height="h" fill="url(#barGrad)" rx="2" opacity="0.4">
                                    <animate attributeName="height" :from="0" :to="h" dur="1.5s" fill="freeze" :begin="i * 0.05 + 's'" />
                                    <animate attributeName="y" :from="250" :to="250 - h" dur="1.5s" fill="freeze" :begin="i * 0.05 + 's'" />
                                </rect>
                            </g>
                            <!-- Price line -->
                            <polyline fill="none" stroke="url(#lineGrad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                points="15,130 41,110 68,140 94,90 121,120 147,80 174,100 200,70 227,105 253,85 280,95 306,75 333,110 359,60 386,90 412,65 439,80 465,55 492,70 518,50 545,75 571,40 598,60 624,45 651,55 677,35 704,50 730,30 757,65 784,40">
                                <animate attributeName="stroke-dashoffset" from="2000" to="0" dur="2s" fill="freeze" />
                                <animate attributeName="stroke-dasharray" from="2000" to="2000" dur="0.01s" fill="freeze" />
                            </polyline>
                            <!-- Glow dot at end -->
                            <circle cx="784" cy="40" r="4" fill="#F59E0B">
                                <animate attributeName="r" values="3;6;3" dur="2s" repeatCount="indefinite" />
                                <animate attributeName="opacity" values="1;0.5;1" dur="2s" repeatCount="indefinite" />
                            </circle>
                        </svg>
                        <!-- Price labels -->
                        <div class="absolute top-2 left-3 text-[10px] text-gray-600">$0.25</div>
                        <div class="absolute bottom-2 left-3 text-[10px] text-gray-600">$0.10</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Markets Preview -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="grid md:grid-cols-2 gap-6">
                    <!-- Top Gainers -->
                    <div class="glass-dark rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-white">{{ t('home.topGainers') }}</h3>
                            <Link href="/markets" class="text-primary-400 hover:text-primary-300 text-sm">{{ t('home.viewAll') }}</Link>
                        </div>
                        <div v-if="isLoading" class="py-8 text-center text-dark-400">
                            <div class="animate-pulse">Loading live data...</div>
                        </div>
                        <div v-else class="space-y-4">
                            <div
                                v-for="coin in topGainers"
                                :key="coin.symbol"
                                class="flex items-center justify-between p-3 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <div class="flex items-center gap-3">
                                    <CoinIcon :symbol="coin.symbol" size="lg" />
                                    <div>
                                        <p class="font-semibold text-white">{{ coin.symbol }}</p>
                                        <p class="text-sm text-dark-400">{{ coin.name }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-white">${{ coin.price }}</p>
                                    <p class="text-sm text-trading-green font-medium">{{ coin.change }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Volume -->
                    <div class="glass-dark rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-white">{{ t('home.topVolume') }}</h3>
                            <Link href="/markets" class="text-primary-400 hover:text-primary-300 text-sm">{{ t('home.viewAll') }}</Link>
                        </div>
                        <div v-if="isLoading" class="py-8 text-center text-dark-400">
                            <div class="animate-pulse">Loading live data...</div>
                        </div>
                        <div v-else class="space-y-4">
                            <div
                                v-for="coin in topVolume"
                                :key="coin.symbol"
                                class="flex items-center justify-between p-3 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <div class="flex items-center gap-3">
                                    <CoinIcon :symbol="coin.symbol" size="lg" />
                                    <div>
                                        <p class="font-semibold text-white">{{ coin.symbol }}</p>
                                        <p class="text-sm text-dark-400">{{ coin.name }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-white">${{ coin.price }}</p>
                                    <p :class="['text-sm font-medium', coin.isUp ? 'text-trading-green' : 'text-trading-red']">
                                        {{ coin.change }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TPIX Token Sale Banner — โฆษณาการขายเหรียญ -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-accent-500/20 via-primary-500/15 to-warm-500/20 border border-primary-500/20 p-8 md:p-12">
                    <!-- Glow Effect -->
                    <div class="absolute top-0 right-0 w-80 h-80 bg-primary-500/10 rounded-full blur-3xl"></div>
                    <div class="absolute bottom-0 left-0 w-60 h-60 bg-accent-500/10 rounded-full blur-3xl"></div>

                    <div class="relative flex flex-col lg:flex-row items-center gap-8">
                        <!-- Left: Text -->
                        <div class="flex-1 text-center lg:text-left">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-trading-green/10 border border-trading-green/20 text-trading-green text-xs font-semibold mb-4">
                                <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                                {{ t('tokenSale.title') }}
                            </div>
                            <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                                Buy <span class="text-gradient">TPIX</span> Token
                            </h2>
                            <p class="text-dark-300 mb-6 max-w-lg">
                                Join the TPIX ecosystem. Buy TPIX tokens at the best price during our ICO.
                                Pay with BNB or USDT on BSC Network.
                            </p>
                            <div class="flex flex-wrap items-center justify-center lg:justify-start gap-3">
                                <Link href="/token-sale" class="btn-primary px-6 py-3">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Buy TPIX Now
                                </Link>
                                <Link href="/whitepaper" class="btn-secondary px-6 py-3">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    Read Whitepaper
                                </Link>
                            </div>
                        </div>

                        <!-- Right: Price Cards -->
                        <div class="flex-shrink-0 grid grid-cols-3 gap-3">
                            <div class="glass-card text-center px-4 py-5 min-w-[100px]">
                                <p class="text-xs text-dark-400 mb-1">Private</p>
                                <p class="text-lg font-bold text-white">$0.05</p>
                            </div>
                            <div class="glass-card text-center px-4 py-5 min-w-[100px] border-primary-500/30">
                                <p class="text-xs text-primary-400 mb-1">Pre-Sale</p>
                                <p class="text-lg font-bold text-white">$0.08</p>
                            </div>
                            <div class="glass-card text-center px-4 py-5 min-w-[100px]">
                                <p class="text-xs text-dark-400 mb-1">Public</p>
                                <p class="text-lg font-bold text-white">$0.10</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TPIX Ecosystem — ลิงก์เข้าถึงทุกส่วนของระบบ -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                        TPIX <span class="text-gradient">Ecosystem</span>
                    </h2>
                    <p class="text-dark-400 max-w-2xl mx-auto">
                        Explore the complete TPIX ecosystem. From token sale to master node, everything in one place.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Token Sale -->
                    <Link href="/token-sale" class="glass-card group hover:border-primary-500/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-primary-500/10 flex items-center justify-center mb-4 group-hover:bg-primary-500/20 transition-colors">
                            <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Token Sale</h3>
                        <p class="text-dark-400 text-sm">Buy TPIX at ICO price. Pay with BNB or USDT.</p>
                    </Link>

                    <!-- Whitepaper -->
                    <Link href="/whitepaper" class="glass-card group hover:border-accent-500/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-accent-500/10 flex items-center justify-center mb-4 group-hover:bg-accent-500/20 transition-colors">
                            <svg class="w-6 h-6 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Whitepaper</h3>
                        <p class="text-dark-400 text-sm">Learn about TPIX vision, tokenomics, and roadmap.</p>
                    </Link>

                    <!-- Explorer -->
                    <Link href="/explorer" class="glass-card group hover:border-trading-green/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-trading-green/10 flex items-center justify-center mb-4 group-hover:bg-trading-green/20 transition-colors">
                            <svg class="w-6 h-6 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Explorer</h3>
                        <p class="text-dark-400 text-sm">Browse TPIX Chain blocks, transactions, and addresses.</p>
                    </Link>

                    <!-- Master Node -->
                    <Link href="/masternode" class="glass-card group hover:border-warm-500/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-warm-500/10 flex items-center justify-center mb-4 group-hover:bg-warm-500/20 transition-colors">
                            <svg class="w-6 h-6 text-warm-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Master Node</h3>
                        <p class="text-dark-400 text-sm">Run a TPIX Master Node and earn up to 15% APY rewards.</p>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                        Why Trade with TPIX TRADE?
                    </h2>
                    <p class="text-dark-400 max-w-2xl mx-auto">
                        Built by traders, for traders. Experience the future of decentralized trading.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div v-for="feature in features" :key="feature.title" class="glass-card group">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-accent-500/10 via-primary-500/15 to-warm-500/10 flex items-center justify-center mb-4 group-hover:from-accent-500/20 group-hover:via-primary-500/25 group-hover:to-warm-500/15 transition-colors">
                            <svg v-if="feature.icon === 'shield'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <svg v-else-if="feature.icon === 'globe'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <svg v-else-if="feature.icon === 'lightning'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            <svg v-else class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">{{ feature.title }}</h3>
                        <p class="text-dark-400">{{ feature.description }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ป้ายโฆษณาด้านล่าง Home -->
        <BannerAd placement="home_bottom" class="py-4 max-w-6xl mx-auto" />

        <!-- CTA Section -->
        <section class="py-16">
            <div class="max-w-4xl mx-auto">
                <div class="glass-card text-center bg-brand-gradient-subtle border-primary-500/20">
                    <h2 class="text-3xl font-bold text-white mb-4">
                        Ready to {{ t('home.startTrading') }}?
                    </h2>
                    <p class="text-dark-400 mb-8 max-w-xl mx-auto">
                        Connect your wallet and start trading in seconds. No registration required.
                    </p>
                    <Link href="/trade" class="btn-primary px-8 py-4 text-lg inline-flex">
                        Launch App
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Footer ใช้จาก AppLayout — ไม่ต้องซ้ำที่นี่ -->
    </AppLayout>
</template>
