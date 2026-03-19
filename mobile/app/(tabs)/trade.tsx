import { useState, useMemo } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path, Defs, LinearGradient as SvgGradient, Stop, Rect } from 'react-native-svg';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import OrderBookMobile from '@/components/trading/OrderBookMobile';
import TradeFormMobile from '@/components/trading/TradeFormMobile';
import PairHeader from '@/components/trading/PairHeader';
import { formatPrice } from '@/utils/formatters';
import { useResponsiveLayout } from '@/utils/responsive';

const CHART_HEIGHT = 220;

// Generate candlestick-like price data / สร้างข้อมูลราคาแบบแท่งเทียน
function generatePriceData(length: number = 60): number[] {
  const data: number[] = [];
  let price = 98000;
  for (let i = 0; i < length; i++) {
    price += (Math.random() - 0.48) * 300;
    price = Math.max(price, 95000);
    price = Math.min(price, 101000);
    data.push(price);
  }
  return data;
}

/**
 * Generate mock recent trades data once (not on every render)
 * สร้างข้อมูลการเทรดล่าสุดจำลองครั้งเดียว (ไม่สร้างใหม่ทุกรอบ render)
 */
function generateMockTrades(count: number = 15) {
  return Array.from({ length: count }).map((_, i) => {
    const isBuy = Math.random() > 0.45;
    const price = 98432.5 + (Math.random() - 0.5) * 100;
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
      {/* Price line at current / เส้นราคาปัจจุบัน */}
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

export default function TradeScreen() {
  const insets = useSafeAreaInsets();
  const { chartWidth } = useResponsiveLayout();
  const [activeTab, setActiveTab] = useState<TabType>('chart');
  const [activeTimeframe, setActiveTimeframe] = useState('1H');
  const [priceData] = useState(() => generatePriceData(60));

  // Memoize mock trades so they don't regenerate on every render
  // จำ mock trades ไว้เพื่อไม่ให้สร้างใหม่ทุกรอบ render
  const mockTrades = useMemo(() => generateMockTrades(15), []);

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      {/* Pair Header / ส่วนหัวคู่เทรด */}
      <PairHeader
        symbol="BTC/USDT"
        price={98432.50}
        change24h={2.34}
        high={99100.0}
        low={95800.0}
        volume="2.1B"
      />

      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
      >
        {/* Tab Switcher / แถบสลับ */}
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

        {/* Chart View / มุมมองกราฟ */}
        {activeTab === 'chart' && (
          <View>
            {/* Timeframe Selector / เลือกกรอบเวลา */}
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

            {/* Price Chart / กราฟราคา */}
            <GlassCard style={styles.chartCard}>
              <PriceChart data={priceData} chartWidth={chartWidth} />
            </GlassCard>
          </View>
        )}

        {/* Order Book View / มุมมอง Order Book */}
        {activeTab === 'orderbook' && <OrderBookMobile />}

        {/* Recent Trades (memoized) / การเทรดล่าสุด (จำค่าไว้) */}
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

        {/* Trade Form / ฟอร์มเทรด */}
        <TradeFormMobile />

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
  // Tabs / แถบ
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
  // Timeframes / กรอบเวลา
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
  // Chart / กราฟ
  chartCard: {
    padding: spacing.md,
    marginBottom: spacing.xl,
    alignItems: 'center',
  },
  // Trades / การเทรด
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
