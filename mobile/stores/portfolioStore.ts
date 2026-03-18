import { create } from 'zustand';

export interface PortfolioAsset {
  symbol: string;
  name: string;
  balance: number;
  value: number;
  price: number;
  change24h: number;
  allocation: number;
}

interface PortfolioState {
  assets: PortfolioAsset[];
  totalValue: number;
  totalChange24h: number;
  totalChangePercent: number;
  isLoading: boolean;

  loadMockData: () => void;
}

const MOCK_ASSETS: PortfolioAsset[] = [
  {
    symbol: 'BTC',
    name: 'Bitcoin',
    balance: 0.4523,
    value: 44520.14,
    price: 98432.50,
    change24h: 2.34,
    allocation: 52.3,
  },
  {
    symbol: 'ETH',
    name: 'Ethereum',
    balance: 5.234,
    value: 20144.21,
    price: 3847.20,
    change24h: -1.12,
    allocation: 23.7,
  },
  {
    symbol: 'SOL',
    name: 'Solana',
    balance: 42.5,
    value: 7961.10,
    price: 187.32,
    change24h: 5.67,
    allocation: 9.4,
  },
  {
    symbol: 'BNB',
    name: 'BNB',
    balance: 8.12,
    value: 5102.93,
    price: 628.45,
    change24h: 0.87,
    allocation: 6.0,
  },
  {
    symbol: 'USDT',
    name: 'Tether',
    balance: 4850.00,
    value: 4850.00,
    price: 1.00,
    change24h: 0.01,
    allocation: 5.7,
  },
  {
    symbol: 'LINK',
    name: 'Chainlink',
    balance: 135.5,
    value: 2470.17,
    price: 18.23,
    change24h: 4.12,
    allocation: 2.9,
  },
];

export const usePortfolioStore = create<PortfolioState>((set) => ({
  assets: [],
  totalValue: 0,
  totalChange24h: 0,
  totalChangePercent: 0,
  isLoading: false,

  loadMockData: () => {
    const totalValue = MOCK_ASSETS.reduce((sum, a) => sum + a.value, 0);
    // Compute actual 24h change from individual assets
    // คำนวณการเปลี่ยนแปลง 24h จากสินทรัพย์แต่ละตัว
    const totalChange24h = MOCK_ASSETS.reduce((sum, a) => {
      const prevValue = a.value / (1 + a.change24h / 100);
      return sum + (a.value - prevValue);
    }, 0);
    const totalChangePercent = (totalChange24h / (totalValue - totalChange24h)) * 100;

    set({
      assets: MOCK_ASSETS,
      totalValue,
      totalChange24h,
      totalChangePercent,
      isLoading: false,
    });
  },
}));
