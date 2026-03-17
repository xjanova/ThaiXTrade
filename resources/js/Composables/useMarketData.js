/**
 * TPIX TRADE - useMarketData Composable
 * Fetches real market data from our backend API (which proxies Binance)
 * Developed by Xman Studio
 */

import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const REFRESH_INTERVAL = 15000; // 15 seconds

export function useMarketData() {
    const tickers = ref([]);
    const topGainers = ref([]);
    const topVolume = ref([]);
    const isLoading = ref(true);
    const error = ref(null);

    let refreshTimer = null;

    async function fetchTickers() {
        try {
            const { data } = await axios.get('/api/v1/market/tickers');
            if (data.success && data.data.length > 0) {
                tickers.value = data.data;

                // Calculate top gainers (highest positive change)
                topGainers.value = [...data.data]
                    .filter(t => parseFloat(t.priceChangePercent) > 0)
                    .sort((a, b) => parseFloat(b.priceChangePercent) - parseFloat(a.priceChangePercent))
                    .slice(0, 4)
                    .map(formatTicker);

                // Calculate top volume
                topVolume.value = [...data.data]
                    .sort((a, b) => parseFloat(b.quoteVolume) - parseFloat(a.quoteVolume))
                    .slice(0, 4)
                    .map(formatTicker);
            }
            error.value = null;
        } catch (err) {
            error.value = err.message;
        } finally {
            isLoading.value = false;
        }
    }

    function formatTicker(t) {
        const price = parseFloat(t.price);
        const change = parseFloat(t.priceChangePercent);
        const volume = parseFloat(t.quoteVolume);

        return {
            symbol: t.baseAsset,
            name: getTokenName(t.baseAsset),
            price: formatPrice(price),
            change: (change >= 0 ? '+' : '') + change.toFixed(2) + '%',
            isUp: change >= 0,
            volume: formatVolume(volume),
            marketCap: '-',
            rawPrice: price,
            rawChange: change,
            rawVolume: volume,
            high: formatPrice(parseFloat(t.high)),
            low: formatPrice(parseFloat(t.low)),
        };
    }

    function formatPrice(price) {
        if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (price >= 1) return price.toFixed(2);
        if (price >= 0.01) return price.toFixed(4);
        return price.toFixed(8);
    }

    function formatVolume(vol) {
        if (vol >= 1e12) return (vol / 1e12).toFixed(1) + 'T';
        if (vol >= 1e9) return (vol / 1e9).toFixed(1) + 'B';
        if (vol >= 1e6) return (vol / 1e6).toFixed(1) + 'M';
        if (vol >= 1e3) return (vol / 1e3).toFixed(1) + 'K';
        return vol.toFixed(2);
    }

    function startAutoRefresh() {
        refreshTimer = setInterval(fetchTickers, REFRESH_INTERVAL);
    }

    function stopAutoRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    onUnmounted(() => {
        stopAutoRefresh();
    });

    return {
        tickers,
        topGainers,
        topVolume,
        isLoading,
        error,
        fetchTickers,
        startAutoRefresh,
        stopAutoRefresh,
    };
}

/**
 * Map common symbols to full names.
 */
function getTokenName(symbol) {
    const names = {
        BTC: 'Bitcoin', ETH: 'Ethereum', BNB: 'BNB', SOL: 'Solana',
        XRP: 'XRP', ADA: 'Cardano', DOGE: 'Dogecoin', DOT: 'Polkadot',
        AVAX: 'Avalanche', MATIC: 'Polygon', LINK: 'Chainlink', UNI: 'Uniswap',
        ATOM: 'Cosmos', LTC: 'Litecoin', BCH: 'Bitcoin Cash', NEAR: 'NEAR Protocol',
        FIL: 'Filecoin', APT: 'Aptos', ARB: 'Arbitrum', OP: 'Optimism',
        PEPE: 'Pepe', BONK: 'Bonk', WIF: 'dogwifhat', FLOKI: 'Floki',
        SHIB: 'Shiba Inu', TRX: 'TRON', TON: 'Toncoin', SUI: 'Sui',
        SEI: 'Sei', INJ: 'Injective', TIA: 'Celestia', RENDER: 'Render',
        FET: 'Fetch.ai', AAVE: 'Aave', MKR: 'Maker', SNX: 'Synthetix',
    };
    return names[symbol] || symbol;
}
