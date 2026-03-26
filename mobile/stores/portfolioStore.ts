/**
 * Portfolio Store — ดึงข้อมูลจริงจาก wallet + market prices
 * ใช้ Binance price สำหรับคำนวณมูลค่า
 */

import { create } from 'zustand';
import { useMarketStore } from './marketStore';

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
  error: string | null;

  fetchRealPortfolio: () => void;
  loadMockData: () => void;
}

// ยอดคงเหลือจำลอง (จะถูกแทนที่ด้วยข้อมูลจาก wallet จริงเมื่อเชื่อมต่อแล้ว)
const DEMO_BALANCES: Record<string, { balance: number; name: string }> = {
  BTC: { balance: 0.4523, name: 'Bitcoin' },
  ETH: { balance: 5.234, name: 'Ethereum' },
  SOL: { balance: 42.5, name: 'Solana' },
  BNB: { balance: 8.12, name: 'BNB' },
  USDT: { balance: 4850.00, name: 'Tether' },
  LINK: { balance: 135.5, name: 'Chainlink' },
};

export const usePortfolioStore = create<PortfolioState>((set) => ({
  assets: [],
  totalValue: 0,
  totalChange24h: 0,
  totalChangePercent: 0,
  isLoading: false,
  error: null,

  fetchRealPortfolio: () => {
    set({ isLoading: true });

    // ดึงราคาจริงจาก market store
    const marketPairs = useMarketStore.getState().pairs;

    // สร้าง price map จากข้อมูลตลาด
    const priceMap = new Map<string, { price: number; change24h: number }>();
    for (const pair of marketPairs) {
      const base = pair.symbol.split('/')[0];
      priceMap.set(base, { price: pair.price, change24h: pair.change24h });
    }
    // USDT = $1
    if (!priceMap.has('USDT')) {
      priceMap.set('USDT', { price: 1.0, change24h: 0.01 });
    }

    const assets: PortfolioAsset[] = [];
    let totalValue = 0;

    for (const [symbol, info] of Object.entries(DEMO_BALANCES)) {
      const market = priceMap.get(symbol);
      const price = market?.price ?? 0;
      const change24h = market?.change24h ?? 0;
      const value = info.balance * price;
      totalValue += value;

      assets.push({
        symbol,
        name: info.name,
        balance: info.balance,
        value,
        price,
        change24h,
        allocation: 0, // คำนวณทีหลัง
      });
    }

    // คำนวณ allocation + total change
    let totalChange24h = 0;
    for (const asset of assets) {
      asset.allocation = totalValue > 0 ? (asset.value / totalValue) * 100 : 0;
      const prevValue = asset.value / (1 + asset.change24h / 100);
      totalChange24h += asset.value - prevValue;
    }

    // เรียงตามมูลค่า
    assets.sort((a, b) => b.value - a.value);

    const prevTotalValue = totalValue - totalChange24h;
    const totalChangePercent = prevTotalValue > 0
      ? (totalChange24h / prevTotalValue) * 100
      : 0;

    set({
      assets,
      totalValue,
      totalChange24h,
      totalChangePercent,
      isLoading: false,
      error: null,
    });
  },

  // Fallback mock data (ใช้เมื่อยังไม่มีข้อมูลราคาจริง)
  loadMockData: () => {
    const mockAssets: PortfolioAsset[] = [
      { symbol: 'BTC', name: 'Bitcoin', balance: 0.4523, value: 44520.14, price: 98432.50, change24h: 2.34, allocation: 52.3 },
      { symbol: 'ETH', name: 'Ethereum', balance: 5.234, value: 20144.21, price: 3847.20, change24h: -1.12, allocation: 23.7 },
      { symbol: 'SOL', name: 'Solana', balance: 42.5, value: 7961.10, price: 187.32, change24h: 5.67, allocation: 9.4 },
      { symbol: 'BNB', name: 'BNB', balance: 8.12, value: 5102.93, price: 628.45, change24h: 0.87, allocation: 6.0 },
      { symbol: 'USDT', name: 'Tether', balance: 4850.00, value: 4850.00, price: 1.00, change24h: 0.01, allocation: 5.7 },
      { symbol: 'LINK', name: 'Chainlink', balance: 135.5, value: 2470.17, price: 18.23, change24h: 4.12, allocation: 2.9 },
    ];
    const totalValue = mockAssets.reduce((sum, a) => sum + a.value, 0);
    const totalChange24h = mockAssets.reduce((sum, a) => {
      const prevValue = a.value / (1 + a.change24h / 100);
      return sum + (a.value - prevValue);
    }, 0);
    const prevTotalValue = totalValue - totalChange24h;
    const totalChangePercent = prevTotalValue > 0 ? (totalChange24h / prevTotalValue) * 100 : 0;

    set({ assets: mockAssets, totalValue, totalChange24h, totalChangePercent, isLoading: false });
  },
}));
