<script setup>
/**
 * TPIX TRADE - Trading Chart Component
 * Powered by TradingView Lightweight Charts
 * Developed by Xman Studio
 */

import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { createChart, ColorType, CrosshairMode } from 'lightweight-charts';

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

let chart = null;
let candleSeries = null;
let lineSeries = null;
let volumeSeries = null;
let maSeries = null;
let emaSeries = null;

const currentPrice = ref('67,234.50');
const priceChange = ref('+2.45%');
const priceChangePositive = ref(true);
const high24h = ref('68,500.00');
const low24h = ref('65,200.00');
const volume24h = ref('1.2B');

const toggleIndicator = (indicator) => {
    const index = activeIndicators.value.indexOf(indicator);
    if (index > -1) {
        activeIndicators.value.splice(index, 1);
    } else {
        activeIndicators.value.push(indicator);
    }
    updateIndicators();
};

// Generate realistic OHLCV mock data
function generateCandleData(count = 200) {
    const data = [];
    let basePrice = 65000;
    const now = Math.floor(Date.now() / 1000);
    const interval = 3600; // 1 hour

    for (let i = count; i >= 0; i--) {
        const time = now - i * interval;
        const volatility = 0.02;
        const change = (Math.random() - 0.48) * volatility * basePrice;
        const open = basePrice;
        const close = basePrice + change;
        const high = Math.max(open, close) + Math.random() * volatility * basePrice * 0.5;
        const low = Math.min(open, close) - Math.random() * volatility * basePrice * 0.5;
        const volume = Math.random() * 500 + 100;

        data.push({
            time,
            open: parseFloat(open.toFixed(2)),
            high: parseFloat(high.toFixed(2)),
            low: parseFloat(low.toFixed(2)),
            close: parseFloat(close.toFixed(2)),
            volume: parseFloat(volume.toFixed(2)),
        });

        basePrice = close;
    }

    // Update current price from last candle
    const last = data[data.length - 1];
    const prev = data[data.length - 2];
    currentPrice.value = last.close.toLocaleString('en-US', { minimumFractionDigits: 2 });
    const pctChange = ((last.close - prev.close) / prev.close * 100).toFixed(2);
    priceChange.value = (pctChange >= 0 ? '+' : '') + pctChange + '%';
    priceChangePositive.value = pctChange >= 0;

    const prices = data.map(d => d.high);
    high24h.value = Math.max(...prices.slice(-24)).toLocaleString('en-US', { minimumFractionDigits: 2 });
    low24h.value = Math.min(...data.slice(-24).map(d => d.low)).toLocaleString('en-US', { minimumFractionDigits: 2 });

    return data;
}

// Calculate Moving Average
function calculateMA(data, period = 20) {
    const result = [];
    for (let i = 0; i < data.length; i++) {
        if (i < period - 1) continue;
        let sum = 0;
        for (let j = 0; j < period; j++) {
            sum += data[i - j].close;
        }
        result.push({
            time: data[i].time,
            value: parseFloat((sum / period).toFixed(2)),
        });
    }
    return result;
}

// Calculate EMA
function calculateEMA(data, period = 12) {
    const result = [];
    const k = 2 / (period + 1);
    let ema = data[0].close;

    for (let i = 0; i < data.length; i++) {
        ema = data[i].close * k + ema * (1 - k);
        if (i >= period - 1) {
            result.push({
                time: data[i].time,
                value: parseFloat(ema.toFixed(2)),
            });
        }
    }
    return result;
}

function initChart() {
    if (!chartContainer.value) return;

    // Clean up existing chart
    if (chart) {
        chart.remove();
        chart = null;
    }

    chart = createChart(chartContainer.value, {
        autoSize: true,
        layout: {
            background: { type: ColorType.Solid, color: 'transparent' },
            textColor: '#64748b',
            fontFamily: "'Inter', sans-serif",
            fontSize: 11,
        },
        grid: {
            vertLines: { color: 'rgba(255, 255, 255, 0.04)' },
            horzLines: { color: 'rgba(255, 255, 255, 0.04)' },
        },
        crosshair: {
            mode: CrosshairMode.Normal,
            vertLine: {
                color: 'rgba(14, 165, 233, 0.4)',
                width: 1,
                style: 2,
                labelBackgroundColor: '#0ea5e9',
            },
            horzLine: {
                color: 'rgba(14, 165, 233, 0.4)',
                width: 1,
                style: 2,
                labelBackgroundColor: '#0ea5e9',
            },
        },
        rightPriceScale: {
            borderColor: 'rgba(255, 255, 255, 0.1)',
            scaleMargins: { top: 0.1, bottom: 0.25 },
        },
        timeScale: {
            borderColor: 'rgba(255, 255, 255, 0.1)',
            timeVisible: true,
            secondsVisible: false,
        },
        handleScroll: { vertTouchDrag: false },
    });

    const candleData = generateCandleData(200);

    // Candlestick series
    candleSeries = chart.addCandlestickSeries({
        upColor: '#00C853',
        downColor: '#FF1744',
        borderUpColor: '#00C853',
        borderDownColor: '#FF1744',
        wickUpColor: '#00C853',
        wickDownColor: '#FF1744',
    });
    candleSeries.setData(candleData);

    // Line series (hidden initially)
    lineSeries = chart.addLineSeries({
        color: '#0ea5e9',
        lineWidth: 2,
        visible: chartType.value === 'line',
    });
    lineSeries.setData(candleData.map(d => ({ time: d.time, value: d.close })));

    // Volume series
    volumeSeries = chart.addHistogramSeries({
        color: '#0ea5e9',
        priceFormat: { type: 'volume' },
        priceScaleId: 'volume',
    });
    chart.priceScale('volume').applyOptions({
        scaleMargins: { top: 0.8, bottom: 0 },
    });
    volumeSeries.setData(
        candleData.map(d => ({
            time: d.time,
            value: d.volume,
            color: d.close >= d.open ? 'rgba(0, 200, 83, 0.3)' : 'rgba(255, 23, 68, 0.3)',
        }))
    );

    // Toggle visibility based on chart type
    candleSeries.applyOptions({ visible: chartType.value === 'candle' });
    lineSeries.applyOptions({ visible: chartType.value === 'line' });

    // Indicators
    updateIndicators(candleData);

    // Fit content
    chart.timeScale().fitContent();

    // Simulate realtime updates
    startRealtimeUpdates(candleData);
}

let realtimeInterval = null;
function startRealtimeUpdates(data) {
    if (realtimeInterval) clearInterval(realtimeInterval);

    let lastCandle = { ...data[data.length - 1] };

    realtimeInterval = setInterval(() => {
        const change = (Math.random() - 0.49) * 50;
        lastCandle.close = parseFloat((lastCandle.close + change).toFixed(2));
        lastCandle.high = Math.max(lastCandle.high, lastCandle.close);
        lastCandle.low = Math.min(lastCandle.low, lastCandle.close);

        if (candleSeries) candleSeries.update(lastCandle);
        if (lineSeries) lineSeries.update({ time: lastCandle.time, value: lastCandle.close });

        // Update display
        currentPrice.value = lastCandle.close.toLocaleString('en-US', { minimumFractionDigits: 2 });
        const prev = data[data.length - 2];
        if (prev) {
            const pct = ((lastCandle.close - prev.close) / prev.close * 100).toFixed(2);
            priceChange.value = (pct >= 0 ? '+' : '') + pct + '%';
            priceChangePositive.value = pct >= 0;
        }
    }, 2000);
}

function updateIndicators(data) {
    if (!chart) return;
    const candleData = data || generateCandleData(200);

    // MA
    if (maSeries) { chart.removeSeries(maSeries); maSeries = null; }
    if (activeIndicators.value.includes('MA')) {
        maSeries = chart.addLineSeries({
            color: '#f59e0b',
            lineWidth: 1,
            title: 'MA 20',
        });
        maSeries.setData(calculateMA(candleData, 20));
    }

    // EMA
    if (emaSeries) { chart.removeSeries(emaSeries); emaSeries = null; }
    if (activeIndicators.value.includes('EMA')) {
        emaSeries = chart.addLineSeries({
            color: '#a855f7',
            lineWidth: 1,
            title: 'EMA 12',
        });
        emaSeries.setData(calculateEMA(candleData, 12));
    }
}

// Watch chart type changes
watch(chartType, (newType) => {
    if (candleSeries) candleSeries.applyOptions({ visible: newType === 'candle' });
    if (lineSeries) lineSeries.applyOptions({ visible: newType === 'line' });
});

// Watch timeframe changes
watch(selectedTimeframe, () => {
    // Re-generate data for different timeframe
    if (chart) initChart();
});

onMounted(() => {
    nextTick(() => {
        initChart();
    });
});

onUnmounted(() => {
    if (realtimeInterval) clearInterval(realtimeInterval);
    if (chart) {
        chart.remove();
        chart = null;
    }
});
</script>

<template>
    <div class="chart-container flex flex-col overflow-hidden">
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
                        <p :class="['text-2xl font-bold font-mono', priceChangePositive ? 'text-trading-green' : 'text-trading-red']">
                            ${{ currentPrice }}
                        </p>
                        <p :class="['text-sm', priceChangePositive ? 'text-trading-green' : 'text-trading-red']">
                            {{ priceChange }}
                        </p>
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
                        title="Candlestick"
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
                        title="Line"
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
        <div class="flex items-center gap-1 px-4 py-2 border-b border-white/5 flex-shrink-0">
            <button
                v-for="tf in timeframes"
                :key="tf"
                @click="selectedTimeframe = tf"
                :class="['chart-timeframe-btn', { 'active': selectedTimeframe === tf }]"
            >
                {{ tf }}
            </button>
            <div class="ml-auto flex items-center gap-2">
                <span class="text-xs text-dark-500">Powered by</span>
                <span class="text-xs font-semibold text-primary-400">TradingView</span>
            </div>
        </div>

        <!-- TradingView Chart Area -->
        <div ref="chartContainer" class="flex-1 relative overflow-hidden" style="min-height: 0;"></div>
    </div>
</template>
