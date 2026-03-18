import { useEffect, useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  Dimensions,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path, Defs, LinearGradient as SvgGradient, Stop, Circle } from 'react-native-svg';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import PriceChange from '@/components/common/PriceChange';
import AssetRow from '@/components/portfolio/AssetRow';
import { usePortfolioStore } from '@/stores/portfolioStore';

const { width: SCREEN_WIDTH } = Dimensions.get('window');
const CHART_SIZE = SCREEN_WIDTH * 0.45;

// Donut chart for allocation
function AllocationChart({ assets }: { assets: { symbol: string; allocation: number; color: string }[] }) {
  const radius = CHART_SIZE / 2 - 10;
  const cx = CHART_SIZE / 2;
  const cy = CHART_SIZE / 2;
  const circumference = 2 * Math.PI * radius;

  let currentAngle = -90;
  const segments = assets.map((asset) => {
    const angle = (asset.allocation / 100) * 360;
    const startAngle = currentAngle;
    currentAngle += angle;

    const startRad = (startAngle * Math.PI) / 180;
    const endRad = ((startAngle + angle) * Math.PI) / 180;
    const largeArc = angle > 180 ? 1 : 0;

    const x1 = cx + radius * Math.cos(startRad);
    const y1 = cy + radius * Math.sin(startRad);
    const x2 = cx + radius * Math.cos(endRad);
    const y2 = cy + radius * Math.sin(endRad);

    return {
      ...asset,
      d: `M ${cx} ${cy} L ${x1} ${y1} A ${radius} ${radius} 0 ${largeArc} 1 ${x2} ${y2} Z`,
    };
  });

  return (
    <Svg width={CHART_SIZE} height={CHART_SIZE}>
      {segments.map((seg, i) => (
        <Path key={i} d={seg.d} fill={seg.color} opacity={0.85} />
      ))}
      <Circle cx={cx} cy={cy} r={radius * 0.6} fill={colors.bg.primary} />
    </Svg>
  );
}

const ASSET_COLORS = [
  colors.brand.cyan,
  colors.brand.purple,
  colors.trading.green,
  '#F59E0B',
  colors.text.tertiary,
  colors.trading.red,
];

type TabType = 'assets' | 'history';

export default function PortfolioScreen() {
  const insets = useSafeAreaInsets();
  const { assets, totalValue, totalChange24h, totalChangePercent, loadMockData } = usePortfolioStore();
  const [activeTab, setActiveTab] = useState<TabType>('assets');

  useEffect(() => {
    loadMockData();
  }, []);

  const allocationData = assets.map((a, i) => ({
    symbol: a.symbol,
    allocation: a.allocation,
    color: ASSET_COLORS[i % ASSET_COLORS.length],
  }));

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Text style={styles.title}>Portfolio</Text>
        <Pressable style={styles.iconBtn}>
          <Ionicons name="pie-chart-outline" size={20} color={colors.text.secondary} />
        </Pressable>
      </View>

      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
      >
        {/* Total Value Card */}
        <GlassCard variant="brand" style={styles.valueCard}>
          <Text style={styles.valueLabel}>Total Balance</Text>
          <Text style={styles.totalValue}>
            ${totalValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}
          </Text>
          <View style={styles.changeRow}>
            <PriceChange value={totalChangePercent} size="md" showIcon />
            <Text style={styles.changeAmount}>
              {totalChange24h >= 0 ? '+' : ''}${totalChange24h.toLocaleString('en-US', { minimumFractionDigits: 2 })}
            </Text>
          </View>
        </GlassCard>

        {/* Allocation Chart */}
        <GlassCard style={styles.allocationCard}>
          <Text style={styles.allocationTitle}>Allocation</Text>
          <View style={styles.allocationContent}>
            <AllocationChart assets={allocationData} />
            <View style={styles.allocationLegend}>
              {allocationData.map((a) => (
                <View key={a.symbol} style={styles.legendItem}>
                  <View style={[styles.legendDot, { backgroundColor: a.color }]} />
                  <Text style={styles.legendSymbol}>{a.symbol}</Text>
                  <Text style={styles.legendPercent}>{a.allocation}%</Text>
                </View>
              ))}
            </View>
          </View>
        </GlassCard>

        {/* Tab Switcher */}
        <View style={styles.tabBar}>
          <Pressable
            style={[styles.tab, activeTab === 'assets' && styles.tabActive]}
            onPress={() => setActiveTab('assets')}
          >
            <Text style={[styles.tabText, activeTab === 'assets' && styles.tabTextActive]}>
              Assets ({assets.length})
            </Text>
          </Pressable>
          <Pressable
            style={[styles.tab, activeTab === 'history' && styles.tabActive]}
            onPress={() => setActiveTab('history')}
          >
            <Text style={[styles.tabText, activeTab === 'history' && styles.tabTextActive]}>
              History
            </Text>
          </Pressable>
        </View>

        {/* Asset List */}
        {activeTab === 'assets' && (
          <View style={styles.assetList}>
            {assets.map((asset) => (
              <AssetRow
                key={asset.symbol}
                symbol={asset.symbol}
                name={asset.name}
                balance={asset.balance}
                value={asset.value}
                change24h={asset.change24h}
              />
            ))}
          </View>
        )}

        {/* Transaction History */}
        {activeTab === 'history' && (
          <GlassCard style={styles.historyCard}>
            {[
              { type: 'buy', symbol: 'BTC', amount: '0.025', value: '$2,460', time: '2h ago' },
              { type: 'sell', symbol: 'ETH', amount: '1.5', value: '$5,770', time: '5h ago' },
              { type: 'buy', symbol: 'SOL', amount: '10', value: '$1,873', time: '1d ago' },
              { type: 'deposit', symbol: 'USDT', amount: '5,000', value: '$5,000', time: '2d ago' },
              { type: 'buy', symbol: 'LINK', amount: '50', value: '$911', time: '3d ago' },
              { type: 'sell', symbol: 'DOGE', amount: '10,000', value: '$1,847', time: '5d ago' },
            ].map((tx, i) => (
              <View key={i} style={[styles.txRow, i > 0 && styles.txRowBorder]}>
                <View
                  style={[
                    styles.txIcon,
                    {
                      backgroundColor:
                        tx.type === 'buy'
                          ? colors.trading.greenBg
                          : tx.type === 'sell'
                          ? colors.trading.redBg
                          : colors.brand.cyan + '20',
                    },
                  ]}
                >
                  <Ionicons
                    name={
                      tx.type === 'buy'
                        ? 'arrow-down'
                        : tx.type === 'sell'
                        ? 'arrow-up'
                        : 'wallet'
                    }
                    size={16}
                    color={
                      tx.type === 'buy'
                        ? colors.trading.green
                        : tx.type === 'sell'
                        ? colors.trading.red
                        : colors.brand.cyan
                    }
                  />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.txTitle}>
                    {tx.type.charAt(0).toUpperCase() + tx.type.slice(1)} {tx.symbol}
                  </Text>
                  <Text style={styles.txTime}>{tx.time}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={styles.txAmount}>
                    {tx.type === 'sell' ? '-' : '+'}{tx.amount} {tx.symbol}
                  </Text>
                  <Text style={styles.txValue}>{tx.value}</Text>
                </View>
              </View>
            ))}
          </GlassCard>
        )}

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
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.lg,
  },
  title: {
    ...typography.h2,
    color: colors.text.primary,
  },
  iconBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.bg.card,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    alignItems: 'center',
    justifyContent: 'center',
  },
  scrollContent: {
    paddingHorizontal: spacing.xl,
  },
  // Value Card
  valueCard: {
    padding: spacing.xl,
    marginBottom: spacing.xl,
    alignItems: 'center',
  },
  valueLabel: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    marginBottom: spacing.sm,
  },
  totalValue: {
    ...typography.h1,
    color: colors.text.primary,
    fontSize: 36,
  },
  changeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.sm,
  },
  changeAmount: {
    ...typography.body,
    color: colors.trading.green,
    fontWeight: '600',
  },
  // Allocation
  allocationCard: {
    padding: spacing.lg,
    marginBottom: spacing.xl,
  },
  allocationTitle: {
    ...typography.h4,
    color: colors.text.primary,
    marginBottom: spacing.lg,
  },
  allocationContent: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  allocationLegend: {
    flex: 1,
    marginLeft: spacing.lg,
    gap: spacing.sm,
  },
  legendItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  legendDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  legendSymbol: {
    ...typography.bodySmall,
    color: colors.text.secondary,
    flex: 1,
  },
  legendPercent: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
  },
  // Tabs
  tabBar: {
    flexDirection: 'row',
    gap: spacing.xs,
    marginBottom: spacing.lg,
  },
  tab: {
    flex: 1,
    paddingVertical: spacing.md,
    alignItems: 'center',
    borderRadius: 10,
    backgroundColor: colors.bg.card,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
  },
  tabActive: {
    backgroundColor: colors.brand.cyan + '15',
    borderColor: colors.brand.cyan + '40',
  },
  tabText: {
    ...typography.body,
    color: colors.text.tertiary,
    fontWeight: '600',
  },
  tabTextActive: {
    color: colors.brand.cyan,
  },
  // Assets
  assetList: {
    gap: spacing.sm,
    marginBottom: spacing.xl,
  },
  // History
  historyCard: {
    padding: spacing.lg,
    marginBottom: spacing.xl,
  },
  txRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
  },
  txRowBorder: {
    borderTopWidth: 1,
    borderTopColor: colors.divider,
  },
  txIcon: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  txTitle: {
    ...typography.body,
    color: colors.text.primary,
    fontWeight: '600',
    fontSize: 14,
  },
  txTime: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 11,
  },
  txAmount: {
    ...typography.monoSmall,
    color: colors.text.primary,
  },
  txValue: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
    fontSize: 11,
  },
});
