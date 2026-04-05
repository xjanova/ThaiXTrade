import { useEffect, useState, useCallback } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  RefreshControl,
  Platform,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path, Circle } from 'react-native-svg';
import * as Haptics from 'expo-haptics';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import PriceChange from '@/components/common/PriceChange';
import AssetRow from '@/components/portfolio/AssetRow';
import { usePortfolioStore } from '@/stores/portfolioStore';
import { useResponsiveLayout } from '@/utils/responsive';
import { COIN_COLORS } from '@/components/common/CoinIcon';

// Donut chart for allocation
function AllocationChart({ assets, chartSize }: { assets: { symbol: string; allocation: number; color: string }[]; chartSize: number }) {
  const radius = chartSize / 2 - 10;
  const cx = chartSize / 2;
  const cy = chartSize / 2;

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
    <Svg width={chartSize} height={chartSize}>
      {segments.map((seg, i) => (
        <Path key={i} d={seg.d} fill={seg.color} opacity={0.85} />
      ))}
      <Circle cx={cx} cy={cy} r={radius * 0.6} fill={colors.bg.primary} />
    </Svg>
  );
}

// Use consistent coin colors from CoinIcon / ใช้สีเหรียญที่สอดคล้องกันจาก CoinIcon
const ASSET_COLOR_MAP: Record<string, string> = COIN_COLORS;
const getAssetColor = (symbol: string, fallback: string = colors.brand.cyan): string =>
  ASSET_COLOR_MAP[symbol] || fallback;

type TabType = 'assets' | 'history';

export default function PortfolioScreen() {
  const insets = useSafeAreaInsets();
  const { allocationChartSize } = useResponsiveLayout();
  const chartSize = allocationChartSize;
  const { assets, totalValue, totalChange24h, totalChangePercent, fetchRealPortfolio, transactions, isLoadingTx, fetchTransactionHistory } = usePortfolioStore();
  const [activeTab, setActiveTab] = useState<TabType>('assets');
  const [refreshing, setRefreshing] = useState(false);
  const [balanceHidden, setBalanceHidden] = useState(false);

  useEffect(() => {
    fetchRealPortfolio();
    fetchTransactionHistory();
  }, [fetchRealPortfolio, fetchTransactionHistory]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    if (Platform.OS !== 'web') {
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    }
    await Promise.all([fetchRealPortfolio(), fetchTransactionHistory()]);
    setRefreshing(false);
  }, [fetchRealPortfolio, fetchTransactionHistory]);

  const allocationData = assets.map((a) => ({
    symbol: a.symbol,
    allocation: a.allocation,
    color: getAssetColor(a.symbol),
  }));

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Text style={styles.title}>Portfolio</Text>
        <Pressable
          style={styles.iconBtn}
          onPress={() => setBalanceHidden(!balanceHidden)}
        >
          <Ionicons
            name={balanceHidden ? 'eye-off-outline' : 'eye-outline'}
            size={20}
            color={colors.text.secondary}
          />
        </Pressable>
      </View>

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
        {/* Total Value Card */}
        <GlassCard variant="brand" style={styles.valueCard}>
          <Text style={styles.valueLabel}>Total Balance</Text>
          <Text style={styles.totalValue}>
            {balanceHidden ? '******' : `$${totalValue.toLocaleString('en-US', { minimumFractionDigits: 2 })}`}
          </Text>
          <View style={styles.changeRow}>
            <PriceChange value={totalChangePercent} size="md" showIcon />
            {!balanceHidden && (
              <Text style={[styles.changeAmount, totalChange24h < 0 && { color: colors.trading.red }]}>
                {totalChange24h >= 0 ? '+' : ''}${totalChange24h.toLocaleString('en-US', { minimumFractionDigits: 2 })}
              </Text>
            )}
          </View>
        </GlassCard>

        {/* Allocation Chart */}
        <GlassCard style={styles.allocationCard}>
          <Text style={styles.allocationTitle}>Allocation</Text>
          <View style={styles.allocationContent}>
            <AllocationChart assets={allocationData} chartSize={chartSize} />
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

        {/* Transaction History — ดึงจาก API จริง */}
        {activeTab === 'history' && (
          <GlassCard style={styles.historyCard}>
            {isLoadingTx ? (
              <View style={{ padding: 20, alignItems: 'center' }}>
                <Ionicons name="time-outline" size={20} color={colors.text.tertiary} />
                <Text style={[styles.txTime, { marginTop: 8 }]}>Loading history...</Text>
              </View>
            ) : transactions.length === 0 ? (
              <View style={{ padding: 20, alignItems: 'center' }}>
                <Ionicons name="receipt-outline" size={24} color={colors.text.disabled} />
                <Text style={[styles.txTime, { marginTop: 8 }]}>No transactions yet</Text>
              </View>
            ) : (
              transactions.map((tx, i) => (
                <View key={tx.id} style={[styles.txRow, i > 0 && styles.txRowBorder]}>
                  <View
                    style={[
                      styles.txIcon,
                      {
                        backgroundColor:
                          tx.type === 'buy' || tx.type === 'deposit'
                            ? colors.trading.greenBg
                            : tx.type === 'sell' || tx.type === 'withdraw'
                            ? colors.trading.redBg
                            : colors.brand.cyan + '20',
                      },
                    ]}
                  >
                    <Ionicons
                      name={
                        tx.type === 'buy' || tx.type === 'deposit'
                          ? 'arrow-down'
                          : tx.type === 'sell' || tx.type === 'withdraw'
                          ? 'arrow-up'
                          : 'swap-horizontal'
                      }
                      size={16}
                      color={
                        tx.type === 'buy' || tx.type === 'deposit'
                          ? colors.trading.green
                          : tx.type === 'sell' || tx.type === 'withdraw'
                          ? colors.trading.red
                          : colors.brand.cyan
                      }
                    />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.txTitle}>
                      {tx.type.charAt(0).toUpperCase() + tx.type.slice(1)} {tx.symbol}
                    </Text>
                    <Text style={styles.txTime}>
                      {tx.time ? new Date(tx.time).toLocaleDateString() : ''}
                    </Text>
                  </View>
                  <View style={{ alignItems: 'flex-end' }}>
                    <Text style={styles.txAmount}>
                      {tx.type === 'sell' || tx.type === 'withdraw' ? '-' : '+'}{tx.amount} {tx.symbol}
                    </Text>
                    <Text style={styles.txValue}>{tx.value}</Text>
                  </View>
                </View>
              ))
            )}
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
  assetList: {
    gap: spacing.sm,
    marginBottom: spacing.xl,
  },
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
