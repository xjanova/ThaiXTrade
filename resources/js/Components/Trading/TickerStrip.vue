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
let refreshTimer = null;

// TPIX ticker — แสดงเป็นตัวแรกเสมอ (ราคาจาก token sale / DEX)
const tpixTicker = {
    symbol: 'TPIX',
    name: 'TPIX',
    price: '0.10',
    change: '0.00',
    isUp: true,
    isTpix: true,
};

async function fetchTickers() {
    try {
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
        // TPIX อยู่แรกเสมอ ตามด้วย Binance tickers
        tickers.value = [tpixTicker, ...binanceTickers];
    } catch (err) {
        // แม้ Binance fail ก็ยังแสดง TPIX
        tickers.value = [tpixTicker];
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
    <div class="ticker-strip overflow-hidden">
        <div class="flex items-center animate-ticker" v-if="duplicatedTickers.length > 0">
            <div
                v-for="(ticker, index) in duplicatedTickers"
                :key="`${ticker.symbol}-${index}`"
                class="ticker-item px-6 flex-shrink-0"
            >
                <div class="flex items-center gap-3">
                    <!-- Coin Logo + Symbol -->
                    <CoinIcon v-if="ticker.isTpix" :symbol="ticker.symbol" size="xs" src="/logo.png" />
                    <CoinIcon v-else :symbol="ticker.symbol" size="xs" />
                    <span class="ticker-symbol" :class="{ 'text-primary-400 font-semibold': ticker.isTpix }">{{ ticker.symbol }}</span>

                    <!-- Price -->
                    <span :class="['ticker-price font-mono', ticker.isUp ? 'text-trading-green' : 'text-trading-red']">
                        ${{ ticker.price }}
                    </span>

                    <!-- Change -->
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
