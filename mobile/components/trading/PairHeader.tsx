import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { colors, spacing, radius, typography } from '@/theme';
import { PriceChange } from '../common/PriceChange';
import { formatPrice } from '@/utils/formatters';

interface PairHeaderProps {
  symbol: string;
  price: number;
  change24h: number;
  high: number;
  low: number;
  volume: string;
}

function StatItem({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.statItem}>
      <Text style={styles.statLabel}>{label}</Text>
      <Text style={styles.statValue}>{value}</Text>
    </View>
  );
}

export function PairHeader({
  symbol,
  price,
  change24h,
  high,
  low,
  volume,
}: PairHeaderProps) {
  const isPositive = change24h >= 0;
  const priceColor = isPositive ? colors.trading.green : colors.trading.red;

  return (
    <LinearGradient
      colors={colors.gradient.card}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={styles.container}
    >
      {/* Top row: symbol, price, change */}
      <View style={styles.topRow}>
        <View style={styles.symbolContainer}>
          <Text style={styles.symbol}>{symbol}</Text>
        </View>
        <View style={styles.priceContainer}>
          <Text style={[styles.price, { color: priceColor }]}>
            {formatPrice(price)}
          </Text>
          <PriceChange value={change24h} size="sm" />
        </View>
      </View>

      {/* Stats row */}
      <View style={styles.statsRow}>
        <StatItem label="24h High" value={formatPrice(high)} />
        <View style={styles.statDivider} />
        <StatItem label="24h Low" value={formatPrice(low)} />
        <View style={styles.statDivider} />
        <StatItem label="24h Vol" value={volume} />
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  container: {
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    padding: spacing.lg,
    gap: spacing.md,
  },
  topRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  symbolContainer: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  symbol: {
    ...typography.h4,
    color: colors.text.primary,
    letterSpacing: 0.5,
  },
  priceContainer: {
    alignItems: 'flex-end',
    gap: spacing.xs,
  },
  price: {
    ...typography.monoLarge,
  },
  statsRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg.input,
    borderRadius: radius.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
  },
  statItem: {
    flex: 1,
    alignItems: 'center',
    gap: 2,
  },
  statLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
    fontSize: 9,
  },
  statValue: {
    ...typography.monoSmall,
    color: colors.text.secondary,
  },
  statDivider: {
    width: 1,
    height: 24,
    backgroundColor: colors.divider,
    marginHorizontal: spacing.xs,
  },
});
