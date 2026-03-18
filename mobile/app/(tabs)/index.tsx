import { useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  useWindowDimensions,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import PriceChange from '@/components/common/PriceChange';
import MiniChart from '@/components/trading/MiniChart';
import { useMarketStore } from '@/stores/marketStore';
import { usePortfolioStore } from '@/stores/portfolioStore';
import { formatCurrency } from '@/utils/formatters';

type IoniconsName = ComponentProps<typeof Ionicons>['name'];

export default function HomeScreen() {
  const insets = useSafeAreaInsets();
  const { width: screenWidth } = useWindowDimensions();
  const { pairs, favorites, loadMockData: loadMarket } = useMarketStore();
  const { totalValue, totalChangePercent, loadMockData: loadPortfolio } = usePortfolioStore();

  useEffect(() => {
    loadMarket();
    loadPortfolio();
  }, []);

  const favoritePairs = pairs.filter((p) => favorites.includes(p.symbol));
  const topGainers = [...pairs].sort((a, b) => b.change24h - a.change24h).slice(0, 5);

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      {/* Header / ส่วนหัว */}
      <View style={styles.header}>
        <View>
          <Text style={styles.greeting}>Welcome back</Text>
          <Text style={styles.appName}>TPIX TRADE</Text>
        </View>
        <View style={styles.headerRight}>
          <Pressable style={styles.iconBtn}>
            <Ionicons name="notifications-outline" size={22} color={colors.text.secondary} />
          </Pressable>
          <Pressable style={styles.iconBtn}>
            <Ionicons name="scan-outline" size={22} color={colors.text.secondary} />
          </Pressable>
        </View>
      </View>

      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
      >
        {/* Portfolio Card / การ์ดพอร์ตโฟลิโอ */}
        <GlassCard variant="brand" style={styles.portfolioCard}>
          <View style={styles.portfolioHeader}>
            <Text style={styles.portfolioLabel}>Total Portfolio Value</Text>
            <Ionicons name="eye-outline" size={18} color={colors.text.tertiary} />
          </View>
          <Text style={styles.portfolioValue}>
            {formatCurrency(totalValue)}
          </Text>
          <View style={styles.portfolioChange}>
            <PriceChange value={totalChangePercent} size="md" showIcon />
            <Text style={styles.portfolioChangeLabel}> 24h Change</Text>
          </View>

          {/* Quick Actions / ปุ่มลัด */}
          <View style={styles.quickActions}>
            {([
              { icon: 'arrow-down-outline' as IoniconsName, label: 'Deposit', color: colors.trading.green },
              { icon: 'arrow-up-outline' as IoniconsName, label: 'Withdraw', color: colors.trading.red },
              { icon: 'swap-horizontal-outline' as IoniconsName, label: 'Swap', color: colors.brand.cyan },
              { icon: 'card-outline' as IoniconsName, label: 'Buy', color: colors.brand.purple },
            ]).map((action) => (
              <Pressable key={action.label} style={styles.quickAction}>
                <View style={[styles.quickActionIcon, { backgroundColor: action.color + '20' }]}>
                  <Ionicons
                    name={action.icon}
                    size={20}
                    color={action.color}
                  />
                </View>
                <Text style={styles.quickActionLabel}>{action.label}</Text>
              </Pressable>
            ))}
          </View>
        </GlassCard>

        {/* Favorites / รายการโปรด */}
        <View style={styles.sectionHeader}>
          <Text style={styles.sectionTitle}>Favorites</Text>
          <Pressable onPress={() => router.push('/markets')}>
            <Text style={styles.seeAll}>See All</Text>
          </Pressable>
        </View>

        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={styles.favoritesScroll}
        >
          {favoritePairs.map((pair) => (
            <GlassCard
              key={pair.symbol}
              style={[styles.favoriteCard, { width: screenWidth * 0.42 }]}
              onPress={() => router.push('/trade')}
            >
              <View style={styles.favoriteHeader}>
                <View style={styles.coinIcon}>
                  <Text style={styles.coinIconText}>
                    {pair.symbol.charAt(0)}
                  </Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.favoriteSymbol}>{pair.symbol.split('/')[0]}</Text>
                  <Text style={styles.favoriteName}>{pair.name}</Text>
                </View>
              </View>
              <MiniChart
                data={pair.chartData}
                color={pair.change24h >= 0 ? colors.trading.green : colors.trading.red}
                width={screenWidth * 0.35}
                height={40}
              />
              <View style={styles.favoriteFooter}>
                <Text style={styles.favoritePrice}>{formatCurrency(pair.price)}</Text>
                <PriceChange value={pair.change24h} size="sm" />
              </View>
            </GlassCard>
          ))}
        </ScrollView>

        {/* Top Gainers / เหรียญที่ขึ้นมากที่สุด */}
        <View style={styles.sectionHeader}>
          <Text style={styles.sectionTitle}>Top Gainers</Text>
          <Pressable onPress={() => router.push('/markets')}>
            <Text style={styles.seeAll}>See All</Text>
          </Pressable>
        </View>

        <GlassCard style={styles.gainersCard}>
          {topGainers.map((pair, index) => (
            <Pressable
              key={pair.symbol}
              style={[
                styles.gainerRow,
                index < topGainers.length - 1 && styles.gainerRowBorder,
              ]}
              onPress={() => router.push('/trade')}
            >
              <View style={styles.gainerLeft}>
                <Text style={styles.gainerRank}>#{index + 1}</Text>
                <View style={[styles.coinIcon, styles.coinIconSm]}>
                  <Text style={[styles.coinIconText, { fontSize: 10 }]}>
                    {pair.symbol.charAt(0)}
                  </Text>
                </View>
                <View>
                  <Text style={styles.gainerSymbol}>{pair.symbol.split('/')[0]}</Text>
                  <Text style={styles.gainerName}>{pair.name}</Text>
                </View>
              </View>
              <View style={styles.gainerRight}>
                <Text style={styles.gainerPrice}>{formatCurrency(pair.price)}</Text>
                <PriceChange value={pair.change24h} size="sm" />
              </View>
            </Pressable>
          ))}
        </GlassCard>

        {/* Market Overview Banner / แบนเนอร์ภาพรวมตลาด */}
        <LinearGradient
          colors={['rgba(6, 182, 212, 0.15)', 'rgba(139, 92, 246, 0.15)']}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={styles.banner}
        >
          <View style={styles.bannerContent}>
            <Ionicons name="flash" size={24} color={colors.brand.cyan} />
            <View style={{ flex: 1, marginLeft: spacing.md }}>
              <Text style={styles.bannerTitle}>Trade with Zero Fees</Text>
              <Text style={styles.bannerDesc}>
                Enjoy 0% maker fees on all spot trading pairs
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={colors.text.tertiary} />
          </View>
        </LinearGradient>

        <View style={{ height: 100 }} />
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
  greeting: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
  },
  appName: {
    ...typography.h3,
    color: colors.text.primary,
    letterSpacing: 1,
  },
  headerRight: {
    flexDirection: 'row',
    gap: spacing.sm,
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
  // Portfolio Card / การ์ดพอร์ตโฟลิโอ
  portfolioCard: {
    padding: spacing.xl,
    marginBottom: spacing['2xl'],
  },
  portfolioHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.sm,
  },
  portfolioLabel: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
  },
  portfolioValue: {
    ...typography.h1,
    color: colors.text.primary,
    marginBottom: spacing.sm,
  },
  portfolioChange: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: spacing.xl,
  },
  portfolioChangeLabel: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
  },
  quickActions: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    borderTopWidth: 1,
    borderTopColor: colors.divider,
    paddingTop: spacing.lg,
  },
  quickAction: {
    alignItems: 'center',
    gap: spacing.sm,
  },
  quickActionIcon: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
  },
  quickActionLabel: {
    ...typography.bodySmall,
    color: colors.text.secondary,
    fontSize: 11,
  },
  // Section / ส่วน
  sectionHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.lg,
  },
  sectionTitle: {
    ...typography.h4,
    color: colors.text.primary,
  },
  seeAll: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
  },
  // Favorites / รายการโปรด
  favoritesScroll: {
    gap: spacing.md,
    paddingBottom: spacing['2xl'],
  },
  favoriteCard: {
    padding: spacing.lg,
  },
  favoriteHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  coinIcon: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.brand.cyan + '20',
    alignItems: 'center',
    justifyContent: 'center',
  },
  coinIconSm: {
    width: 28,
    height: 28,
    borderRadius: 14,
  },
  coinIconText: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
    fontWeight: '700',
    fontSize: 12,
  },
  favoriteSymbol: {
    ...typography.bodySmall,
    color: colors.text.primary,
    fontWeight: '600',
  },
  favoriteName: {
    fontSize: 10,
    color: colors.text.tertiary,
  },
  favoriteFooter: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: spacing.md,
  },
  favoritePrice: {
    ...typography.mono,
    color: colors.text.primary,
    fontSize: 13,
  },
  // Gainers / เหรียญขึ้น
  gainersCard: {
    padding: spacing.lg,
    marginBottom: spacing['2xl'],
  },
  gainerRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: spacing.md,
  },
  gainerRowBorder: {
    borderBottomWidth: 1,
    borderBottomColor: colors.divider,
  },
  gainerLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  gainerRank: {
    ...typography.caption,
    color: colors.text.tertiary,
    width: 24,
    fontSize: 10,
  },
  gainerSymbol: {
    ...typography.bodySmall,
    color: colors.text.primary,
    fontWeight: '600',
  },
  gainerName: {
    fontSize: 10,
    color: colors.text.tertiary,
  },
  gainerRight: {
    alignItems: 'flex-end',
    gap: 2,
  },
  gainerPrice: {
    ...typography.monoSmall,
    color: colors.text.primary,
  },
  // Banner / แบนเนอร์
  banner: {
    borderRadius: 16,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    overflow: 'hidden',
    marginBottom: spacing['2xl'],
  },
  bannerContent: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: spacing.lg,
  },
  bannerTitle: {
    ...typography.body,
    color: colors.text.primary,
    fontWeight: '600',
  },
  bannerDesc: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    marginTop: 2,
  },
});
