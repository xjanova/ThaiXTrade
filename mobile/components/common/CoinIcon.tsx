import React from 'react';
import { StyleSheet, Text, View, ViewStyle, StyleProp } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { colors, typography } from '@/theme';

interface CoinIconProps {
  symbol: string;
  color?: string;
  size?: number;
  style?: StyleProp<ViewStyle>;
}

// สีแบรนด์เหรียญ / Coin brand colors
const COIN_COLORS: Record<string, string> = {
  BTC: '#F7931A',
  ETH: '#627EEA',
  BNB: '#F3BA2F',
  SOL: '#9945FF',
  XRP: '#23292F',
  ADA: '#0033AD',
  DOGE: '#C2A633',
  AVAX: '#E84142',
  DOT: '#E6007A',
  LINK: '#2A5ADA',
  UNI: '#FF007A',
  MATIC: '#8247E5',
  USDT: '#26A17B',
  USDC: '#2775CA',
  TPIX: '#06b6d4',
};

function getCoinColor(symbol: string): string {
  const base = symbol.split('/')[0];
  return COIN_COLORS[base] || colors.brand.cyan;
}

function lightenColor(hex: string, amount: number): string {
  const num = parseInt(hex.replace('#', ''), 16);
  const r = Math.min(255, ((num >> 16) & 0xFF) + amount);
  const g = Math.min(255, ((num >> 8) & 0xFF) + amount);
  const b = Math.min(255, (num & 0xFF) + amount);
  return `rgb(${r}, ${g}, ${b})`;
}

export default function CoinIcon({ symbol, color, size = 40, style }: CoinIconProps) {
  const baseSymbol = symbol.split('/')[0];
  const letter = baseSymbol.charAt(0).toUpperCase();
  const coinColor = color || getCoinColor(symbol);
  const fontSize = size * 0.4;

  return (
    <View style={[{ width: size, height: size, borderRadius: size / 2 }, style]}>
      <LinearGradient
        colors={[coinColor, lightenColor(coinColor, 40)]}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={[
          styles.gradient,
          { width: size, height: size, borderRadius: size / 2 },
        ]}
      >
        <Text style={[styles.letter, { fontSize, lineHeight: fontSize * 1.2 }]}>
          {letter}
        </Text>
      </LinearGradient>
    </View>
  );
}

export { getCoinColor, COIN_COLORS };

const styles = StyleSheet.create({
  gradient: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  letter: {
    color: '#FFFFFF',
    fontWeight: '800',
    textShadowColor: 'rgba(0, 0, 0, 0.3)',
    textShadowOffset: { width: 0, height: 1 },
    textShadowRadius: 2,
  },
});
