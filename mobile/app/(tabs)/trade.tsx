/**
 * Trade Screen — ใช้ข้อมูลจริงจาก Binance API
 * Chart, Order Book, Recent Trades ดึงจาก REST API
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  Alert,
  RefreshControl,
  ActivityIndicator,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import OrderBookMobile from '@/components/trading/OrderBookMobile';
import TradeFormMobile from '@/components/trading/TradeFormMobile';
import PairHeader from '@/components/trading/PairHeader';
import TradingViewChart from '@/components/trading/TradingViewChart';
import { formatPrice } from '@/utils/formatters';
import { useMarketStore } from '@/stores/marketStore';
import { useWalletStore } from '@/stores/walletStore';
import { playTradeSound, playErrorSound, playClickSound } from '@/utils/sounds';
import api from '@/services/api';

const BINANCE_REST = 'https://api.binance.com/api/v3';

// --- Binance data fetchers ---

interface OrderBookEntry {
  price: number;
  amount: number;
  total: number;
}

interface BinanceTrade {
  id: number;
  isBuy: boolean;
  price: number;
  amount: string;
  time: number;
}

async function fetchOrderBook(symbol: string): Promise<{
  asks: OrderBookEntry[];
  bids: OrderBookEntry[];
  spread: number;
  spreadPercent: number;
}> {
  const binanceSymbol = symbol.replace('/', '');
  const res = await fetch(`${BINANCE_REST}/depth?symbol=${binanceSymbol}&limit=10`, {
    signal: AbortSignal.timeout(10000),
  });
  if (!res.ok) throw new Error(`Binance depth error: ${res.status}`);
  const data: { bids: [string, string][]; asks: [string, string][] } = await res.json();

  let askTotal = 0;
  const asks: OrderBookEntry[] = data.asks.slice(0, 8).map(([p, q]) => {
    const price = parseFloat(p);
    const amount = parseFloat(q);
    askTotal += amount;
    return { price, amount, total: askTotal };
  });

  let bidTotal = 0;
  const bids: OrderBookEntry[] = data.bids.slice(0, 8).map(([p, q]) => {
    const price = parseFloat(p);
    const amount = parseFloat(q);
    bidTotal += amount;
    return { price, amount, total: bidTotal };
  });

  const spread = asks.length > 0 && bids.length > 0 ? asks[0].price - bids[0].price : 0;
  const midPrice = bids.length > 0 ? bids[0].price : 1;
  const spreadPercent = (spread / midPrice) * 100;

  return { asks, bids, spread, spreadPercent };
}

async function fetchRecentTrades(symbol: string): Promise<BinanceTrade[]> {
  const binanceSymbol = symbol.replace('/', '');
  const res = await fetch(`${BINANCE_REST}/trades?symbol=${binanceSymbol}&limit=15`, {
    signal: AbortSignal.timeout(10000),
  });
  if (!res.ok) throw new Error(`Binance trades error: ${res.status}`);
  const data: Array<{
    id: number;
    price: string;
    qty: string;
    time: number;
    isBuyerMaker: boolean;
  }> = await res.json();

  return data.map((t) => ({
    id: t.id,
    isBuy: !t.isBuyerMaker,
    price: parseFloat(t.price),
    amount: parseFloat(t.qty).toFixed(4),
    time: t.time,
  }));
}

// TradingView chart จัดการ klines เอง ไม่ต้อง fetch แยก

type TabType = 'chart' | 'orderbook' | 'trades';
type IoniconsName = ComponentProps<typeof Ionicons>['name'];

// Binance interval mapping
const TIMEFRAME_MAP: Record<string, string> = {
  '1m': '1m',
  '5m': '5m',
  '15m': '15m',
  '1H': '1h',
  '4H': '4h',
  '1D': '1d',
  '1W': '1w',
};
const timeframes = Object.keys(TIMEFRAME_MAP);

// Default pair when none selected
const DEFAULT_PAIR = {
  symbol: 'BTC/USDT',
  name: 'Bitcoin',
  price: 0,
  change24h: 0,
  high24h: 0,
  low24h: 0,
  volume24h: '...',
};

export default function TradeScreen() {
  const insets = useSafeAreaInsets();
  const selectedPair = useMarketStore((s) => s.selectedPair);
  const fetchRealData = useMarketStore((s) => s.fetchRealData);
  const wallet = useWalletStore((s) => s.wallet);
  const showWalletModal = useWalletStore((s) => s.showModal);
  const [activeTab, setActiveTab] = useState<TabType>('chart');
  const [activeTimeframe, setActiveTimeframe] = useState('1H');
  const [refreshing, setRefreshing] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const submittingRef = useRef(false); // FIX: ใช้ ref ป้องกัน double-submit (state อาจ update ไม่ทัน)
  const [feeRate, setFeeRate] = useState(0.3); // Default, จะ fetch จาก API

  // Real data state
  const [orderBook, setOrderBook] = useState<{
    asks: OrderBookEntry[];
    bids: OrderBookEntry[];
    spread: number;
    spreadPercent: number;
  } | null>(null);
  const [trades, setTrades] = useState<BinanceTrade[]>([]);

  const pair = selectedPair || DEFAULT_PAIR;
  const mountedRef = useRef(true);

  useEffect(() => {
    mountedRef.current = true;
    return () => { mountedRef.current = false; };
  }, []);

  // ดึง order book + trades เมื่อเปลี่ยน pair (chart ใช้ TradingView จัดการเอง)
  useEffect(() => {
    let cancelled = false;

    async function loadMarketData() {
      try {
        const [ob, tr] = await Promise.all([
          fetchOrderBook(pair.symbol),
          fetchRecentTrades(pair.symbol),
        ]);
        if (!cancelled && mountedRef.current) {
          setOrderBook(ob);
          setTrades(tr);
        }
      } catch {
        // ปล่อยให้ component ใช้ mock data
      }
    }

    loadMarketData();

    // Auto-refresh ทุก 5 วินาที
    const interval = setInterval(loadMarketData, 5000);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [pair.symbol]);

  // Fetch market data + fee rate on mount
  useEffect(() => {
    fetchRealData();

    // Fetch real fee rate from backend (ไม่ hardcode)
    const isTpixPair = pair.symbol.includes('TPIX');
    api.getFeeInfo(isTpixPair ? 4289 : 56).then((res) => {
      if (res?.data?.fee_rate != null && mountedRef.current) {
        setFeeRate(Number(res.data.fee_rate) || 0.3);
      }
    }).catch(() => {}); // ใช้ default 0.3 ถ้า fetch ล้มเหลว
  }, []);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    playClickSound();
    try {
      await fetchRealData();
      const [ob, tr] = await Promise.all([
        fetchOrderBook(pair.symbol),
        fetchRecentTrades(pair.symbol),
      ]);
      if (mountedRef.current) {
        setOrderBook(ob);
        setTrades(tr);
      }
    } catch {
      // silent
    } finally {
      if (mountedRef.current) setRefreshing(false);
    }
  }, [pair.symbol, fetchRealData]);

  const handleOrderSubmit = useCallback(async (order: {
    side: 'buy' | 'sell';
    type: 'limit' | 'market';
    price: number | null;
    amount: number;
    total: number;
  }) => {
    // FIX: ใช้ ref ป้องกัน double-submit (state update อาจไม่ทัน)
    if (submittingRef.current || isSubmitting) return;
    submittingRef.current = true;

    // ตรวจสอบว่าเชื่อมต่อ wallet แล้วหรือยัง
    if (!wallet) {
      playErrorSound();
      Alert.alert(
        'Wallet Required',
        'Please connect your wallet before placing orders.',
        [
          { text: 'Cancel', style: 'cancel' },
          { text: 'Connect Wallet', onPress: showWalletModal },
        ],
      );
      return;
    }

    setIsSubmitting(true);
    const baseSymbol = pair.symbol.split('/')[0];

    try {
      // ส่ง order จริงไปที่ backend (ต้องส่ง wallet_address + chain_id ด้วย)
      // chain_id: TPIX Chain = 4289, BSC = 56 — ใช้ตาม pair symbol
      // TPIX pairs จะถูก route ไป internal order book, อื่นๆ ไป legacy
      const isTpixPair = pair.symbol.includes('TPIX');
      await api.createOrder({
        pair: pair.symbol,
        side: order.side,
        type: order.type,
        price: order.price ?? undefined,
        amount: order.amount,
        wallet_address: wallet.address,
        chain_id: isTpixPair ? 4289 : 56, // TPIX Chain หรือ BSC
        total: order.total,
      });

      playTradeSound();
      Alert.alert(
        'Order Placed',
        `${order.side === 'buy' ? 'Buy' : 'Sell'} ${order.amount} ${baseSymbol}\n` +
        `${order.type === 'market' ? 'Market Order' : `Limit @ $${formatPrice(order.price!)}`}\n` +
        `Total: $${order.total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
        [{ text: 'OK' }],
      );
    } catch (err) {
      playErrorSound();
      const message = err instanceof Error ? err.message : 'Order failed';
      Alert.alert('Order Failed', message, [{ text: 'OK' }]);
    } finally {
      submittingRef.current = false;
      if (mountedRef.current) setIsSubmitting(false);
    }
  }, [pair.symbol, wallet, isSubmitting]);

  const formatTradeTime = useCallback((timestamp: number) => {
    const diff = Math.floor((Date.now() - timestamp) / 1000);
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    return `${Math.floor(diff / 3600)}h ago`;
  }, []);

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      {/* Pair Header */}
      <PairHeader
        symbol={pair.symbol}
        price={pair.price}
        change24h={pair.change24h}
        high={pair.high24h}
        low={pair.low24h}
        volume={pair.volume24h}
      />

      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor={colors.brand.cyan}
            colors={[colors.brand.cyan]}
            progressBackgroundColor={colors.bg.secondary}
          />
        }
      >
        {/* Tab Switcher */}
        <View style={styles.tabBar}>
          {([
            { key: 'chart' as TabType, label: 'Chart', icon: 'analytics-outline' as IoniconsName },
            { key: 'orderbook' as TabType, label: 'Order Book', icon: 'bar-chart-outline' as IoniconsName },
            { key: 'trades' as TabType, label: 'Trades', icon: 'time-outline' as IoniconsName },
          ]).map((tab) => (
            <Pressable
              key={tab.key}
              style={[styles.tab, activeTab === tab.key && styles.tabActive]}
              onPress={() => {
                playClickSound();
                setActiveTab(tab.key);
              }}
            >
              <Ionicons
                name={tab.icon}
                size={16}
                color={activeTab === tab.key ? colors.brand.cyan : colors.text.tertiary}
              />
              <Text
                style={[
                  styles.tabText,
                  activeTab === tab.key && styles.tabTextActive,
                ]}
              >
                {tab.label}
              </Text>
            </Pressable>
          ))}
        </View>

        {/* Chart View — TradingView (lightweight-charts) */}
        {activeTab === 'chart' && (
          <View>
            <ScrollView
              horizontal
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={styles.timeframes}
            >
              {timeframes.map((tf) => (
                <Pressable
                  key={tf}
                  style={[
                    styles.timeframeBtn,
                    activeTimeframe === tf && styles.timeframeBtnActive,
                  ]}
                  onPress={() => {
                    playClickSound();
                    setActiveTimeframe(tf);
                  }}
                >
                  <Text
                    style={[
                      styles.timeframeText,
                      activeTimeframe === tf && styles.timeframeTextActive,
                    ]}
                  >
                    {tf}
                  </Text>
                </Pressable>
              ))}
            </ScrollView>

            <View style={{ marginBottom: spacing.xl }}>
              <TradingViewChart
                symbol={pair.symbol}
                interval={TIMEFRAME_MAP[activeTimeframe] || '1h'}
                height={300}
              />
            </View>
          </View>
        )}

        {/* Order Book View — ใช้ข้อมูลจริงจาก Binance */}
        {activeTab === 'orderbook' && (
          <OrderBookMobile
            asks={orderBook?.asks}
            bids={orderBook?.bids}
            spread={orderBook?.spread}
            spreadPercent={orderBook?.spreadPercent}
          />
        )}

        {/* Recent Trades — ใช้ข้อมูลจริงจาก Binance */}
        {activeTab === 'trades' && (
          <GlassCard style={styles.tradesCard}>
            <View style={styles.tradesHeader}>
              <Text style={[styles.colLabel, { flex: 1 }]}>Price</Text>
              <Text style={[styles.colLabel, { flex: 1, textAlign: 'center' }]}>Amount</Text>
              <Text style={[styles.colLabel, { flex: 1, textAlign: 'right' }]}>Time</Text>
            </View>
            {trades.length > 0 ? (
              trades.map((trade) => (
                <View key={trade.id} style={styles.tradeRow}>
                  <Text
                    style={[
                      styles.tradePrice,
                      { color: trade.isBuy ? colors.trading.green : colors.trading.red },
                    ]}
                  >
                    {formatPrice(trade.price)}
                  </Text>
                  <Text style={styles.tradeAmount}>{trade.amount}</Text>
                  <Text style={styles.tradeTime}>{formatTradeTime(trade.time)}</Text>
                </View>
              ))
            ) : (
              <View style={{ padding: 20, alignItems: 'center' }}>
                <ActivityIndicator color={colors.brand.cyan} />
                <Text style={[styles.tabText, { marginTop: 8 }]}>Loading trades...</Text>
              </View>
            )}
          </GlassCard>
        )}

        {/* Trade Form */}
        <TradeFormMobile
          symbol={pair.symbol}
          currentPrice={pair.price}
          onSubmitOrder={handleOrderSubmit}
          isSubmitting={isSubmitting}
          isWalletConnected={!!wallet}
          feeRate={feeRate}
        />

        <View style={{ height: 120 }} />
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.bg.primary,
  },
  scrollContent: {
    paddingHorizontal: spacing.xl,
  },
  tabBar: {
    flexDirection: 'row',
    gap: spacing.xs,
    marginBottom: spacing.lg,
  },
  tab: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    paddingVertical: spacing.sm,
    borderRadius: 8,
    backgroundColor: colors.bg.card,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
  },
  tabActive: {
    backgroundColor: colors.brand.cyan + '15',
    borderColor: colors.brand.cyan + '40',
  },
  tabText: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 12,
  },
  tabTextActive: {
    color: colors.brand.cyan,
  },
  timeframes: {
    gap: spacing.xs,
    paddingBottom: spacing.md,
  },
  timeframeBtn: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    borderRadius: 6,
  },
  timeframeBtnActive: {
    backgroundColor: colors.brand.cyan + '20',
  },
  timeframeText: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 12,
  },
  timeframeTextActive: {
    color: colors.brand.cyan,
    fontWeight: '600',
  },
  tradesCard: {
    padding: spacing.lg,
    marginBottom: spacing.xl,
  },
  tradesHeader: {
    flexDirection: 'row',
    paddingBottom: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.divider,
    marginBottom: spacing.sm,
  },
  colLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
    fontSize: 10,
  },
  tradeRow: {
    flexDirection: 'row',
    paddingVertical: 4,
  },
  tradePrice: {
    flex: 1,
    ...typography.monoSmall,
  },
  tradeAmount: {
    flex: 1,
    ...typography.monoSmall,
    color: colors.text.secondary,
    textAlign: 'center',
  },
  tradeTime: {
    flex: 1,
    ...typography.monoSmall,
    color: colors.text.tertiary,
    textAlign: 'right',
  },
});
