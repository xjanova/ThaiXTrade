import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import {
  useSharedValue,
  useAnimatedStyle,
  withSpring,
} from 'react-native-reanimated';
import { LinearGradient } from 'expo-linear-gradient';
import { colors, spacing, radius, typography } from '@/theme';
import { PriceChange } from '../common/PriceChange';
import { AnimatedPressable, SPRING_CONFIG } from '@/utils/animation';
import { formatBalance, formatUsdValue } from '@/utils/formatters';

interface AssetRowProps {
  symbol: string;
  name: string;
  balance: number;
  value: number;
  change24h: number;
  icon?: string;
  onPress?: () => void;
}

function CoinIcon({ symbol, icon }: { symbol: string; icon?: string }) {
  const letter = symbol.charAt(0).toUpperCase();

  return (
    <LinearGradient
      colors={[colors.bg.tertiary, colors.bg.secondary]}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={styles.iconCircle}
    >
      <Text style={styles.iconLetter}>{icon ?? letter}</Text>
    </LinearGradient>
  );
}

export function AssetRow({
  symbol,
  name,
  balance,
  value,
  change24h,
  icon,
  onPress,
}: AssetRowProps) {
  const scale = useSharedValue(1);

  const animatedStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
  }));

  const handlePressIn = () => {
    if (onPress) {
      scale.value = withSpring(0.98, SPRING_CONFIG);
    }
  };

  const handlePressOut = () => {
    if (onPress) {
      scale.value = withSpring(1, SPRING_CONFIG);
    }
  };

  return (
    <AnimatedPressable
      onPress={onPress}
      onPressIn={handlePressIn}
      onPressOut={handlePressOut}
      disabled={!onPress}
      style={[animatedStyle]}
    >
      <LinearGradient
        colors={colors.gradient.card}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={styles.container}
      >
        {/* Left: coin icon + symbol/name */}
        <View style={styles.leftSection}>
          <CoinIcon symbol={symbol} icon={icon} />
          <View style={styles.nameContainer}>
            <Text style={styles.symbol} numberOfLines={1}>
              {symbol}
            </Text>
            <Text style={styles.name} numberOfLines={1}>
              {name}
            </Text>
          </View>
        </View>

        {/* Right: balance, value, change */}
        <View style={styles.rightSection}>
          <Text style={styles.balance} numberOfLines={1}>
            {formatBalance(balance)} {symbol}
          </Text>
          <View style={styles.valueRow}>
            <Text style={styles.value} numberOfLines={1}>
              {formatUsdValue(value)}
            </Text>
            <PriceChange value={change24h} size="sm" />
          </View>
        </View>
      </LinearGradient>
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    padding: spacing.lg,
  },
  leftSection: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    flex: 1,
    marginRight: spacing.md,
  },
  iconCircle: {
    width: 44,
    height: 44,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconLetter: {
    ...typography.h4,
    color: colors.brand.cyan,
    fontSize: 18,
  },
  nameContainer: {
    gap: 2,
    flex: 1,
  },
  symbol: {
    ...typography.body,
    fontWeight: '700',
    color: colors.text.primary,
  },
  name: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 12,
  },
  rightSection: {
    alignItems: 'flex-end',
    gap: spacing.xs,
  },
  balance: {
    ...typography.mono,
    color: colors.text.primary,
    fontSize: 14,
  },
  valueRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  value: {
    ...typography.monoSmall,
    color: colors.text.secondary,
  },
});
