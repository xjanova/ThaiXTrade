import React from 'react';
import { StyleSheet, Text, View, Pressable } from 'react-native';
import {
  useSharedValue,
  useAnimatedStyle,
  withSpring,
} from 'react-native-reanimated';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, radius, typography } from '@/theme';
import MiniChart from '../trading/MiniChart';
import PriceChange from '../common/PriceChange';
import CoinIcon from '../common/CoinIcon';
import { AnimatedPressable, SPRING_CONFIG } from '@/utils/animation';
import { formatPrice } from '@/utils/formatters';

interface MarketRowProps {
  symbol: string;
  name: string;
  price: number;
  change24h: number;
  volume: string;
  chartData: number[];
  iconColor?: string;
  isFavorite?: boolean;
  onPress?: () => void;
  onToggleFavorite?: () => void;
}

export default function MarketRow({
  symbol,
  name,
  price,
  change24h,
  volume,
  chartData,
  iconColor,
  isFavorite = false,
  onPress,
  onToggleFavorite,
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
        <CoinIcon symbol={symbol} color={iconColor} size={40} />
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

      {/* Right: price + change + favorite */}
      <View style={styles.rightSection}>
        <Text style={styles.price} numberOfLines={1}>
          ${formatPrice(price)}
        </Text>
        <View style={styles.rightBottom}>
          <PriceChange value={change24h} size="sm" />
          {onToggleFavorite && (
            <Pressable
              onPress={(e) => {
                e.stopPropagation?.();
                onToggleFavorite();
              }}
              hitSlop={8}
              style={styles.starBtn}
            >
              <Ionicons
                name={isFavorite ? 'star' : 'star-outline'}
                size={16}
                color={isFavorite ? '#FFD600' : colors.text.disabled}
              />
            </Pressable>
          )}
        </View>
      </View>
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
  rightBottom: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  price: {
    ...typography.mono,
    color: colors.text.primary,
    fontSize: 14,
  },
  starBtn: {
    padding: 2,
  },
});
