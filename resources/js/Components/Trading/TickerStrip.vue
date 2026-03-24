<script setup>
/**
 * TPIX TRADE - Ticker Strip Component
 * Real-time price ticker from Binance API
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted } from 'vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import axios from 'axios';

const tickers = ref([]);
const tpixFixed = ref({ symbol: 'TPIX', price: '$0.18', change: '+0.00%', isUp: true });
let refreshTimer = null;

async function fetchTickers() {
    try {
        // Fetch TPIX price from internal API
        try {
            const { data: tpixData } = await axios.get('/api/v1/tpix/price');
            if (tpixData.success) {
                const p = tpixData.data;
                tpixFixed.value = {
                    symbol: 'TPIX',
                    price: '$' + formatPrice(p.price),
                    change: (p.change_24h >= 0 ? '+' : '') + p.change_24h.toFixed(2) + '%',
                    isUp: p.change_24h >= 0,
                };
            }
        } catch { /* keep default */ }

        const { data } = await axios.get('/api/v1/market/tickers');
        const binanceTickers = (data.success && data.data.length > 0)
            ? data.data.slice(0, 14).map(t => {
                const price = parseFloat(t.price);
                const change = parseFloat(t.priceChangePercent);
                return {
                    symbol: t.baseAsset,
                    name: t.baseAsset,
                    price: formatPrice(price),
                    change: change.toFixed(2),
                    isUp: change >= 0,
                };
            })
            : [];
        tickers.value = binanceTickers;
    } catch (err) {
        tickers.value = [];
    }
}

function formatPrice(price) {
    if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (price >= 1) return price.toFixed(2);
    if (price >= 0.01) return price.toFixed(4);
    return price.toFixed(8);
}

// Duplicate for seamless scrolling
const duplicatedTickers = computed(() => [...tickers.value, ...tickers.value]);

onMounted(async () => {
    await fetchTickers();
    refreshTimer = setInterval(fetchTickers, 15000);
});

onUnmounted(() => {
    if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<template>
    <div class="ticker-strip overflow-hidden flex items-center">
        <!-- TPIX Fixed on Left (ไม่วิ่ง) -->
        <div class="flex-shrink-0 px-4 py-1 border-r border-white/10 bg-dark-950/80 z-10 flex items-center gap-2">
            <img src="/tpixlogo.webp" alt="TPIX" class="w-5 h-5 object-contain" />
            <span class="text-cyan-400 font-bold text-sm">TPIX</span>
            <span :class="['font-mono text-sm font-semibold', tpixFixed.isUp ? 'text-trading-green' : 'text-trading-red']">
                {{ tpixFixed.price }}
            </span>
            <span :class="['text-xs', tpixFixed.isUp ? 'text-trading-green' : 'text-trading-red']">
                {{ tpixFixed.change }}
            </span>
        </div>

        <!-- Scrolling Tickers -->
        <div class="flex-1 overflow-hidden">
            <div class="flex items-center animate-ticker" v-if="duplicatedTickers.length > 0">
                <div
                    v-for="(ticker, index) in duplicatedTickers"
                    :key="`${ticker.symbol}-${index}`"
                    class="ticker-item px-6 flex-shrink-0"
                >
                    <div class="flex items-center gap-3">
                        <CoinIcon :symbol="ticker.symbol" size="xs" />
                        <span class="ticker-symbol">{{ ticker.symbol }}</span>
                        <span :class="['ticker-price font-mono', ticker.isUp ? 'text-trading-green' : 'text-trading-red']">
                            ${{ ticker.price }}
                        </span>
                        <span :class="['ticker-change flex items-center gap-1', ticker.isUp ? 'text-trading-green' : 'text-trading-red']">
                            <svg :class="['w-3 h-3', { 'rotate-180': !ticker.isUp }]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                            </svg>
                            {{ ticker.change }}%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes ticker {
    0% {
        transform: translateX(0);
    }
    100% {
        transform: translateX(-50%);
    }
}

.animate-ticker {
    animation: ticker 30s linear infinite;
}

.animate-ticker:hover {
    animation-play-state: paused;
}
</style>
