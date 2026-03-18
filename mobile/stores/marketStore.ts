import { create } from 'zustand';

export interface MarketPair {
  symbol: string;
  name: string;
  price: number;
  change24h: number;
  high24h: number;
  low24h: number;
  volume24h: string;
  chartData: number[];
}

interface MarketState {
  pairs: MarketPair[];
  favorites: string[];
  selectedPair: MarketPair | null;
  isLoading: boolean;
  searchQuery: string;

  setSearchQuery: (query: string) => void;
  setSelectedPair: (pair: MarketPair) => void;
  toggleFavorite: (symbol: string) => void;
  loadMockData: () => void;
}

// Generate realistic chart data
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

const MOCK_PAIRS: MarketPair[] = [
  {
    symbol: 'BTC/USDT',
    name: 'Bitcoin',
    price: 98432.50,
    change24h: 2.34,
    high24h: 99100.00,
    low24h: 95800.00,
    volume24h: '2.1B',
    chartData: generateChartData(96000, 800, 1),
  },
  {
    symbol: 'ETH/USDT',
    name: 'Ethereum',
    price: 3847.20,
    change24h: -1.12,
    high24h: 3920.00,
    low24h: 3780.00,
    volume24h: '1.4B',
    chartData: generateChartData(3850, 40, -0.5),
  },
  {
    symbol: 'BNB/USDT',
    name: 'BNB',
    price: 628.45,
    change24h: 0.87,
    high24h: 635.00,
    low24h: 618.00,
    volume24h: '342M',
    chartData: generateChartData(620, 8, 0.5),
  },
  {
    symbol: 'SOL/USDT',
    name: 'Solana',
    price: 187.32,
    change24h: 5.67,
    high24h: 192.00,
    low24h: 175.00,
    volume24h: '890M',
    chartData: generateChartData(178, 5, 1.5),
  },
  {
    symbol: 'XRP/USDT',
    name: 'Ripple',
    price: 2.48,
    change24h: -0.45,
    high24h: 2.55,
    low24h: 2.42,
    volume24h: '567M',
    chartData: generateChartData(2.45, 0.05, -0.3),
  },
  {
    symbol: 'ADA/USDT',
    name: 'Cardano',
    price: 0.892,
    change24h: 3.21,
    high24h: 0.915,
    low24h: 0.856,
    volume24h: '234M',
    chartData: generateChartData(0.86, 0.02, 1),
  },
  {
    symbol: 'DOGE/USDT',
    name: 'Dogecoin',
    price: 0.1847,
    change24h: -2.33,
    high24h: 0.1920,
    low24h: 0.1800,
    volume24h: '456M',
    chartData: generateChartData(0.19, 0.005, -1),
  },
  {
    symbol: 'AVAX/USDT',
    name: 'Avalanche',
    price: 42.67,
    change24h: 1.89,
    high24h: 43.50,
    low24h: 41.20,
    volume24h: '178M',
    chartData: generateChartData(41.5, 1, 0.8),
  },
  {
    symbol: 'DOT/USDT',
    name: 'Polkadot',
    price: 8.94,
    change24h: -0.67,
    high24h: 9.15,
    low24h: 8.78,
    volume24h: '123M',
    chartData: generateChartData(8.9, 0.15, -0.3),
  },
  {
    symbol: 'LINK/USDT',
    name: 'Chainlink',
    price: 18.23,
    change24h: 4.12,
    high24h: 18.80,
    low24h: 17.20,
    volume24h: '198M',
    chartData: generateChartData(17.5, 0.5, 1.2),
  },
  {
    symbol: 'UNI/USDT',
    name: 'Uniswap',
    price: 12.45,
    change24h: 1.34,
    high24h: 12.80,
    low24h: 12.10,
    volume24h: '89M',
    chartData: generateChartData(12.2, 0.3, 0.6),
  },
  {
    symbol: 'MATIC/USDT',
    name: 'Polygon',
    price: 1.23,
    change24h: -1.56,
    high24h: 1.28,
    low24h: 1.19,
    volume24h: '156M',
    chartData: generateChartData(1.25, 0.03, -0.5),
  },
];

export const useMarketStore = create<MarketState>((set) => ({
  pairs: [],
  favorites: ['BTC/USDT', 'ETH/USDT', 'SOL/USDT'],
  selectedPair: null,
  isLoading: false,
  searchQuery: '',

  setSearchQuery: (query) => set({ searchQuery: query }),

  setSelectedPair: (pair) => set({ selectedPair: pair }),

  toggleFavorite: (symbol) =>
    set((state) => ({
      favorites: state.favorites.includes(symbol)
        ? state.favorites.filter((s) => s !== symbol)
        : [...state.favorites, symbol],
    })),

  loadMockData: () => set({ pairs: MOCK_PAIRS, isLoading: false }),
}));
