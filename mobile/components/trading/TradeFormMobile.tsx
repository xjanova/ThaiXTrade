import React, { useState, useCallback, useMemo } from 'react';
import {
  StyleSheet,
  Text,
  View,
  TextInput,
  Pressable,
} from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import GradientButton from '../common/GradientButton';
import { formatNumber, parseInputNumber } from '@/utils/formatters';

type TradeSide = 'buy' | 'sell';
type OrderType = 'limit' | 'market';

interface TradeFormMobileProps {
  symbol?: string;
  currentPrice?: number;
  isSubmitting?: boolean;
  isWalletConnected?: boolean;
  feeRate?: number; // ค่า fee เป็น % (เช่น 0.3 = 0.3%)
  onSubmitOrder?: (order: {
    side: TradeSide;
    type: OrderType;
    price: number | null;
    amount: number;
    total: number;
  }) => void;
}

const PERCENT_BUTTONS = [25, 50, 75, 100] as const;

export default function TradeFormMobile({
  symbol = 'BTC/USDT',
  currentPrice = 0,
  isSubmitting = false,
  isWalletConnected = false,
  feeRate = 0.3,
  onSubmitOrder,
}: TradeFormMobileProps) {
  const [side, setSide] = useState<TradeSide>('buy');
  const [orderType, setOrderType] = useState<OrderType>('limit');
  const [priceInput, setPriceInput] = useState(formatNumber(currentPrice, 2));
  const [amountInput, setAmountInput] = useState('');
  const [selectedPercent, setSelectedPercent] = useState<number | null>(null);

  const price = orderType === 'market' ? currentPrice : parseInputNumber(priceInput);
  const amount = parseInputNumber(amountInput);
  const total = price * amount;
  const estimatedFee = total * (feeRate / 100);

  const baseSymbol = symbol.split('/')[0] || 'BTC';
  const quoteSymbol = symbol.split('/')[1] || 'USDT';

  const isBuy = side === 'buy';
  const activeColor = isBuy ? colors.trading.green : colors.trading.red;
  const activeBg = isBuy ? colors.trading.greenBg : colors.trading.redBg;

  // % buttons ปิดไว้เมื่อยังไม่มี balance จริง (ป้องกันคำนวณผิด)
  const handlePercentPress = useCallback(
    (_percent: number) => {
      setSelectedPercent(_percent);
      // TODO: ใช้ balance จริงจาก wallet/API เมื่อพร้อม
    },
    [],
  );

  const handleSubmit = useCallback(() => {
    // FIX: Input validation ป้องกัน NaN, ค่าลบ, ค่าเกินขอบเขต
    if (!Number.isFinite(amount) || amount <= 0) return;
    if (orderType === 'limit' && (!Number.isFinite(price) || price <= 0)) return;
    if (!Number.isFinite(total) || total <= 0) return;
    // ป้องกัน amount/price เกินขอบเขต (max 1 billion)
    if (amount > 1_000_000_000 || (price && price > 1_000_000_000)) return;

    onSubmitOrder?.({
      side,
      type: orderType,
      price: orderType === 'market' ? null : price,
      amount,
      total,
    });
  }, [side, orderType, price, amount, total, onSubmitOrder]);

  const canSubmit = useMemo(() => {
    if (!isWalletConnected) return false;
    if (!Number.isFinite(amount) || amount <= 0) return false;
    if (orderType === 'limit' && (!Number.isFinite(price) || price <= 0)) return false;
    if (!Number.isFinite(total) || total <= 0) return false;
    return true;
  }, [amount, orderType, price, total, isWalletConnected]);

  return (
    <View style={styles.container}>
      {/* Buy/Sell Tabs */}
      <View style={styles.tabRow}>
        <Pressable
          onPress={() => setSide('buy')}
          style={[
            styles.tab,
            side === 'buy' && {
              backgroundColor: colors.trading.greenBg,
              borderBottomColor: colors.trading.green,
              borderBottomWidth: 2,
            },
          ]}
        >
          <Text
            style={[
              styles.tabText,
              side === 'buy' && { color: colors.trading.green },
            ]}
          >
            Buy
          </Text>
        </Pressable>
        <Pressable
          onPress={() => setSide('sell')}
          style={[
            styles.tab,
            side === 'sell' && {
              backgroundColor: colors.trading.redBg,
              borderBottomColor: colors.trading.red,
              borderBottomWidth: 2,
            },
          ]}
        >
          <Text
            style={[
              styles.tabText,
              side === 'sell' && { color: colors.trading.red },
            ]}
          >
            Sell
          </Text>
        </Pressable>
      </View>

      <View style={styles.formBody}>
        {/* Order Type Selector */}
        <View style={styles.orderTypeRow}>
          {(['limit', 'market'] as const).map((type) => (
            <Pressable
              key={type}
              onPress={() => setOrderType(type)}
              style={[
                styles.orderTypeBtn,
                orderType === type && styles.orderTypeBtnActive,
              ]}
            >
              <Text
                style={[
                  styles.orderTypeText,
                  orderType === type && styles.orderTypeTextActive,
                ]}
              >
                {type.charAt(0).toUpperCase() + type.slice(1)}
              </Text>
            </Pressable>
          ))}
        </View>

        {/* Available Balance */}
        <View style={styles.balanceRow}>
          <Text style={styles.balanceLabel}>Available</Text>
          <Text style={styles.balanceValue}>
            {isWalletConnected ? `— ${isBuy ? quoteSymbol : baseSymbol}` : 'Connect Wallet'}
          </Text>
        </View>

        {/* Price Input */}
        {orderType === 'limit' ? (
          <View style={styles.inputGroup}>
            <Text style={styles.inputLabel}>Price</Text>
            <View style={styles.inputContainer}>
              <TextInput
                style={styles.input}
                value={priceInput}
                onChangeText={(text) => {
                  setPriceInput(text);
                  setSelectedPercent(null);
                }}
                keyboardType="decimal-pad"
                placeholderTextColor={colors.text.disabled}
                placeholder="0.00"
              />
              <Text style={styles.inputSuffix}>{quoteSymbol}</Text>
            </View>
          </View>
        ) : (
          <View style={styles.inputGroup}>
            <Text style={styles.inputLabel}>Price</Text>
            <View style={[styles.inputContainer, styles.inputDisabled]}>
              <Text style={styles.marketPriceText}>Market Price</Text>
            </View>
          </View>
        )}

        {/* Amount Input */}
        <View style={styles.inputGroup}>
          <Text style={styles.inputLabel}>Amount</Text>
          <View style={styles.inputContainer}>
            <TextInput
              style={styles.input}
              value={amountInput}
              onChangeText={(text) => {
                setAmountInput(text);
                setSelectedPercent(null);
              }}
              keyboardType="decimal-pad"
              placeholderTextColor={colors.text.disabled}
              placeholder="0.000000"
            />
            <Text style={styles.inputSuffix}>{baseSymbol}</Text>
          </View>
        </View>

        {/* Percentage Buttons */}
        <View style={styles.percentRow}>
          {PERCENT_BUTTONS.map((percent) => (
            <Pressable
              key={percent}
              onPress={() => handlePercentPress(percent)}
              style={[
                styles.percentBtn,
                selectedPercent === percent && {
                  backgroundColor: activeBg,
                  borderColor: activeColor,
                },
              ]}
            >
              <Text
                style={[
                  styles.percentText,
                  selectedPercent === percent && { color: activeColor },
                ]}
              >
                {percent}%
              </Text>
            </Pressable>
          ))}
        </View>

        {/* Total Display */}
        <View style={styles.totalRow}>
          <Text style={styles.totalLabel}>Total</Text>
          <Text style={styles.totalValue}>
            {total > 0 ? formatNumber(total, 2) : '0.00'} {quoteSymbol}
          </Text>
        </View>

        {/* Fee Display */}
        {total > 0 && (
          <View style={styles.feeRow}>
            <Text style={styles.feeLabel}>Est. Fee ({feeRate}%)</Text>
            <Text style={styles.feeValue}>
              {formatNumber(estimatedFee, 4)} {quoteSymbol}
            </Text>
          </View>
        )}

        {/* Submit Button */}
        <GradientButton
          title={isSubmitting ? 'Placing Order...' : `${isBuy ? 'Buy' : 'Sell'} ${baseSymbol}`}
          onPress={handleSubmit}
          variant={isBuy ? 'buy' : 'sell'}
          size="lg"
          fullWidth
          disabled={!canSubmit || isSubmitting}
          style={styles.submitBtn}
        />
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
    overflow: 'hidden',
  },
  tabRow: {
    flexDirection: 'row',
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.md,
    borderBottomWidth: 2,
    borderBottomColor: 'transparent',
  },
  tabText: {
    ...typography.body,
    fontWeight: '700',
    color: colors.text.tertiary,
  },
  formBody: {
    padding: spacing.lg,
    gap: spacing.md,
  },
  orderTypeRow: {
    flexDirection: 'row',
    backgroundColor: colors.bg.primary,
    borderRadius: radius.sm,
    padding: 2,
  },
  orderTypeBtn: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: spacing.sm,
    borderRadius: radius.sm - 2,
  },
  orderTypeBtnActive: {
    backgroundColor: colors.bg.tertiary,
  },
  orderTypeText: {
    ...typography.bodySmall,
    fontWeight: '600',
    color: colors.text.tertiary,
  },
  orderTypeTextActive: {
    color: colors.text.primary,
  },
  balanceRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  balanceLabel: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
  },
  balanceValue: {
    ...typography.monoSmall,
    color: colors.text.secondary,
  },
  inputGroup: {
    gap: spacing.xs,
  },
  inputLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
  },
  inputContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg.input,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    paddingHorizontal: spacing.md,
    height: 48,
  },
  inputDisabled: {
    opacity: 0.6,
  },
  input: {
    ...typography.mono,
    color: colors.text.primary,
    flex: 1,
    padding: 0,
  },
  inputSuffix: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontWeight: '600',
    marginLeft: spacing.sm,
  },
  marketPriceText: {
    ...typography.body,
    color: colors.text.tertiary,
    fontStyle: 'italic',
  },
  percentRow: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  percentBtn: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: spacing.sm,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    backgroundColor: colors.bg.input,
  },
  percentText: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
  },
  totalRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingTop: spacing.xs,
    borderTopWidth: 1,
    borderTopColor: colors.divider,
  },
  totalLabel: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontWeight: '600',
  },
  totalValue: {
    ...typography.mono,
    color: colors.text.primary,
  },
  feeRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  feeLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
  },
  feeValue: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
  },
  submitBtn: {
    marginTop: spacing.xs,
  },
});
