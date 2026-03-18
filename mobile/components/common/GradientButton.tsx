import React from 'react';
import {
  StyleSheet,
  Text,
  Pressable,
  ActivityIndicator,
  ViewStyle,
  StyleProp,
  View,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Animated, {
  useSharedValue,
  useAnimatedStyle,
  withSpring,
} from 'react-native-reanimated';
import { colors, spacing, radius, typography } from '@/theme';

type ButtonVariant = 'brand' | 'buy' | 'sell' | 'outline';
type ButtonSize = 'sm' | 'md' | 'lg';

interface GradientButtonProps {
  title: string;
  onPress: () => void;
  variant?: ButtonVariant;
  disabled?: boolean;
  loading?: boolean;
  size?: ButtonSize;
  fullWidth?: boolean;
  style?: StyleProp<ViewStyle>;
}

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

const SPRING_CONFIG = {
  damping: 15,
  stiffness: 350,
  mass: 0.7,
};

const gradientMap: Record<Exclude<ButtonVariant, 'outline'>, readonly [string, string, ...string[]]> = {
  brand: colors.gradient.brand,
  buy: colors.gradient.green,
  sell: colors.gradient.red,
};

const sizeMap: Record<ButtonSize, {
  height: number;
  paddingHorizontal: number;
  fontSize: number;
  fontWeight: TextStyle['fontWeight'];
}> = {
  sm: {
    height: 36,
    paddingHorizontal: spacing.md,
    fontSize: 13,
    fontWeight: '600',
  },
  md: {
    height: 48,
    paddingHorizontal: spacing.xl,
    fontSize: 15,
    fontWeight: '700',
  },
  lg: {
    height: 56,
    paddingHorizontal: spacing['2xl'],
    fontSize: 17,
    fontWeight: '700',
  },
};

import { TextStyle } from 'react-native';

export function GradientButton({
  title,
  onPress,
  variant = 'brand',
  disabled = false,
  loading = false,
  size = 'md',
  fullWidth = false,
  style,
}: GradientButtonProps) {
  const scale = useSharedValue(1);

  const animatedStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
  }));

  const handlePressIn = () => {
    if (!disabled && !loading) {
      scale.value = withSpring(0.96, SPRING_CONFIG);
    }
  };

  const handlePressOut = () => {
    scale.value = withSpring(1, SPRING_CONFIG);
  };

  const sizeConfig = sizeMap[size];
  const isOutline = variant === 'outline';

  const containerStyle: ViewStyle = {
    height: sizeConfig.height,
    borderRadius: radius.md,
    opacity: disabled ? 0.4 : 1,
    alignSelf: fullWidth ? 'stretch' : 'auto',
  };

  const textStyle: TextStyle = {
    fontSize: sizeConfig.fontSize,
    fontWeight: sizeConfig.fontWeight,
    color: isOutline ? colors.brand.cyan : colors.white,
    letterSpacing: 0.3,
  };

  const content = (
    <View style={[styles.contentRow, { paddingHorizontal: sizeConfig.paddingHorizontal }]}>
      {loading ? (
        <ActivityIndicator
          size="small"
          color={isOutline ? colors.brand.cyan : colors.white}
        />
      ) : (
        <Text style={textStyle}>{title}</Text>
      )}
    </View>
  );

  if (isOutline) {
    return (
      <AnimatedPressable
        onPress={onPress}
        onPressIn={handlePressIn}
        onPressOut={handlePressOut}
        disabled={disabled || loading}
        style={[animatedStyle, containerStyle, style]}
      >
        <LinearGradient
          colors={colors.gradient.brand}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 0 }}
          style={[styles.outlineBorder, { borderRadius: radius.md }]}
        >
          <View
            style={[
              styles.outlineInner,
              {
                height: sizeConfig.height - 2,
                borderRadius: radius.md - 1,
              },
            ]}
          >
            {content}
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
      disabled={disabled || loading}
      style={[animatedStyle, containerStyle, style]}
    >
      <LinearGradient
        colors={gradientMap[variant]}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 0 }}
        style={[
          styles.gradientFill,
          {
            height: sizeConfig.height,
            borderRadius: radius.md,
          },
        ]}
      >
        {content}
      </LinearGradient>
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  contentRow: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
  },
  gradientFill: {
    overflow: 'hidden',
  },
  outlineBorder: {
    padding: 1,
  },
  outlineInner: {
    backgroundColor: colors.bg.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
