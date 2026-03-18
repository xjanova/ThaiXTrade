import React, { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import { formatPrice, formatAmount } from '@/utils/formatters';

interface OrderBookEntry {
  price: number;
  amount: number;
  total: number;
}

interface OrderBookMobileProps {
  asks?: OrderBookEntry[];
  bids?: OrderBookEntry[];
  spread?: number;
  spreadPercent?: number;
}

const VISIBLE_ROWS = 8;

function generateMockOrderBook(): {
  asks: OrderBookEntry[];
  bids: OrderBookEntry[];
  spread: number;
  spreadPercent: number;
} {
  // Use consistent BTC price / ใช้ราคา BTC ที่สอดคล้องกันทั้งแอป
  const midPrice = 98_432.50;
  const asks: OrderBookEntry[] = [];
  const bids: OrderBookEntry[] = [];

  let askTotal = 0;
  for (let i = 0; i < VISIBLE_ROWS; i++) {
    const price = midPrice + (i + 1) * 2.5 + Math.random() * 5;
    const amount = 0.01 + Math.random() * 1.5;
    askTotal += amount;
    asks.push({
      price: Math.round(price * 100) / 100,
      amount: Math.round(amount * 10000) / 10000,
      total: Math.round(askTotal * 10000) / 10000,
    });
  }

  let bidTotal = 0;
  for (let i = 0; i < VISIBLE_ROWS; i++) {
    const price = midPrice - (i + 1) * 2.5 - Math.random() * 5;
    const amount = 0.01 + Math.random() * 1.5;
    bidTotal += amount;
    bids.push({
      price: Math.round(price * 100) / 100,
      amount: Math.round(amount * 10000) / 10000,
      total: Math.round(bidTotal * 10000) / 10000,
    });
  }

  const spread = asks[0].price - bids[0].price;
  const spreadPercent = (spread / midPrice) * 100;

  return { asks, bids, spread, spreadPercent };
}

function OrderRow({
  entry,
  side,
  maxTotal,
}: {
  entry: OrderBookEntry;
  side: 'ask' | 'bid';
  maxTotal: number;
}) {
  const barWidth = maxTotal > 0 ? (entry.total / maxTotal) * 100 : 0;
  const barColor = side === 'ask' ? colors.trading.redBg : colors.trading.greenBg;
  const priceColor = side === 'ask' ? colors.trading.red : colors.trading.green;

  return (
    <View style={styles.row}>
      <View
        style={[
          styles.barFill,
          {
            backgroundColor: barColor,
            width: `${barWidth}%`,
            [side === 'ask' ? 'right' : 'left']: 0,
          },
        ]}
      />
      <Text style={[styles.amountText, styles.cellLeft]} numberOfLines={1}>
        {formatAmount(entry.amount)}
      </Text>
      <Text style={[styles.priceText, { color: priceColor }]} numberOfLines={1}>
        {formatPrice(entry.price)}
      </Text>
      <Text style={[styles.amountText, styles.cellRight]} numberOfLines={1}>
        {formatAmount(entry.total)}
      </Text>
    </View>
  );
}

export function OrderBookMobile({
  asks: asksProp,
  bids: bidsProp,
  spread: spreadProp,
  spreadPercent: spreadPercentProp,
}: OrderBookMobileProps) {
  const { asks, bids, spread, spreadPercent } = useMemo(() => {
    if (asksProp?.length && bidsProp?.length) {
      return {
        asks: asksProp.slice(0, VISIBLE_ROWS),
        bids: bidsProp.slice(0, VISIBLE_ROWS),
        spread: spreadProp ?? 0,
        spreadPercent: spreadPercentProp ?? 0,
      };
    }
    return generateMockOrderBook();
  }, [asksProp, bidsProp, spreadProp, spreadPercentProp]);

  const maxAskTotal = asks.length > 0 ? asks[asks.length - 1].total : 1;
  const maxBidTotal = bids.length > 0 ? bids[bids.length - 1].total : 1;
  const maxTotal = Math.max(maxAskTotal, maxBidTotal);

  // Asks are displayed in reverse order (highest at top)
  const reversedAsks = [...asks].reverse();

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <Text style={[styles.headerText, styles.cellLeft]}>Amount</Text>
        <Text style={styles.headerText}>Price (USDT)</Text>
        <Text style={[styles.headerText, styles.cellRight]}>Total</Text>
      </View>

      {/* Asks (sell orders) */}
      <View style={styles.section}>
        {reversedAsks.map((entry, index) => (
          <OrderRow
            key={`ask-${index}`}
            entry={entry}
            side="ask"
            maxTotal={maxTotal}
          />
        ))}
      </View>

      {/* Spread indicator */}
      <View style={styles.spreadContainer}>
        <View style={styles.spreadLine} />
        <View style={styles.spreadBadge}>
          <Text style={styles.spreadPrice}>{formatPrice(spread)}</Text>
          <Text style={styles.spreadPercent}>
            ({spreadPercent.toFixed(3)}%)
          </Text>
        </View>
        <View style={styles.spreadLine} />
      </View>

      {/* Bids (buy orders) */}
      <View style={styles.section}>
        {bids.map((entry, index) => (
          <OrderRow
            key={`bid-${index}`}
            entry={entry}
            side="bid"
            maxTotal={maxTotal}
          />
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.bg.card,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    paddingVertical: spacing.sm,
    overflow: 'hidden',
  },
  header: {
    flexDirection: 'row',
    paddingHorizontal: spacing.md,
    paddingBottom: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.divider,
  },
  headerText: {
    ...typography.caption,
    color: colors.text.tertiary,
    flex: 1,
    textAlign: 'center',
  },
  cellLeft: {
    textAlign: 'left',
  },
  cellRight: {
    textAlign: 'right',
  },
  section: {
    paddingHorizontal: spacing.xs,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 3,
    paddingHorizontal: spacing.sm,
    position: 'relative',
    overflow: 'hidden',
  },
  barFill: {
    position: 'absolute',
    top: 0,
    bottom: 0,
  },
  priceText: {
    ...typography.monoSmall,
    flex: 1,
    textAlign: 'center',
  },
  amountText: {
    ...typography.monoSmall,
    color: colors.text.secondary,
    flex: 1,
  },
  spreadContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
  },
  spreadLine: {
    flex: 1,
    height: 1,
    backgroundColor: colors.divider,
  },
  spreadBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg.tertiary,
    borderRadius: radius.sm,
    paddingVertical: 3,
    paddingHorizontal: spacing.sm,
    marginHorizontal: spacing.sm,
    gap: 4,
  },
  spreadPrice: {
    ...typography.monoSmall,
    color: colors.text.primary,
  },
  spreadPercent: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
    fontSize: 10,
  },
});
