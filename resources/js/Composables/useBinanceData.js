/**
 * TPIX TRADE - useBinanceData Composable
 * Real-time market data from Binance public API
 * Handles ticker, order book depth, and recent trades
 * Developed by Xman Studio
 */

import { ref, onUnmounted } from 'vue';

const BINANCE_REST = 'https://api.binance.com/api/v3';
const BINANCE_WS = 'wss://stream.binance.com:9443/stream';

export function useBinanceData(getBinanceSymbol) {
    const ticker = ref({
        price: 0,
        priceChange: 0,
        priceChangePercent: 0,
        high: 0,
        low: 0,
        volume: 0,
    });

    const asks = ref([]);
    const bids = ref([]);
    const trades = ref([]);
    const isLoading = ref(true);
    const error = ref(null);

    let ws = null;
    let reconnectTimer = null;

    function getSymbol() {
        return typeof getBinanceSymbol === 'function'
            ? getBinanceSymbol()
            : getBinanceSymbol;
    }

    function formatPrice(price) {
        if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (price >= 1) return price.toFixed(2);
        if (price >= 0.01) return price.toFixed(4);
        return price.toFixed(8);
    }

    function processDepth(rawAsks, rawBids) {
        const allTotals = [
            ...rawAsks.map(([p, q]) => parseFloat(p) * parseFloat(q)),
            ...rawBids.map(([p, q]) => parseFloat(p) * parseFloat(q)),
        ];
        const maxTotal = Math.max(...allTotals, 1);

        asks.value = rawAsks.slice(0, 12).map(([price, qty]) => {
            const p = parseFloat(price);
            const q = parseFloat(qty);
            return {
                price: p,
                priceFormatted: formatPrice(p),
                amount: q,
                total: p * q,
                depth: Math.min(100, ((p * q) / maxTotal) * 100),
            };
        });

        bids.value = rawBids.slice(0, 12).map(([price, qty]) => {
            const p = parseFloat(price);
            const q = parseFloat(qty);
            return {
                price: p,
                priceFormatted: formatPrice(p),
                amount: q,
                total: p * q,
                depth: Math.min(100, ((p * q) / maxTotal) * 100),
            };
        });
    }

    async function fetchInitialData() {
        const symbol = getSymbol();
        isLoading.value = true;
        error.value = null;

        try {
            const [tickerRes, depthRes, tradesRes] = await Promise.all([
                fetch(`${BINANCE_REST}/ticker/24hr?symbol=${symbol}`),
                fetch(`${BINANCE_REST}/depth?symbol=${symbol}&limit=12`),
                fetch(`${BINANCE_REST}/trades?symbol=${symbol}&limit=20`),
            ]);

            if (!tickerRes.ok || !depthRes.ok || !tradesRes.ok) {
                throw new Error('Failed to fetch market data');
            }

            const tickerData = await tickerRes.json();
            const depthData = await depthRes.json();
            const tradesData = await tradesRes.json();

            // Process ticker
            ticker.value = {
                price: parseFloat(tickerData.lastPrice),
                priceChange: parseFloat(tickerData.priceChange),
                priceChangePercent: parseFloat(tickerData.priceChangePercent),
                high: parseFloat(tickerData.highPrice),
                low: parseFloat(tickerData.lowPrice),
                volume: parseFloat(tickerData.quoteVolume),
            };

            // Process order book
            processDepth(depthData.asks, depthData.bids);

            // Process trades
            trades.value = tradesData.reverse().map(t => ({
                id: t.id,
                price: parseFloat(t.price),
                priceFormatted: formatPrice(parseFloat(t.price)),
                amount: parseFloat(t.qty),
                time: new Date(t.time).toLocaleTimeString('en-US', { hour12: false }),
                isBuy: !t.isBuyerMaker,
            }));

            isLoading.value = false;
        } catch (err) {
            error.value = err.message;
            isLoading.value = false;
        }
    }

    function connectWebSocket() {
        disconnectWebSocket();

        const symbol = getSymbol().toLowerCase();
        const streams = [
            `${symbol}@ticker`,
            `${symbol}@depth20@1000ms`,
            `${symbol}@trade`,
        ];

        ws = new WebSocket(`${BINANCE_WS}?streams=${streams.join('/')}`);

        ws.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                const { stream, data } = msg;

                if (stream?.includes('@ticker')) {
                    ticker.value = {
                        price: parseFloat(data.c),
                        priceChange: parseFloat(data.p),
                        priceChangePercent: parseFloat(data.P),
                        high: parseFloat(data.h),
                        low: parseFloat(data.l),
                        volume: parseFloat(data.q),
                    };
                }

                if (stream?.includes('@depth')) {
                    processDepth(data.asks, data.bids);
                }

                if (stream?.endsWith('@trade')) {
                    const trade = {
                        id: data.t,
                        price: parseFloat(data.p),
                        priceFormatted: formatPrice(parseFloat(data.p)),
                        amount: parseFloat(data.q),
                        time: new Date(data.T).toLocaleTimeString('en-US', { hour12: false }),
                        isBuy: !data.m,
                    };
                    trades.value = [trade, ...trades.value.slice(0, 19)];
                }
            } catch { /* ignore parse errors */ }
        };

        ws.onclose = () => {
            reconnectTimer = setTimeout(connectWebSocket, 5000);
        };

        ws.onerror = () => {
            try { ws?.close(); } catch { /* */ }
        };
    }

    function disconnectWebSocket() {
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        if (ws) {
            try { ws.close(); } catch { /* */ }
            ws = null;
        }
    }

    onUnmounted(() => {
        disconnectWebSocket();
    });

    return {
        ticker,
        asks,
        bids,
        trades,
        isLoading,
        error,
        formatPrice,
        fetchInitialData,
        connectWebSocket,
        disconnectWebSocket,
    };
}
