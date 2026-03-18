import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, radius } from '@/theme';

type PriceChangeSize = 'sm' | 'md' | 'lg';

interface PriceChangeProps {
  value: number;
  prefix?: '+' | '-';
  showIcon?: boolean;
  size?: PriceChangeSize;
}

const sizeConfig: Record<PriceChangeSize, {
  fontSize: number;
  iconSize: number;
  paddingVertical: number;
  paddingHorizontal: number;
  lineHeight: number;
}> = {
  sm: {
    fontSize: 11,
    iconSize: 10,
    paddingVertical: 2,
    paddingHorizontal: spacing.xs,
    lineHeight: 14,
  },
  md: {
    fontSize: 13,
    iconSize: 13,
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.sm,
    lineHeight: 18,
  },
  lg: {
    fontSize: 16,
    iconSize: 16,
    paddingVertical: spacing.xs + 2,
    paddingHorizontal: spacing.md,
    lineHeight: 22,
  },
};

export function PriceChange({
  value,
  prefix,
  showIcon = true,
  size = 'md',
}: PriceChangeProps) {
  const isPositive = value >= 0;
  const config = sizeConfig[size];

  const textColor = isPositive ? colors.trading.green : colors.trading.red;
  const bgColor = isPositive ? colors.trading.greenBg : colors.trading.redBg;
  const iconName = isPositive ? 'caret-up' : 'caret-down';

  const displayPrefix = prefix ?? (isPositive ? '+' : '');
  const formattedValue = `${displayPrefix}${Math.abs(value).toFixed(2)}%`;

  return (
    <View
      style={[
        styles.pill,
        {
          backgroundColor: bgColor,
          paddingVertical: config.paddingVertical,
          paddingHorizontal: config.paddingHorizontal,
        },
      ]}
    >
      {showIcon && (
        <Ionicons
          name={iconName}
          size={config.iconSize}
          color={textColor}
          style={styles.icon}
        />
      )}
      <Text
        style={[
          styles.text,
          {
            color: textColor,
            fontSize: config.fontSize,
            lineHeight: config.lineHeight,
          },
        ]}
      >
        {formattedValue}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    borderRadius: radius.sm,
    alignSelf: 'flex-start',
  },
  icon: {
    marginRight: 2,
  },
  text: {
    fontWeight: '700',
    fontVariant: ['tabular-nums'],
  },
});
