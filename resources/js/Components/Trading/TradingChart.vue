<script setup>
/**
 * ThaiXTrade - Trading Chart Component
 * TradingView-style candlestick chart
 * Developed by Xman Studio
 */

import { ref, onMounted, watch } from 'vue';

const props = defineProps({
    symbol: {
        type: String,
        default: 'BTC/USDT'
    },
    interval: {
        type: String,
        default: '1H'
    }
});

const chartContainer = ref(null);
const selectedTimeframe = ref('1H');
const chartType = ref('candle');

const timeframes = ['1m', '5m', '15m', '1H', '4H', '1D', '1W'];
const indicators = ref(['MA', 'EMA', 'RSI', 'MACD']);
const activeIndicators = ref(['MA']);

const toggleIndicator = (indicator) => {
    const index = activeIndicators.value.indexOf(indicator);
    if (index > -1) {
        activeIndicators.value.splice(index, 1);
    } else {
        activeIndicators.value.push(indicator);
    }
};

// Mock price data for demo
const currentPrice = ref('67,234.50');
const priceChange = ref('+2.45%');
const high24h = ref('68,500.00');
const low24h = ref('65,200.00');
const volume24h = ref('1.2B');
</script>

<template>
    <div class="chart-container h-full flex flex-col">
        <!-- Chart Header -->
        <div class="chart-toolbar flex-shrink-0">
            <div class="flex items-center gap-4">
                <!-- Symbol Info -->
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center">
                        <span class="text-white font-bold text-sm">BTC</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-white">{{ symbol }}</h2>
                        <p class="text-xs text-dark-400">Bitcoin / Tether</p>
                    </div>
                </div>

                <!-- Price Display -->
                <div class="hidden md:flex items-center gap-6 ml-6 pl-6 border-l border-white/10">
                    <div>
                        <p class="text-2xl font-bold text-trading-green font-mono">${{ currentPrice }}</p>
                        <p class="text-sm text-trading-green">{{ priceChange }}</p>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <div>
                            <p class="text-dark-400">24h High</p>
                            <p class="text-white font-mono">${{ high24h }}</p>
                        </div>
                        <div>
                            <p class="text-dark-400">24h Low</p>
                            <p class="text-white font-mono">${{ low24h }}</p>
                        </div>
                        <div>
                            <p class="text-dark-400">24h Volume</p>
                            <p class="text-white font-mono">${{ volume24h }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <!-- Chart Type -->
                <div class="flex items-center gap-1 p-1 rounded-lg bg-dark-800">
                    <button
                        @click="chartType = 'candle'"
                        :class="['p-2 rounded-lg transition-all', chartType === 'candle' ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white']"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                            <rect x="3" y="6" width="4" height="12" rx="1"/>
                            <rect x="10" y="3" width="4" height="18" rx="1"/>
                            <rect x="17" y="8" width="4" height="8" rx="1"/>
                        </svg>
                    </button>
                    <button
                        @click="chartType = 'line'"
                        :class="['p-2 rounded-lg transition-all', chartType === 'line' ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white']"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                    </button>
                </div>

                <!-- Indicators -->
                <div class="hidden lg:flex items-center gap-1">
                    <button
                        v-for="indicator in indicators"
                        :key="indicator"
                        @click="toggleIndicator(indicator)"
                        :class="['px-3 py-1.5 text-xs font-medium rounded-lg transition-all', activeIndicators.includes(indicator) ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5']"
                    >
                        {{ indicator }}
                    </button>
                </div>

                <!-- Fullscreen -->
                <button class="p-2 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Timeframe Selector -->
        <div class="flex items-center gap-1 px-4 py-2 border-b border-white/5">
            <button
                v-for="tf in timeframes"
                :key="tf"
                @click="selectedTimeframe = tf"
                :class="['chart-timeframe-btn', { 'active': selectedTimeframe === tf }]"
            >
                {{ tf }}
            </button>
        </div>

        <!-- Chart Area (Mockup) -->
        <div ref="chartContainer" class="flex-1 relative min-h-[400px] p-4">
            <!-- SVG Chart Mockup -->
            <svg class="w-full h-full" viewBox="0 0 800 400" preserveAspectRatio="none">
                <!-- Grid Lines -->
                <defs>
                    <pattern id="grid" width="50" height="40" patternUnits="userSpaceOnUse">
                        <path d="M 50 0 L 0 0 0 40" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="1"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#grid)"/>

                <!-- Price Line -->
                <path
                    d="M 0 300 L 50 280 L 100 290 L 150 250 L 200 270 L 250 200 L 300 220 L 350 180 L 400 190 L 450 150 L 500 170 L 550 120 L 600 140 L 650 100 L 700 110 L 750 80 L 800 90"
                    fill="none"
                    stroke="url(#lineGradient)"
                    stroke-width="2"
                />

                <!-- Area Fill -->
                <path
                    d="M 0 300 L 50 280 L 100 290 L 150 250 L 200 270 L 250 200 L 300 220 L 350 180 L 400 190 L 450 150 L 500 170 L 550 120 L 600 140 L 650 100 L 700 110 L 750 80 L 800 90 L 800 400 L 0 400 Z"
                    fill="url(#areaGradient)"
                />

                <!-- Candlesticks (simplified) -->
                <g v-if="chartType === 'candle'">
                    <rect x="45" y="270" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="95" y="280" width="10" height="20" fill="#FF1744" rx="1"/>
                    <rect x="145" y="240" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="195" y="250" width="10" height="30" fill="#FF1744" rx="1"/>
                    <rect x="245" y="190" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="295" y="200" width="10" height="30" fill="#FF1744" rx="1"/>
                    <rect x="345" y="170" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="395" y="180" width="10" height="20" fill="#FF1744" rx="1"/>
                    <rect x="445" y="140" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="495" y="160" width="10" height="20" fill="#FF1744" rx="1"/>
                    <rect x="545" y="110" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="595" y="130" width="10" height="20" fill="#FF1744" rx="1"/>
                    <rect x="645" y="90" width="10" height="30" fill="#00C853" rx="1"/>
                    <rect x="695" y="100" width="10" height="20" fill="#FF1744" rx="1"/>
                    <rect x="745" y="70" width="10" height="30" fill="#00C853" rx="1"/>
                </g>

                <!-- Gradients -->
                <defs>
                    <linearGradient id="lineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#0ea5e9"/>
                        <stop offset="100%" stop-color="#38bdf8"/>
                    </linearGradient>
                    <linearGradient id="areaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="rgba(14, 165, 233, 0.3)"/>
                        <stop offset="100%" stop-color="rgba(14, 165, 233, 0)"/>
                    </linearGradient>
                </defs>

                <!-- Current Price Line -->
                <line x1="0" y1="90" x2="800" y2="90" stroke="#0ea5e9" stroke-width="1" stroke-dasharray="5,5"/>
                <rect x="750" y="80" width="50" height="20" fill="#0ea5e9" rx="4"/>
                <text x="775" y="94" text-anchor="middle" fill="white" font-size="10" font-family="monospace">67,234</text>
            </svg>

            <!-- Current Price Label -->
            <div class="absolute top-4 right-4 glass-sm px-3 py-2">
                <p class="text-xs text-dark-400">Last Price</p>
                <p class="text-lg font-bold font-mono text-trading-green">${{ currentPrice }}</p>
            </div>
        </div>

        <!-- Volume Chart -->
        <div class="h-20 px-4 pb-4">
            <svg class="w-full h-full" viewBox="0 0 800 60" preserveAspectRatio="none">
                <rect x="40" y="40" width="15" height="20" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="90" y="30" width="15" height="30" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="140" y="20" width="15" height="40" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="190" y="35" width="15" height="25" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="240" y="10" width="15" height="50" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="290" y="25" width="15" height="35" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="340" y="15" width="15" height="45" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="390" y="30" width="15" height="30" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="440" y="5" width="15" height="55" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="490" y="25" width="15" height="35" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="540" y="10" width="15" height="50" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="590" y="20" width="15" height="40" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="640" y="5" width="15" height="55" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
                <rect x="690" y="15" width="15" height="45" fill="rgba(255, 23, 68, 0.5)" rx="2"/>
                <rect x="740" y="0" width="15" height="60" fill="rgba(0, 200, 83, 0.5)" rx="2"/>
            </svg>
        </div>
    </div>
</template>
