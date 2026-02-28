<script setup>
/**
 * TPIX TRADE - Ticker Strip Component
 * Real-time price ticker
 * Developed by Xman Studio
 */

import { ref, onMounted, onUnmounted } from 'vue';
import { getCoinLogo } from '@/utils/cryptoLogos';

const tickers = ref([
    { symbol: 'BTC', name: 'Bitcoin', price: '67,234.50', change: '+2.45', isUp: true },
    { symbol: 'ETH', name: 'Ethereum', price: '3,456.78', change: '+1.23', isUp: true },
    { symbol: 'BNB', name: 'BNB', price: '567.89', change: '-0.56', isUp: false },
    { symbol: 'SOL', name: 'Solana', price: '178.90', change: '+5.67', isUp: true },
    { symbol: 'XRP', name: 'Ripple', price: '0.5234', change: '+0.89', isUp: true },
    { symbol: 'ADA', name: 'Cardano', price: '0.4567', change: '-1.23', isUp: false },
    { symbol: 'DOGE', name: 'Dogecoin', price: '0.0823', change: '+3.45', isUp: true },
    { symbol: 'DOT', name: 'Polkadot', price: '7.89', change: '-0.78', isUp: false },
    { symbol: 'MATIC', name: 'Polygon', price: '0.8901', change: '+2.12', isUp: true },
    { symbol: 'AVAX', name: 'Avalanche', price: '38.90', change: '+4.56', isUp: true },
]);

// Duplicate for seamless scrolling
const duplicatedTickers = ref([...tickers.value, ...tickers.value]);
</script>

<template>
    <div class="ticker-strip overflow-hidden">
        <div class="flex items-center animate-ticker">
            <div
                v-for="(ticker, index) in duplicatedTickers"
                :key="`${ticker.symbol}-${index}`"
                class="ticker-item px-6 flex-shrink-0"
            >
                <div class="flex items-center gap-3">
                    <!-- Coin Logo + Symbol -->
                    <img v-if="getCoinLogo(ticker.symbol)" :src="getCoinLogo(ticker.symbol, 'thumb')" :alt="ticker.symbol" class="w-4 h-4 rounded-full" />
                    <span class="ticker-symbol">{{ ticker.symbol }}</span>

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
