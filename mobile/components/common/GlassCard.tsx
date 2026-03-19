import React from 'react';
import {
  StyleSheet,
  ViewStyle,
  StyleProp,
  Pressable,
  View,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import {
  useSharedValue,
  useAnimatedStyle,
  withSpring,
} from 'react-native-reanimated';
import { colors, spacing, radius } from '@/theme';
import { AnimatedPressable, SPRING_CONFIG } from '@/utils/animation';
import { webCursor } from '@/utils/web';

type GlassCardVariant = 'default' | 'elevated' | 'brand';

interface GlassCardProps {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  onPress?: () => void;
  variant?: GlassCardVariant;
}

const variantStyles: Record<GlassCardVariant, {
  background: readonly [string, string, ...string[]];
  borderColor: string;
  borderWidth: number;
}> = {
  default: {
    background: colors.gradient.card,
    borderColor: colors.bg.cardBorder,
    borderWidth: 1,
  },
  elevated: {
    background: [colors.bg.elevated, 'rgba(21, 28, 50, 0.85)'] as const,
    borderColor: 'rgba(255, 255, 255, 0.1)',
    borderWidth: 1,
  },
  brand: {
    background: ['rgba(6, 182, 212, 0.08)', 'rgba(139, 92, 246, 0.08)'] as const,
    borderColor: 'transparent',
    borderWidth: 0,
  },
};

export function GlassCard({
  children,
  style,
  onPress,
  variant = 'default',
}: GlassCardProps) {
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

  const config = variantStyles[variant];

  const cardContent = (
    <LinearGradient
      colors={config.background}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={[
        styles.gradient,
        {
          borderColor: config.borderColor,
          borderWidth: config.borderWidth,
        },
      ]}
    >
      {children}
    </LinearGradient>
  );

  if (variant === 'brand') {
    return (
      <AnimatedPressable
        onPress={onPress}
        onPressIn={handlePressIn}
        onPressOut={handlePressOut}
        disabled={!onPress}
        style={[animatedStyle, onPress && webCursor, style]}
      >
        <LinearGradient
          colors={colors.gradient.brand}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={styles.brandBorder}
        >
          <View style={styles.brandInner}>
            <LinearGradient
              colors={variantStyles.brand.background}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={styles.brandContent}
            >
              {children}
            </LinearGradient>
          </View>
        </LinearGradient>
      </AnimatedPressable>
    );
  }

  return (
    <AnimatedPressable
      onPress={onPress}
      onPressIn={handlePressIn}
      onPressOut={handlePressOut}
      disabled={!onPress}
      style={[animatedStyle, style]}
    >
      {cardContent}
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  gradient: {
    borderRadius: radius.lg,
    padding: spacing.lg,
    overflow: 'hidden',
  },
  brandBorder: {
    borderRadius: radius.lg,
    padding: 1,
  },
  brandInner: {
    borderRadius: radius.lg - 1,
    overflow: 'hidden',
  },
  brandContent: {
    borderRadius: radius.lg - 1,
    padding: spacing.lg,
  },
});
