<script setup>
/**
 * TPIX TRADE - Trading Chart Component
 * Real-time candlestick chart powered by TradingView Lightweight Charts
 * Data from Binance public API
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { createChart, ColorType, CrosshairMode, CandlestickSeries, LineSeries, HistogramSeries, LineStyle, createSeriesMarkers } from 'lightweight-charts';
import { getPairLogo, getBaseSymbol } from '@/utils/cryptoLogos';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    ticker: { type: Object, default: () => ({}) },
    isTpix: { type: Boolean, default: false },
    /**
     * URL แท่งเทียนจากเซิร์ฟเวอร์ของเราเอง (คู่บน TPIX DEX) — ถ้าตั้งไว้จะไม่ไปถาม Binance
     * รับ ?interval=&limit= ต่อท้ายให้เอง และไม่เปิด WebSocket ของ Binance
     */
    klinesUrl: { type: String, default: '' },
    /**
     * ไม้ที่ต้องปักบนกราฟ — ทั้งของบอทและที่ผู้ใช้วางเอง
     *
     * รูปแบบ: { time (วินาที), side: 'buy'|'sell', price, source: 'bot'|'mine', label? }
     * ตัวกราฟไม่รู้ว่าไม้มาจากไหน ผู้เรียกเป็นคนรวมรายการมาให้ครบแล้ว
     */
    markers: { type: Array, default: () => [] },
    /**
     * เส้นราคาแนวนอนของบอท — ต้นทุน / SL / TP ของไม้ที่บอทถืออยู่ในคู่นี้
     *
     * รูปแบบ: { price, color, title, style: 'dashed'|'dotted'|'solid', lineWidth? }
     * วาดเฉพาะตอนเปิดโหมดบอท ไม่งั้นกราฟรกสำหรับคนที่เทรดเองอย่างเดียว
     */
    priceLines: { type: Array, default: () => [] },
    /**
     * โหมดบอท — เจ้าของสั่ง "การเข้าไม้ ออกไม้ ต้องแสดงชัดเจนในเส้นกราฟเมื่อเปิดโหมดบอท"
     * เปิดแล้วป้ายของบอทใหญ่ขึ้นและมีข้อความ (ซื้อ $20 / ขาย +0.35) + วาดเส้นราคาของบอท
     * ปิดแล้วป้ายบอทยังอยู่แต่เล็กเงียบ เพื่อไม่บังไม้ที่ผู้ใช้วางเอง
     */
    botMode: { type: Boolean, default: false },
});

const emit = defineEmits(['update:botMode']);
const { t } = useTranslation();

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
let markersApi = null;
let priceLineRefs = [];

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
// 24h high/low/volume แสดงบนแถบหัวของหน้าเทรดแล้ว จึงไม่คำนวณซ้ำที่นี่

/**
 * สถิติ 24 ชั่วโมง — ย้ายมาจากแถบหัวของหน้าเทรดที่ถูกตัดออกไปแล้ว
 * วางไว้แถวเดียวกับ timeframe จึงไม่กินพื้นที่แนวตั้งเพิ่มแม้แต่พิกเซลเดียว
 */
const stats24h = computed(() => {
    const t = props.ticker || {};
    const num = (...candidates) => {
        for (const c of candidates) {
            const n = parseFloat(c);
            if (Number.isFinite(n) && n !== 0) return n;
        }
        return null;
    };

    return {
        high: num(t.highPrice, t.high),
        low: num(t.lowPrice, t.low),
        volume: num(t.volume, t.quoteVolume),
    };
});

function compactNumber(value) {
    if (value === null) return '—';
    return value.toLocaleString('en-US', { notation: 'compact', maximumFractionDigits: 1 });
}

function priceLabel(value) {
    if (value === null) return '—';
    return '$' + value.toLocaleString('en-US', { maximumFractionDigits: value >= 1 ? 2 : 6 });
}

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

        if (props.klinesUrl) {
            // คู่บน TPIX DEX: แท่งจากราคาพูลที่เซิร์ฟเวอร์เก็บทุกนาที
            const sep = props.klinesUrl.includes('?') ? '&' : '?';
            const res = await fetch(`${props.klinesUrl}${sep}interval=${interval}&limit=300`);
            if (!res.ok) throw new Error('Failed to fetch DEX klines');
            const json = await res.json();
            data = json.data || [];
        } else if (props.isTpix) {
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

    if (!chartContainer.value) {
        isLoading.value = false;
        return;
    }

    // No data: generate flat line at current price (or $0.18 default for TPIX)
    if (!candleData.length) {
        const basePrice = props.ticker?.price || props.ticker?.lastPrice || 0.18;
        const now = Math.floor(Date.now() / 1000);
        for (let i = 299; i >= 0; i--) {
            candleData.push({
                time: now - (i * 3600),
                open: basePrice,
                high: basePrice,
                low: basePrice,
                close: basePrice,
                volume: 0,
            });
        }
        storedCandleData = candleData;
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

    /*
     * ป้ายไม้บนกราฟ — ผูกกับซีรีส์แท่งเทียนเสมอ แม้ตอนนั้นจะแสดงแบบเส้นอยู่
     *
     * v5 ย้าย marker ออกจาก series API มาเป็นปลั๊กอินแยก (createSeriesMarkers)
     * ผูกไว้กับซีรีส์เดียวตายตัวเพื่อไม่ให้ป้ายหายตอนผู้ใช้สลับ candle/line
     */
    markersApi = createSeriesMarkers(candleSeriesRef, buildMarkers());
    applyPriceLines();

    // Toggle visibility
    candleSeriesRef.applyOptions({ visible: chartType.value === 'candle' });
    lineSeriesRef.applyOptions({ visible: chartType.value === 'line' });

    // Indicators
    updateIndicators(candleData);

    chart.timeScale().fitContent();
    isLoading.value = false;

    // Connect WebSocket for real-time kline updates — เฉพาะคู่ที่ราคามาจาก Binance
    // คู่บน TPIX DEX / TPIX ภายใน ไม่มีสตรีมของ Binance → รีเฟรชแท่งจากเซิร์ฟเวอร์เป็นรอบแทน
    if (props.klinesUrl || props.isTpix) {
        startInternalPolling();
    } else {
        connectKlineWS();
    }
}

let internalPollTimer = null;

function startInternalPolling() {
    stopInternalPolling();
    internalPollTimer = setInterval(async () => {
        if (!candleSeriesRef) return;
        const data = await fetchKlines();
        if (!data.length) return;
        const last = data[data.length - 1];
        try {
            candleSeriesRef.update(chartType.value === 'candle'
                ? last
                : { time: last.time, value: last.close });
        } catch {
            // แท่งย้อนเวลากว่าตัวสุดท้ายที่วาดไว้ — ปล่อยรอบหน้า
        }
    }, 30_000);
}

function stopInternalPolling() {
    if (internalPollTimer) clearInterval(internalPollTimer);
    internalPollTimer = null;
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
/**
 * แปลงไม้ที่ส่งเข้ามาเป็นป้ายของ lightweight-charts.
 *
 * จัดเรียงตามเวลาเสมอ — ปลั๊กอิน marker ของ v5 ต้องการลำดับเวลาจากน้อยไปมาก
 * ไม่งั้นมันจะโยน error แล้วป้ายหายทั้งชุด (ไม่ใช่แค่ใบที่ผิดลำดับ)
 *
 * แยกสีตามที่มา: ไม้ของบอทใช้สีฟ้า/ม่วงของแบรนด์ · ไม้ที่เราวางเองใช้เขียว/แดง
 * เพื่อให้มองแวบเดียวก็รู้ว่าอันไหนบอททำ อันไหนเราทำเอง
 */
function buildMarkers() {
    return [...(props.markers || [])]
        .filter(m => m && Number.isFinite(Number(m.time)))
        .sort((a, b) => Number(a.time) - Number(b.time))
        .map((m) => {
            const isBuy = String(m.side).toLowerCase() === 'buy';
            const isBot = m.source === 'bot';
            // โหมดบอท: ป้ายของบอทต้องอ่านออกจากระยะไกล — ใหญ่ขึ้นและบอกว่าซื้อ/ขายเท่าไหร่
            const emphasize = isBot && props.botMode;

            return {
                time: Math.floor(Number(m.time)),
                position: isBuy ? 'belowBar' : 'aboveBar',
                shape: isBuy ? 'arrowUp' : 'arrowDown',
                color: isBot
                    ? (isBuy ? '#38bdf8' : '#c084fc')
                    : (isBuy ? '#00C853' : '#FF1744'),
                text: emphasize
                    ? (m.label || t(isBuy ? 'aiTrade.botBuy' : 'aiTrade.botSell'))
                    : (isBot ? '' : (m.label || '')),
                size: emphasize ? 2 : 1,
            };
        });
}

function refreshMarkers() {
    if (markersApi) markersApi.setMarkers(buildMarkers());
}

/**
 * เส้นราคาของบอท — ต้นทุน / SL / TP ของไม้ที่ถืออยู่.
 *
 * ลบของเก่าทิ้งก่อนวาดใหม่ทุกครั้ง ไม่งั้นทุกครั้งที่รายการบอทรีเฟรช (ทุก ~30 วิ)
 * เส้นจะซ้อนทับกันเพิ่มขึ้นเรื่อยๆ จนกราฟทึบ
 */
function applyPriceLines() {
    if (!candleSeriesRef) return;

    priceLineRefs.forEach((ref) => {
        try { candleSeriesRef.removePriceLine(ref); } catch (_) { /* ซีรีส์ถูกสร้างใหม่ไปแล้ว */ }
    });
    priceLineRefs = [];

    if (!props.botMode) return;

    const styles = { dashed: LineStyle.Dashed, dotted: LineStyle.Dotted, solid: LineStyle.Solid };

    (props.priceLines || []).forEach((line) => {
        const price = Number(line.price);
        if (!Number.isFinite(price) || price <= 0) return;

        priceLineRefs.push(candleSeriesRef.createPriceLine({
            price,
            color: line.color || '#38bdf8',
            lineWidth: line.lineWidth || 1,
            lineStyle: styles[line.style] ?? LineStyle.Dashed,
            axisLabelVisible: true,
            title: line.title || '',
        }));
    });
}

// ไม้ใหม่เข้ามา (บอทเพิ่งวาง หรือเราเพิ่งกดเอง) ต้องโผล่บนกราฟทันที
watch(() => props.markers, refreshMarkers, { deep: true });
watch(() => props.priceLines, applyPriceLines, { deep: true });
watch(() => props.botMode, () => { refreshMarkers(); applyPriceLines(); });

watch(chartType, (newType) => {
    if (candleSeriesRef) candleSeriesRef.applyOptions({ visible: newType === 'candle' });
    if (lineSeriesRef) lineSeriesRef.applyOptions({ visible: newType === 'line' });
});

// Watch timeframe changes - re-fetch real data
watch(() => props.klinesUrl, () => { initChart(); });

watch(selectedTimeframe, () => {
    if (chart) initChart();
});

onMounted(() => {
    nextTick(() => initChart());
});

onUnmounted(() => {
    markersApi = null;
    priceLineRefs = [];
    disconnectKlineWS();
    stopInternalPolling();
    if (chart) { chart.remove(); chart = null; }
});
</script>

<template>
    <!-- ไม่มีกรอบ/พื้นหลังของตัวเอง — การ์ดที่ครอบอยู่ (DraggableCard) เป็นคนวาดให้
         ไม่งั้นจะเห็นขอบกระจกซ้อนกันสองชั้น -->
    <div class="flex flex-col overflow-hidden min-h-0">
        <!-- แถบเครื่องมือแถวเดียว — รวมคู่เทรด ราคา timeframe อินดิเคเตอร์ และสถิติ 24 ชม.
             แถบหัวของหน้าเทรดถูกตัดออกแล้ว ข้อมูลทั้งหมดจึงมารวมอยู่แถวนี้แถวเดียว -->
        <div class="flex items-center gap-2 px-3 py-1.5 border-b border-white/5 flex-shrink-0 flex-wrap">
            <div class="flex items-center gap-2 flex-shrink-0">
                <div class="w-6 h-6 rounded-md overflow-hidden bg-dark-800 flex items-center justify-center">
                    <img v-if="getPairLogo(symbol)" :src="getPairLogo(symbol)" :alt="getBaseSymbol(symbol)" class="w-5 h-5" />
                    <span v-else class="text-white font-bold text-[10px]">{{ getBaseSymbol(symbol).charAt(0) }}</span>
                </div>
                <div class="leading-none">
                    <h2 class="text-xs font-bold text-white">{{ symbol }}</h2>
                    <p class="text-[10px] text-dark-500 hidden sm:block">{{ pairName }}</p>
                </div>
                <p :class="['ml-1 text-sm font-bold font-mono', isPositive ? 'text-trading-green' : 'text-trading-red']">
                    ${{ displayPrice }}
                    <span class="text-[10px] font-medium">{{ displayChange }}</span>
                </p>
            </div>

            <!-- Timeframes -->
            <div class="flex items-center gap-0.5 flex-wrap">
                <button
                    v-for="tf in timeframes"
                    :key="tf"
                    type="button"
                    :class="['px-1.5 py-0.5 text-[11px] font-medium rounded-md transition-all',
                        selectedTimeframe === tf ? 'text-primary-400 bg-primary-500/10' : 'text-dark-400 hover:text-white hover:bg-white/5']"
                    @click="selectedTimeframe = tf"
                >
                    {{ tf }}
                </button>
            </div>

            <!-- สถิติ 24 ชม. — โชว์เฉพาะจอกว้างพอ ไม่งั้นแถบตกบรรทัดแล้วกินพื้นที่กราฟ -->
            <div class="hidden 2xl:flex items-center gap-3 text-[10px] font-mono flex-shrink-0">
                <span class="text-dark-500">
                    H <span class="text-dark-200">{{ priceLabel(stats24h.high) }}</span>
                </span>
                <span class="text-dark-500">
                    L <span class="text-dark-200">{{ priceLabel(stats24h.low) }}</span>
                </span>
                <span class="text-dark-500">
                    V <span class="text-dark-200">{{ compactNumber(stats24h.volume) }}</span>
                </span>
            </div>

            <div class="ml-auto flex items-center gap-1.5 flex-shrink-0">
                <span v-if="isLoading" class="text-[10px] text-dark-500 animate-pulse">Loading…</span>

                <!-- โหมดบอท — เน้นป้ายเข้า/ออกไม้ของบอท + เส้นต้นทุน/SL/TP ของไม้ที่ถืออยู่ -->
                <button
                    type="button"
                    :title="t('aiTrade.botModeHint')"
                    :aria-pressed="botMode ? 'true' : 'false'"
                    :class="['px-1.5 py-0.5 text-[10px] font-medium rounded-md transition-all flex items-center gap-1',
                        botMode ? 'bg-sky-500/20 text-sky-300' : 'text-dark-400 hover:text-white hover:bg-white/5']"
                    @click="emit('update:botMode', !botMode)"
                >
                    <span aria-hidden="true">🤖</span>{{ t('aiTrade.botMode') }}
                </button>

                <!-- Indicators -->
                <button
                    v-for="indicator in indicators"
                    :key="indicator"
                    type="button"
                    :class="['px-1.5 py-0.5 text-[10px] font-medium rounded-md transition-all',
                        activeIndicators.includes(indicator) ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5']"
                    @click="toggleIndicator(indicator)"
                >
                    {{ indicator }}
                </button>

                <!-- Chart Type -->
                <div class="flex items-center gap-0.5 p-0.5 rounded-lg bg-dark-800">
                    <button
                        type="button"
                        title="Candlestick"
                        aria-label="กราฟแท่งเทียน"
                        :class="['p-1 rounded-md transition-all', chartType === 'candle' ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white']"
                        @click="chartType = 'candle'"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <rect x="3" y="6" width="4" height="12" rx="1" />
                            <rect x="10" y="3" width="4" height="18" rx="1" />
                            <rect x="17" y="8" width="4" height="8" rx="1" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        title="Line"
                        aria-label="กราฟเส้น"
                        :class="['p-1 rounded-md transition-all', chartType === 'line' ? 'bg-primary-500/20 text-primary-400' : 'text-dark-400 hover:text-white']"
                        @click="chartType = 'line'"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Chart Area -->
        <div ref="chartContainer" class="flex-1 relative overflow-hidden" style="min-height: 0;"></div>

        <!-- คำอธิบายสัญลักษณ์ของบอท — โผล่เฉพาะโหมดบอท ไม่รับคลิกจึงไม่บังการลากกราฟ
             (รูทของคอมโพเนนต์เป็น relative จากการ์ดที่ครอบอยู่) -->
        <div
            v-if="botMode"
            class="absolute left-2 bottom-7 z-10 pointer-events-none px-2 py-1 rounded-md bg-dark-900/75 border border-white/5 text-[10px] text-dark-300 font-mono"
        >
            {{ t('aiTrade.legendBot') }}
        </div>
    </div>
</template>
