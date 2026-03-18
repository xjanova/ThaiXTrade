import React from 'react';
import { StyleSheet, Text, View, Pressable } from 'react-native';
import Animated, {
  useSharedValue,
  useAnimatedStyle,
  withSpring,
} from 'react-native-reanimated';
import { colors, spacing, radius, typography } from '@/theme';
import { MiniChart } from '../trading/MiniChart';
import { PriceChange } from '../common/PriceChange';

interface MarketRowProps {
  symbol: string;
  name: string;
  price: number;
  change24h: number;
  volume: string;
  chartData: number[];
  onPress?: () => void;
}

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

const SPRING_CONFIG = {
  damping: 15,
  stiffness: 300,
  mass: 0.8,
};

function formatPrice(price: number): string {
  if (price >= 1_000) {
    return price.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }
  if (price >= 1) {
    return price.toLocaleString('en-US', {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4,
    });
  }
  return price.toLocaleString('en-US', {
    minimumFractionDigits: 6,
    maximumFractionDigits: 6,
  });
}

function CoinIcon({ symbol }: { symbol: string }) {
  const letter = symbol.charAt(0).toUpperCase();

  return (
    <View style={styles.iconCircle}>
      <Text style={styles.iconLetter}>{letter}</Text>
    </View>
  );
}

export function MarketRow({
  symbol,
  name,
  price,
  change24h,
  volume,
  chartData,
  onPress,
}: MarketRowProps) {
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

  const chartColor = change24h >= 0 ? colors.trading.green : colors.trading.red;

  return (
    <AnimatedPressable
      onPress={onPress}
      onPressIn={handlePressIn}
      onPressOut={handlePressOut}
      disabled={!onPress}
      style={[animatedStyle, styles.container]}
    >
      {/* Left: coin icon + symbol/name */}
      <View style={styles.leftSection}>
        <CoinIcon symbol={symbol} />
        <View style={styles.nameContainer}>
          <Text style={styles.symbol} numberOfLines={1}>
            {symbol}
          </Text>
          <Text style={styles.name} numberOfLines={1}>
            {name}
          </Text>
        </View>
      </View>

      {/* Center: mini chart */}
      <View style={styles.chartSection}>
        <MiniChart
          data={chartData}
          color={chartColor}
          width={72}
          height={32}
        />
      </View>

      {/* Right: price + change */}
      <View style={styles.rightSection}>
        <Text style={styles.price} numberOfLines={1}>
          ${formatPrice(price)}
        </Text>
        <PriceChange value={change24h} size="sm" />
      </View>

      {/* Separator */}
      <View style={styles.separator} />
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.lg,
    position: 'relative',
  },
  leftSection: {
    flexDirection: 'row',
    alignItems: 'center',
    flex: 1,
    gap: spacing.md,
  },
  iconCircle: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.bg.tertiary,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconLetter: {
    ...typography.h4,
    color: colors.brand.cyan,
    fontSize: 16,
  },
  nameContainer: {
    gap: 2,
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
  chartSection: {
    paddingHorizontal: spacing.md,
  },
  rightSection: {
    alignItems: 'flex-end',
    gap: spacing.xs,
    minWidth: 90,
  },
  price: {
    ...typography.mono,
    color: colors.text.primary,
    fontSize: 14,
  },
  separator: {
    position: 'absolute',
    bottom: 0,
    left: spacing.lg + 40 + spacing.md, // offset past icon
    right: spacing.lg,
    height: 1,
    backgroundColor: colors.divider,
  },
});
