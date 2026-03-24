<script setup>
/**
 * TPIX TRADE - Trading Chart Component
 * Real-time candlestick chart powered by TradingView Lightweight Charts
 * Data from Binance public API
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { createChart, ColorType, CrosshairMode, CandlestickSeries, LineSeries, HistogramSeries } from 'lightweight-charts';
import { getPairLogo, getBaseSymbol } from '@/utils/cryptoLogos';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    ticker: { type: Object, default: () => ({}) },
    isTpix: { type: Boolean, default: false },
});

const BINANCE_REST = 'https://api.binance.com/api/v3';

const chartContainer = ref(null);
const selectedTimeframe = ref('1H');
const chartType = ref('candle');
const isLoading = ref(false);

const timeframes = ['1m', '5m', '15m', '1H', '4H', '1D', '1W'];
const binanceIntervals = { '1m': '1m', '5m': '5m', '15m': '15m', '1H': '1h', '4H': '4h', '1D': '1d', '1W': '1w' };

const indicators = ref(['MA', 'EMA']);
const activeIndicators = ref(['MA']);

let chart = null;
let candleSeriesRef = null;
let lineSeriesRef = null;
let volumeSeriesRef = null;
let maSeriesRef = null;
let emaSeriesRef = null;
let klineWs = null;
let reconnectTimer = null;
let storedCandleData = [];

const binanceSymbol = computed(() => props.symbol.replace('/', ''));

// Ticker display (from parent via props)
const displayPrice = computed(() => {
    const p = props.ticker?.price;
    if (!p) return '—';
    return p >= 1 ? p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : p.toFixed(8);
});
const displayChange = computed(() => {
    const pct = props.ticker?.priceChangePercent;
    if (pct == null) return '';
    return (pct >= 0 ? '+' : '') + pct.toFixed(2) + '%';
});
const isPositive = computed(() => (props.ticker?.priceChangePercent ?? 0) >= 0);
const displayHigh = computed(() => {
    const h = props.ticker?.high;
    return h ? (h >= 1 ? h.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : h.toFixed(8)) : '—';
});
const displayLow = computed(() => {
    const l = props.ticker?.low;
    return l ? (l >= 1 ? l.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : l.toFixed(8)) : '—';
});
const displayVolume = computed(() => {
    const v = props.ticker?.volume;
    if (!v) return '—';
    if (v >= 1e9) return (v / 1e9).toFixed(2) + 'B';
    if (v >= 1e6) return (v / 1e6).toFixed(2) + 'M';
    if (v >= 1e3) return (v / 1e3).toFixed(2) + 'K';
    return v.toFixed(2);
});

const pairName = computed(() => {
    const base = getBaseSymbol(props.symbol);
    const names = { BTC: 'Bitcoin', ETH: 'Ethereum', BNB: 'BNB', SOL: 'Solana', XRP: 'XRP', ADA: 'Cardano', DOGE: 'Dogecoin', DOT: 'Polkadot' };
    return (names[base] || base) + ' / Tether';
});

const toggleIndicator = (indicator) => {
    const idx = activeIndicators.value.indexOf(indicator);
    if (idx > -1) activeIndicators.value.splice(idx, 1);
    else activeIndicators.value.push(indicator);
    updateIndicators(storedCandleData);
};

// Calculate Moving Average
function calculateMA(data, period = 20) {
    const result = [];
    for (let i = period - 1; i < data.length; i++) {
        let sum = 0;
        for (let j = 0; j < period; j++) sum += data[i - j].close;
        result.push({ time: data[i].time, value: parseFloat((sum / period).toFixed(2)) });
    }
    return result;
}

// Calculate EMA
function calculateEMA(data, period = 12) {
    if (!data.length) return [];
    const result = [];
    const k = 2 / (period + 1);
    let ema = data[0].close;
    for (let i = 0; i < data.length; i++) {
        ema = data[i].close * k + ema * (1 - k);
        if (i >= period - 1) result.push({ time: data[i].time, value: parseFloat(ema.toFixed(2)) });
    }
    return result;
}

// Fetch klines — from internal API for TPIX, from Binance for other tokens
async function fetchKlines() {
    const interval = binanceIntervals[selectedTimeframe.value] || '1h';

    try {
        let data;

        if (props.isTpix) {
            // TPIX pair: use our internal kline API
            const res = await fetch(`/api/v1/tpix/klines?interval=${interval}&limit=300`);
            if (!res.ok) throw new Error('Failed to fetch TPIX klines');
            const json = await res.json();
            data = json.data || [];
        } else {
            // Other pairs: use Binance API
            const symbol = binanceSymbol.value;
            const res = await fetch(`${BINANCE_REST}/klines?symbol=${symbol}&interval=${interval}&limit=300`);
            if (!res.ok) throw new Error('Failed to fetch klines');
            data = await res.json();
        }

        return data.map(k => ({
            time: Math.floor((Array.isArray(k) ? k[0] : k.time) / 1000),
            open: parseFloat(Array.isArray(k) ? k[1] : k.open),
            high: parseFloat(Array.isArray(k) ? k[2] : k.high),
            low: parseFloat(Array.isArray(k) ? k[3] : k.low),
            close: parseFloat(Array.isArray(k) ? k[4] : k.close),
            volume: parseFloat(Array.isArray(k) ? k[5] : k.volume),
        }));
    } catch (err) {
        return [];
    }
}

// Connect kline WebSocket for real-time candle updates
function connectKlineWS() {
    disconnectKlineWS();
    const interval = binanceIntervals[selectedTimeframe.value] || '1h';
    const stream = `${binanceSymbol.value.toLowerCase()}@kline_${interval}`;

    klineWs = new WebSocket(`wss://stream.binance.com:9443/ws/${stream}`);

    klineWs.onmessage = (event) => {
        try {
            const msg = JSON.parse(event.data);
            const k = msg.k;
            if (!k) return;

            const candle = {
                time: Math.floor(k.t / 1000),
                open: parseFloat(k.o),
                high: parseFloat(k.h),
                low: parseFloat(k.l),
                close: parseFloat(k.c),
                volume: parseFloat(k.v),
            };

            // Update chart series
            if (candleSeriesRef) candleSeriesRef.update(candle);
            if (lineSeriesRef) lineSeriesRef.update({ time: candle.time, value: candle.close });
            if (volumeSeriesRef) volumeSeriesRef.update({
                time: candle.time,
                value: candle.volume,
                color: candle.close >= candle.open ? 'rgba(0, 200, 83, 0.3)' : 'rgba(255, 23, 68, 0.3)',
            });

            // Update stored data for indicator recalculation
            if (storedCandleData.length > 0) {
                const lastIdx = storedCandleData.length - 1;
                if (storedCandleData[lastIdx].time === candle.time) {
                    storedCandleData[lastIdx] = candle;
                } else {
                    storedCandleData.push(candle);
                }
            }
        } catch { /* ignore */ }
    };

    klineWs.onclose = () => {
        reconnectTimer = setTimeout(connectKlineWS, 5000);
    };
    klineWs.onerror = () => { try { klineWs?.close(); } catch { /* */ } };
}

function disconnectKlineWS() {
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
    if (klineWs) { try { klineWs.close(); } catch { /* */ } klineWs = null; }
}

async function initChart() {
    if (!chartContainer.value) return;

    // Clean up
    if (chart) { chart.remove(); chart = null; }
    candleSeriesRef = null;
    lineSeriesRef = null;
    volumeSeriesRef = null;
    maSeriesRef = null;
    emaSeriesRef = null;

    isLoading.value = true;

    // Fetch real data
    const candleData = await fetchKlines();
    storedCandleData = candleData;

    if (!candleData.length || !chartContainer.value) {
        isLoading.value = false;
        return;
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
            vertLine: { color: 'rgba(14, 165, 233, 0.4)', width: 1, style: 2, labelBackgroundColor: '#0ea5e9' },
            horzLine: { color: 'rgba(14, 165, 233, 0.4)', width: 1, style: 2, labelBackgroundColor: '#0ea5e9' },
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

    // Candlestick series
    candleSeriesRef = chart.addSeries(CandlestickSeries, {
        upColor: '#00C853',
        downColor: '#FF1744',
        borderUpColor: '#00C853',
        borderDownColor: '#FF1744',
        wickUpColor: '#00C853',
        wickDownColor: '#FF1744',
    });
    candleSeriesRef.setData(candleData);

    // Line series (hidden initially)
    lineSeriesRef = chart.addSeries(LineSeries, {
        color: '#0ea5e9',
        lineWidth: 2,
        visible: chartType.value === 'line',
    });
    lineSeriesRef.setData(candleData.map(d => ({ time: d.time, value: d.close })));

    // Volume series
    volumeSeriesRef = chart.addSeries(HistogramSeries, {
        color: '#0ea5e9',
        priceFormat: { type: 'volume' },
        priceScaleId: 'volume',
    });
    chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });
    volumeSeriesRef.setData(
        candleData.map(d => ({
            time: d.time,
            value: d.volume,
            color: d.close >= d.open ? 'rgba(0, 200, 83, 0.3)' : 'rgba(255, 23, 68, 0.3)',
        }))
    );

    // Toggle visibility
    candleSeriesRef.applyOptions({ visible: chartType.value === 'candle' });
    lineSeriesRef.applyOptions({ visible: chartType.value === 'line' });

    // Indicators
    updateIndicators(candleData);

    chart.timeScale().fitContent();
    isLoading.value = false;

    // Connect WebSocket for real-time kline updates
    connectKlineWS();
}

function updateIndicators(data) {
    if (!chart || !data?.length) return;

    if (maSeriesRef) { chart.removeSeries(maSeriesRef); maSeriesRef = null; }
    if (activeIndicators.value.includes('MA')) {
        maSeriesRef = chart.addSeries(LineSeries, { color: '#f59e0b', lineWidth: 1, title: 'MA 20' });
        maSeriesRef.setData(calculateMA(data, 20));
    }

    if (emaSeriesRef) { chart.removeSeries(emaSeriesRef); emaSeriesRef = null; }
    if (activeIndicators.value.includes('EMA')) {
        emaSeriesRef = chart.addSeries(LineSeries, { color: '#a855f7', lineWidth: 1, title: 'EMA 12' });
        emaSeriesRef.setData(calculateEMA(data, 12));
    }
}

// Watch chart type changes
watch(chartType, (newType) => {
    if (candleSeriesRef) candleSeriesRef.applyOptions({ visible: newType === 'candle' });
    if (lineSeriesRef) lineSeriesRef.applyOptions({ visible: newType === 'line' });
});

// Watch timeframe changes - re-fetch real data
watch(selectedTimeframe, () => {
    if (chart) initChart();
});

onMounted(() => {
    nextTick(() => initChart());
});

onUnmounted(() => {
    disconnectKlineWS();
    if (chart) { chart.remove(); chart = null; }
});
</script>

<template>
    <div class="chart-container flex flex-col overflow-hidden">
        <!-- Chart Header -->
        <div class="chart-toolbar flex-shrink-0">
            <div class="flex items-center gap-4 min-w-0">
                <!-- Symbol Info -->
                <div class="flex items-center gap-3 flex-shrink-0">
                    <div class="w-8 h-8 rounded-lg overflow-hidden bg-dark-800 flex items-center justify-center">
                        <img v-if="getPairLogo(symbol)" :src="getPairLogo(symbol)" :alt="getBaseSymbol(symbol)" class="w-7 h-7" />
                        <span v-else class="text-white font-bold text-xs">{{ getBaseSymbol(symbol).charAt(0) }}</span>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white leading-tight">{{ symbol }}</h2>
                        <p class="text-xs text-dark-400 leading-tight">{{ pairName }}</p>
                    </div>
                </div>

                <!-- Price Display -->
                <div class="hidden md:flex items-center gap-4 ml-4 pl-4 border-l border-white/10 min-w-0">
                    <div class="flex-shrink-0">
                        <p :class="['text-xl font-bold font-mono leading-tight', isPositive ? 'text-trading-green' : 'text-trading-red']">
                            ${{ displayPrice }}
                        </p>
                        <p :class="['text-xs leading-tight', isPositive ? 'text-trading-green' : 'text-trading-red']">
                            {{ displayChange }}
                        </p>
                    </div>
                    <div class="hidden xl:grid grid-cols-3 gap-3 text-xs">
                        <div>
                            <p class="text-dark-400">24h High</p>
                            <p class="text-white font-mono">${{ displayHigh }}</p>
                        </div>
                        <div>
                            <p class="text-dark-400">24h Low</p>
                            <p class="text-white font-mono">${{ displayLow }}</p>
                        </div>
                        <div>
                            <p class="text-dark-400">Volume</p>
                            <p class="text-white font-mono">${{ displayVolume }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                <!-- Chart Type -->
                <div class="flex items-center gap-1 p-1 rounded-lg bg-dark-800">
                    <button
                        @click="chartType = 'candle'"
                        :class="['p-1.5 rounded-lg transition-all', chartType === 'candle' ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white']"
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
                        :class="['p-1.5 rounded-lg transition-all', chartType === 'line' ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white']"
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
                        :class="['px-2 py-1 text-xs font-medium rounded-lg transition-all', activeIndicators.includes(indicator) ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5']"
                    >
                        {{ indicator }}
                    </button>
                </div>
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
                <span v-if="isLoading" class="text-xs text-dark-500 animate-pulse">Loading...</span>
                <span class="text-xs text-dark-500">Powered by</span>
                <span class="text-xs font-semibold text-primary-400">TradingView</span>
            </div>
        </div>

        <!-- Chart Area -->
        <div ref="chartContainer" class="flex-1 relative overflow-hidden" style="min-height: 0;"></div>
    </div>
</template>
