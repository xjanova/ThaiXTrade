/**
 * Portfolio Store v2 — ดึง balance จริงจาก API + fallback demo
 * ใช้ Binance + tpix.online price สำหรับคำนวณมูลค่า
 * Developed by Xman Studio
 */

import { create } from 'zustand';
import { useMarketStore } from './marketStore';
import { useWalletStore } from './walletStore';
import api from '@/services/api';

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
  isDemo: boolean;

  fetchRealPortfolio: () => Promise<void>;
  loadMockData: () => void;
}

// Demo balances — ใช้เมื่อยังไม่เชื่อมต่อ wallet
const DEMO_BALANCES: Record<string, { balance: number; name: string }> = {
  BTC: { balance: 0.00, name: 'Bitcoin' },
  ETH: { balance: 0.00, name: 'Ethereum' },
  TPIX: { balance: 0.00, name: 'TPIX' },
  BNB: { balance: 0.00, name: 'BNB' },
  USDT: { balance: 0.00, name: 'Tether' },
};

export const usePortfolioStore = create<PortfolioState>((set) => ({
  assets: [],
  totalValue: 0,
  totalChange24h: 0,
  totalChangePercent: 0,
  isLoading: false,
  error: null,
  isDemo: true,

  fetchRealPortfolio: async () => {
    set({ isLoading: true, error: null });

    const wallet = useWalletStore.getState().wallet;
    const activeChainId = useWalletStore.getState().activeChainId;

    // ดึงราคาจาก market store
    const marketPairs = useMarketStore.getState().pairs;
    const priceMap = new Map<string, { price: number; change24h: number }>();
    for (const pair of marketPairs) {
      const base = pair.symbol.split('/')[0];
      priceMap.set(base, { price: pair.price, change24h: pair.change24h });
    }
    if (!priceMap.has('USDT')) priceMap.set('USDT', { price: 1.0, change24h: 0.01 });
    if (!priceMap.has('TPIX')) priceMap.set('TPIX', { price: 0.18, change24h: 0.0 });

    let balances = { ...DEMO_BALANCES };
    let isDemo = true;

    // ถ้ามี wallet → ดึง balance จริงจาก API
    if (wallet?.address) {
      try {
        const response = await api.getWalletBalance(wallet.address, activeChainId);
        if (response?.data?.balances) {
          balances = {};
          for (const b of response.data.balances) {
            balances[b.symbol] = { balance: parseFloat(b.balance) || 0, name: b.name || b.symbol };
          }
          isDemo = false;
        }
      } catch {
        // API fail → ใช้ balance ว่าง (ไม่ใช้ demo)
        balances = { TPIX: { balance: 0, name: 'TPIX' } };
        isDemo = false;
      }
    }

    // สร้าง portfolio assets
    const assets: PortfolioAsset[] = [];
    let totalValue = 0;

    for (const [symbol, info] of Object.entries(balances)) {
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
        allocation: 0,
      });
    }

    // คำนวณ allocation + total change
    let totalChange24h = 0;
    for (const asset of assets) {
      asset.allocation = totalValue > 0 ? (asset.value / totalValue) * 100 : 0;
      const prevValue = asset.value / (1 + asset.change24h / 100);
      totalChange24h += asset.value - prevValue;
    }

    assets.sort((a, b) => b.value - a.value);

    const prevTotalValue = totalValue - totalChange24h;
    const totalChangePercent = prevTotalValue > 0 ? (totalChange24h / prevTotalValue) * 100 : 0;

    set({
      assets,
      totalValue,
      totalChange24h,
      totalChangePercent,
      isLoading: false,
      error: null,
      isDemo,
    });
  },

  loadMockData: () => {
    set({
      assets: [
        { symbol: 'TPIX', name: 'TPIX', balance: 0, value: 0, price: 0.18, change24h: 0, allocation: 100 },
      ],
      totalValue: 0,
      totalChange24h: 0,
      totalChangePercent: 0,
      isLoading: false,
      error: null,
      isDemo: true,
    });
  },
}));
