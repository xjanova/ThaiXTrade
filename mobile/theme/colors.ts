export const colors = {
  // Background layers
  bg: {
    primary: '#0a0e1a',
    secondary: '#0f1629',
    tertiary: '#151c32',
    card: 'rgba(15, 22, 41, 0.85)',
    cardBorder: 'rgba(255, 255, 255, 0.06)',
    elevated: 'rgba(21, 28, 50, 0.95)',
    input: 'rgba(10, 14, 26, 0.6)',
    overlay: 'rgba(0, 0, 0, 0.7)',
  },

  // Brand colors
  brand: {
    cyan: '#06b6d4',
    cyanLight: '#22d3ee',
    cyanDark: '#0891b2',
    purple: '#8b5cf6',
    purpleLight: '#a78bfa',
    purpleDark: '#7c3aed',
  },

  // Trading colors
  trading: {
    green: '#00C853',
    greenLight: '#69F0AE',
    greenDark: '#00A844',
    greenBg: 'rgba(0, 200, 83, 0.12)',
    red: '#FF1744',
    redLight: '#FF5252',
    redDark: '#D50000',
    redBg: 'rgba(255, 23, 68, 0.12)',
    yellow: '#FFD600',
  },

  // Text colors
  text: {
    primary: '#FFFFFF',
    secondary: 'rgba(255, 255, 255, 0.7)',
    tertiary: 'rgba(255, 255, 255, 0.45)',
    disabled: 'rgba(255, 255, 255, 0.25)',
  },

  // Gradient pairs
  gradient: {
    brand: ['#06b6d4', '#8b5cf6'] as const,
    brandAlt: ['#22d3ee', '#a78bfa'] as const,
    green: ['#00C853', '#69F0AE'] as const,
    red: ['#FF1744', '#FF5252'] as const,
    card: ['rgba(15, 22, 41, 0.9)', 'rgba(21, 28, 50, 0.7)'] as const,
    dark: ['#0a0e1a', '#0f1629'] as const,
  },

  // Glow / shadow colors
  glow: {
    cyan: 'rgba(6, 182, 212, 0.3)',
    purple: 'rgba(139, 92, 246, 0.3)',
    green: 'rgba(0, 200, 83, 0.3)',
    red: 'rgba(255, 23, 68, 0.3)',
  },

  // Misc
  white: '#FFFFFF',
  black: '#000000',
  transparent: 'transparent',
  divider: 'rgba(255, 255, 255, 0.06)',
} as const;
