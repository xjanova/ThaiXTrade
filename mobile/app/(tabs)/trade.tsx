import { useState, useMemo, useCallback } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  Alert,
  Platform,
  RefreshControl,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path, Defs, LinearGradient as SvgGradient, Stop, Rect } from 'react-native-svg';
import * as Haptics from 'expo-haptics';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import OrderBookMobile from '@/components/trading/OrderBookMobile';
import TradeFormMobile from '@/components/trading/TradeFormMobile';
import PairHeader from '@/components/trading/PairHeader';
import { formatPrice } from '@/utils/formatters';
import { useResponsiveLayout } from '@/utils/responsive';
import { useMarketStore } from '@/stores/marketStore';

const CHART_HEIGHT = 220;

function generatePriceData(base: number, length: number = 60): number[] {
  const data: number[] = [];
  let price = base;
  const volatility = base * 0.003;
  for (let i = 0; i < length; i++) {
    price += (Math.random() - 0.48) * volatility;
    price = Math.max(price, base * 0.97);
    price = Math.min(price, base * 1.03);
    data.push(price);
  }
  return data;
}

function generateMockTrades(basePrice: number, count: number = 15) {
  return Array.from({ length: count }).map((_, i) => {
    const isBuy = Math.random() > 0.45;
    const price = basePrice + (Math.random() - 0.5) * (basePrice * 0.001);
    const amount = (Math.random() * 2).toFixed(4);
    const mins = Math.floor(Math.random() * 60);
    return { id: i, isBuy, price, amount, mins };
  });
}

function PriceChart({ data, chartWidth }: { data: number[]; chartWidth: number }) {
  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;

  const points = data.map((val, i) => ({
    x: (i / (data.length - 1)) * chartWidth,
    y: CHART_HEIGHT - ((val - min) / range) * (CHART_HEIGHT - 20) - 10,
  }));

  let pathD = `M ${points[0].x} ${points[0].y}`;
  for (let i = 1; i < points.length; i++) {
    const cp1x = (points[i - 1].x + points[i].x) / 2;
    const cp1y = points[i - 1].y;
    const cp2x = cp1x;
    const cp2y = points[i].y;
    pathD += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${points[i].x} ${points[i].y}`;
  }

  const fillD = pathD + ` L ${chartWidth} ${CHART_HEIGHT} L 0 ${CHART_HEIGHT} Z`;
  const isUp = data[data.length - 1] >= data[0];
  const lineColor = isUp ? colors.trading.green : colors.trading.red;

  return (
    <Svg width={chartWidth} height={CHART_HEIGHT}>
      <Defs>
        <SvgGradient id="chartFill" x1="0" y1="0" x2="0" y2="1">
          <Stop offset="0" stopColor={lineColor} stopOpacity="0.25" />
          <Stop offset="1" stopColor={lineColor} stopOpacity="0" />
        </SvgGradient>
      </Defs>
      <Path d={fillD} fill="url(#chartFill)" />
      <Path d={pathD} stroke={lineColor} strokeWidth={2} fill="none" />
      <Rect
        x={0}
        y={points[points.length - 1].y}
        width={chartWidth}
        height={0.5}
        fill={lineColor}
        opacity={0.3}
      />
    </Svg>
  );
}

type TabType = 'chart' | 'orderbook' | 'trades';
type IoniconsName = ComponentProps<typeof Ionicons>['name'];
const timeframes = ['1m', '5m', '15m', '1H', '4H', '1D', '1W'];

// Default pair when none selected / คู่เทรดเริ่มต้นเมื่อยังไม่ได้เลือก
const DEFAULT_PAIR = {
  symbol: 'BTC/USDT',
  name: 'Bitcoin',
  price: 98432.50,
  change24h: 2.34,
  high24h: 99100.00,
  low24h: 95800.00,
  volume24h: '2.1B',
};

export default function TradeScreen() {
  const insets = useSafeAreaInsets();
  const { chartWidth } = useResponsiveLayout();
  const selectedPair = useMarketStore((s) => s.selectedPair);
  const [activeTab, setActiveTab] = useState<TabType>('chart');
  const [activeTimeframe, setActiveTimeframe] = useState('1H');
  const [refreshing, setRefreshing] = useState(false);

  // Use selected pair or default / ใช้คู่ที่เลือกหรือค่าเริ่มต้น
  const pair = selectedPair || DEFAULT_PAIR;

  const [priceData] = useState(() => generatePriceData(pair.price, 60));
  const mockTrades = useMemo(() => generateMockTrades(pair.price, 15), [pair.symbol]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    if (Platform.OS !== 'web') {
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    }
    await new Promise((r) => setTimeout(r, 600));
    setRefreshing(false);
  }, []);

  const handleOrderSubmit = useCallback((order: {
    side: 'buy' | 'sell';
    type: 'limit' | 'market';
    price: number | null;
    amount: number;
    total: number;
  }) => {
    if (Platform.OS !== 'web') {
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    }
    const baseSymbol = pair.symbol.split('/')[0];
    Alert.alert(
      'Order Placed',
      `${order.side === 'buy' ? 'Buy' : 'Sell'} ${order.amount} ${baseSymbol}\n${order.type === 'market' ? 'Market Order' : `Limit @ $${formatPrice(order.price!)}`}\nTotal: $${order.total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      [{ text: 'OK', style: 'default' }],
    );
  }, [pair.symbol]);

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
              onPress={() => setActiveTab(tab.key)}
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

        {/* Chart View */}
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
                  onPress={() => setActiveTimeframe(tf)}
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

            <GlassCard style={styles.chartCard}>
              <PriceChart data={priceData} chartWidth={chartWidth} />
            </GlassCard>
          </View>
        )}

        {/* Order Book View */}
        {activeTab === 'orderbook' && <OrderBookMobile />}

        {/* Recent Trades */}
        {activeTab === 'trades' && (
          <GlassCard style={styles.tradesCard}>
            <View style={styles.tradesHeader}>
              <Text style={[styles.colLabel, { flex: 1 }]}>Price</Text>
              <Text style={[styles.colLabel, { flex: 1, textAlign: 'center' }]}>Amount</Text>
              <Text style={[styles.colLabel, { flex: 1, textAlign: 'right' }]}>Time</Text>
            </View>
            {mockTrades.map((trade) => (
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
                <Text style={styles.tradeTime}>{trade.mins}m ago</Text>
              </View>
            ))}
          </GlassCard>
        )}

        {/* Trade Form */}
        <TradeFormMobile
          symbol={pair.symbol}
          currentPrice={pair.price}
          onSubmitOrder={handleOrderSubmit}
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
  chartCard: {
    padding: spacing.md,
    marginBottom: spacing.xl,
    alignItems: 'center',
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
