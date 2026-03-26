import { useEffect, useState, useCallback } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  Platform,
  RefreshControl,
  Alert,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import * as Haptics from 'expo-haptics';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import PriceChange from '@/components/common/PriceChange';
import MiniChart from '@/components/trading/MiniChart';
import CoinIcon from '@/components/common/CoinIcon';
import { useMarketStore, MarketPair } from '@/stores/marketStore';
import { usePortfolioStore } from '@/stores/portfolioStore';
import { formatCurrency } from '@/utils/formatters';
import { useResponsiveLayout } from '@/utils/responsive';

type IoniconsName = ComponentProps<typeof Ionicons>['name'];

export default function HomeScreen() {
  const insets = useSafeAreaInsets();
  const { contentWidth, favoriteCardWidth, isWeb } = useResponsiveLayout();
  const { pairs, favorites, setSelectedPair, fetchRealData: loadMarket } = useMarketStore();
  const { totalValue, totalChangePercent, fetchRealPortfolio: loadPortfolio } = usePortfolioStore();
  const [refreshing, setRefreshing] = useState(false);
  const [balanceHidden, setBalanceHidden] = useState(false);

  useEffect(() => {
    loadMarket();
    loadPortfolio();
  }, [loadMarket, loadPortfolio]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    if (Platform.OS !== 'web') {
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    }
    await new Promise((r) => setTimeout(r, 800));
    loadMarket();
    loadPortfolio();
    setRefreshing(false);
  }, [loadMarket, loadPortfolio]);

  const favoritePairs = pairs.filter((p) => favorites.includes(p.symbol));
  const topGainers = [...pairs].sort((a, b) => b.change24h - a.change24h).slice(0, 5);

  const handleSelectPair = useCallback((pair: MarketPair) => {
    setSelectedPair(pair);
    router.push('/trade');
  }, [setSelectedPair]);

  const handleQuickAction = useCallback((action: string) => {
    if (Platform.OS !== 'web') {
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
    }
    switch (action) {
      case 'Swap':
        router.push('/trade');
        break;
      case 'Deposit':
      case 'Withdraw':
      case 'Buy':
        Alert.alert(action, `${action} feature coming soon`, [{ text: 'OK' }]);
        break;
    }
  }, []);

  const handleNotifications = useCallback(() => {
    Alert.alert('Notifications', 'No new notifications', [{ text: 'OK' }]);
  }, []);

  const handleScan = useCallback(() => {
    Alert.alert('QR Scanner', 'Scan QR code feature coming soon', [{ text: 'OK' }]);
  }, []);

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      {/* Header */}
      <View style={styles.header}>
        <View>
          <Text style={styles.greeting}>Welcome back</Text>
          <Text style={styles.appName}>TPIX TRADE</Text>
        </View>
        <View style={styles.headerRight}>
          <Pressable style={styles.iconBtn} onPress={handleNotifications}>
            <Ionicons name="notifications-outline" size={22} color={colors.text.secondary} />
          </Pressable>
          <Pressable style={styles.iconBtn} onPress={handleScan}>
            <Ionicons name="scan-outline" size={22} color={colors.text.secondary} />
          </Pressable>
        </View>
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
        {/* Portfolio Card */}
        <GlassCard variant="brand" style={styles.portfolioCard}>
          <View style={styles.portfolioHeader}>
            <Text style={styles.portfolioLabel}>Total Portfolio Value</Text>
            <Pressable onPress={() => setBalanceHidden(!balanceHidden)}>
              <Ionicons
                name={balanceHidden ? 'eye-off-outline' : 'eye-outline'}
                size={18}
                color={colors.text.tertiary}
              />
            </Pressable>
          </View>
          <Text style={styles.portfolioValue}>
            {balanceHidden ? '******' : formatCurrency(totalValue)}
          </Text>
          <View style={styles.portfolioChange}>
            <PriceChange value={totalChangePercent} size="md" showIcon />
            <Text style={styles.portfolioChangeLabel}> 24h Change</Text>
          </View>

          {/* Quick Actions */}
          <View style={styles.quickActions}>
            {([
              { icon: 'arrow-down-outline' as IoniconsName, label: 'Deposit', color: colors.trading.green },
              { icon: 'arrow-up-outline' as IoniconsName, label: 'Withdraw', color: colors.trading.red },
              { icon: 'swap-horizontal-outline' as IoniconsName, label: 'Swap', color: colors.brand.cyan },
              { icon: 'card-outline' as IoniconsName, label: 'Buy', color: colors.brand.purple },
            ]).map((action) => (
              <Pressable
                key={action.label}
                style={styles.quickAction}
                onPress={() => handleQuickAction(action.label)}
              >
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

        {/* Favorites */}
        <View style={styles.sectionHeader}>
          <Text style={styles.sectionTitle}>Favorites</Text>
          <Pressable onPress={() => router.push('/markets')}>
            <Text style={styles.seeAll}>See All</Text>
          </Pressable>
        </View>

        {favoritePairs.length > 0 ? (
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.favoritesScroll}
          >
            {favoritePairs.map((pair) => (
              <GlassCard
                key={pair.symbol}
                style={[styles.favoriteCard, { width: favoriteCardWidth }]}
                onPress={() => handleSelectPair(pair)}
              >
                <View style={styles.favoriteHeader}>
                  <CoinIcon symbol={pair.symbol} color={pair.iconColor} size={32} />
                  <View style={{ flex: 1 }}>
                    <Text style={styles.favoriteSymbol}>{pair.symbol.split('/')[0]}</Text>
                    <Text style={styles.favoriteName}>{pair.name}</Text>
                  </View>
                </View>
                <MiniChart
                  data={pair.chartData}
                  color={pair.change24h >= 0 ? colors.trading.green : colors.trading.red}
                  width={Math.min(favoriteCardWidth * 0.83, 180)}
                  height={40}
                />
                <View style={styles.favoriteFooter}>
                  <Text style={styles.favoritePrice}>{formatCurrency(pair.price)}</Text>
                  <PriceChange value={pair.change24h} size="sm" />
                </View>
              </GlassCard>
            ))}
          </ScrollView>
        ) : (
          <GlassCard style={styles.emptyFavorites}>
            <Ionicons name="star-outline" size={32} color={colors.text.disabled} />
            <Text style={styles.emptyText}>No favorites yet</Text>
            <Text style={styles.emptySubtext}>Add coins from Markets tab</Text>
          </GlassCard>
        )}

        {/* Top Gainers */}
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
              onPress={() => handleSelectPair(pair)}
            >
              <View style={styles.gainerLeft}>
                <Text style={styles.gainerRank}>#{index + 1}</Text>
                <CoinIcon symbol={pair.symbol} color={pair.iconColor} size={28} />
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

        {/* Banner */}
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
  // Portfolio Card
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
  // Section
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
  // Favorites
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
  // Empty favorites
  emptyFavorites: {
    padding: spacing['2xl'],
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: spacing['2xl'],
  },
  emptyText: {
    ...typography.body,
    color: colors.text.tertiary,
    fontWeight: '600',
  },
  emptySubtext: {
    ...typography.bodySmall,
    color: colors.text.disabled,
  },
  // Gainers
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
  // Banner
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
