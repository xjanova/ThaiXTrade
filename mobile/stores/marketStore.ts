/**
 * Market Store — ดึงข้อมูลราคาจริงจาก Binance + TPIX API
 * ไม่ใช้ mock data อีกต่อไป
 */

import { create } from 'zustand';
import { COIN_COLORS } from '@/components/common/CoinIcon';

const BINANCE_REST = 'https://api.binance.com/api/v3';
const API_BASE = 'https://tpixtrade.com/api/v1';

// คู่เทรดที่แสดงในแอพ (Binance symbol → display)
const TRADING_PAIRS = [
  { binance: 'BTCUSDT', symbol: 'BTC/USDT', name: 'Bitcoin', letter: 'B', colorKey: 'BTC' },
  { binance: 'ETHUSDT', symbol: 'ETH/USDT', name: 'Ethereum', letter: 'E', colorKey: 'ETH' },
  { binance: 'BNBUSDT', symbol: 'BNB/USDT', name: 'BNB', letter: 'B', colorKey: 'BNB' },
  { binance: 'SOLUSDT', symbol: 'SOL/USDT', name: 'Solana', letter: 'S', colorKey: 'SOL' },
  { binance: 'XRPUSDT', symbol: 'XRP/USDT', name: 'Ripple', letter: 'X', colorKey: 'XRP' },
  { binance: 'ADAUSDT', symbol: 'ADA/USDT', name: 'Cardano', letter: 'A', colorKey: 'ADA' },
  { binance: 'DOGEUSDT', symbol: 'DOGE/USDT', name: 'Dogecoin', letter: 'D', colorKey: 'DOGE' },
  { binance: 'AVAXUSDT', symbol: 'AVAX/USDT', name: 'Avalanche', letter: 'A', colorKey: 'AVAX' },
  { binance: 'DOTUSDT', symbol: 'DOT/USDT', name: 'Polkadot', letter: 'D', colorKey: 'DOT' },
  { binance: 'LINKUSDT', symbol: 'LINK/USDT', name: 'Chainlink', letter: 'L', colorKey: 'LINK' },
  { binance: 'UNIUSDT', symbol: 'UNI/USDT', name: 'Uniswap', letter: 'U', colorKey: 'UNI' },
  { binance: 'MATICUSDT', symbol: 'MATIC/USDT', name: 'Polygon', letter: 'M', colorKey: 'MATIC' },
];

export interface MarketPair {
  symbol: string;
  name: string;
  price: number;
  change24h: number;
  high24h: number;
  low24h: number;
  volume24h: string;
  chartData: number[];
  iconColor: string;
  iconLetter: string;
}

interface MarketState {
  pairs: MarketPair[];
  favorites: string[];
  selectedPair: MarketPair | null;
  isLoading: boolean;
  error: string | null;
  searchQuery: string;
  lastFetchedAt: number | null;

  setSearchQuery: (query: string) => void;
  setSelectedPair: (pair: MarketPair) => void;
  toggleFavorite: (symbol: string) => void;
  fetchRealData: () => Promise<void>;
  // Fallback ถ้า API ล้มเหลว
  loadMockData: () => void;
}

function formatVolume(vol: number): string {
  if (vol >= 1e9) return `${(vol / 1e9).toFixed(1)}B`;
  if (vol >= 1e6) return `${(vol / 1e6).toFixed(0)}M`;
  if (vol >= 1e3) return `${(vol / 1e3).toFixed(0)}K`;
  return vol.toFixed(0);
}

export const useMarketStore = create<MarketState>((set, get) => ({
  pairs: [],
  favorites: ['BTC/USDT', 'ETH/USDT', 'SOL/USDT'],
  selectedPair: null,
  isLoading: false,
  error: null,
  searchQuery: '',
  lastFetchedAt: null,

  setSearchQuery: (query) => set({ searchQuery: query }),

  setSelectedPair: (pair) => set({ selectedPair: pair }),

  toggleFavorite: (symbol) =>
    set((state) => ({
      favorites: state.favorites.includes(symbol)
        ? state.favorites.filter((s) => s !== symbol)
        : [...state.favorites, symbol],
    })),

  fetchRealData: async () => {
    // Throttle: ไม่ fetch ถ้า fetch ไปแล้วภายใน 5 วินาที
    const now = Date.now();
    const { lastFetchedAt } = get();
    if (lastFetchedAt && now - lastFetchedAt < 5000) return;

    set({ isLoading: true, error: null });

    try {
      // ดึง ticker 24hr จาก Binance สำหรับทุกคู่
      const symbols = TRADING_PAIRS.map((p) => p.binance);
      const url = `${BINANCE_REST}/ticker/24hr?symbols=${encodeURIComponent(JSON.stringify(symbols))}`;

      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);

      const response = await fetch(url, { signal: controller.signal });
      clearTimeout(timeout);

      if (!response.ok) throw new Error(`Binance API error: ${response.status}`);

      const tickers: Array<{
        symbol: string;
        lastPrice: string;
        priceChangePercent: string;
        highPrice: string;
        lowPrice: string;
        quoteVolume: string;
      }> = await response.json();

      // สร้าง map สำหรับ lookup
      const tickerMap = new Map(tickers.map((t) => [t.symbol, t]));

      // ดึง klines สำหรับ mini chart (24 candles, 1h interval)
      const chartPromises = TRADING_PAIRS.map(async (pair) => {
        try {
          const kRes = await fetch(
            `${BINANCE_REST}/klines?symbol=${pair.binance}&interval=1h&limit=24`,
            { signal: AbortSignal.timeout(10000) }
          );
          if (!kRes.ok) return [];
          const klines: Array<[number, string, string, string, string, ...unknown[]]> = await kRes.json();
          return klines.map((k) => parseFloat(k[4])); // close prices
        } catch {
          return [];
        }
      });

      const charts = await Promise.all(chartPromises);

      // สร้าง pairs จากข้อมูลจริง
      const pairs: MarketPair[] = TRADING_PAIRS.map((config, i) => {
        const ticker = tickerMap.get(config.binance);
        const price = ticker ? parseFloat(ticker.lastPrice) : 0;
        const change = ticker ? parseFloat(ticker.priceChangePercent) : 0;
        const high = ticker ? parseFloat(ticker.highPrice) : 0;
        const low = ticker ? parseFloat(ticker.lowPrice) : 0;
        const volume = ticker ? parseFloat(ticker.quoteVolume) : 0;

        return {
          symbol: config.symbol,
          name: config.name,
          price,
          change24h: change,
          high24h: high,
          low24h: low,
          volume24h: formatVolume(volume),
          chartData: charts[i].length > 0 ? charts[i] : [price], // fallback
          iconColor: (COIN_COLORS as Record<string, string>)[config.colorKey] || '#06b6d4',
          iconLetter: config.letter,
        };
      });

      // อัพเดท selectedPair ด้วยราคาล่าสุด
      const { selectedPair } = get();
      let updatedSelected = selectedPair;
      if (selectedPair) {
        const fresh = pairs.find((p) => p.symbol === selectedPair.symbol);
        if (fresh) updatedSelected = fresh;
      }

      set({
        pairs,
        selectedPair: updatedSelected,
        isLoading: false,
        error: null,
        lastFetchedAt: Date.now(),
      });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to fetch market data';
      set({ error: message, isLoading: false });

      // ถ้ายังไม่มี data เลย → ใช้ fallback
      if (get().pairs.length === 0) {
        get().loadMockData();
      }
    }
  },

  // Fallback mock data (ใช้เมื่อ API ล้มเหลวครั้งแรก)
  loadMockData: () => {
    const generateChartData = (base: number, volatility: number, trend: number): number[] => {
      const data: number[] = [];
      let current = base;
      for (let i = 0; i < 24; i++) {
        current += (Math.random() - 0.5 + trend * 0.1) * volatility;
        current = Math.max(current, base * 0.85);
        data.push(current);
      }
      return data;
    };

    set({
      pairs: [
        { symbol: 'BTC/USDT', name: 'Bitcoin', price: 98432.50, change24h: 2.34, high24h: 99100, low24h: 95800, volume24h: '2.1B', chartData: generateChartData(96000, 800, 1), iconColor: COIN_COLORS.BTC, iconLetter: 'B' },
        { symbol: 'ETH/USDT', name: 'Ethereum', price: 3847.20, change24h: -1.12, high24h: 3920, low24h: 3780, volume24h: '1.4B', chartData: generateChartData(3850, 40, -0.5), iconColor: COIN_COLORS.ETH, iconLetter: 'E' },
        { symbol: 'BNB/USDT', name: 'BNB', price: 628.45, change24h: 0.87, high24h: 635, low24h: 618, volume24h: '342M', chartData: generateChartData(620, 8, 0.5), iconColor: COIN_COLORS.BNB, iconLetter: 'B' },
        { symbol: 'SOL/USDT', name: 'Solana', price: 187.32, change24h: 5.67, high24h: 192, low24h: 175, volume24h: '890M', chartData: generateChartData(178, 5, 1.5), iconColor: COIN_COLORS.SOL, iconLetter: 'S' },
      ],
      isLoading: false,
      error: 'Using offline data — pull to refresh',
    });
  },
}));
