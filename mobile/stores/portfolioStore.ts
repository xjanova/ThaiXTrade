/**
 * Portfolio Store v3 — ดึง balance + transaction history จริงจาก API
 * ใช้ Binance + tpix.online price สำหรับคำนวณมูลค่า
 * FIX: NaN protection, real transaction history, proper error handling
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

export interface TransactionRecord {
  id: string;
  type: 'buy' | 'sell' | 'swap' | 'deposit' | 'withdraw' | 'transfer';
  symbol: string;
  amount: string;
  value: string;
  time: string;
  status: string;
}

interface PortfolioState {
  assets: PortfolioAsset[];
  totalValue: number;
  totalChange24h: number;
  totalChangePercent: number;
  isLoading: boolean;
  error: string | null;
  isDemo: boolean;

  // Transaction history (real data)
  transactions: TransactionRecord[];
  isLoadingTx: boolean;

  fetchRealPortfolio: () => Promise<void>;
  fetchTransactionHistory: () => Promise<void>;
}

/** Safe number: ป้องกัน NaN / Infinity */
function safeNum(val: unknown, fallback = 0): number {
  const n = typeof val === 'string' ? parseFloat(val) : Number(val);
  return Number.isFinite(n) ? n : fallback;
}

export const usePortfolioStore = create<PortfolioState>((set) => ({
  assets: [],
  totalValue: 0,
  totalChange24h: 0,
  totalChangePercent: 0,
  isLoading: false,
  error: null,
  isDemo: true,

  transactions: [],
  isLoadingTx: false,

  fetchRealPortfolio: async () => {
    set({ isLoading: true, error: null });

    const wallet = useWalletStore.getState().wallet;
    const activeChainId = useWalletStore.getState().activeChainId;

    // ดึงราคาจาก market store
    const marketPairs = useMarketStore.getState().pairs;
    const priceMap = new Map<string, { price: number; change24h: number }>();
    for (const pair of marketPairs) {
      const base = pair.symbol.split('/')[0];
      priceMap.set(base, { price: safeNum(pair.price), change24h: safeNum(pair.change24h) });
    }
    if (!priceMap.has('USDT')) priceMap.set('USDT', { price: 1.0, change24h: 0.01 });

    // ดึง TPIX price จาก API จริง (ไม่ hardcode)
    if (!priceMap.has('TPIX')) {
      try {
        const tpixRes = await api.getTpixPrice();
        if (tpixRes?.data?.price) {
          priceMap.set('TPIX', { price: safeNum(tpixRes.data.price), change24h: safeNum(tpixRes.data.change_24h) });
        }
      } catch {
        priceMap.set('TPIX', { price: 0.18, change24h: 0 });
      }
    }

    let balances: Record<string, { balance: number; name: string }> = {};
    let isDemo = true;

    // ถ้ามี wallet → ดึง balance จริงจาก API
    if (wallet?.address) {
      try {
        const response = await api.getWalletBalance(wallet.address, activeChainId);
        if (response?.data?.balances) {
          for (const b of response.data.balances) {
            const bal = safeNum(b.balance);
            if (bal > 0) {
              balances[b.symbol] = { balance: bal, name: b.name || b.symbol };
            }
          }
          isDemo = false;
        }
      } catch {
        balances = {};
        isDemo = false;
      }
    }

    // สร้าง portfolio assets พร้อม NaN protection
    const assets: PortfolioAsset[] = [];
    let totalValue = 0;

    for (const [symbol, info] of Object.entries(balances)) {
      const market = priceMap.get(symbol);
      const price = safeNum(market?.price);
      const change24h = safeNum(market?.change24h);
      const value = safeNum(info.balance * price);
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

    // คำนวณ allocation + total change (ป้องกัน division by zero)
    let totalChange24h = 0;
    for (const asset of assets) {
      asset.allocation = totalValue > 0.01 ? Math.round((asset.value / totalValue) * 10000) / 100 : 0;
      const divisor = 1 + asset.change24h / 100;
      const prevValue = divisor > 0.001 ? asset.value / divisor : asset.value;
      totalChange24h += asset.value - prevValue;
    }

    assets.sort((a, b) => b.value - a.value);

    const prevTotalValue = totalValue - totalChange24h;
    const totalChangePercent = prevTotalValue > 0.01
      ? Math.round((totalChange24h / prevTotalValue) * 10000) / 100
      : 0;

    set({
      assets,
      totalValue: safeNum(totalValue),
      totalChange24h: safeNum(totalChange24h),
      totalChangePercent: safeNum(totalChangePercent),
      isLoading: false,
      error: null,
      isDemo,
    });
  },

  // ดึงประวัติธุรกรรมจริงจาก API (ไม่ใช้ mock data)
  fetchTransactionHistory: async () => {
    const wallet = useWalletStore.getState().wallet;
    if (!wallet?.address) {
      set({ transactions: [], isLoadingTx: false });
      return;
    }

    set({ isLoadingTx: true });
    try {
      // ดึงทั้ง trade history และ wallet transactions
      const [tradeRes, txRes] = await Promise.allSettled([
        api.getTradeHistory(wallet.address),
        api.getWalletTransactions(wallet.address, 50),
      ]);

      const records: TransactionRecord[] = [];

      // Trade history
      if (tradeRes.status === 'fulfilled' && tradeRes.value?.data) {
        for (const t of tradeRes.value.data) {
          records.push({
            id: t.id || t.uuid || String(Math.random()),
            type: t.side === 'sell' ? 'sell' : 'buy',
            symbol: t.pair?.split('/')[0] || t.pair?.split('-')[0] || 'TPIX',
            amount: String(safeNum(t.amount || t.from_amount)),
            value: `$${safeNum(t.total || t.value).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            time: t.created_at || '',
            status: t.status || 'confirmed',
          });
        }
      }

      // Wallet transactions (swaps, transfers)
      if (txRes.status === 'fulfilled' && txRes.value?.data) {
        for (const tx of txRes.value.data) {
          records.push({
            id: tx.uuid || String(Math.random()),
            type: tx.type?.replace('order_', '') || 'transfer',
            symbol: tx.from_token_symbol || tx.from_token?.slice(0, 6) || 'TOKEN',
            amount: String(safeNum(tx.from_amount)),
            value: `$${safeNum(tx.value_usd || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            time: tx.created_at || '',
            status: tx.status || 'confirmed',
          });
        }
      }

      // Sort by time desc
      records.sort((a, b) => new Date(b.time).getTime() - new Date(a.time).getTime());

      set({ transactions: records.slice(0, 50), isLoadingTx: false });
    } catch {
      set({ transactions: [], isLoadingTx: false });
    }
  },
}));
