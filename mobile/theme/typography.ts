import { TextStyle } from 'react-native';

/**
 * Typography scale for TPIX TRADE
 * ระบบตัวอักษรสำหรับ TPIX TRADE
 *
 * Uses typed keys for autocomplete and compile-time safety.
 * ใช้ typed keys สำหรับ autocomplete และความปลอดภัยตอน compile
 */

type TypographyKeys =
  | 'h1'
  | 'h2'
  | 'h3'
  | 'h4'
  | 'body'
  | 'bodySmall'
  | 'caption'
  | 'mono'
  | 'monoLarge'
  | 'monoSmall';

export const typography: Record<TypographyKeys, TextStyle> = {
  h1: {
    fontSize: 32,
    fontWeight: '700',
    letterSpacing: -0.5,
  },
  h2: {
    fontSize: 24,
    fontWeight: '700',
    letterSpacing: -0.3,
  },
  h3: {
    fontSize: 20,
    fontWeight: '600',
  },
  h4: {
    fontSize: 18,
    fontWeight: '600',
  },
  body: {
    fontSize: 15,
    fontWeight: '400',
    lineHeight: 22,
  },
  bodySmall: {
    fontSize: 13,
    fontWeight: '400',
    lineHeight: 18,
  },
  caption: {
    fontSize: 11,
    fontWeight: '500',
    letterSpacing: 0.5,
    textTransform: 'uppercase',
  },
  mono: {
    fontSize: 15,
    fontWeight: '600',
    fontFamily: 'SpaceMono',
    fontVariant: ['tabular-nums'],
  },
  monoLarge: {
    fontSize: 22,
    fontWeight: '700',
    fontFamily: 'SpaceMono',
    fontVariant: ['tabular-nums'],
  },
  monoSmall: {
    fontSize: 12,
    fontWeight: '500',
    fontFamily: 'SpaceMono',
    fontVariant: ['tabular-nums'],
  },
} as const;
